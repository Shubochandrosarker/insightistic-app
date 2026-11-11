<?php

namespace App\Domain\Billing;

use App\Domain\Billing\Exceptions\BillingConfigurationException;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Services\StripeService;
use Illuminate\Support\Str;

/**
 * Stripe Checkout for the dual-provider setup (Paddle + Stripe).
 *
 * - Prices come only from plan_prices (provider = stripe); nothing is
 *   invented or derived from amounts.
 * - Subscriptions: 7-day trial ONLY when the app-side TrialService says the
 *   org is still eligible (single source of truth for trials). If the org is
 *   mid in-app trial, Stripe gets trial_end = the remaining in-app trial so
 *   no one ever gets a second trial.
 * - Lifetime (Founding Angel): mode=payment, no trial, reserved against the
 *   shared 100-spot LtdCampaign before the session is created.
 * - Credit packs: one-time mode=payment via createCreditsCheckout(), priced
 *   from config/commercial.php credit_packs (a STRIPE_PRICE_CREDITS_* id wins
 *   when set, else an inline price_data).
 * - automatic_tax is always on (subscriptions and lifetime).
 */
final class StripeBillingService
{
    public function __construct(
        private StripeService $stripe,
        private TrialService $trials,
        private LtdCampaignService $ltdCampaign,
    ) {}

    public function enabled(): bool
    {
        return in_array('stripe', (array) config('billing.checkout_providers', []), true)
            && StripeService::mode() !== 'unconfigured';
    }

    /** @return array{provider:string, url:string, session_id:string, trial_days:int} */
    public function createCheckout(Organization $org, string $planSlug, string $kind, string $successUrl, string $cancelUrl): array
    {
        if ($kind === 'credit_pack') {
            // Packs are not plan purchases and have their own one-time
            // endpoint (createCreditsCheckout → POST /billing/credits/checkout),
            // which prices them from config/commercial.php credit_packs.
            throw new BillingConfigurationException('provider_not_supported', 'Insight Credit packs use their own checkout — open the credit packs section of the billing page.');
        }
        if (! in_array($kind, ['monthly', 'yearly', 'lifetime'], true)) {
            throw new BillingConfigurationException('invalid_kind', 'Unknown purchase kind.');
        }
        if (! $this->enabled()) {
            throw new BillingConfigurationException('stripe_not_configured', 'Stripe checkout is not configured yet. Please choose Paddle or try again later.');
        }

        $plan = Plan::where('slug', $planSlug)->first();
        $recurring = $kind !== 'lifetime';
        if (! $plan || ($recurring && ! $plan->is_active)
            || ($recurring && ! in_array($planSlug, config('commercial.recurring_plan_slugs', []), true))
            || (! $recurring && ! in_array($planSlug, config('commercial.ltd_plan_slugs', []), true))) {
            throw new BillingConfigurationException('plan_not_available', 'This plan is not available.');
        }

        $price = PlanPrice::where('plan_id', $plan->id)->where('provider', 'stripe')
            ->where('kind', $kind)->where('is_active', true)->first();
        if (! $price) {
            throw new BillingConfigurationException('price_not_configured', 'This plan/kind has no Stripe price configured yet.');
        }

        $reservation = null;
        try {
            $reservation = $recurring ? null : $this->ltdCampaign->reserve($org, $planSlug, (string) Str::uuid());

            $metadata = [
                'organization_id' => (string) $org->id,
                'plan_id'         => (string) $plan->id,
                'plan_slug'       => $plan->slug,
                'purchase_kind'   => $kind,
                'app'             => 'insightistic',
            ];
            if ($reservation) {
                $metadata['ltd_reservation_id'] = (string) $reservation->id;
                $metadata['ltd_campaign_id'] = (string) $reservation->campaign_id;
            }

            $params = [
                'mode'                 => $recurring ? 'subscription' : 'payment',
                'customer'             => $this->stripe->customerId($org),
                'line_items'           => [['price' => $price->provider_price_id, 'quantity' => 1]],
                'automatic_tax'        => ['enabled' => true],
                'customer_update'      => ['address' => 'auto', 'name' => 'auto'],
                'tax_id_collection'    => ['enabled' => true],
                'billing_address_collection' => 'required',
                'client_reference_id'  => (string) $org->id,
                'success_url'          => $successUrl,
                'cancel_url'           => $cancelUrl,
                'metadata'             => $metadata,
            ];

            $trialDays = 0;
            if ($recurring) {
                $subscriptionData = ['metadata' => $metadata];
                [$trialDays, $trialEnd] = $this->trialFor($org);
                if ($trialEnd) {
                    $subscriptionData['trial_end'] = $trialEnd;
                } elseif ($trialDays > 0) {
                    $subscriptionData['trial_period_days'] = $trialDays;
                }
                if ($trialDays > 0 || $trialEnd) {
                    $subscriptionData['trial_settings'] = ['end_behavior' => ['missing_payment_method' => 'cancel']];
                    $params['metadata']['trial_days'] = (string) $trialDays;
                }
                $params['subscription_data'] = $subscriptionData;
            } else {
                $params['payment_intent_data'] = ['metadata' => $metadata];
                $params['invoice_creation'] = ['enabled' => true, 'invoice_data' => ['metadata' => $metadata]];
                // A Stripe session lives ≥30 min; the reservation is 15. Expire
                // the session with the reservation so a slot can't be paid late.
                $params['expires_at'] = now()->addMinutes(30)->getTimestamp();
                $reservation->forceFill(['expires_at' => now()->addMinutes(31)])->save();
            }

            $session = $this->stripe->createCheckoutSession($params);
        } catch (\Throwable $e) {
            $this->ltdCampaign->release($reservation);
            throw $e;
        }

        return ['provider' => 'stripe', 'url' => $session->url, 'session_id' => $session->id, 'trial_days' => $trialDays];
    }

