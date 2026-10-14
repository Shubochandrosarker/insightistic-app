<?php

namespace App\Http\Controllers\Api;

use App\Domain\Billing\Exceptions\BillingConfigurationException;
use App\Domain\Billing\Exceptions\PaddleApiException;
use App\Domain\Billing\PaddleBillingService;
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
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BillingController extends Controller
{
    public function __construct(
        private StripeService $stripe,
        private UsageService $usage,
        private Tenancy $tenancy,
        private LicenseService $licenses,
        private LifecycleMailer $mailer,
        private StripeWebhookProcessor $processor,
        private PaddleBillingService $paddleBilling,
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

        return response()->json([
            'organization' => $org->only(['id', 'name', 'status', 'trial_ends_at']),
            'plan'         => $effectivePlan ? array_merge($effectivePlan->toArray(), [
                'effective_source' => $resolved['source'], 'effective_status' => $resolved['status'],
                'features' => $resolved['features'], 'limits' => $resolved['limits'],
            ]) : ['slug' => $resolved['plan']['slug'], 'name' => $resolved['plan']['name'], 'limits' => $resolved['limits'], 'features' => $resolved['features']],
            // Additive extension (roadmap 23-STRIPE-TO-PADDLE-MIGRATION): the
            // provider-neutral columns ride along on the serialized model —
            // null for legacy Stripe rows — and `lifetime_grant` reports an
            // active one-time Paddle purchase. No existing key removed.
            'subscription' => $this->subscriptionPayload($org),
            'usage'        => [
                'ai_insights' => ['used' => $org->currentUsage->ai_insights_used ?? 0, 'limit' => $resolved['limits']['standard_ai_requests_monthly'] ?? $effectivePlan?->ai_insight_limit],
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

        // Provider-aware: Paddle orgs get a temporary Paddle customer-portal
        // link, Stripe orgs keep the Stripe portal. Paddle links are generated
        // per request and never persisted.
        if ($org->subscription?->billing_provider === 'paddle') {
            $url = $this->paddleBilling->portalUrl($org);

            if (! $url) {
                return response()->json(['message' => 'Could not open the Paddle portal. Contact support if this persists.'], 502);
            }

            return response()->json(['url' => $url]);
        }

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

        if ($sub?->billing_provider === 'paddle') {
            $paddleSub = $this->paddleBilling->cancel($org);

            return response()->json([
                'ok'      => true,
                'ends_at' => $paddleSub->current_period_end?->toIso8601String(),
                'message' => 'Subscription will end at the close of the current billing period.',
            ]);
        }

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

        // Paddle has no un-cancel: a canceled Paddle subscription is final,
        // the customer returns via a new checkout (roadmap doc 23 §Phase 6).
        if ($sub?->billing_provider === 'paddle') {
            return response()->json([
                'message' => 'Paddle subscriptions can’t be reactivated — after the period ends, start a new checkout to return.',
                'code'    => 'paddle_no_reactivate',
            ], 422);
        }

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

    // ---- Paddle (roadmap 23-STRIPE-TO-PADDLE-MIGRATION) ----------------------
    // Both actions are flag-gated here rather than at the route, so the API
    // surface stays discoverable (501 tells the SPA the flag is off, not 404).

    /** Plan/currency/price data the SPA needs to render a Paddle.js checkout. */
    public function paddleCheckoutConfig(Request $request)
    {
        if (! $this->paddleEnabled()) {
            return response()->json(['message' => 'Paddle billing is not enabled.', 'code' => 'paddle_not_enabled'], 501);
        }

        $data = $request->validate([
            'plan' => ['required', 'string', 'exists:plans,slug'],
            'kind' => ['required', 'in:monthly,yearly,lifetime,credit_pack'],
            'credit_pack' => ['required_if:kind,credit_pack', 'nullable', 'in:credits_5000,credits_20000,credits_50000'],
        ]);

        // Funnel event (spec §33): checkout OPENED — the verified purchase
        // event lands later from the Paddle webhook projector, never from
        // the browser.
        try {
            app(\App\Services\Analytics\AttributionService::class)->event(
                'checkout_started',
                $this->tenancy->organization(),
                $request->user(),
                ['plan' => $data['plan'], 'checkout_kind' => $data['kind'], 'credit_pack' => $data['credit_pack'] ?? null],
            );
        } catch (\Throwable) {
        }

        try {
            return response()->json($this->paddleBilling->checkoutConfig(
                $this->tenancy->organization(),
                $data['plan'],
                $data['kind'],
                $data['credit_pack'] ?? null,
            ));
        } catch (BillingConfigurationException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], 422);
        } catch (PaddleApiException $e) {
            return $this->providerError($e);
        }
    }

    /** Move the org's Paddle subscription onto another plan's price. */
    public function paddleChangePlan(Request $request)
    {
        if (! $this->paddleEnabled()) {
            return response()->json(['message' => 'Paddle billing is not enabled.', 'code' => 'paddle_not_enabled'], 501);
        }

        $data = $request->validate([
            'plan'                   => ['required', 'string', 'exists:plans,slug'],
            'proration_billing_mode' => ['sometimes', 'in:prorated_immediately,prorated_next_billing_period,do_not_bill,full_immediately'],
        ]);

        $sub = $this->tenancy->organization()->subscription;

        if (! $sub?->provider_subscription_id || $sub->billing_provider !== 'paddle') {
            return response()->json(['message' => 'No active subscription to change.', 'code' => 'no_subscription'], 422);
        }

        try {
            $subscription = $this->paddleBilling->changePlan(
                $this->tenancy->organization(),
                $data['plan'],
                $data['proration_billing_mode'] ?? 'prorated_immediately',
            );

            return response()->json(['ok' => true, 'subscription' => $subscription->fresh()]);
        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'No active subscription to change.', 'code' => 'no_subscription'], 422);
        } catch (BillingConfigurationException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], 422);
        } catch (PaddleApiException $e) {
            return $this->providerError($e);
        }
    }

    private function paddleEnabled(): bool
    {
        return (bool) config('feature-flags.paddle');
    }

    /**
     * Map a Paddle API failure onto the API's provider-error shape without
     * ever echoing provider internals (or the API key) to the tenant.
     */
    private function providerError(PaddleApiException $e)
    {
        Log::warning('Paddle API error during tenant billing action.', [
            'status' => $e->status,
            'error'  => $e->getMessage(),
        ]);

        return response()->json(['message' => 'Billing provider is temporarily unavailable.', 'code' => 'billing_provider_error'], 502);
    }

    /** Subscription JSON: full model (provider-neutral columns included) + lifetime_grant. */
    private function subscriptionPayload($org): ?array
    {
        $sub = $org->subscription;

        if (! $sub) {
            return null;
        }

        $grant = EntitlementGrant::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)
            ->where('source', 'paddle_lifetime')
            ->whereNull('revoked_at')
            ->orderByDesc('id')
            ->first();

        return $sub->toArray() + [
            'lifetime_grant' => $grant?->isActive()
                ? ['active' => true, 'plan_slug' => Plan::find($grant->plan_id)?->slug]
                : null,
        ];
    }
}
