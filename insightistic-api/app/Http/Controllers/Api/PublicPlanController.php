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
        $plans = Plan::where('is_active', true)
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
                    'tracked_keywords'       => $p->limit('tracked_keywords'),
                    'ai_prompt_runs_monthly' => $p->limit('ai_prompt_runs_monthly'),
                ],
                'is_featured'      => (bool) $p->is_featured,
            ]);

        return response()
            ->json(['plans' => $plans])
            ->header('Cache-Control', 'public, max-age=300');
    }
}
