<?php

/*
 * Versioned commercial catalog. Paddle price identifiers intentionally do not
 * live here: provider IDs are deployment-specific and are mapped only by the
 * idempotent PaddleCatalogSeeder.
 */
return [
    'version' => '2026-10-14',
    'currency' => 'USD',
    'trial_days' => 14,
    'ltd_campaign' => [
        'slug' => 'founding-angel-2026',
        'name' => 'Founding Angel Plans',
        'maximum_sales' => 100,
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
    'ltd_plan_slugs' => ['angel_solo', 'angel_studio', 'angel_agency'],
    'admin_plan_slugs' => [
        'free', 'starter', 'growth', 'agency', 'agency_pro',
        'angel_solo', 'angel_studio', 'angel_agency',
    ],
    // A single upgrade order for the dashboard. This is intentionally a
    // product hierarchy rather than a price sort: lifetime and recurring
    // offers can be compared by the capacity tier they provide.
    'upgrade_ranks' => [
        'free' => 0,
        'starter' => 10,
        'angel_solo' => 20,
        'growth' => 30,
        'angel_studio' => 40,
        'agency' => 50,
        'angel_agency' => 60,
        'agency_pro' => 70,
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
    'angel_plans' => [
        'angel_solo' => [
            'name' => 'Angel Solo', 'price' => 149, 'sites' => 3, 'seats' => 1,
            'crawl_pages' => 10000, 'browser_minutes' => 15, 'keywords' => 150,
            'standard_ai_requests' => 25, 'monthly_credits' => 3000, 'reports' => 5,
            'features' => ['basic_analytics', 'seo_analytics', 'ai_insights'],
        ],
        'angel_studio' => [
            'name' => 'Angel Studio', 'price' => 299, 'sites' => 7, 'seats' => 3,
            'crawl_pages' => 40000, 'browser_minutes' => 60, 'keywords' => 500,
            'standard_ai_requests' => 100, 'monthly_credits' => 8000, 'reports' => 25,
            'features' => ['basic_analytics', 'woocommerce_analytics', 'seo_analytics',
                'email_audit_automation', 'ai_insights', 'advanced_reports',
                'scheduled_reports', 'multi_site', 'white_label_reports', 'team_access'],
        ],
        'angel_agency' => [
            'name' => 'Angel Agency', 'price' => 699, 'sites' => 15, 'seats' => 10,
            'crawl_pages' => 100000, 'browser_minutes' => 180, 'keywords' => 1500,
            'standard_ai_requests' => 250, 'monthly_credits' => 20000, 'reports' => 100,
            'features' => ['basic_analytics', 'woocommerce_analytics', 'seo_analytics',
                'email_audit_automation', 'ai_insights', 'advanced_reports',
                'scheduled_reports', 'multi_site', 'white_label_reports',
                'white_label_dashboard', 'custom_domain', 'team_access'],
        ],
    ],
];
