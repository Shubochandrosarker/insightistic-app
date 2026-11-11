<?php

namespace App\Domain\Billing;

use App\Domain\Billing\Exceptions\BillingConfigurationException;
use App\Models\BillingTransaction;
use App\Models\EntitlementGrant;
use App\Models\LtdCampaign;
use App\Models\LtdCheckoutReservation;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanPrice;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

/** Atomic, provider-independent inventory for lifetime-deal campaign caps. */
final class LtdCampaignService
{
    public function campaign(?string $excludeReference = null): LtdCampaign
    {
        return $this->campaignFor(config('commercial.ltd_campaign'), $excludeReference);
    }

    /** Idempotent campaign row (firstOrCreate) + historical-sales self-heal. */
    private function campaignFor(array $cfg, ?string $excludeReference = null): LtdCampaign
    {
        $campaign = LtdCampaign::query()->firstOrCreate(
            ['slug' => $cfg['slug']],
            ['name' => $cfg['name'], 'maximum_sales' => $cfg['maximum_sales'], 'state' => 'active', 'opened_at' => now()],
        );

        $this->synchroniseHistoricalSales($campaign, $excludeReference);

        return $campaign->fresh();
    }

    /**
     * Campaign config for an LTD plan slug; null when the slug is not a
     * campaign tier or its campaign is disabled. Founding Angel stays the
     * direct-from-site offer; the AppSumo tiers (appsumo_*) are gated by
     * FEATURE_LTD_APPSUMO and disabled by default.
     */
    private function campaignConfigForPlan(string $planSlug): ?array
    {
        if (str_starts_with($planSlug, 'angel_')) {
            return config('commercial.ltd_campaign');
        }

        if (str_starts_with($planSlug, 'appsumo_')) {
            return (bool) config('feature-flags.ltd_appsumo')
                ? config('commercial.appsumo_campaign')
                : null;
        }

        return null;
    }

    /** Reserve one slot before Paddle checkout is opened. */
    public function reserve(Organization $organization, string $planSlug, ?string $idempotencyKey = null): LtdCheckoutReservation
    {
        $campaignConfig = $this->campaignConfigForPlan($planSlug);
        if (! $campaignConfig) {
            throw new BillingConfigurationException('invalid_ltd_plan', 'This is not an available lifetime deal plan.');
        }

        return DB::transaction(function () use ($organization, $planSlug, $idempotencyKey, $campaignConfig) {
            $campaign = LtdCampaign::whereKey($this->campaignFor($campaignConfig)->id)->lockForUpdate()->firstOrFail();
            $this->expireReservations($campaign);

            $existingGrant = EntitlementGrant::withoutGlobalScope('organization')
                ->where('organization_id', $organization->id)->whereNull('revoked_at')
                ->whereIn('source', \App\Models\EntitlementGrant::LIFETIME_SOURCES)->exists();
            if ($existingGrant) {
                throw new BillingConfigurationException('ltd_already_owned', 'This organization already has a lifetime entitlement. Contact support for an upgrade review.');
            }

            if ($idempotencyKey) {
                $existing = LtdCheckoutReservation::where('idempotency_key', $idempotencyKey)->first();
                if ($existing && in_array($existing->status, ['reserved', 'consumed'], true)) {
                    return $existing;
                }
            }

            if ($campaign->state !== 'active' || $campaign->successful_sales >= $campaign->maximum_sales) {
                $this->close($campaign);
                throw new BillingConfigurationException('ltd_sold_out', (string) $campaignConfig['sold_out_message']);
            }

            $reserved = (int) $campaign->reservations()->where('status', 'reserved')->count();
            if ($campaign->successful_sales + $reserved >= $campaign->maximum_sales) {
                throw new BillingConfigurationException('ltd_sold_out', 'The remaining ' . $campaignConfig['name'] . ' checkout inventory is currently reserved. Please try again later.');
            }

            return LtdCheckoutReservation::create([
                'campaign_id' => $campaign->id,
                'organization_id' => $organization->id,
                'plan_slug' => $planSlug,
                'idempotency_key' => $idempotencyKey ?: (string) Str::uuid(),
                'status' => 'reserved',
                'expires_at' => now()->addMinutes(15),
            ]);
        });
    }

