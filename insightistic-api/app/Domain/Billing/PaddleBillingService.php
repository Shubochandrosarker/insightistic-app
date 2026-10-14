<?php

namespace App\Domain\Billing;

use App\Domain\Billing\Exceptions\BillingConfigurationException;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Services\Billing\PaddleClient;
use Illuminate\Support\Str;

/**
 * Tenant-facing Paddle operations (roadmap 23-STRIPE-TO-PADDLE-MIGRATION):
 * what the SPA needs to render Paddle.js checkout, plus the two mutations
 * that don't need to leave the app — cancel and change plan. Mutations go
 * through PaddleClient and are re-projected locally, so local state moves
 * immediately and converges with whatever Paddle later emits as events.
 */
final class PaddleBillingService
{
    public function __construct(
        private PaddleClient $client,
        private PaddleSubscriptionProjector $subscriptions,
        private LtdCampaignService $ltdCampaign,
    ) {}

    /**
     * Everything the frontend needs to open a Paddle.js checkout. Prices and
     * amounts are served only from plan_prices — the frontend never composes
     * its own price, and custom_data is a routing hint the server re-checks
     * on every webhook, never a source of truth.
     *
     * @return array{provider:string, price_id:string, currency:string,
     *               amount_minor:int, client_token:?string,
     *               customer:array{email:?string}, custom_data:array}
     */
    public function checkoutConfig(Organization $org, string $planSlug, string $kind, ?string $creditPack = null): array
    {
        $plan = Plan::where('slug', $planSlug)->first();
        $reservation = null;

        // Hidden plans (Angel LTD tiers, credit packs) are seeded
        // is_active=false so they never appear as subscribable plans — their
        // purchasability is the ACTIVE PADDLE PRICE below, not the plan flag.
        // Recurring kinds still require an active plan.
        $recurring = in_array($kind, ['monthly', 'yearly'], true);

        if (! $plan || ($recurring && ! $plan->is_active)) {
            throw new BillingConfigurationException('plan_not_available', 'This plan is not available.');
        }

        if ($kind === 'lifetime' && str_starts_with($planSlug, 'angel_')) {
            $reservation = $this->ltdCampaign->reserve($org, $planSlug, (string) Str::uuid());
        }

        if ($kind === 'credit_pack' && $planSlug !== 'insight_credits') {
            throw new BillingConfigurationException('invalid_credit_pack', 'This is not a credit-pack purchase.');
        }

        $pack = null;
        if ($kind === 'credit_pack') {
            $pack = collect(config('commercial.credit_packs'))
                ->first(fn (array $candidate): bool => $candidate['slug'] === $creditPack);
            if (! $pack) {
                throw new BillingConfigurationException('invalid_credit_pack', 'Choose a valid Insight Credit pack.');
            }
        }

        $priceQuery = PlanPrice::query()
            ->where('plan_id', $plan->id)
            ->where('provider', 'paddle')
            ->where('kind', $kind)
            ->where('currency', config('billing.paddle.default_currency', 'USD'))
            ->where('is_active', true);

        // Keep the selector portable across the supported database drivers;
        // metadata_json is already a tiny, bounded credit-pack catalog.
        $prices = $priceQuery->get();
        $price = $pack
            ? $prices->first(fn (PlanPrice $candidate): bool => (int) ($candidate->metadata_json['credits'] ?? 0) === (int) $pack['credits'])
            : $prices->first();

        if (! $price) {
            $this->ltdCampaign->release($reservation);
            throw new BillingConfigurationException('price_not_configured', 'This plan/kind has no Paddle price configured yet.');
        }

        $clientToken = config('billing.paddle.client_token');
        if (! is_string($clientToken) || trim($clientToken) === '') {
            $this->ltdCampaign->release($reservation);
            throw new BillingConfigurationException(
                'paddle_not_configured',
                'Paddle checkout is not configured yet. Please try again later.'
            );
        }

        return [
            'provider'     => 'paddle',
            'price_id'     => $price->provider_price_id,
            'currency'     => $price->currency,
            'amount_minor' => $price->amount_minor,
            'client_token' => $clientToken,
            'environment'  => config('billing.paddle.sandbox') ? 'sandbox' : 'production',
            'customer'     => ['email' => $org->owner?->email],
            'custom_data'  => [
                'organization_id' => $org->id,
                'purchase_kind'   => $kind,
                'plan_slug'       => $plan->slug,
                ...($pack ? ['credit_pack' => $pack['slug'], 'credit_amount' => $pack['credits']] : []),
                ...($reservation ? [
                    'ltd_reservation_id' => $reservation->id,
                    'ltd_campaign_id' => $reservation->campaign_id,
                ] : []),
            ],
        ];
    }

    /**
     * Temporary customer-portal deep link, generated per request and never
     * persisted (roadmap doc 22). Null when Paddle gives no portal URL
     * (e.g. missing customer) — the controller renders that as a plain error.
     */
    public function portalUrl(Organization $org): ?string
    {
        $sub = $org->subscription;

        if (! $sub?->provider_customer_id || $sub->billing_provider !== 'paddle') {
            return null;
        }

        return $this->client->customerPortalSession($sub->provider_customer_id);
    }

    /**
     * Cancel at period end via Paddle, then project the returned subscription
     * (with its scheduled_change) so the local row moves without waiting for
     * the subscription.updated webhook.
     */
    public function cancel(Organization $org): Subscription
    {
        $sub = $this->paddleSubscription($org);

        $payload = $this->client->cancelSubscriptionAtPeriodEnd($sub->provider_subscription_id);

        return $this->subscriptions->project($payload);
    }

    /**
     * Move the org's Paddle subscription onto the target plan's recurring
     * price. The billing interval of the current price is preserved when it
     * has a mapping, so a monthly tenant doesn't silently jump to yearly.
     */
    public function changePlan(Organization $org, string $planSlug, string $prorationBillingMode): Subscription
    {
        $sub = $this->paddleSubscription($org);

        $kind = PlanPrice::query()
            ->where('provider', 'paddle')
            ->where('provider_price_id', $sub->provider_price_id)
            ->value('kind');

        $price = PlanPrice::query()
            ->where('provider', 'paddle')
            ->where('kind', in_array($kind, ['monthly', 'yearly'], true) ? $kind : 'monthly')
            ->where('currency', config('billing.paddle.default_currency', 'USD'))
            ->where('is_active', true)
            ->whereHas('plan', fn ($query) => $query->where('slug', $planSlug)->where('is_active', true))
            ->first();

        if (! $price) {
            throw new BillingConfigurationException('price_not_configured', 'This plan has no Paddle price configured for the change.');
        }

        $payload = $this->client->changePlan($sub->provider_subscription_id, $price->provider_price_id, $prorationBillingMode);

        return $this->subscriptions->project($payload);
    }

    // ------------------------------------------------------------------

    private function paddleSubscription(Organization $org): Subscription
    {
        return Subscription::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)
            ->where('billing_provider', 'paddle')
            ->whereNotNull('provider_subscription_id')
            ->orderByDesc('id')
            ->firstOrFail();
    }
}
