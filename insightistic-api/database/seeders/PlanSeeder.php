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

        foreach ($plans as [$slug, $name, $m, $y, $sites, $users, $ai, $reports, $wl, $cd, $active, $featured]) {
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
                'is_active'             => $active,
                'is_featured'           => $featured,
            ]);
        }
    }
}
