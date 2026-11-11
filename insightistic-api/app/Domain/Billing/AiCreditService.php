<?php

namespace App\Domain\Billing;

use App\Models\Organization;
use App\Models\UsageCounter;
use Illuminate\Support\Facades\DB;

/** Applies the standard-request allowance before the published credit tariff. */
final class AiCreditService
{
    public function __construct(
        private CreditWallet $wallet,
        private EntitlementResolver $entitlements,
    ) {}

    public function cost(string $action): int
    {
        return (int) config('commercial.ai_action_costs.' . $action, 0);
    }

    public function walletEnabled(Organization $organization): bool
    {
        $resolved = $this->entitlements->resolve($organization);
        if (array_key_exists('monthly_ai_credits', $resolved['limits'] ?? [])) {
            return true;
        }

        return array_key_exists('monthly_ai_credits', $organization->plan?->limits_json ?? []);
    }

    public function actionForInsightType(string $type): string
    {
        return match ($type) {
            'seo' => 'seo_opportunity_analysis',
            'commerce', 'products', 'customers' => 'woocommerce_business_analysis',
            'executive', 'report' => 'advanced_multi_source_ai_report',
            default => 'basic_wordpress_dashboard_insight',
        };
    }

    public function entitledForAction(Organization $organization, string $action): bool
    {
        $feature = match ($action) {
            'woocommerce_business_analysis' => 'woocommerce_analytics',
            'advanced_multi_source_ai_report', 'comprehensive_ai_business_audit' => 'advanced_reports',
            default => 'ai_insights',
        };
        $resolved = $this->entitlements->resolve($organization);
        $features = $resolved['features'] ?? [];
        if ($features === [] && $organization->plan) {
            $features = $organization->plan->features_json ?? [];
        }

        return in_array($feature, $features, true);
    }

    public function standardEligible(Organization $organization, string $action): bool
    {
        $planSlug = $this->entitlements->resolve($organization)['plan']['slug']
            ?? $organization->plan?->slug;

        if ($planSlug === 'free') {
            return false;
        }

        return in_array($action, [
            'basic_wordpress_dashboard_insight',
            'seo_opportunity_analysis',
            'woocommerce_business_analysis',
        ], true);
    }

    /** True when this action is free because it spends a standard-request slot instead of credits. */
    public function willUseStandardRequest(Organization $organization, string $action, bool $eligibleForStandard = false): bool
    {
        return $eligibleForStandard && $this->standardRemaining($organization) > 0;
    }

    public function canCharge(Organization $organization, string $action, bool $eligibleForStandard = false): bool
    {
        $cost = $this->cost($action);
        if ($cost <= 0) {
            return false;
        }

        if ($eligibleForStandard && $this->standardRemaining($organization) > 0) {
            return true;
        }

        return $this->wallet->summary($organization)['total_usable'] >= $cost;
    }

    public function walletSummary(Organization $organization): array
    {
        return $this->wallet->summary($organization);
    }

    /** @return array{charged:string,cost:int} */
    public function charge(Organization $organization, string $action, string $reference, bool $eligibleForStandard = false): array
    {
        $cost = $this->cost($action);
        if ($cost <= 0) {
            throw new \InvalidArgumentException('Unknown AI action tariff.');
        }

        $period = now()->format('Y-m');
        $resolved = $this->entitlements->resolve($organization);
        return DB::transaction(function () use ($organization, $action, $reference, $eligibleForStandard, $cost, $period, $resolved): array {
            $counter = UsageCounter::withoutGlobalScope('organization')
                ->firstOrCreate(['organization_id' => $organization->id, 'period' => $period]);
            $counter = UsageCounter::withoutGlobalScope('organization')->whereKey($counter->id)->lockForUpdate()->firstOrFail();

            if ($eligibleForStandard && (int) $counter->ai_insights_used < $this->standardLimit($organization, $resolved)) {
                $counter->increment('ai_insights_used');

                return ['charged' => 'standard_request', 'cost' => 0];
            }

            $this->wallet->consume($organization, $cost, 'ai_' . $action, $reference);

            return ['charged' => 'insight_credits', 'cost' => $cost];
        });
    }

    private function standardRemaining(Organization $organization): int
    {
        $resolved = $this->entitlements->resolve($organization);

        return max(0, $this->standardLimit($organization, $resolved) - (int) UsageCounter::currentFor($organization)->ai_insights_used);
    }

    private function standardLimit(Organization $organization, array $resolved): int
    {
        $limit = (int) ($resolved['limits']['standard_ai_requests_monthly'] ?? 0);
        if ($limit <= 0 && ! ($resolved['plan']['id'] ?? null) && $organization->plan) {
            $limit = $organization->plan->limit('standard_ai_requests_monthly');
        }

        return $limit;
    }
}
