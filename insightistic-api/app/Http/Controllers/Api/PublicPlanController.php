<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Domain\Billing\LtdCampaignService;
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
    public function index(Request $request, LtdCampaignService $campaign)
    {
        $plans = Plan::where(fn ($query) => $query->where('is_active', true)->orWhere('slug', 'free'))
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

        $ltd = collect(config('commercial.angel_plans'))->map(fn (array $p, string $slug) => [
            'slug' => $slug, 'name' => $p['name'], 'price' => $p['price'],
            'sites' => $p['sites'], 'team_seats' => $p['seats'],
            'monthly_ai_credits' => $p['monthly_credits'], 'features' => $p['features'],
            'limits' => [
                'crawl_pages_monthly' => $p['crawl_pages'],
                'browser_rendering_minutes_monthly' => $p['browser_minutes'],
                'tracked_keywords' => $p['keywords'],
                'standard_ai_requests_monthly' => $p['standard_ai_requests'],
                'client_ready_reports_monthly' => $p['reports'],
            ],
        ])->values();

        return response()
            ->json([
                'catalog_version' => config('commercial.version'),
                'trial_days' => config('commercial.trial_days'),
                'plans' => $plans,
                'ltd' => ['campaign' => $campaign->status(), 'plans' => $ltd],
                'credit_packs' => config('commercial.credit_packs'),
                'ai_action_costs' => config('commercial.ai_action_costs'),
            ])
            ->header('Cache-Control', 'public, max-age=300');
    }
}
