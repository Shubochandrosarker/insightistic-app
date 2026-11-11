<?php

namespace App\Http\Controllers\Api;

use App\Domain\Billing\Exceptions\BillingConfigurationException;
use App\Domain\Billing\PaddleBillingService;
use App\Domain\Billing\CreditWallet;
use App\Domain\Billing\EntitlementResolver;
use App\Domain\Billing\StripeBillingService;
use App\Domain\Billing\TrialService;
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
            'organization' => $org->only(['id', 'name', 'status', 'trial_ends_at', 'trial_started_at', 'trial_plan_slug']),
            'trial'        => app(TrialService::class)->eligibility($org),
            'refund_policy' => [
                'money_back_days' => (int) config('commercial.refund_window_days', 30),
                'no_questions_asked' => true,
                // Policy (Nov 2026): only the one-time Lifetime Deal is refundable.
                'applies_to' => ['lifetime'],
                'subscriptions_refundable' => false,
                'credit_packs_refundable' => false,
            ],
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
            ] + $this->featureUsage($org),
            'credits' => $this->creditWallet->summary($org),
        ]);
    }

    /**
     * Org-wide meters for the per-feature plan dimensions, derived from the
     * same ledgers each feature's quota gate counts: active tracked keywords,
     * crawl pages reserved this calendar month (sum of max_pages of every run,
     * any status), and AI visibility prompt runs this month.
     *
     * @return array<string, array{used: int, limit: int}>
     */
    private function featureUsage($org): array
    {
        $usage = app(UsageService::class);
        $siteIds = \Illuminate\Support\Facades\DB::table('sites')->where('organization_id', $org->id)->pluck('id');
        $monthStart = now()->startOfMonth();

        return [
            'tracked_keywords' => [
                'used'  => (int) \Illuminate\Support\Facades\DB::table('rank_keywords')->whereIn('site_id', $siteIds)->where('active', true)->count(),
                'limit' => $usage->limit($org, 'tracked_keywords'),
            ],
            'crawl_pages' => [
                'used'  => (int) \Illuminate\Support\Facades\DB::table('crawl_runs')->whereIn('site_id', $siteIds)->where('created_at', '>=', $monthStart)->sum('max_pages'),
                'limit' => $usage->limit($org, 'http_crawl_pages_monthly'),
            ],
            'ai_prompt_runs' => [
                'used'  => (int) \Illuminate\Support\Facades\DB::table('ai_visibility_runs')->whereIn('site_id', $siteIds)->where('created_at', '>=', $monthStart)->count(),
                'limit' => $usage->limit($org, 'ai_prompt_runs_monthly'),
            ],
        ];
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

    /**
     * GET /billing/credits/balance — the wallet as the UI meters it: per-bucket
     * balances, the plan's monthly credit allowance, and the 20% / 5% / zero
     * warning thresholds. Additive to /billing/credits (which stays the
     * wallet + transaction-history + catalogue view).
     */
    public function creditBalance()
    {
        $org = $this->tenancy->organization();
        $wallet = $this->creditWallet->summary($org); // also grants this period's allowance
        $resolved = $this->entitlements->resolve($org);

        // Same fallback CreditWallet::ensureCurrentPeriod uses: a persisted
        // plan with no subscription still owns its monthly allowance.
        $allowance = (int) ($resolved['limits']['monthly_ai_credits'] ?? 0);
        if ($allowance <= 0 && ! ($resolved['plan']['id'] ?? null) && $org->plan) {
            $allowance = $org->plan->limit('monthly_ai_credits');
        }

        $monthlyRemaining = (int) $wallet['monthly_included']['remaining'];
        $usable = (int) $wallet['total_usable'];
        $lowAt = (int) ceil($allowance * 0.20);
        $criticalAt = (int) ceil($allowance * 0.05);

        // Threshold state tracks what the customer can actually spend, so a
        // purchased pack keeps the meter out of "zero" once the monthly
        // allowance is gone.
        $state = match (true) {
            $allowance <= 0        => 'none',
            $usable <= 0           => 'zero',
            $usable <= $criticalAt => 'critical',
            $usable <= $lowAt      => 'low',
            default                => 'ok',
        };

        return response()->json([
            'buckets'   => $wallet,
            'allowance' => [
                'monthly_ai_credits' => $allowance,
                'plan_slug'          => $resolved['plan']['slug'] ?? $org->plan?->slug,
                'remaining'          => $monthlyRemaining,
                'percent_remaining'  => $allowance > 0 ? round($monthlyRemaining / $allowance * 100, 1) : null,
            ],
            'thresholds' => [
                'low'         => 20,
                'critical'    => 5,
                'zero'        => 0,
                'low_at'      => $lowAt,
                'critical_at' => $criticalAt,
                'state'       => $state,
            ],
        ]);
    }

    /**
     * POST /billing/checkout and /billing/stripe/checkout — Stripe Checkout
     * Session. Accepts {plan, kind: monthly|yearly|lifetime} (or the legacy
     * {plan, interval}). Trials come from TrialService only.
     */
    public function checkout(Request $request, StripeBillingService $stripeBilling)
    {
        $org = $this->tenancy->organization();

        $data = $request->validate([
            'plan'     => ['required', 'string', 'exists:plans,slug'],
            'kind'     => ['required_without:interval', 'nullable', 'in:monthly,yearly,lifetime,credit_pack'],
            'interval' => ['required_without:kind', 'nullable', 'in:monthly,yearly'],
        ]);
        $kind = $data['kind'] ?? $data['interval'];

        $app = rtrim(config('insightistic.app_url'), '/');
        try {
            $result = $stripeBilling->createCheckout(
                $org, $data['plan'], $kind,
                $app . '/dashboard/billing?checkout=success&provider=stripe&plan='
                    . rawurlencode($data['plan']) . '&kind=' . rawurlencode($kind)
                    . '&session_id={CHECKOUT_SESSION_ID}',
                $app . '/dashboard/billing?checkout=cancel&provider=stripe&plan='
                    . rawurlencode($data['plan']) . '&kind=' . rawurlencode($kind),
            );
        } catch (BillingConfigurationException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], 422);
        } catch (\Throwable $e) {
            Log::warning('Stripe checkout failed', ['org' => $org->id, 'error' => class_basename($e)]);

            return response()->json(['message' => 'Billing provider is temporarily unavailable.', 'code' => 'billing_provider_error'], 502);
        }

        return response()->json($result);
    }

    /**
     * POST /billing/credits/checkout — buy an Insight Credit pack with Stripe.
     *
     * One-time payment: nothing is charged until the customer confirms on
     * Stripe's page, so no reauth (same reasoning as billing/stripe/checkout).
     * Credits are only ever granted by the webhook, never by this response.
     */
    public function creditPackCheckout(Request $request, StripeBillingService $stripeBilling)
    {
        $org = $this->tenancy->organization();

        $data = $request->validate([
            'pack' => ['required', 'string', 'in:credits_5000,credits_20000,credits_50000'],
        ]);

        $app = rtrim(config('insightistic.app_url'), '/');
        try {
            $result = $stripeBilling->createCreditsCheckout(
                $org,
                $data['pack'],
                $app . '/dashboard/billing?credits=success&pack=' . rawurlencode($data['pack'])
                    . '&session_id={CHECKOUT_SESSION_ID}',
                $app . '/dashboard/billing?credits=cancel&pack=' . rawurlencode($data['pack']),
            );
        } catch (BillingConfigurationException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], 422);
        } catch (\Throwable $e) {
            Log::warning('Stripe credit-pack checkout failed', ['org' => $org->id, 'error' => class_basename($e)]);

            return response()->json(['message' => 'Billing provider is temporarily unavailable.', 'code' => 'billing_provider_error'], 502);
        }

        return response()->json($result);
    }

    /** GET /billing/providers — checkout providers the customer may choose. Stripe only: the Paddle checkout surface is retired (webhook stays read-only). */
    public function providers(StripeBillingService $stripeBilling)
    {
        return response()->json([
            'providers' => array_values(array_filter([
                $stripeBilling->enabled() ? ['id' => 'stripe', 'label' => 'Stripe', 'kinds' => ['monthly', 'yearly', 'lifetime']] : null,
            ])),
            'current' => $this->providerFor($this->tenancy->organization()),
        ]);
    }

    /**
     * GET /billing/summary — one provider-neutral shape for the billing page:
     * provider, plan, status, next renewal, portal kind, invoices.
     */
    public function summary()
    {
        $org = $this->tenancy->organization()->load(['plan', 'subscription']);
        $sub = $org->subscription;
        $provider = $this->providerFor($org);
        $resolved = $this->entitlements->resolve($org);

        $invoices = \App\Models\BillingTransaction::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)
            ->orderByDesc('occurred_at')->orderByDesc('id')->limit(24)->get()
            ->map(fn ($t) => [
                'id'          => $t->id,
                'provider'    => $t->provider,
                'number'      => $t->invoice_number,
                'kind'        => $t->kind,
                'status'      => $t->status,
                'currency'    => $t->currency,
                'total_minor' => (int) $t->total_minor,
                'tax_minor'   => (int) $t->tax_minor,
                'date'        => ($t->completed_at ?? $t->occurred_at)?->toIso8601String(),
                // Stripe hosted invoice link (safe, customer-scoped).
                'url'         => is_array($t->summary_json) ? ($t->summary_json['hosted_invoice_url'] ?? null) : null,
            ])->values();

        return response()->json([
            'provider'             => $provider,
            'plan'                 => ['slug' => $resolved['plan']['slug'] ?? null, 'name' => $resolved['plan']['name'] ?? null, 'source' => $resolved['source']],
            'status'               => $sub?->status ?? $org->status,
            'next_renewal_at'      => ($sub?->cancel_at_period_end ? null : ($sub?->next_billed_at ?? $sub?->current_period_end))?->toIso8601String(),
            'current_period_end'   => $sub?->current_period_end?->toIso8601String(),
            'cancel_at_period_end' => (bool) $sub?->cancel_at_period_end,
            'trial_ends_at'        => $org->trial_ends_at?->toIso8601String(),
            // Stripe-only portal (Paddle surface retired): the portal is the
            // Stripe Customer Portal for every org, legacy Paddle-billed ones
            // included — portal() creates the Stripe customer on the fly.
            'portal'               => ['type' => 'stripe_portal', 'available' => (bool) ($org->stripe_customer_id || $sub?->stripe_customer_id)],
            'invoices' => $invoices,
        ]);
    }

    private function providerFor($org): ?string
    {
        $sub = $org->subscription;
        if ($sub && in_array($sub->billing_provider, ['paddle', 'stripe'], true)) {
            return $sub->billing_provider;
        }
        if (in_array($org->billing_provider, ['paddle', 'stripe'], true)) {
            return $org->billing_provider;
        }
        if ($sub?->stripe_subscription_id) {
            return 'stripe';
        }

        return $org->paddle_customer_id ? 'paddle' : null;
    }

    /**
     * Open the Stripe customer portal — for every org, Paddle-era ones
     * included. The Stripe customer is created on the fly (get-or-create)
     * so the portal always opens; no Paddle fallback.
     */
    public function portal()
    {
        $org = $this->tenancy->organization();

        try {
            $customerId = $this->stripe->customerId($org);
            $url = $this->stripe->portalUrl($customerId, rtrim(config('insightistic.app_url'), '/') . '/dashboard/billing');
        } catch (\Throwable $e) {
            Log::warning('Stripe portal failed', ['org' => $org->id, 'error' => class_basename($e)]);

            return response()->json(['message' => 'Could not open the billing portal. Contact support if this persists.'], 502);
        }

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

    /**
     * POST /billing/start-trial — activate any regular plan as a 7-day trial
     * with no payment (no Paddle checkout). LTD tiers are never trialable.
     */
    public function startTrial(Request $request, TrialService $trials)
    {
        $data = $request->validate(['plan' => ['required', 'string', 'exists:plans,slug']]);
        $org = $this->tenancy->organization()->load('subscription');

        try {
            $org = $trials->start($org, $data['plan']);
        } catch (BillingConfigurationException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], 422);
        }

        try {
            app(LicenseService::class)->syncForOrganization($org);
            app(LifecycleMailer::class)->send($org->fresh(['plan', 'owner']), 'trial_started', [], 'trial:' . $org->id);
            app(\App\Services\Analytics\AttributionService::class)->event('trial_started', $org, $request->user(), ['plan' => $data['plan']]);
        } catch (\Throwable) {
        }

        return response()->json([
            'ok' => true,
            'organization' => $org->only(['id', 'status', 'trial_ends_at', 'trial_plan_slug']),
            'credits' => $this->creditWallet->summary($org),
        ]);
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
            ->whereIn('source', \App\Models\EntitlementGrant::LIFETIME_SOURCES)
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