    /**
     * One-time Insight Credit pack (mode=payment).
     *
     * The catalogue is config/commercial.php credit_packs — the same array the
     * Paddle overlay and the public pricing endpoint read — so a Stripe pack
     * can never advertise a different amount than the rest of the product. A
     * STRIPE_PRICE_CREDITS_<pack> id is preferred when configured; otherwise an
     * inline price_data is built from price_minor so packs work with zero
     * Stripe catalog setup.
     *
     * No LtdCampaign reservation, no trial, no automatic_tax: a pack is a
     * digital top-up charged at the catalogue price, not a plan purchase.
     * Fulfilment is webhook-only (StripeWebhookProcessor::fulfillCreditPack).
     *
     * @return array{provider:string, url:string, session_id:string, pack:string, credits:int}
     */
    public function createCreditsCheckout(Organization $org, string $packSlug, string $successUrl, string $cancelUrl): array
    {
        $pack = collect(config('commercial.credit_packs', []))
            ->first(fn (array $candidate): bool => ($candidate['slug'] ?? null) === $packSlug);
        if (! $pack || (int) $pack['credits'] <= 0) {
            throw new BillingConfigurationException('invalid_credit_pack', 'Choose a valid Insight Credit pack.');
        }
        if (! $this->enabled()) {
            throw new BillingConfigurationException('stripe_not_configured', 'Stripe checkout is not configured yet. Please choose Paddle or try again later.');
        }

        $credits = (int) $pack['credits'];
        $metadata = [
            'organization_id' => (string) $org->id,
            'purchase_kind'   => 'credit_pack',
            'pack'            => $packSlug,
            'credits'         => (string) $credits,
            'app'             => 'insightistic',
        ];

        $priceId = config('billing.stripe.credit_prices.' . $packSlug);
        $lineItem = $priceId
            ? ['price' => $priceId, 'quantity' => 1]
            : [
                'price_data'   => [
                    'currency'     => 'usd',
                    'unit_amount'  => (int) $pack['price_minor'],
                    'product_data' => [
                        'name'     => 'Insight Credits — ' . number_format($credits) . ' credits',
                        'metadata' => ['pack' => $packSlug, 'credits' => (string) $credits],
                    ],
                ],
                'quantity' => 1,
            ];

        $session = $this->stripe->createCheckoutSession([
            'mode'                => 'payment',
            'customer'            => $this->stripe->customerId($org),
            'line_items'          => [$lineItem],
            'client_reference_id' => (string) $org->id,
            'success_url'         => $successUrl,
            'cancel_url'          => $cancelUrl,
            'metadata'            => $metadata,
            // Mirrors the metadata onto the payment intent so a refund or
            // chargeback can be traced back to the org and pack.
            'payment_intent_data' => ['metadata' => $metadata],
        ]);

        return [
            'provider'   => 'stripe',
            'url'        => $session->url,
            'session_id' => $session->id,
            'pack'       => $packSlug,
            'credits'    => $credits,
        ];
    }

    /**
     * Trial comes from TrialService only:
     *  - eligible (never trialed)            → trial_period_days = trial_days
     *  - mid in-app trial (trial_ends_at > now) → trial_end = in-app trial end (no extra days)
     *  - otherwise                            → no trial
     *
     * @return array{0:int,1:?int}
     */
    private function trialFor(Organization $org): array
    {
        if ($this->trials->eligibility($org)['eligible']) {
            return [$this->trials->trialDays(), null];
        }
        if ($org->trial_ends_at && $org->trial_ends_at->isFuture() && $org->trial_ends_at->diffInHours(now(), true) >= 48) {
            // Stripe requires trial_end ≥ 48h out; shorter remainders just bill now.
            return [0, $org->trial_ends_at->getTimestamp()];
        }

        return [0, null];
    }
}
