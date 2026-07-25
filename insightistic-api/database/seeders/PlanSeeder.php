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

        // Public lineup (spec §13): Starter / Growth ("Best Value") / Agency.
        // business + agency_pro stay seeded but hidden so historical Stripe
        // price ids and FKs survive. 'free' is likewise not a pricing-page
        // card (is_active=false) — it's the automatic signup state every
        // organization starts on (AccountProvisioner), not something anyone
        // picks or upgrades away from a trial into.
        // Prices in cents.
        $plans = [
            //  slug          name          $m     $y      sites users ai    rpts  wl     cd     active featured
            ['free',       'Free',       0,     0,      3,   1,   10,   0,    false, false, false, false],
            ['starter',    'Starter',    900,   9000,   1,   1,   20,   4,    false, false, true,  false],
            ['growth',     'Growth',     2900,  29000,  3,   3,   100,  20,   false, false, true,  true],
            ['business',   'Business',   7900,  79000,  10,  10,  300,  100,  false, false, false, false],
            ['agency',     'Agency',     19900, 199000, 30,  30,  1000, 300,  true,  false, true,  false],
            ['agency_pro', 'Agency Pro', 39900, 399000, 100, 100, 3000, 1000, true,  true,  false, false],
        ];

        // Knowledge base + Copilot entitlements (spec Phase 8). Database
        // configuration, not frontend hardcoding — the admin plan editor can
        // override any of these per plan.
        $limits = [
            //  slug          kbs  srcs  mb    chunks  embeds   msgs  schedRpts retDays
            'free'       => [1,   5,    25,   1000,   2000,    25,   0,    180],
            'starter'    => [1,   10,   50,   2000,   5000,    50,   4,    365],
            'growth'     => [3,   50,   250,  10000,  25000,   300,  20,   730],
            'business'   => [10,  150,  1000, 50000,  100000,  1000, 100,  1095],
            'agency'     => [30,  500,  5000, 200000, 400000,  3000, 300,  1095],
            'agency_pro' => [100, 2000, 20000, 800000, 1500000, 10000, 1000, 1825],
        ];

        foreach ($plans as [$slug, $name, $m, $y, $sites, $users, $ai, $reports, $wl, $cd, $active, $featured]) {
            [$kbs, $srcs, $mb, $chunks, $embeds, $msgs, $schedRpts, $retDays] = $limits[$slug];

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
                    'knowledge_bases'     => $kbs,
                    'knowledge_sources'   => $srcs,
                    'storage_mb'          => $mb,
                    'indexed_chunks'      => $chunks,
                    'monthly_embeddings'  => $embeds,
                    'monthly_ai_messages' => $msgs,
                    'scheduled_reports'   => $schedRpts,
                    'data_retention_days' => $retDays,
                ],
                'is_active'             => $active,
                'is_featured'           => $featured,
            ]);
        }
    }
}
