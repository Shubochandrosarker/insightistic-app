<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MetricSnapshot;
use App\Models\SeoDaily;
use App\Models\Site;
use App\Models\TrafficDaily;
use App\Models\WcOrder;
use App\Support\DateRange;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bounded CSV exports (spec §12). Synchronous, not queued — every domain
 * here reads from an already-aggregated daily table capped by DateRange's
 * own 90-day custom-range ceiling (App\Support\DateRange has no ceiling
 * itself, so this controller enforces one), so a request never touches
 * more than a few hundred rows. A queued-export path for larger arbitrary
 * ranges is real future work, not something to fake with a synchronous
 * "queued" status that never actually queues.
 */
class ExportController extends Controller
{
    private const MAX_DAYS = 90;

    /** GET /sites/{site}/exports/traffic.csv */
    public function traffic(Request $request)
    {
        [$site, $range] = $this->siteAndRange($request);

        $rows = TrafficDaily::where('site_id', $site->id)
            ->whereBetween('date', [$range->dateFrom(), $range->dateTo()])
            ->orderBy('date')
            ->get(['date', 'source', 'users', 'sessions', 'page_views', 'conversions', 'revenue']);

        return $this->csv('traffic', ['Date', 'Source', 'Users', 'Sessions', 'Page views', 'Conversions', 'Revenue'], $rows, fn ($r) => [
            $r->date->toDateString(), $r->source, $r->users, $r->sessions, $r->page_views, $r->conversions, $r->revenue,
        ]);
    }

    /** GET /sites/{site}/exports/seo.csv */
    public function seo(Request $request)
    {
        [$site, $range] = $this->siteAndRange($request);

        $rows = SeoDaily::where('site_id', $site->id)
            ->whereBetween('date', [$range->dateFrom(), $range->dateTo()])
            ->orderBy('date')
            ->get(['date', 'clicks', 'impressions', 'ctr', 'avg_position']);

        return $this->csv('seo', ['Date', 'Clicks', 'Impressions', 'CTR', 'Avg position'], $rows, fn ($r) => [
            $r->date->toDateString(), $r->clicks, $r->impressions, $r->ctr, $r->avg_position,
        ]);
    }

    /** GET /sites/{site}/exports/performance.csv */
    public function performance(Request $request)
    {
        $site = $this->site($request);
        $strategy = $request->query('strategy', 'mobile') === 'desktop' ? 'desktop' : 'mobile';

        $rows = \App\Models\PerformanceRun::where('site_id', $site->id)
            ->where('strategy', $strategy)->where('status', 'complete')
            ->orderByDesc('run_at')->limit(self::MAX_DAYS)
            ->get(['run_at', 'performance_score', 'lcp_ms', 'inp_ms', 'cls']);

        return $this->csv('performance', ['Run at', 'Performance score', 'LCP (ms)', 'INP (ms)', 'CLS'], $rows, fn ($r) => [
            optional($r->run_at)->toIso8601String(), $r->performance_score, $r->lcp_ms, $r->inp_ms, $r->cls,
        ]);
    }

    /** GET /sites/{site}/exports/commerce.csv */
    public function commerce(Request $request)
    {
        [$site, $range] = $this->siteAndRange($request);

        $rows = MetricSnapshot::where('site_id', $site->id)
            ->whereBetween('date', [$range->dateFrom(), $range->dateTo()])
            ->orderBy('date')
            ->get(['date', 'revenue', 'orders', 'refunds', 'average_order_value', 'new_customers', 'returning_customers']);

        return $this->csv('commerce', ['Date', 'Revenue', 'Orders', 'Refunds', 'AOV', 'New customers', 'Returning customers'], $rows, fn ($r) => [
            $r->date->toDateString(), $r->revenue, $r->orders, $r->refunds, $r->average_order_value, $r->new_customers, $r->returning_customers,
        ]);
    }

    private function csv(string $domain, array $header, $rows, callable $mapRow): StreamedResponse
    {
        $filename = $domain . '-export-' . now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($header, $rows, $mapRow) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $header);
            foreach ($rows as $row) {
                fputcsv($out, $mapRow($row));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /** @return array{0: Site, 1: DateRange} */
    private function siteAndRange(Request $request): array
    {
        $site = $this->site($request);
        $range = DateRange::fromRequest(
            $request->query('period'),
            $request->query('from'),
            $request->query('to'),
            $site->timezone ?: config('app.timezone', 'UTC'),
        );

        if ($range->days() > self::MAX_DAYS) {
            abort(422, 'Export range is limited to ' . self::MAX_DAYS . ' days. Use a shorter range.');
        }

        return [$site, $range];
    }

    private function site(Request $request): Site
    {
        return $request->attributes->get('site');
    }
}
