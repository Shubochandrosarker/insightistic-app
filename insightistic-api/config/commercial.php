<?php

/*
 * Versioned commercial catalog. Paddle price identifiers intentionally do not
 * live here: provider IDs are deployment-specific and are mapped only by the
 * idempotent PaddleCatalogSeeder.
 */
return [
    'version' => '2026-11-16',
    'currency' => 'USD',
    // Single source of truth for the no-card trial (settled 2026-11-11: 7).
    // Read by TrialService, Stripe trial_period_days, /public/plans, the
    // lifecycle emails and the app copy fallback.
    'trial_days' => (int) env('INSIGHTISTIC_TRIAL_DAYS', 7),
    'trial_card_required' => false,
    // INSIGHTISTIC_SIGNUP_TRIAL_DAYS is NOT read by any code (prod .env.ai
    // still has 14). Surfaced on System Health so it can be removed safely.
    'legacy_signup_trial_days_env' => env('INSIGHTISTIC_SIGNUP_TRIAL_DAYS'),
    // 30-day money-back guarantee: Lifetime Deal (Founding Angel) ONLY.
    // Subscriptions (7-day free trial) and credit packs are non-refundable.
    'refund_window_days' => 30,
    'ltd_campaign' => [
        'slug' => 'founding-angel-2026',
        'name' => 'Founding Angel Plans',
        'maximum_sales' => 100,
        'sold_out_message' => 'Our first 100 Founding Angel licenses have been claimed. Lifetime plans are now closed.',
    ],
    // AppSumo-exclusive LTD. These are AppSumo's five listing tiers, not
    // Stripe products. AppSumo owns the one-time checkout and its signed
    // webhook owns the entitlement projection. The recurring Stripe catalog
    // remains independent below.
    'appsumo_campaign' => [
        'slug' => 'appsumo_2026',
        'name' => 'AppSumo 2026',
        // doc 33 scarcity ceiling. maximum_sales is read once when the
        // campaign row is created (LtdCampaignService firstOrCreate); adjust
        // here BEFORE the first sale, or update the ltd_campaigns row directly.
        'maximum_sales' => 500,
        'sold_out_message' => 'The AppSumo 2026 licenses have been claimed. The deal is sold out.',
    ],
    'ai_action_costs' => [
        // WordPress plugin actions use the public 50-credit tariff for both
        // Free Connected and paid accounts. SaaS dashboard actions retain
        // their existing plan-aware tariffs below.
        'plugin_dashboard_insight' => 50,
        'plugin_ai_report' => 50,
        'basic_wordpress_dashboard_insight' => 100,
        'seo_opportunity_analysis' => 200,
        'woocommerce_business_analysis' => 300,
        'advanced_multi_source_ai_report' => 500,
        'comprehensive_ai_business_audit' => 1000,
    ],
    // Variable-cost product actions are metered from the same wallet as AI
    // overage. Rank checks are deliberately cheap enough to make local SEO
    // useful while still recovering DataForSEO spend.
    'usage_credit_costs' => [
        'team_member_added' => 10,
        'rank_keyword_check' => 10,
        'local_rank_keyword_check' => 15,
    ],
    // AppSumo launch tariff: one included standard AI action is backed by
    // 50 Insight Credits. Keep this explicit so the public catalog, wallet
    // grants and AppSumo listing stay easy to audit together.
    'standard_ai_action_credit_multiplier' => 50,
    'credit_packs' => [
        ['slug' => 'credits_5000', 'credits' => 5000, 'price_minor' => 1500],
        ['slug' => 'credits_20000', 'credits' => 20000, 'price_minor' => 4900],
        ['slug' => 'credits_50000', 'credits' => 50000, 'price_minor' => 9900],
    ],
    // Free Connected plugin accounts may download three AI reports per
    // month. Paid accounts are credit-metered without a report-count cap.
    'plugin_free_reports_monthly' => 3,
    // The public/admin catalog is deliberately allow-listed. Historical
    // plan rows remain in the database so old webhooks and entitlements can
    // still be resolved, but they are not new offers.
    'recurring_plan_slugs' => ['starter', 'growth', 'agency', 'agency_pro'],
    // Stripe lifetime allow-list (StripeBillingService): Founding Angel only â€”
    // the AppSumo tiers are Paddle-only by design.
    'ltd_plan_slugs' => ['angel_solo', 'angel_studio', 'angel_agency'],
    'admin_plan_slugs' => [
        'free', 'starter', 'growth', 'agency', 'agency_pro',
        'angel_solo', 'angel_studio', 'angel_agency',
        'appsumo_tier_1', 'appsumo_tier_2', 'appsumo_tier_3',
        'appsumo_tier_4', 'appsumo_tier_5',
        // Historical AppSumo rows remain valid for old webhook replays.
        'appsumo_solo', 'appsumo_studio', 'appsumo_agency',
    ],
    // A single upgrade order for the dashboard. This is intentionally a
    // product hierarchy rather than a price sort: lifetime and recurring
    // offers can be compared by the capacity tier they provide.
    'upgrade_ranks' => [
        'free' => 0,
        'starter' => 10,
        'angel_solo' => 20,
        'appsumo_tier_1' => 25,
        'growth' => 30,
        'angel_studio' => 40,
        'agency' => 50,
        'appsumo_tier_2' => 55,
        'angel_agency' => 60,
        'appsumo_tier_3' => 65,
        'appsumo_tier_4' => 68,
        'appsumo_tier_5' => 72,
        'agency_pro' => 70,
    ],
    'regular_plans' => [
        'free' => [
            'name' => 'Free Connected', 'monthly' => 0, 'yearly' => 0,
            'sites' => 1, 'seats' => 1, 'crawl_pages' => 0, 'browser_minutes' => 0,
            'keywords' => 3, 'standard_ai_requests' => 10, 'monthly_credits' => 1000,
            'rank_cadence' => 'weekly',
            'reports' => 0, 'active' => false, 'featured' => false,
            'features' => ['basic_analytics', 'seo_analytics', 'ai_insights'],
        ],
        'starter' => [
            'name' => 'Starter', 'monthly' => 2900, 'yearly' => 29000,
            'sites' => 1, 'seats' => 1, 'crawl_pages' => 20000, 'browser_minutes' => 30,
            'keywords' => 30, 'standard_ai_requests' => 50, 'monthly_credits' => 5000,
            'rank_cadence' => 'daily',
            'reports' => 10, 'active' => true, 'featured' => false,
            'features' => ['basic_analytics', 'seo_analytics', 'ai_insights'],
        ],
        'growth' => [
            'name' => 'Growth', 'monthly' => 6900, 'yearly' => 69000,
            'sites' => 3, 'seats' => 3, 'crawl_pages' => 80000, 'browser_minutes' => 120,
            'keywords' => 100, 'standard_ai_requests' => 200, 'monthly_credits' => 15000,
            'rank_cadence' => 'daily',
            'reports' => 40, 'active' => true, 'featured' => true,
            'features' => ['basic_analytics', 'woocommerce_analytics', 'seo_analytics',
                'email_audit_automation', 'ai_insights', 'advanced_reports',
                'scheduled_reports', 'multi_site'],
        ],
        'agency' => [
            'name' => 'Agency', 'monthly' => 14900, 'yearly' => 149000,
            'sites' => 10, 'seats' => 10, 'crawl_pages' => 250000, 'browser_minutes' => 300,
            'keywords' => 250, 'standard_ai_requests' => 600, 'monthly_credits' => 40000,
            'rank_cadence' => 'daily',
            'reports' => 150, 'active' => true, 'featured' => false,
            'features' => ['basic_analytics', 'woocommerce_analytics', 'seo_analytics',
                'email_audit_automation', 'ai_insights', 'advanced_reports',
                'scheduled_reports', 'multi_site', 'agency_white_label',
                'white_label_reports', 'white_label_dashboard', 'team_access', 'priority_support'],
        ],
        'agency_pro' => [
            'name' => 'Agency Pro', 'monthly' => 29900, 'yearly' => 299000,
            'sites' => 30, 'seats' => 25, 'crawl_pages' => 600000, 'browser_minutes' => 600,
            'keywords' => 600, 'standard_ai_requests' => 1500, 'monthly_credits' => 100000,
            'rank_cadence' => 'daily',
            'reports' => 500, 'active' => true, 'featured' => false,
            'features' => ['basic_analytics', 'woocommerce_analytics', 'seo_analytics',
                'email_audit_automation', 'ai_insights', 'advanced_reports',
                'scheduled_reports', 'multi_site', 'agency_white_label',
                'white_label_reports', 'white_label_dashboard', 'custom_domain',
                'team_access', 'priority_support'],
        ],
    ],
    'angel_plans' => [
        'angel_solo' => [
            'name' => 'Angel Solo', 'price' => 149, 'sites' => 3, 'seats' => 1,
            'crawl_pages' => 10000, 'browser_minutes' => 15, 'keywords' => 150,
            'rank_cadence' => 'weekly',
            'standard_ai_requests' => 25, 'monthly_credits' => 3000, 'reports' => 5,
            'features' => ['basic_analytics', 'seo_analytics', 'ai_insights'],
        ],
        'angel_studio' => [
            'name' => 'Angel Studio', 'price' => 299, 'sites' => 7, 'seats' => 3,
            'crawl_pages' => 40000, 'browser_minutes' => 60, 'keywords' => 500,
            'rank_cadence' => 'weekly',
            'standard_ai_requests' => 100, 'monthly_credits' => 8000, 'reports' => 25,
            'features' => ['basic_analytics', 'woocommerce_analytics', 'seo_analytics',
                'email_audit_automation', 'ai_insights', 'advanced_reports',
                'scheduled_reports', 'multi_site', 'white_label_reports', 'team_access'],
        ],
        'angel_agency' => [
            'name' => 'Angel Agency', 'price' => 699, 'sites' => 15, 'seats' => 10,
            'crawl_pages' => 100000, 'browser_minutes' => 180, 'keywords' => 1500,
            'rank_cadence' => 'weekly',
            'standard_ai_requests' => 250, 'monthly_credits' => 20000, 'reports' => 100,
            'features' => ['basic_analytics', 'woocommerce_analytics', 'seo_analytics',
                'email_audit_automation', 'ai_insights', 'advanced_reports',
                'scheduled_reports', 'multi_site', 'white_label_reports',
                'white_label_dashboard', 'custom_domain', 'team_access'],
        ],
    ],

    // AppSumo-exclusive LTD tiers. `monthly_credits` is the included wallet
    // allowance. It is intentionally separate from `standard_ai_requests`:
    // standard requests are the predictable included AI actions, while the
    // wallet pays for variable work such as rank checks, team seats and AI
    // overage. The allowance follows the approved launch tariff of 50
    // credits per included standard AI action.
    'appsumo_tier_plan_slugs' => [
        1 => 'appsumo_tier_1',
        2 => 'appsumo_tier_2',
        3 => 'appsumo_tier_3',
        4 => 'appsumo_tier_4',
        5 => 'appsumo_tier_5',
    ],
    'appsumo_plans' => [
        'appsumo_tier_1' => [
            'name' => 'AppSumo Tier 1', 'price' => 49, 'sites' => 1, 'seats' => 4,
            'team_member_limit' => 3, 'team_member_add_credits' => 10,
            'crawl_pages' => 10000, 'browser_minutes' => 15, 'keywords' => 10, 'rank_depth' => 100,
            'rank_cadence' => 'weekly',
            'standard_ai_requests' => 100, 'monthly_credits' => 5000, 'reports' => 5, 'scheduled_report_limit' => 1,
            'features' => ['basic_analytics', 'ga4_analytics', 'search_console_analytics',
                'cloudflare_analytics', 'pagespeed_analytics', 'seo_analytics',
                'rank_tracking', 'local_seo_analytics', 'ai_insights', 'ai_copilot',
                'scheduled_reports', 'custom_knowledge_base', 'team_access'],
        ],
        'appsumo_tier_2' => [
            'name' => 'AppSumo Tier 2', 'price' => 79, 'sites' => 3, 'seats' => 0,
            'team_member_limit' => 0, 'team_member_add_credits' => 10,
            'crawl_pages' => 40000, 'browser_minutes' => 60, 'keywords' => 20, 'rank_depth' => 20,
            'rank_cadence' => 'weekly',
            'standard_ai_requests' => 200, 'monthly_credits' => 10000, 'reports' => 25, 'scheduled_report_limit' => 3,
            'features' => ['basic_analytics', 'ga4_analytics', 'search_console_analytics',
                'cloudflare_analytics', 'pagespeed_analytics', 'woocommerce_analytics',
                'seo_analytics', 'rank_tracking', 'local_seo_analytics', 'ai_insights',
                'ai_copilot', 'scheduled_reports', 'custom_knowledge_base', 'team_access',
                'custom_workflow_builder'],
        ],
        'appsumo_tier_3' => [
            'name' => 'AppSumo Tier 3', 'price' => 149, 'sites' => 10, 'seats' => 0,
            'team_member_limit' => 0, 'team_member_add_credits' => 10,
            'crawl_pages' => 100000, 'browser_minutes' => 180, 'keywords' => 40, 'rank_depth' => 20,
            'rank_cadence' => 'weekly',
            'standard_ai_requests' => 600, 'monthly_credits' => 30000, 'reports' => 100, 'scheduled_report_limit' => 10,
            'features' => ['basic_analytics', 'ga4_analytics', 'search_console_analytics',
                'cloudflare_analytics', 'pagespeed_analytics', 'woocommerce_analytics',
                'seo_analytics', 'rank_tracking', 'local_seo_analytics', 'ai_insights',
                'ai_copilot', 'scheduled_reports', 'custom_knowledge_base', 'team_access',
                'custom_workflow_builder', 'competitor_insights', 'market_insights',
                'white_label_reports', 'priority_support'],
        ],
        'appsumo_tier_4' => [
            'name' => 'AppSumo Tier 4', 'price' => 269, 'sites' => 25, 'seats' => 0,
            'team_member_limit' => 0, 'team_member_add_credits' => 10,
            'crawl_pages' => 250000, 'browser_minutes' => 300, 'keywords' => 100, 'rank_depth' => 20,
            'rank_cadence' => 'weekly',
            'standard_ai_requests' => 1500, 'monthly_credits' => 75000, 'reports' => 250, 'scheduled_report_limit' => 25,
            'features' => ['basic_analytics', 'ga4_analytics', 'search_console_analytics',
                'cloudflare_analytics', 'pagespeed_analytics', 'woocommerce_analytics',
                'seo_analytics', 'rank_tracking', 'local_seo_analytics', 'ai_insights',
                'ai_copilot', 'scheduled_reports', 'custom_knowledge_base', 'team_access',
                'custom_workflow_builder', 'competitor_insights', 'market_insights',
                'white_label_reports', 'white_label_dashboard', 'priority_support'],
        ],
        'appsumo_tier_5' => [
            'name' => 'AppSumo Tier 5', 'price' => 499, 'sites' => 50, 'seats' => 0,
            'team_member_limit' => 0, 'team_member_add_credits' => 10,
            'crawl_pages' => 600000, 'browser_minutes' => 600, 'keywords' => 250, 'rank_depth' => 20,
            'rank_cadence' => 'weekly',
            'standard_ai_requests' => 5000, 'monthly_credits' => 250000, 'reports' => 500, 'scheduled_report_limit' => 50,
            'features' => ['basic_analytics', 'ga4_analytics', 'search_console_analytics',
                'cloudflare_analytics', 'pagespeed_analytics', 'woocommerce_analytics',
                'seo_analytics', 'rank_tracking', 'local_seo_analytics', 'ai_insights',
                'ai_copilot', 'scheduled_reports', 'custom_knowledge_base', 'team_access',
                'custom_workflow_builder', 'competitor_insights', 'market_insights',
                'white_label_reports', 'white_label_dashboard', 'custom_domain',
                'priority_support'],
        ],
    ],
];
