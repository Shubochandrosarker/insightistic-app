<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        // Feature slugs are the single vocabulary shared by the API, the
        // dashboard, and the WordPress plugin's feature gate.
        $features = [
            // Free forever — no card, no trial expiration. This is what the
            // WordPress plugin actually promises ("Create a free account —
            // no card, free forever") for AI Insights and email automation;
            // every paid plan below is an upgrade from here, not a
            // replacement for it. See AccountProvisioner for signup wiring.
            'free' => [
                'basic_analytics', 'ai_insights', 'email_audit_automation',
            ],
            'starter' => [
                'basic_analytics', 'seo_analysis', 'ai_insights',
            ],
            'growth' => [
                'basic_analytics', 'woocommerce_analytics', 'seo_analysis',
                'email_audit_automation', 'ai_insights', 'advanced_reports',
                'scheduled_reports', 'multi_site',
            ],
            'business' => [
                'basic_analytics', 'woocommerce_analytics', 'seo_analysis',
                'email_audit_automation', 'ai_insights', 'advanced_reports',
                'scheduled_reports', 'multi_site',
            ],
            'agency' => [
                'basic_analytics', 'woocommerce_analytics', 'seo_analysis',
                'email_audit_automation', 'ai_insights', 'advanced_reports',
                'scheduled_reports', 'multi_site', 'agency_white_label', 'team_access',
            ],
            'agency_pro' => [
                'basic_analytics', 'woocommerce_analytics', 'seo_analysis',
                'email_audit_automation', 'ai_insights', 'advanced_reports',
                'scheduled_reports', 'multi_site', 'agency_white_label', 'team_access',
            ],
        ];

        // Public lineup (roadmap-2026 doc 24, the locked commercial model):
        // Starter $29 / Growth $69 / Agency $149 / Agency Pro $299, annual
        // = 10x monthly. Sites 3/10/30/100, team 1/3/10/30, crawl pages
        // 10k/50k/200k/1M, tracked keywords 150/750/3k/10k, AI prompt runs
        // 15/75/250/1k. business + agency_pro stay seeded but hidden so
        // historical Stripe price ids and FKs survive. 'free' is likewise
        // not a pricing-page card (is_active=false) — it's the automatic
        // signup state every organization starts on (AccountProvisioner),
        // not something anyone picks or upgrades away from a trial into.
        // Prices in cents.
        $plans = [
            //  slug          name          $m     $y      sites users ai    rpts  wl     cd     active featured
            ['free',       'Free',       0,     0,      3,   1,   10,   0,    false, false, false, false],
            ['starter',    'Starter',    2900,  29000,  3,   1,   20,   4,    false, false, true,  false],
            ['growth',     'Growth',     6900,  69000,  10,  3,   100,  20,   false, false, true,  true],
            ['business',   'Business',   7900,  79000,  10,  10,  300,  100,  false, false, false, false],
            ['agency',     'Agency',     14900, 149000, 30,  10,  1000, 100,  true,  false, true,  false],
            ['agency_pro', 'Agency Pro', 29900, 299000, 100, 30,  3000, 300,  true,  true,  false, false],
        ];

        // Knowledge base + Copilot entitlements (spec Phase 8), plus the
        // roadmap-era usage keys (docs 24/26): crawl pages, tracked
        // keywords, AI visibility prompt runs — and, since Phase 7 (doc 12),
        // the crawl cadence: crawl_frequency_days (7 = weekly on
        // free/starter, 1 = daily on the paid tiers). Phase 10 (doc 13)
        // adds rank_checks_monthly: SERP checks are variable provider cost
        // and must be metered, never "unlimited" (doc 13, quotas).
        // Database configuration, not frontend hardcoding — the admin plan
        // editor can override any of these per plan.
        $limits = [
            //  slug          kbs  srcs  mb    chunks  embeds   msgs  schedRpts retDays | crawl  kw    prompts  freqDays  checks/mo
            'free'       => [1,   5,    25,   1000,   2000,    25,   0,    180,      500,    0,     0,       7,        0],
            'starter'    => [1,   10,   50,   2000,   5000,    50,   4,    365,      10000,  150,   15,      7,        300],
            'growth'     => [3,   50,   250,  10000,  25000,   300,  20,   730,      50000,  750,   75,      1,        1500],
            'business'   => [10,  150,  1000, 50000,  100000,  1000, 100,  1095,     100000, 1500,  150,     1,        5000],
            'agency'     => [30,  500,  5000, 200000, 400000,  3000, 300,  1095,     200000, 3000,  250,     1,        15000],
            'agency_pro' => [100, 2000, 20000, 800000, 1500000, 10000, 1000, 1825,    1000000, 10000, 1000,    1,        50000],
        ];

        foreach ($plans as [$slug, $name, $m, $y, $sites, $users, $ai, $reports, $wl, $cd, $active, $featured]) {
            [$kbs, $srcs, $mb, $chunks, $embeds, $msgs, $schedRpts, $retDays, $crawl, $kw, $prompts, $freqDays, $rankChecks] = $limits[$slug];

            Plan::updateOrCreate(['slug' => $slug], [
                'name'                  => $name,
                'price_monthly'         => $m,
                'price_yearly'          => $y,
                'site_limit'            => $sites,
                'user_limit'            => $users,
                'ai_insight_limit'      => $ai,
                'report_limit'          => $reports,
                'white_label_enabled'   => $wl,
                'custom_domain_enabled' => $cd,
                'features_json'         => $features[$slug],
                'limits_json'           => [
                    'knowledge_bases'          => $kbs,
                    'knowledge_sources'        => $srcs,
                    'storage_mb'               => $mb,
                    'indexed_chunks'           => $chunks,
                    'monthly_embeddings'       => $embeds,
                    'monthly_ai_messages'      => $msgs,
                    'scheduled_reports'        => $schedRpts,
                    'data_retention_days'      => $retDays,
                    'crawl_pages_monthly'      => $crawl,
                    'tracked_keywords'         => $kw,
                    'ai_prompt_runs_monthly'   => $prompts,
                    'crawl_frequency_days'     => $freqDays,
                    'rank_checks_monthly'      => $rankChecks,
                ],
                'is_active'             => $active,
                'is_featured'           => $featured,
            ]);
        }
    }
}
