<?php

namespace App\Http\Controllers\Api;

use App\Domain\Search\OpportunityEngine;
use App\Domain\Search\SearchExplorer;
use App\Http\Controllers\Controller;
use App\Models\CrawlIssue;
use App\Models\CrawlPage;
use App\Models\CrawlRun;
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

    /** Row cap for the Search Intelligence export (search() below). */
    private const EXPORT_ROW_LIMIT = 10000;

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

    /**
     * GET /sites/{site}/search/export.csv — Search Intelligence export
     * (Phase 5, roadmap 10-GOOGLE-SEARCH-CONSOLE). ?type= picks the report:
     * queries|pages|winners|losers|striking_distance|ctr_opportunities|
     * content_decay|cannibalization. Follows the bounded synchronous idiom
     * of every other export here: the 90-day DateRange ceiling bounds the
     * underlying daily table scan, and the grouped explorer output is
     * truncated at EXPORT_ROW_LIMIT rows (the API contract's own
     * "bounded exports" posture — not a silent queue that never queues).
     */
    public function search(Request $request)
    {
        [$site, $range] = $this->siteAndRange($request);

        $type = $request->query('type', 'queries');
        if (! in_array($type, [
            'queries', 'pages', 'winners', 'losers', 'striking_distance',
            'ctr_opportunities', 'content_decay', 'cannibalization',
        ], true)) {
            abort(422, 'Unknown search export type.');
        }

        // Explorer/engine windows: inclusive calendar dates; the export's
        // period range is the current window and the equal-length window
        // before it is the comparison window (same as the search endpoints).
        $from = $range->startLocal;
        $to = $range->endExclusiveLocal->subDay();
        $prev = $range->previous();
        $prevFrom = $prev->startLocal;
        $prevTo = $prev->endExclusiveLocal->subDay();

        $explorer = app(SearchExplorer::class);
        $engine = app(OpportunityEngine::class);

        $rows = [];
        $header = [];
        $mapRow = fn ($r) => $r;

        switch ($type) {
            case 'queries':
            case 'pages':
                $result = $type === 'queries'
                    ? $explorer->exploreQueries($site, $from, $to, $prevFrom, $prevTo, [], self::EXPORT_ROW_LIMIT)
                    : $explorer->explorePages($site, $from, $to, $prevFrom, $prevTo, [], self::EXPORT_ROW_LIMIT);
                $rows = $result['data'];
                $labelKey = $type === 'queries' ? 'query' : 'page_path';
                $header = [$type === 'queries' ? 'Query' : 'Page', 'Clicks', 'Impressions', 'CTR', 'Position', 'Prev clicks', 'Prev impressions', 'Prev position', 'Clicks delta', 'Impressions delta', 'Position delta'];
                $mapRow = fn ($r) => [
                    $r[$labelKey], $r['clicks'], $r['impressions'], $r['ctr'], $r['position'],
                    $r['prev_clicks'], $r['prev_impressions'], $r['prev_position'],
                    $r['clicks_delta'], $r['impressions_delta'], $r['position_delta'],
                ];
                break;

            case 'winners':
            case 'losers':
                $rows = $type === 'winners'
                    ? $engine->winners($site, $from, $to, $prevFrom, $prevTo)
                    : $engine->losers($site, $from, $to, $prevFrom, $prevTo);
                $header = ['Query', 'Clicks', 'Prev clicks', 'Clicks delta', 'Impressions', 'Reason'];
                $mapRow = fn ($r) => [$r['query'], $r['clicks'], $r['prev_clicks'], $r['clicks_delta'], $r['impressions'], $r['reason']];
                break;

            case 'striking_distance':
                $rows = $engine->strikingDistance($site, $from, $to, $prevFrom, $prevTo);
                $header = ['Query', 'Position', 'Impressions', 'Clicks', 'CTR', 'Expected CTR', 'Score', 'Reason'];
                $mapRow = fn ($r) => [$r['query'], $r['position'], $r['impressions'], $r['clicks'], $r['ctr'], $r['expected_ctr'], $r['score'], $r['reason']];
                break;

            case 'ctr_opportunities':
                $rows = $engine->ctrOpportunities($site, $from, $to, $prevFrom, $prevTo);
                $header = ['Query', 'Position', 'Band', 'Impressions', 'Clicks', 'CTR', 'Expected CTR', 'Missed clicks', 'Reason'];
                $mapRow = fn ($r) => [$r['query'], $r['position'], $r['band'], $r['impressions'], $r['clicks'], $r['ctr'], $r['expected_ctr'], $r['missed_clicks'], $r['reason']];
                break;

            case 'content_decay':
                $rows = $engine->contentDecay($site, $from, $to, $prevFrom, $prevTo);
                $header = ['Dimension', 'Query or page', 'Prev clicks', 'Clicks', 'Reason'];
                $mapRow = fn ($r) => [$r['dimension'], $r['query'] ?? $r['page_path'], $r['prev_clicks'], $r['clicks'], $r['reason']];
                break;

            case 'cannibalization':
                $rows = $engine->cannibalization($site, $from, $to, $prevFrom, $prevTo);
                $header = ['Query', 'Competing pages', 'Top page', 'Total clicks', 'Total impressions', 'Reason'];
                $mapRow = fn ($r) => [
                    $r['query'], count($r['pages']), $r['pages'][0]['page_path'] ?? '',
                    $r['total_clicks'], $r['total_impressions'], $r['reason'],
                ];
                break;
        }

        return $this->csv("search-{$type}", $header, $rows, $mapRow);
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

    /** GET /sites/{site}/exports/crawl.csv?type=pages|issues (latest run, 10k cap). */
    public function crawl(Request $request)
    {
        $site = $this->site($request);
        $type = $request->query('type', 'pages');

        if (! in_array($type, ['pages', 'issues'], true)) {
            abort(422, 'Unknown crawl export type.');
        }

        $runId = CrawlRun::where('site_id', $site->id)->orderByDesc('id')->value('id');

        if (! $runId) {
            abort(422, 'No crawl run yet.');
        }

        if ($type === 'pages') {
            $rows = CrawlPage::where('run_id', $runId)
                ->orderBy('url')
                ->limit(10000)
                ->get();

            return $this->csv('crawl-pages', [
                'URL', 'Status', 'Indexable', 'Title', 'Meta description', 'H1 count',
                'Word count', 'Canonical', 'Response ms',
            ], $rows, fn ($r) => [
                $r->url, $r->status_code, $r->indexable ? 'yes' : 'no', $r->title,
                $r->meta_description, $r->h1_count, $r->word_count, $r->canonical_url, $r->response_ms,
            ]);
        }

        $rows = CrawlIssue::where('site_id', $site->id)
            ->whereIn('status', ['open', 'ignored'])
            ->orderByDesc('severity')
            ->orderBy('issue_code')
            ->limit(10000)
            ->get();

        return $this->csv('crawl-issues', [
            'Code', 'Severity', 'Category', 'Status', 'Target URL', 'First seen', 'Last seen',
        ], $rows, fn ($r) => [
            $r->issue_code, $r->severity, $r->category, $r->status, $r->target_url,
            $r->first_seen_at?->toDateString(), $r->last_seen_at?->toDateString(),
        ]);
    }
}
