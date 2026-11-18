<?php

namespace App\Http\Controllers\Api;

use App\Domain\Billing\CreditWallet;
use App\Domain\Billing\EntitlementResolver;
use App\Http\Controllers\Controller;
use App\Models\EntitlementGrant;
use App\Models\Plan;
use App\Services\LicenseService;
use App\Services\LifecycleMailer;
use App\Services\StripeService;
use App\Services\StripeWebhookProcessor;
use App\Services\UsageService;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class BillingController extends Controller
{
    public function __construct(
        private StripeService $stripe,
        private UsageService $usage,
        private Tenancy $tenancy,
        private LicenseService $licenses,
        private LifecycleMailer $mailer,
        private StripeWebhookProcessor $processor,
        private CreditWallet $creditWallet,
        private EntitlementResolver $entitlements,
    ) {}

    /** Current subscription + plan + this month's usage. */
    public function subscription()
    {
        $org = $this->tenancy->organization()->load(['plan', 'subscription', 'currentUsage']);
        $resolved = $this->entitlements->resolve($org);
        $effectivePlan = $org->plan;
        if (($resolved['plan']['id'] ?? null) && (! $effectivePlan || $effectivePlan->id !== $resolved['plan']['id'])) {
            $effectivePlan = Plan::find($resolved['plan']['id']);
        }

        $counter = $this->usage->counter($org);
        $lifetimeGrant = $this->lifetimeGrantPayload($org);

        return response()->json([
            'organization' => $org->only(['id', 'name', 'status', 'trial_ends_at']),
            'plan'         => $effectivePlan ? array_merge($effectivePlan->toArray(), [
                'effective_source' => $resolved['source'], 'effective_status' => $resolved['status'],
                'features' => $resolved['features'], 'limits' => $resolved['limits'],
            ]) : ['slug' => $resolved['plan']['slug'], 'name' => $resolved['plan']['name'], 'limits' => $resolved['limits'], 'features' => $resolved['features']],
            // Provider-neutral columns remain in the serialized model for
            // historical data, but every new paid action is Stripe-only.
            'subscription' => $this->subscriptionPayload($org),
            'lifetime_grant' => $lifetimeGrant,
            'usage'        => [
                'ai_insights' => ['used' => $org->currentUsage->ai_insights_used ?? 0, 'limit' => $resolved['limits']['standard_ai_requests_monthly'] ?? $effectivePlan?->ai_insight_limit],
                'jolly_messages' => ['used' => (int) $counter->ai_messages_used, 'limit' => $resolved['limits']['monthly_ai_messages'] ?? 0],
                'standard_ai_actions' => ['used' => $org->currentUsage->ai_insights_used ?? 0, 'limit' => $resolved['limits']['standard_ai_requests_monthly'] ?? $effectivePlan?->ai_insight_limit],
                'reports'     => ['used' => $org->currentUsage->reports_generated ?? 0, 'limit' => $resolved['limits']['client_ready_reports_monthly'] ?? $effectivePlan?->report_limit],
            ],
            'credits' => $this->creditWallet->summary($org),
        ]);
    }

    /** Dedicated wallet endpoint used by the app and WordPress connector. */
    public function credits()
    {
        $org = $this->tenancy->organization();

        return response()->json([
            'wallet' => $this->creditWallet->summary($org),
            'transactions' => $this->creditWallet->transactions($org),
            'action_costs' => config('commercial.ai_action_costs'),
            'credit_packs' => config('commercial.credit_packs'),
        ]);
    }

    /** Start a Stripe Checkout session for a plan. */
    public function checkout(Request $request)
    {
        $org = $this->tenancy->organization();

        $data = $request->validate([
            'plan'     => ['required', 'string', Rule::in(config('commercial.recurring_plan_slugs', []))],
            'interval' => ['required', 'in:monthly,yearly'],
        ]);

        $plan = Plan::where('slug', $data['plan'])->first();
        if (! $plan || ! $plan->is_active) {
            return response()->json([
                'message' => 'This plan is not available for Stripe checkout.',
                'code'    => 'plan_not_available',
            ], 422);
        }
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
        try {
            $customerId = $this->stripe->customerId($org);
            $url = $this->stripe->portalUrl($customerId, rtrim(config('insightistic.app_url'), '/') . '/dashboard/billing');
        } catch (\Throwable $e) {
            Log::warning('Stripe portal failed', ['org' => $org->id, 'error' => class_basename($e)]);

            return response()->json(['message' => 'Could not open the Stripe billing portal. Contact support if this persists.'], 502);
        }

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

        // Idempotent: Stripe retries and dashboard replays deliver the same
        // event id more than once. A duplicate is acknowledged with 200 —
        // returning an error would only make Stripe retry it again.
        $processed = $this->processor->processOnce($event);

        return response()->json(['received' => true, 'duplicate' => ! $processed]);
    }

    /** Subscription JSON: full model (provider-neutral columns included) + lifetime_grant. */
    private function subscriptionPayload($org): ?array
    {
        $sub = $org->subscription;

        if (! $sub) {
            return null;
        }

        return $sub->toArray() + [
            'lifetime_grant' => $this->lifetimeGrantPayload($org),
        ];
    }

    /** The lifetime grant is returned even when no subscription row exists. */
    private function lifetimeGrantPayload($org): ?array
    {
        $grant = EntitlementGrant::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)
            ->where('source', 'appsumo_lifetime')
            ->whereNull('revoked_at')
            ->orderByDesc('id')
            ->first();

        if (! $grant || ! $grant->isActive()) {
            return null;
        }

        $plan = $grant->plan_id ? Plan::find($grant->plan_id) : null;

        return [
            'active' => true,
            'plan_slug' => $plan?->slug,
            'plan_name' => $plan?->name,
            'source' => $grant->source,
            'granted_at' => $grant->created_at?->toIso8601String(),
            'limits' => $plan?->limits_json ?? [],
        ];
    }
}
