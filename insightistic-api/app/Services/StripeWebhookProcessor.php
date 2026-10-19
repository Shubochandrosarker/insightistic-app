<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\EntitlementGrant;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Support\Carbon;

/**
 * Core Stripe -> local-state projection (spec §13.8). Shared by the live
 * webhook endpoint (BillingController::webhook) and the Super Admin tools
 * that replay a specific event or resync straight from Stripe
 * (Admin\SubscriptionController). Every public method here converges to the
 * same state no matter how many times it runs on the same input — that's
 * what makes "replay an event" and "resync from Stripe" safe to expose as
 * admin actions instead of one-shot webhook-only logic.
 */
class StripeWebhookProcessor
{
    public function __construct(
        private LicenseService $licenses,
        private LifecycleMailer $mailer,
    ) {}

    /**
     * Handle a live webhook delivery exactly once.
     *
     * Stripe retries on its own schedule and an operator can replay a
     * delivery from the dashboard, so the same event id arrives more than
     * once in normal operation. The unique index on stripe_event_id decides
     * the winner: a duplicate insert throws and we return without
     * reprocessing. A SELECT-then-INSERT check would leave a race window
     * between two concurrent deliveries.
     *
     * Deliberately NOT applied to process() itself — Super Admin's "replay
     * this event" tool calls the public projection methods directly and is
     * *meant* to re-run, which is safe because each converges to the same
     * state.
     *
     * @return bool false when this delivery was a duplicate
     */
    public function processOnce(\Stripe\Event $event): bool
    {
        // insertOrIgnore rather than create()-and-catch: on PostgreSQL a
        // failed INSERT aborts the surrounding transaction, so catching the
        // unique violation would leave every later query failing with 25P02.
        // ON CONFLICT DO NOTHING never raises, and the returned row count
        // still tells us who won the race between concurrent deliveries.
        $inserted = \Illuminate\Support\Facades\DB::table('stripe_webhook_events')->insertOrIgnore([
            'stripe_event_id' => $event->id,
            'type'            => $event->type,
            'created_at'      => now(),
        ]);

        if ($inserted === 0) {
            return false;
        }

        $this->process($event);

        \App\Models\StripeWebhookEvent::where('stripe_event_id', $event->id)
            ->update(['processed_at' => now()]);

        return true;
    }

    public function process(\Stripe\Event $event): void
    {
        switch ($event->type) {
            case 'checkout.session.completed':
            case 'customer.subscription.created':
            case 'customer.subscription.updated':
                $this->syncSubscription($event);
                break;
            case 'customer.subscription.deleted':
                $this->cancelSubscription($event);
                break;
            case 'invoice.payment_failed':
                $this->markPastDue($event);
                break;
        }
    }

    public function syncSubscription(\Stripe\Event $event): void
    {
        $object = $event->data->object;

        // checkout.session.completed carries the subscription id, not the object itself.
        $stripeSub = ($event->type === 'checkout.session.completed') ? null : $object;
        $metaOrg = $object->metadata->organization_id ?? null;
        $metaPlan = $object->metadata->plan_id ?? null;
        $customerId = $object->customer ?? null;

        $org = $this->resolveOrg($metaOrg, $customerId);
        if (! $org) {
            return;
        }

        $this->applyStripeState(
            $org,
            $customerId,
            $object->subscription ?? ($stripeSub->id ?? null),
            $stripeSub,
            $metaPlan,
            $event->type === 'checkout.session.completed',
        );
    }

    /** Admin "resync from Stripe" (spec §13.8) — pulls live state instead of replaying a stored event. */
    public function resyncFromLive(Organization $org, \Stripe\Subscription $stripeSub): void
    {
        $this->applyStripeState($org, $stripeSub->customer, $stripeSub->id, $stripeSub, null, false);
    }

