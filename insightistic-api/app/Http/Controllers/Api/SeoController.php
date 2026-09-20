<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SeoDaily;
use App\Models\SeoPageSnapshot;
use App\Models\SeoQuerySnapshot;
use App\Models\Site;
use App\Support\DateRange;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * SEO Analytics (spec §9, §10.4). Reads seo_daily/seo_query_snapshots/
 * seo_page_snapshots — populated by the connector v2 sync endpoints, the
 * GSC pull and, since Phase 6, the Bing Webmaster sync (roadmap
 * 11-BING-WEBMASTER-INDEXNOW). Rows are separated by `source`, so every
 * read here is provider-aware: ?source=gsc|bing picks one, ?source=combined
 * sums volumes but never averages positions across providers.
 */
class SeoController extends Controller
{
    private const SOURCES = ['gsc', 'bing', 'combined'];

    /** GET /sites/{site}/analytics/seo/overview?source=gsc|bing|combined */
    public function overview(Request $request)
    {
        $site   = $this->site($request);
        $range  = $this->range($request, $site);
        $prev   = $range->previous();
        $source = $this->source($request);

        $current  = $this->aggregate($site->id, $range, $source);
        $previous = $this->aggregate($site->id, $prev, $source);

        // Combined view: volumes sum exactly, but an "average position" or
        // CTR across Bing AND Google search results would be arithmetic
        // nonsense — null those out and say which sources were mixed.
        $positionSources = null;
        if ($source === 'combined') {
            $present = $current['sources_present'];
            $positionSources = count($present) > 1 ? implode('+', $present) : ($present[0] ?? null);
        }

        return response()->json([
            'source'   => $source,
            'range'    => ['period' => $range->label, 'from' => $range->dateFrom(), 'to' => $range->dateTo(), 'timezone' => $range->tz],
            'metrics'  => $current['metrics'],
            'previous' => $previous['metrics'],
            'deltas'   => $this->deltas($current['metrics'], $previous['metrics']),
            'series'   => $this->series($site->id, $range, $source),
            'has_data' => $current['metrics']['clicks'] > 0 || $current['metrics']['impressions'] > 0 || $previous['metrics']['clicks'] > 0,
            'position_sources' => $positionSources,
        ]);
    }

    /** GET /sites/{site}/analytics/seo/queries — top queries + opportunity flags for the range. */
    public function queries(Request $request)
    {
        $site  = $this->site($request);
        $range = $this->range($request, $site);
        $limit = min(100, max(1, (int) $request->query('limit', 25)));

        $rows = SeoQuerySnapshot::where('site_id', $site->id)
            ->where('source', 'gsc') // Bing weekly buckets live in the same table; see bing().
            ->where('period_start', '>=', $range->dateFrom())
            ->where('period_end', '<=', $range->dateTo())
            ->orderByDesc('impressions')
            ->limit($limit)
            ->get([
                'query', 'clicks', 'impressions', 'ctr', 'position',
                'prev_clicks', 'prev_impressions', 'prev_ctr', 'prev_position', 'opportunity',
            ]);

        return response()->json(['queries' => $rows]);
    }

    /** GET /sites/{site}/analytics/seo/pages — top pages for the range. */
    public function pages(Request $request)
    {
        $site  = $this->site($request);
        $range = $this->range($request, $site);
        $limit = min(100, max(1, (int) $request->query('limit', 25)));

        $rows = SeoPageSnapshot::where('site_id', $site->id)
            ->where('source', 'gsc')
            ->where('period_start', '>=', $range->dateFrom())
            ->where('period_end', '<=', $range->dateTo())
            ->orderByDesc('impressions')
            ->limit($limit)
            ->get([
                'page_path', 'clicks', 'impressions', 'ctr', 'position',
                'prev_clicks', 'prev_impressions', 'prev_ctr', 'prev_position',
            ]);

        return response()->json(['pages' => $rows]);
    }

