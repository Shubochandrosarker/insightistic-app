<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RunPageSpeedTest;
use App\Models\PerformanceRun;
use App\Models\Site;
use App\Services\Performance\PageSpeedClient;
use Illuminate\Http\Request;

/**
 * Performance tracking (spec §9, §10.5, Phase 3.3).
 *
 * Runs now come from two places: the SaaS runs PageSpeed Insights itself
 * (on a schedule, or on demand from `runNow`), and the plugin can still
 * push its own via connector v2 `sync/performance/runs`. Both write the
 * same tables, so a site keeps history whether or not the plugin is active.
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

    /**
     * POST /sites/{site}/analytics/performance/run
     *
     * Queue a run rather than executing inline — Lighthouse routinely takes
     * 20-60s, well past any sane HTTP timeout. The job carries the quota
     * guards, so a user hammering this button cannot exhaust the API key.
     */
    public function runNow(Request $request)
    {
        $site = $this->site($request);

        $data = $request->validate([
            'strategy' => ['nullable', 'string', 'in:mobile,desktop'],
            'url'      => ['nullable', 'url', 'max:1000'],
        ]);

        $strategies = isset($data['strategy'])
            ? [$data['strategy']]
            : PageSpeedClient::STRATEGIES;

        foreach ($strategies as $strategy) {
            RunPageSpeedTest::dispatch($site->id, $strategy, $data['url'] ?? null);
        }

        return response()->json([
            'status'     => 'queued',
            'strategies' => $strategies,
        ], 202);
    }

    private function site(Request $request): Site
    {
        return $request->attributes->get('site');
    }
}
