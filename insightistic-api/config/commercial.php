<?php

/*
 * Versioned commercial catalog. Stripe price identifiers intentionally do not
 * live here: provider IDs are deployment-specific and are stored on the
 * seeded recurring plan rows.
 */
return [
    'version' => '2026-11-18',
    'currency' => 'USD',
    'trial_days' => 14,
    'appsumo_campaign' => [
        'slug' => 'appsumo-2026',
        'name' => 'AppSumo Lifetime Deal',
        'maximum_sales' => 500,
        'public' => true,
        'sold_out_message' => 'The AppSumo lifetime licenses have been claimed. The deal is currently closed.',
    ],
    'ai_action_costs' => [
        'basic_wordpress_dashboard_insight' => 100,
        'seo_opportunity_analysis' => 200,
        'woocommerce_business_analysis' => 300,
        'advanced_multi_source_ai_report' => 500,
        'comprehensive_ai_business_audit' => 1000,
    ],
    'credit_packs' => [
        ['slug' => 'credits_5000', 'credits' => 5000, 'price_minor' => 1500],
        ['slug' => 'credits_20000', 'credits' => 20000, 'price_minor' => 4900],
        ['slug' => 'credits_50000', 'credits' => 50000, 'price_minor' => 9900],
    ],
    // The public/admin catalog is deliberately allow-listed. Historical
    // plan rows remain in the database so old webhooks and entitlements can
    // still be resolved, but they are not new offers.
    'recurring_plan_slugs' => ['starter', 'growth', 'agency', 'agency_pro'],
    'admin_plan_slugs' => [
        'free', 'starter', 'growth', 'agency', 'agency_pro',
        'appsumo_tier_1', 'appsumo_tier_2', 'appsumo_tier_3',
        'appsumo_tier_4', 'appsumo_tier_5',
    ],
    // A single upgrade order for the dashboard. This is intentionally a
    // product hierarchy rather than a price sort: lifetime and recurring
    // offers can be compared by the capacity tier they provide.
    'upgrade_ranks' => [
        'free' => 0,
        'starter' => 10,
        'growth' => 20,
        'agency' => 30,
        'agency_pro' => 40,
        'appsumo_tier_1' => 50,
        'appsumo_tier_2' => 60,
        'appsumo_tier_3' => 70,
        'appsumo_tier_4' => 80,
        'appsumo_tier_5' => 90,
    ],
    'regular_plans' => [
        'free' => [
            'name' => 'Free Connected', 'monthly' => 0, 'yearly' => 0,
            'sites' => 1, 'seats' => 1, 'crawl_pages' => 0, 'browser_minutes' => 0,
            'keywords' => 0, 'standard_ai_requests' => 10, 'monthly_credits' => 1000,
            'reports' => 0, 'active' => false, 'featured' => false,
            'features' => ['basic_analytics', 'seo_analytics', 'ai_insights'],
        ],
        'starter' => [
            'name' => 'Starter', 'monthly' => 2900, 'yearly' => 29000,
            'sites' => 1, 'seats' => 1, 'crawl_pages' => 20000, 'browser_minutes' => 30,
            'keywords' => 150, 'standard_ai_requests' => 50, 'monthly_credits' => 5000,
            'reports' => 10, 'active' => true, 'featured' => false,
            'features' => ['basic_analytics', 'seo_analytics', 'ai_insights'],
        ],
        'growth' => [
            'name' => 'Growth', 'monthly' => 6900, 'yearly' => 69000,
            'sites' => 3, 'seats' => 3, 'crawl_pages' => 80000, 'browser_minutes' => 120,
            'keywords' => 500, 'standard_ai_requests' => 200, 'monthly_credits' => 15000,
            'reports' => 40, 'active' => true, 'featured' => true,
            'features' => ['basic_analytics', 'woocommerce_analytics', 'seo_analytics',
                'email_audit_automation', 'ai_insights', 'advanced_reports',
                'scheduled_reports', 'multi_site'],
        ],
        'agency' => [
            'name' => 'Agency', 'monthly' => 14900, 'yearly' => 149000,
            'sites' => 10, 'seats' => 10, 'crawl_pages' => 250000, 'browser_minutes' => 300,
            'keywords' => 1500, 'standard_ai_requests' => 600, 'monthly_credits' => 40000,
            'reports' => 150, 'active' => true, 'featured' => false,
            'features' => ['basic_analytics', 'woocommerce_analytics', 'seo_analytics',
                'email_audit_automation', 'ai_insights', 'advanced_reports',
                'scheduled_reports', 'multi_site', 'agency_white_label',
                'white_label_reports', 'white_label_dashboard', 'team_access', 'priority_support'],
        ],
        'agency_pro' => [
            'name' => 'Agency Pro', 'monthly' => 29900, 'yearly' => 299000,
            'sites' => 30, 'seats' => 25, 'crawl_pages' => 600000, 'browser_minutes' => 600,
            'keywords' => 5000, 'standard_ai_requests' => 1500, 'monthly_credits' => 100000,
            'reports' => 500, 'active' => true, 'featured' => false,
            'features' => ['basic_analytics', 'woocommerce_analytics', 'seo_analytics',
                'email_audit_automation', 'ai_insights', 'advanced_reports',
                'scheduled_reports', 'multi_site', 'agency_white_label',
                'white_label_reports', 'white_label_dashboard', 'custom_domain',
                'team_access', 'priority_support'],
        ],
    ],
    // AppSumo is the only current lifetime offer. AppSumo owns checkout and
    // sends the signed redemption/webhook; these rows are entitlement
    // definitions, not Stripe checkout products.
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
            'rank_cadence' => 'weekly', 'standard_ai_requests' => 100, 'monthly_credits' => 5000,
            'reports' => 5, 'scheduled_report_limit' => 1,
            'features' => ['basic_analytics', 'ga4_analytics', 'search_console_analytics',
                'cloudflare_analytics', 'pagespeed_analytics', 'seo_analytics', 'rank_tracking',
                'local_seo_analytics', 'ai_insights', 'ai_copilot', 'scheduled_reports',
                'custom_knowledge_base', 'team_access'],
        ],
        'appsumo_tier_2' => [
            'name' => 'AppSumo Tier 2', 'price' => 79, 'sites' => 3, 'seats' => 0,
            'team_member_limit' => 0, 'team_member_add_credits' => 10,
            'crawl_pages' => 40000, 'browser_minutes' => 60, 'keywords' => 20, 'rank_depth' => 20,
            'rank_cadence' => 'weekly', 'standard_ai_requests' => 200, 'monthly_credits' => 10000,
            'reports' => 25, 'scheduled_report_limit' => 3,
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
            'rank_cadence' => 'weekly', 'standard_ai_requests' => 600, 'monthly_credits' => 30000,
            'reports' => 100, 'scheduled_report_limit' => 10,
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
            'rank_cadence' => 'weekly', 'standard_ai_requests' => 1500, 'monthly_credits' => 75000,
            'reports' => 250, 'scheduled_report_limit' => 25,
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
            'rank_cadence' => 'weekly', 'standard_ai_requests' => 5000, 'monthly_credits' => 250000,
            'reports' => 500, 'scheduled_report_limit' => 50,
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
