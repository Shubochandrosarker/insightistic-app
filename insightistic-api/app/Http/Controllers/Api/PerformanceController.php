<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PerformanceRun;
use App\Models\Site;
use Illuminate\Http\Request;

/**
 * Performance Tracking (spec §9, §10.5) — read-only for now. Runs are
 * collected by the plugin's existing PageSpeed Insights integration and
 * will arrive via the connector v2 `sync/performance/runs` endpoint
 * (Milestone 3, not yet built) — so a manual "Run test" action isn't wired
 * up here yet either; queueing a test only makes sense once there's a
 * write path that can fulfill it. Deferred, not faked.
 */
class PerformanceController extends Controller
{
    /** GET /sites/{site}/analytics/performance/history?strategy=mobile|desktop */
    public function history(Request $request)
    {
        $site = $this->site($request);
        $strategy = $request->query('strategy', 'mobile') === 'desktop' ? 'desktop' : 'mobile';
        $limit = min(90, max(1, (int) $request->query('limit', 30)));

        $runs = PerformanceRun::where('site_id', $site->id)
            ->where('strategy', $strategy)
            ->where('status', 'complete')
            ->orderByDesc('run_at')
            ->limit($limit)
            ->get([
                'id', 'url', 'run_at', 'performance_score', 'accessibility_score',
                'best_practices_score', 'seo_score', 'ai_readiness_score',
                'lcp_ms', 'lcp_status', 'inp_ms', 'inp_status', 'cls', 'cls_status',
                'fcp_ms', 'tbt_ms', 'speed_index_ms', 'ttfb_ms',
            ]);

        return response()->json(['strategy' => $strategy, 'runs' => $runs]);
    }

    /** GET /sites/{site}/analytics/performance/latest?strategy=mobile|desktop */
    public function latest(Request $request)
    {
        $site = $this->site($request);
        $strategy = $request->query('strategy', 'mobile') === 'desktop' ? 'desktop' : 'mobile';

        $run = PerformanceRun::where('site_id', $site->id)
            ->where('strategy', $strategy)
            ->where('status', 'complete')
            ->orderByDesc('run_at')
            ->with('issues')
            ->first();

        if (! $run) {
            return response()->json(['strategy' => $strategy, 'run' => null]);
        }

        return response()->json([
            'strategy' => $strategy,
            'run'      => $run,
            'issues'   => $run->issues()->orderByDesc('savings_ms')->limit(10)->get(),
        ]);
    }

    private function site(Request $request): Site
    {
        return $request->attributes->get('site');
    }
}
