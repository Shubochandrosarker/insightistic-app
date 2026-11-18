<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\Request;

/**
 * The single public plan source spec §13.7 asks for, alongside
 * Admin\PlanController (which is where plans are actually authored). Public,
 * unauthenticated, read-only, and deliberately narrow — only fields safe to
 * publish (no Stripe price ids, no internal ids beyond slug). Consumed today
 * by the insightistic-website marketing theme's pricing page; the app's own
 * pricing UI can move onto this same endpoint later instead of hardcoding.
 */
class PublicPlanController extends Controller
{
    public function index(Request $request)
    {
        $plans = Plan::whereIn('slug', config('commercial.recurring_plan_slugs', []))
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('price_monthly')
            ->get()
            ->map(fn (Plan $p) => [
                'slug'             => $p->slug,
                'name'             => $p->name,
                'price_monthly'    => $p->price_monthly,
                'price_yearly'     => $p->price_yearly,
                'site_limit'       => $p->site_limit,
                'user_limit'       => $p->user_limit,
                'ai_insight_limit' => $p->ai_insight_limit,
                'report_limit'     => $p->report_limit,
                'features'         => $p->features_json ?? [],
                // Publish the non-sensitive usage limits used by pricing UI.
                // Keeping these in the API prevents the marketing site and
                // dashboard from promising allowances the plan registry does
                // not actually grant.
                'limits'           => [
                    'billing_model'          => $p->limits_json['billing_model'] ?? 'subscription',
                    'crawl_pages_monthly'    => $p->limit('crawl_pages_monthly'),
                    'http_crawl_pages_monthly' => $p->limit('http_crawl_pages_monthly'),
                    'browser_rendering_minutes_monthly' => $p->limit('browser_rendering_minutes_monthly'),
                    'tracked_keywords'       => $p->limit('tracked_keywords'),
                    'ai_prompt_runs_monthly' => $p->limit('ai_prompt_runs_monthly'),
                    'standard_ai_requests_monthly' => $p->limit('standard_ai_requests_monthly'),
                    'monthly_ai_credits' => $p->limit('monthly_ai_credits'),
                    'client_ready_reports_monthly' => $p->limit('client_ready_reports_monthly'),
                ],
                'is_featured'      => (bool) $p->is_featured,
                'is_purchasable'   => (bool) $p->is_active,
            ]);

        $appsumo = collect(config('commercial.appsumo_plans', []))->map(fn (array $p, string $slug) => [
            'slug' => $slug,
            'name' => $p['name'],
            'price' => $p['price'],
            'sites' => $p['sites'],
            'team_seats' => $p['seats'],
            'team_member_limit' => $p['team_member_limit'] ?? $p['seats'],
            'team_member_add_credits' => $p['team_member_add_credits'] ?? 0,
            'rank_cadence' => $p['rank_cadence'] ?? null,
            'monthly_ai_credits' => $p['monthly_credits'],
            'standard_ai_requests' => $p['standard_ai_requests'],
            'features' => $p['features'],
            'limits' => [
                'billing_model' => 'lifetime',
                'crawl_pages_monthly' => $p['crawl_pages'],
                'browser_rendering_minutes_monthly' => $p['browser_minutes'],
                'tracked_keywords' => $p['keywords'],
                'rank_depth' => $p['rank_depth'] ?? 0,
                'rank_check_frequency' => $p['rank_cadence'] ?? null,
                'standard_ai_requests_monthly' => $p['standard_ai_requests'],
                'monthly_ai_credits' => $p['monthly_credits'],
                'client_ready_reports_monthly' => $p['reports'],
                'scheduled_report_limit' => $p['scheduled_report_limit'] ?? 0,
            ],
            // AppSumo owns purchase/redemption. There is intentionally no
            // Stripe checkout action for these public rows.
            'is_purchasable' => false,
            'available_via' => 'appsumo',
        ])->values();

        return response()
            ->json([
                'catalog_version' => config('commercial.version'),
                'trial_days' => config('commercial.trial_days'),
                'plans' => $plans,
                'appsumo' => [
                    'campaign' => config('commercial.appsumo_campaign'),
                    'plans' => $appsumo,
                ],
                'credit_packs' => config('commercial.credit_packs'),
                'ai_action_costs' => config('commercial.ai_action_costs'),
            ])
            ->header('Cache-Control', 'public, max-age=300');
    }
}