    /** Bind a verified completed Paddle transaction to its reservation. */
    public function confirmSale(BillingTransaction $transaction, ?array $customData = null, ?PlanPrice $price = null): void
    {
        $price ??= PlanPrice::where('provider_price_id', $transaction->provider_price_id)->first();
        $campaignConfig = $price?->plan ? $this->campaignConfigForPlan((string) $price->plan->slug) : null;
        if (! $campaignConfig) {
            return;
        }

        DB::transaction(function () use ($transaction, $customData, $campaignConfig) {
            $campaign = LtdCampaign::whereKey($this->campaignFor($campaignConfig, (string) $transaction->provider_transaction_id)->id)->lockForUpdate()->firstOrFail();
            $reservationId = $customData['ltd_reservation_id'] ?? null;
            $reservation = $reservationId
                ? LtdCheckoutReservation::whereKey($reservationId)->lockForUpdate()->first()
                : LtdCheckoutReservation::where('provider_transaction_id', $transaction->provider_transaction_id)->lockForUpdate()->first();

            if (! $reservation || $reservation->campaign_id !== $campaign->id) {
                $summary = is_array($transaction->summary_json) ? $transaction->summary_json : [];
                $summary['ltd_reconciliation'] = [
                    'status' => 'manual_review',
                    'reason' => 'missing_local_reservation',
                    'reviewed_at' => null,
                ];
                $transaction->forceFill(['summary_json' => $summary])->save();
                throw new \RuntimeException('Verified lifetime payment has no local reservation; hold for manual billing reconciliation.');
            }

            if ($reservation->status === 'consumed') {
                return;
            }

            if ($reservation->organization_id !== $transaction->organization_id) {
                throw new \RuntimeException('Lifetime reservation organization does not match the verified transaction.');
            }

            // A reservation that was valid when checkout opened remains
            // fulfillable after the campaign reaches sellout. No new payment
            // without a reservation can enter this path.
            $reservation->update([
                'status' => 'consumed',
                'provider_transaction_id' => $transaction->provider_transaction_id,
                'consumed_at' => now(),
            ]);
            $campaign->increment('successful_sales');
            $campaign->refresh();
            if ($campaign->successful_sales >= $campaign->maximum_sales) {
                $this->close($campaign);
            }
        });
    }

    public function release(?LtdCheckoutReservation $reservation): void
    {
        if ($reservation && $reservation->status === 'reserved') {
            $reservation->update(['status' => 'released']);
        }
    }

    public function status(): array
    {
        $campaign = $this->campaign();
        $this->expireReservations($campaign);
        $reserved = (int) $campaign->reservations()->where('status', 'reserved')->count();
        $remaining = max(0, $campaign->maximum_sales - $campaign->successful_sales - $reserved);

        return [
            'slug' => $campaign->slug,
            'name' => $campaign->name,
            'maximum_sales' => $campaign->maximum_sales,
            'successful_sales' => $campaign->successful_sales,
            'reserved_sales' => $reserved,
            'remaining_sales' => $remaining,
            'state' => $campaign->state,
            'sold_out' => $campaign->state !== 'active' || $remaining <= 0,
        ];
    }

    private function expireReservations(LtdCampaign $campaign): void
    {
        $campaign->reservations()->where('status', 'reserved')->where('expires_at', '<=', now())->update(['status' => 'expired']);
    }

    private function close(LtdCampaign $campaign): void
    {
        if ($campaign->state !== 'closed') {
            $campaign->forceFill(['state' => 'closed', 'closed_at' => $campaign->closed_at ?: now()])->save();
        }
    }

    /**
     * $excludeReference: the sale confirmSale() is about to count itself.
     * Without it, a completed transaction row written before confirmSale()
     * was counted here AND incremented there — every LTD sale used two of
     * the 100 spots (found 2026-11-04 while adding Stripe).
     *
     * Sales are attributed per campaign by plan-slug prefix: appsumo_2026
     * owns appsumo_* plans; Founding Angel keeps every other lifetime sale
     * (angel_* and the legacy founder tiers), so its count is unchanged by
     * the second campaign. Rows whose price/plan cannot be resolved stay in
     * the Founding Angel bucket — the pre-campaign default.
     */
    private function synchroniseHistoricalSales(LtdCampaign $campaign, ?string $excludeReference = null): void
    {
        $appsumoOnly = $campaign->slug === (string) config('commercial.appsumo_campaign.slug');
        $planSlugs = Plan::query()->pluck('slug', 'id');
        $pricePlanIds = PlanPrice::query()->pluck('plan_id', 'provider_price_id');

        $transactionRefs = BillingTransaction::withoutGlobalScope('organization')
            ->where('kind', 'lifetime')
            ->where('status', 'completed')
            ->get(['provider_transaction_id', 'provider_price_id'])
            ->filter(function (BillingTransaction $transaction) use ($appsumoOnly, $planSlugs, $pricePlanIds) {
                $planId = $pricePlanIds[(string) $transaction->provider_price_id] ?? null;
                $isAppsumo = str_starts_with((string) ($planId !== null ? $planSlugs[$planId] ?? '' : ''), 'appsumo_');

                return $appsumoOnly ? $isAppsumo : ! $isAppsumo;
            })
            ->pluck('provider_transaction_id');

        $grantRefs = EntitlementGrant::withoutGlobalScope('organization')
            ->whereIn('source', \App\Models\EntitlementGrant::LIFETIME_SOURCES)
            ->whereNull('revoked_at')
            ->get(['source_reference', 'plan_id'])
            ->filter(function (EntitlementGrant $grant) use ($appsumoOnly, $planSlugs) {
                $isAppsumo = str_starts_with((string) ($grant->plan_id !== null ? $planSlugs[$grant->plan_id] ?? '' : ''), 'appsumo_');

                return $appsumoOnly ? $isAppsumo : ! $isAppsumo;
            })
            ->pluck('source_reference');

        $sales = collect($transactionRefs)
            ->merge($grantRefs)
            ->filter(fn ($reference) => is_string($reference) && $reference !== '' && $reference !== $excludeReference)
            ->unique()
            ->count();

        if ($sales <= (int) $campaign->successful_sales) {
            return;
        }

        $campaign->forceFill(['successful_sales' => $sales])->save();
        if ($sales >= (int) $campaign->maximum_sales) {
            $this->close($campaign);
        }
    }
}
