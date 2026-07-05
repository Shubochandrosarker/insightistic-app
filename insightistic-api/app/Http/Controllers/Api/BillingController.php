<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\LicenseService;
use App\Services\LifecycleMailer;
use App\Services\StripeService;
use App\Services\UsageService;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class BillingController extends Controller
{
    public function __construct(
        private StripeService $stripe,
        private UsageService $usage,
        private Tenancy $tenancy,
        private LicenseService $licenses,
        private LifecycleMailer $mailer,
    ) {}

    /** Current subscription + plan + this month's usage. */
    public function subscription()
    {
        $org = $this->tenancy->organization()->load(['plan', 'subscription', 'currentUsage']);

        return response()->json([
            'organization' => $org->only(['id', 'name', 'status', 'trial_ends_at']),
            'plan'         => $org->plan,
            'subscription' => $org->subscription,
            'usage'        => [
                'ai_insights' => ['used' => $org->currentUsage->ai_insights_used ?? 0, 'limit' => $org->plan?->ai_insight_limit],
                'reports'     => ['used' => $org->currentUsage->reports_generated ?? 0, 'limit' => $org->plan?->report_limit],
            ],
        ]);
    }

    /** Start a Stripe Checkout session for a plan. */
    public function checkout(Request $request)
    {
        $org = $this->tenancy->organization();

        $data = $request->validate([
            'plan'     => ['required', 'string', 'exists:plans,slug'],
            'interval' => ['required', 'in:monthly,yearly'],
        ]);

        $plan = Plan::where('slug', $data['plan'])->first();
        $priceId = $data['interval'] === 'yearly' ? $plan->stripe_price_id_yearly : $plan->stripe_price_id_monthly;

        if (! $priceId) {
            return response()->json([
                'message' => 'This plan/interval has no Stripe price configured yet.',
                'code'    => 'price_not_configured',
            ], 422);
        }

        $app = rtrim(config('insightistic.app_url'), '/');
        $url = $this->stripe->checkoutUrl(
            $org, $plan, $priceId,
            $app . '/billing/success?session_id={CHECKOUT_SESSION_ID}',
            $app . '/billing/cancel',
        );

        return response()->json(['url' => $url]);
    }

    /** Open the Stripe customer portal. */
    public function portal()
    {
        $org = $this->tenancy->organization();
        $customerId = $org->subscription?->stripe_customer_id;

        if (! $customerId) {
            return response()->json(['message' => 'No billing account yet. Start a checkout first.'], 422);
        }

        $url = $this->stripe->portalUrl($customerId, rtrim(config('insightistic.app_url'), '/') . '/billing');

        return response()->json(['url' => $url]);
    }

    /** Cancel at period end — access continues until the paid period closes. */
    public function cancel()
    {
        $org = $this->tenancy->organization();
        $sub = $org->subscription;

        if (! $sub?->stripe_subscription_id) {
            return response()->json(['message' => 'No active subscription to cancel.', 'code' => 'no_subscription'], 422);
        }

        $this->stripe->setCancelAtPeriodEnd($sub->stripe_subscription_id, true);
        $sub->update(['cancel_at_period_end' => true]);

        $this->mailer->send($org, 'subscription_cancelled', [
            'period_end' => $sub->current_period_end?->format('M j, Y'),
        ], 'cancel:' . now()->format('YmdHis'));

        return response()->json([
            'ok'         => true,
            'ends_at'    => $sub->current_period_end?->toIso8601String(),
            'message'    => 'Subscription will end at the close of the current billing period.',
        ]);
    }

    /** Undo a pending cancellation while the period is still active. */
    public function reactivate()
    {
        $org = $this->tenancy->organization();
        $sub = $org->subscription;

        if (! $sub?->stripe_subscription_id || ! $sub->cancel_at_period_end) {
            return response()->json(['message' => 'Nothing to reactivate — start a new checkout instead.', 'code' => 'no_pending_cancel'], 422);
        }

        $this->stripe->setCancelAtPeriodEnd($sub->stripe_subscription_id, false);
        $sub->update(['cancel_at_period_end' => false]);
        $this->licenses->syncForOrganization($org->fresh());

        $this->mailer->send($org, 'subscription_reactivated', [], 'react:' . now()->format('YmdHis'));

        return response()->json(['ok' => true, 'message' => 'Subscription reactivated.']);
    }

    /**
     * Stripe webhook — the source of truth for subscription state.
     * Public route (no auth/tenant). Resolves the org from event metadata.
     */
    public function webhook(Request $request)
    {
        try {
            $event = $this->stripe->verifyWebhook($request->getContent(), $request->header('Stripe-Signature'));
        } catch (\Throwable $e) {
            Log::warning('Stripe webhook verify failed: ' . $e->getMessage());
            return response()->json(['message' => 'Invalid signature.'], 400);
        }

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

        return response()->json(['received' => true]);
    }

    // --- webhook helpers ---------------------------------------------------

    private function syncSubscription(\Stripe\Event $event): void
    {
        $object = $event->data->object;

        // checkout.session.completed carries the subscription id; fetch the full object.
        $stripeSub = ($event->type === 'checkout.session.completed')
            ? null
            : $object;
        $metaOrg = $object->metadata->organization_id ?? null;
        $metaPlan = $object->metadata->plan_id ?? null;
        $customerId = $object->customer ?? null;

        $org = $this->resolveOrg($metaOrg, $customerId);
        if (! $org) {
            return;
        }

        $sub = Subscription::withoutGlobalScope('organization')
            ->firstOrNew(['organization_id' => $org->id]);

        $sub->organization_id      = $org->id;
        $sub->stripe_customer_id   = $customerId ?: $sub->stripe_customer_id;
        $sub->stripe_subscription_id = $object->subscription ?? ($stripeSub->id ?? $sub->stripe_subscription_id);

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
        }
        $sub->save();

        $org->status = $this->orgStatus($sub->status);
        $org->save();

        // License status/limits are a projection of subscription state.
        $this->licenses->syncForOrganization($org->fresh(['plan']));

        if (! $hadSubscription || $event->type === 'checkout.session.completed') {
            $this->mailer->send($org->fresh('plan'), 'subscription_started', [], 'sub:' . ($sub->stripe_subscription_id ?: 'first'));
        } elseif ($plan && $previousPlanId && $plan->id !== $previousPlanId) {
            $this->mailer->send($org->fresh('plan'), 'plan_changed', [], 'plan:' . $plan->id . ':' . now()->format('Ymd'));
        }
    }

    private function cancelSubscription(\Stripe\Event $event): void
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

    private function markPastDue(\Stripe\Event $event): void
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