    private function applyStripeState(
        Organization $org,
        ?string $customerId,
        ?string $subscriptionId,
        $stripeSub,
        $metaPlan,
        bool $isCheckout,
    ): void {
        $sub = Subscription::withoutGlobalScope('organization')
            ->firstOrNew(['organization_id' => $org->id]);

        $sub->organization_id        = $org->id;
        $sub->stripe_customer_id     = $customerId ?: $sub->stripe_customer_id;
        $sub->stripe_subscription_id = $subscriptionId ?: $sub->stripe_subscription_id;

        if ($stripeSub) {
            $sub->status               = $stripeSub->status;
            $sub->current_period_start = $this->ts($stripeSub->current_period_start ?? null);
            $sub->current_period_end   = $this->ts($stripeSub->current_period_end ?? null);
            $sub->cancel_at_period_end = (bool) ($stripeSub->cancel_at_period_end ?? false);
            $priceId = $stripeSub->items->data[0]->price->id ?? null;
            $plan = $this->planFromPrice($priceId) ?? ($metaPlan ? Plan::find($metaPlan) : null);
        } else {
            $sub->status = 'active';
            $plan = $metaPlan ? Plan::find($metaPlan) : null;
        }

        $previousPlanId = $org->plan_id;
        $hadSubscription = (bool) $sub->exists;

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

        $org->status = $this->orgStatus($sub->status);
        $org->save();

        // License status/limits are a projection of subscription state.
        $this->licenses->syncForOrganization($org->fresh(['plan']));

        if (! $hadSubscription || $isCheckout) {
            $this->mailer->send($org->fresh('plan'), 'subscription_started', [], 'sub:' . ($sub->stripe_subscription_id ?: 'first'));
        } elseif ($plan && $previousPlanId && $plan->id !== $previousPlanId) {
            $this->mailer->send($org->fresh('plan'), 'plan_changed', [], 'plan:' . $plan->id . ':' . now()->format('Ymd'));
        }
    }

    public function cancelSubscription(\Stripe\Event $event): void
    {
        $object = $event->data->object;
        $org = $this->resolveOrg($object->metadata->organization_id ?? null, $object->customer ?? null);
        if (! $org) {
            return;
        }

        Subscription::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)
            ->update(['status' => 'canceled', 'cancel_at_period_end' => false]);

        $org->update(['status' => 'canceled']);
        $this->licenses->syncForOrganization($org->fresh());

        $this->mailer->send($org, 'subscription_cancelled', [
            'period_end' => $org->subscription?->current_period_end?->format('M j, Y'),
        ], 'cancel-hook:' . now()->format('Ymd'));
    }

    public function markPastDue(\Stripe\Event $event): void
    {
        $object = $event->data->object;
        $org = $this->resolveOrg(null, $object->customer ?? null);
        if ($org) {
            $org->update(['status' => 'past_due']);
            $this->licenses->syncForOrganization($org->fresh());
            $this->mailer->send($org, 'payment_failed', [], 'pf:' . now()->format('Ym'));
        }
    }

    private function resolveOrg($metaOrgId, $customerId): ?Organization
    {
        if ($metaOrgId && ($org = Organization::find($metaOrgId))) {
            return $org;
        }
        if ($customerId) {
            return Subscription::withoutGlobalScope('organization')
                ->where('stripe_customer_id', $customerId)
                ->first()?->organization;
        }
        return null;
    }

    private function planFromPrice(?string $priceId): ?Plan
    {
        if (! $priceId) {
            return null;
        }
        return Plan::where('stripe_price_id_monthly', $priceId)
            ->orWhere('stripe_price_id_yearly', $priceId)
            ->first();
    }

    private function orgStatus(string $stripeStatus): string
    {
        return match ($stripeStatus) {
            'active'   => 'active',
            'trialing' => 'trialing',
            'past_due', 'unpaid' => 'past_due',
            'canceled', 'incomplete_expired' => 'canceled',
            default => 'active',
        };
    }

    private function ts($unix): ?Carbon
    {
        return $unix ? Carbon::createFromTimestamp($unix) : null;
    }
}
