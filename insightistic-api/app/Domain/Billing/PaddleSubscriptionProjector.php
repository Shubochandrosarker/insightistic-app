<?php

namespace App\Domain\Billing;

use App\Domain\Billing\Exceptions\UnresolvableOrganizationException;
use App\Models\Organization;
use App\Models\EntitlementGrant;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Services\LicenseService;
use App\Services\LifecycleMailer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Paddle -> local-state projection for subscriptions. The Paddle analogue of
 * StripeWebhookProcessor::applyStripeState: same org-status mapping, same
 * license sync, same lifecycle mailer — so the two providers converge onto
 * one subscriptions row shape and every downstream consumer (entitlements,
 * admin tools) stays provider-agnostic.
 *
 * Like the Stripe processor, every run converges to the same state for the
 * same input — replaying an event or re-projecting a live fetch is safe.
 */
final class PaddleSubscriptionProjector
{
    public function __construct(
        private LicenseService $licenses,
        private LifecycleMailer $mailer,
    ) {}

    /**
     * Upsert a Paddle subscription payload (the entity object, as delivered
     * in webhook `data` or returned by the API) onto the subscriptions table.
     *
     * The organization is resolved from the payload's custom_data hint when
     * present, else from the existing local row. A brand-new subscription
     * with neither is a data problem (our checkout always stamps
     * custom_data.organization_id) — logged and raised so the job's error
     * ledger shows it instead of silently dropping a paying customer.
     *
     * @throws \RuntimeException when the owning organization cannot be resolved
     */
    public function project(array $payload): Subscription
    {
        $sub = Subscription::withoutGlobalScope('organization')
            ->where('billing_provider', 'paddle')
            ->where('provider_subscription_id', $payload['id'] ?? null)
            ->firstOrNew();

        $org = $this->resolveOrg($payload, $sub);

        if (! $org) {
            Log::warning('Paddle subscription event could not be resolved to an organization.', [
                'provider_subscription_id' => $payload['id'] ?? null,
                'customer_id'              => $payload['customer_id'] ?? null,
            ]);

            throw new UnresolvableOrganizationException('Paddle subscription', (string) ($payload['id'] ?? '?'));
        }

        $status = $this->localStatus($payload['status'] ?? null);
        $scheduledAction = $payload['scheduled_change']['action'] ?? null;
        $scheduledAt = $this->date($payload['scheduled_change']['effective_at'] ?? null);
        $period = $payload['current_billing_period'] ?? [];

        $isNewSubscription = ! $sub->exists;

        // Funnel events (spec §33) — exactly-once per subscription lifecycle.
        try {
            $analytics = app(\App\Services\Analytics\AttributionService::class);
            if ($isNewSubscription && $status !== 'canceled') {
                $analytics->event('subscription_started', $org, null, [
                    'subscription_id' => $payload['id'] ?? null,
                    'price_id'        => $payload['items'][0]['price']['id'] ?? null,
                ]);
            }
        } catch (\Throwable) {
        }

        $sub->organization_id          = $org->id;
        $sub->billing_provider         = 'paddle';
        $sub->provider_subscription_id = $payload['id'];
        $sub->provider_customer_id     = $payload['customer_id'] ?? $sub->provider_customer_id;
        $sub->provider_price_id        = $payload['items'][0]['price']['id'] ?? null;
        $sub->provider_status          = $payload['status'] ?? null;
        $sub->status                   = $status;
        $sub->current_period_start     = $this->date($period['starts_at'] ?? null);
        $sub->current_period_end       = $this->date($period['ends_at'] ?? null);
        $sub->next_billed_at           = $this->date($payload['next_billed_at'] ?? null);
        $sub->scheduled_change_action  = $scheduledAction;
        $sub->scheduled_change_at      = $scheduledAt;
        $sub->cancel_at_period_end     = $scheduledAction === 'cancel';

        // Plans come only from the plan_prices mapping — never guessed from
        // amount or product name (roadmap 23-STRIPE-TO-PADDLE-MIGRATION §4.1).
        $plan = $this->planFromPrice($sub->provider_price_id);

        if ($sub->provider_price_id !== null && $plan === null) {
            Log::warning('Paddle price id has no active plan_prices mapping; plan left unchanged.', [
                'provider_price_id'        => $sub->provider_price_id,
                'provider_subscription_id' => $sub->provider_subscription_id,
            ]);
        }

        $previousPlanId = $org->plan_id;
        $hadSubscription = $sub->exists;

        if ($plan) {
            $sub->plan_id = $plan->id;
            $org->plan_id = $plan->id;
            EntitlementGrant::withoutGlobalScope('organization')
                ->where('organization_id', $org->id)
                ->where('source', 'manual')
                ->where('source_reference', 'admin_plan_override:' . $org->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);
        }
        $sub->save();

        $org->status = $this->orgStatus($status);
        $org->save();

        // License status/limits are a projection of subscription state —
        // identical call to the Stripe path.
        $this->licenses->syncForOrganization($org->fresh(['plan']));

        if ($status === 'canceled') {
            $this->mailer->send($org, 'subscription_cancelled', [
                'period_end' => $sub->current_period_end?->format('M j, Y'),
            ], 'cancel-hook:' . now()->format('Ymd'));
        } elseif (! $hadSubscription) {
            $this->mailer->send($org->fresh('plan'), 'subscription_started', [], 'sub:' . $sub->provider_subscription_id);
        } elseif ($plan && $previousPlanId && $plan->id !== $previousPlanId) {
            $this->mailer->send($org->fresh('plan'), 'plan_changed', [], 'plan:' . $plan->id . ':' . now()->format('Ymd'));
        }

        return $sub;
    }

    // ------------------------------------------------------------------

    private function resolveOrg(array $payload, Subscription $sub): ?Organization
    {
        $organizationId = $payload['custom_data']['organization_id'] ?? null;

        if ($organizationId && ($org = Organization::find($organizationId))) {
            return $org;
        }

        return $sub->exists ? $sub->organization : null;
    }

    /** Active plan_prices mapping for a Paddle price id; null when unmapped. */
    private function planFromPrice(?string $priceId): ?\App\Models\Plan
    {
        if (! $priceId) {
            return null;
        }

        return PlanPrice::query()
            ->where('provider', 'paddle')
            ->where('provider_price_id', $priceId)
            ->where('is_active', true)
            ->first()?->plan;
    }

    /** Paddle status -> local status (Stripe-compatible vocabulary). */
    private function localStatus(?string $paddleStatus): string
    {
        return match ($paddleStatus) {
            'trialing' => 'trialing',
            'active'   => 'active',
            'past_due' => 'past_due',
            'paused'   => 'paused',
            'canceled' => 'canceled',
            // Unknown statuses keep access rather than locking a paying
            // tenant out; the reconciler will converge on the next fetch.
            default => 'active',
        };
    }

    /** Same org-status projection StripeWebhookProcessor applies. */
    private function orgStatus(string $localStatus): string
    {
        return match ($localStatus) {
            'active'   => 'active',
            'trialing' => 'trialing',
            'past_due' => 'past_due',
            'canceled' => 'canceled',
            // Paused (Paddle-only) and unknown statuses keep the org open,
            // exactly like StripeWebhookProcessor's default branch.
            default    => 'active',
        };
    }

    private function date($value): ?Carbon
    {
        return $value ? Carbon::parse($value) : null;
    }
}
