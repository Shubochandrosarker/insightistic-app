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

        foreach ($catalog['angel_plans'] as $slug => $plan) {
            $this->upsertPlan($slug, $plan, $plan['price'] * 100, 0, false, false, 'lifetime');
        }

        // AppSumo 2026 LTD tiers (Option A): hidden lifetime plans like the
        // angel tiers; purchasability comes from the mapped Paddle price.
        foreach ($catalog['appsumo_plans'] ?? [] as $slug => $plan) {
            $this->upsertPlan($slug, $plan, $plan['price'] * 100, 0, false, false, 'lifetime');
        }

        // Historical rows remain readable for old subscriptions and webhook
        // replays, but can never appear as new offers.
        $legacy = [
            'business' => ['name' => 'Business (legacy)', 'monthly' => 7900, 'yearly' => 79000],
            'founder_tier_1' => ['name' => 'Founder Tier 1 (legacy)', 'monthly' => 7900, 'yearly' => 0],
            'founder_tier_2' => ['name' => 'Founder Tier 2 (legacy)', 'monthly' => 14900, 'yearly' => 0],
            'founder_tier_3' => ['name' => 'Founder Tier 3 (legacy)', 'monthly' => 24900, 'yearly' => 0],
            'insight_credits' => ['name' => 'Insight Credits', 'monthly' => 0, 'yearly' => 0],
        ];

        foreach ($legacy as $slug => $plan) {
            Plan::updateOrCreate(['slug' => $slug], [
                'name' => $plan['name'], 'price_monthly' => $plan['monthly'],
                'price_yearly' => $plan['yearly'], 'is_active' => false, 'is_featured' => false,
            ]);
        }
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
            'standard_ai_requests_monthly' => (int) ($plan['standard_ai_requests'] ?? 0),
            'monthly_ai_credits' => (int) ($plan['monthly_credits'] ?? 0),
            'client_ready_reports_monthly' => (int) ($plan['reports'] ?? 0),
            'crawl_pages_per_run' => min(5000, max(50, (int) ($plan['crawl_pages'] ?? 50))),
            'ai_prompt_runs_monthly' => (int) ($plan['standard_ai_requests'] ?? 0),
            'knowledge_bases' => in_array($slug, ['free', 'starter', 'angel_solo', 'appsumo_solo'], true) ? 1 : 3,
            'knowledge_sources' => $slug === 'free' ? 5 : 50,
            'storage_mb' => $slug === 'free' ? 25 : 250,
            'monthly_ai_messages' => $slug === 'free' ? 25 : 300,
            'scheduled_reports' => in_array($slug, ['growth', 'agency', 'agency_pro', 'angel_studio', 'angel_agency', 'appsumo_studio', 'appsumo_agency'], true) ? 1 : 0,
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
                default => 100,
            },
        ]);
    }
}