    /**
     * GET /sites/{site}/analytics/seo/bing?window=90
     *
     * Bing weekly query buckets (source='bing'): one row per (week ending
     * Saturday, query), as returned by Bing's GetQueryStats. Sorted by
     * impressions, capped at 500 rows — the shape the Search Intelligence
     * source filter renders.
     */
    public function bing(Request $request)
    {
        $site   = $this->site($request);
        $window = min(3650, max(1, (int) $request->query('window', 90)));
        $from   = now()->subDays($window)->toDateString();

        $rows = SeoQuerySnapshot::where('site_id', $site->id)
            ->where('source', 'bing')
            ->where('period_start', '>=', $from)
            ->orderByDesc('impressions')
            ->limit(500)
            ->get([
                'period_end', 'query', 'clicks', 'impressions',
                'avg_click_position', 'avg_impression_position',
            ]);

        return response()->json([
            'window_days' => $window,
            'weekly'      => $rows->map(fn ($r) => [
                'week_end'                => $r->period_end->toDateString(),
                'query'                   => $r->query,
                'clicks'                  => (int) $r->clicks,
                'impressions'             => (int) $r->impressions,
                'avg_click_position'      => $r->avg_click_position !== null ? (float) $r->avg_click_position : null,
                'avg_impression_position' => $r->avg_impression_position !== null ? (float) $r->avg_impression_position : null,
            ])->values(),
        ]);
    }

    /**
     * Metrics for one window. Returns the metric block plus which sources
     * actually had rows, so the combined view knows when position/CTR must
     * be withheld (two providers ⇒ no blended average).
     *
     * @return array{metrics:array<string,mixed>,sources_present:array<int,string>}
     */
    private function aggregate(int $siteId, DateRange $range, string $source): array
    {
        $rows = SeoDaily::where('site_id', $siteId)
            ->whereBetween('date', [$range->dateFrom(), $range->dateTo()])
            ->when($source !== 'combined', fn ($q) => $q->where('source', $source))
            ->get(['source', 'clicks', 'impressions', 'ctr', 'avg_position']);

        $clicks = (int) $rows->sum('clicks');
        $impressions = (int) $rows->sum('impressions');

        $present = $rows->pluck('source')->unique()->sort()->values();
        $mixed = $source === 'combined' && $present->count() > 1;

        return [
            'metrics' => [
                'clicks'       => $clicks,
                'impressions'  => $impressions,
                'ctr'          => ($mixed || $impressions === 0) ? null : round($clicks / $impressions * 100, 2),
                'avg_position' => $mixed ? null : $rows->whereNotNull('avg_position')->avg('avg_position'),
            ],
            'sources_present' => $present->all(),
        ];
    }

    /**
     * Daily series. When two providers have rows on the same day (combined
     * view) clicks/impressions still sum, but CTR/position are nulled —
     * same no-blending rule as the headline metrics.
     */
    private function series(int $siteId, DateRange $range, string $source): array
    {
        $rows = SeoDaily::where('site_id', $siteId)
            ->whereBetween('date', [$range->dateFrom(), $range->dateTo()])
            ->when($source !== 'combined', fn ($q) => $q->where('source', $source))
            ->orderBy('date')
            ->get(['date', 'source', 'clicks', 'impressions', 'ctr', 'avg_position']);

        return $rows
            ->groupBy(fn ($r) => $r->date->toDateString())
            ->sortKeys()
            ->map(fn (Collection $day, string $date) => $this->seriesRow($day, $date, $source))
            ->values()
            ->all();
    }

    private function seriesRow(Collection $day, string $date, string $source): array
    {
        $clicks = (int) $day->sum('clicks');
        $impressions = (int) $day->sum('impressions');
        $singleSource = $source !== 'combined' || $day->pluck('source')->unique()->count() === 1;

        $first = $day->first();
        $positions = $day->whereNotNull('avg_position');

        return [
            'date'         => $date,
            'clicks'       => $clicks,
            'impressions'  => $impressions,
            'ctr'          => $singleSource
                ? ($first->ctr !== null
                    ? (float) $first->ctr
                    : ($impressions > 0 ? round($clicks / $impressions, 4) : null))
                : null,
            'avg_position' => ($singleSource && $positions->isNotEmpty())
                ? round((float) $positions->avg('avg_position'), 2)
                : null,
        ];
    }

    /** Validated ?source= selector, defaulting to the Search Console view. */
    private function source(Request $request): string
    {
        $data = $request->validate(['source' => ['nullable', 'in:' . implode(',', self::SOURCES)]]);

        return $data['source'] ?? 'gsc';
    }

    private function deltas(array $current, array $previous): array
    {
        $out = [];
        foreach ($current as $key => $value) {
            $prevValue = $previous[$key] ?? null;
            $out[$key] = ($value !== null && $prevValue) ? round((($value - $prevValue) / $prevValue) * 100, 1) : null;
        }
        return $out;
    }

    private function site(Request $request): Site
    {
        return $request->attributes->get('site');
    }

    private function range(Request $request, Site $site): DateRange
    {
        return DateRange::fromRequest(
            $request->query('period'),
            $request->query('from'),
            $request->query('to'),
            $site->timezone ?: config('app.timezone', 'UTC'),
        );
    }
}
