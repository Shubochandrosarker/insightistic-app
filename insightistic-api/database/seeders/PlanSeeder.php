<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/** Seeds the versioned commercial catalog without deleting historical plans. */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $catalog = config('commercial');

        foreach ($catalog['regular_plans'] as $slug => $plan) {
            $this->upsertPlan($slug, $plan, $plan['monthly'], $plan['yearly'], $plan['active'], $plan['featured']);
        }

        // AppSumo owns the checkout/redemption flow. Keep the entitlement
        // plans in the same registry so a signed redemption can resolve to a
        // real plan, while intentionally leaving them inactive for direct
        // Stripe checkout.
        foreach ($catalog['appsumo_plans'] ?? [] as $slug => $plan) {
            $this->upsertPlan($slug, $plan, $plan['price'] * 100, 0, false, false, 'lifetime');
        }

        // Existing historical rows are deliberately not deleted here: old
        // subscriptions and entitlement grants may still reference them.
        // No legacy row is created or activated by a fresh seed.
    }

    private function upsertPlan(
        string $slug, array $plan, int $monthly, int $yearly,
        bool $active, bool $featured, string $billingModel = 'subscription'
    ): void {
        $limits = [
            'billing_model' => $billingModel,
            'connected_websites' => (int) $plan['sites'],
            'team_seats' => (int) $plan['seats'],
            'http_crawl_pages_monthly' => (int) ($plan['crawl_pages'] ?? 0),
            // Historical crawler callers use this shorter key. Keep the
            // alias while the canonical commercial vocabulary remains the
            // explicit http_crawl_pages_monthly name above.
            'crawl_pages_monthly' => (int) ($plan['crawl_pages'] ?? 0),
            'browser_rendering_minutes_monthly' => (int) ($plan['browser_minutes'] ?? 0),
            'tracked_keywords' => (int) ($plan['keywords'] ?? 0),
            'rank_depth' => (int) ($plan['rank_depth'] ?? 0),
            'rank_check_frequency' => $plan['rank_cadence'] ?? null,
            'standard_ai_requests_monthly' => (int) ($plan['standard_ai_requests'] ?? 0),
            'monthly_ai_credits' => (int) ($plan['monthly_credits'] ?? 0),
            'client_ready_reports_monthly' => (int) ($plan['reports'] ?? 0),
            'scheduled_report_limit' => (int) ($plan['scheduled_report_limit'] ?? 0),
            'team_member_limit' => (int) ($plan['team_member_limit'] ?? $plan['seats'] ?? 0),
            'team_member_add_credits' => (int) ($plan['team_member_add_credits'] ?? 0),
            'crawl_pages_per_run' => min(5000, max(50, (int) ($plan['crawl_pages'] ?? 50))),
            'ai_prompt_runs_monthly' => (int) ($plan['standard_ai_requests'] ?? 0),
            'knowledge_bases' => in_array($slug, ['free', 'starter', 'appsumo_tier_1'], true) ? 1 : 3,
            'knowledge_sources' => $slug === 'free' ? 5 : 50,
            'storage_mb' => $slug === 'free' ? 25 : 250,
            // JOLLY's visible monthly message allowance is the same
            // published standard-AI-action allowance for each current plan.
            // It prevents a stale generic 300-message cap from appearing on
            // AppSumo Tier 5 (5,000 actions).
            'monthly_ai_messages' => (int) ($plan['standard_ai_requests'] ?? 0),
            'scheduled_reports' => (int) ($plan['scheduled_report_limit'] ?? 0),
            'data_retention_days' => $billingModel === 'lifetime' ? 1825 : 730,
        ];

        Plan::updateOrCreate(['slug' => $slug], [
            'name' => $plan['name'], 'price_monthly' => $monthly, 'price_yearly' => $yearly,
            'site_limit' => (int) $plan['sites'], 'user_limit' => (int) $plan['seats'],
            'ai_insight_limit' => (int) ($plan['standard_ai_requests'] ?? 0),
            'report_limit' => (int) ($plan['reports'] ?? 0),
            'white_label_enabled' => in_array('agency_white_label', $plan['features'] ?? [], true),
            'custom_domain_enabled' => in_array('custom_domain', $plan['features'] ?? [], true),
            'features_json' => $plan['features'] ?? [], 'limits_json' => $limits,
            'is_active' => $active, 'is_featured' => $featured,
            'sort_order' => match ($slug) {
                'free' => 0, 'starter' => 10, 'growth' => 20, 'agency' => 30, 'agency_pro' => 40,
                'appsumo_tier_1' => 50, 'appsumo_tier_2' => 60, 'appsumo_tier_3' => 70,
                'appsumo_tier_4' => 80, 'appsumo_tier_5' => 90,
                default => 100,
            },
        ]);
    }
}
