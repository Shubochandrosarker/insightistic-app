<?php

namespace App\Domain\Search;

use App\Models\SeoPageDaily;
use App\Models\SeoQueryDaily;
use App\Models\SeoQueryPageDaily;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Search Console exploration over the daily GSC fact tables
 * (seo_query_daily / seo_page_daily / seo_query_page_daily).
 *
 * Aggregation semantics (doc 10-GOOGLE-SEARCH-CONSOLE.md):
 *  - clicks / impressions  : plain SUM over the window
 *  - ctr                   : WEIGHTED, sum(clicks) / sum(impressions) — never
 *                            an average of the stored per-day ctr column
 *  - position              : impressions-weighted,
 *                            SUM(position * impressions) / SUM(impressions)
 *                            over rows where position is not null
 *
 * Stored ctr is a 0..1 ratio (mirrors GscClient's ingestion of the raw GSC
 * `ctr` field), and everything this class returns keeps that unit. Human
 * surfaces format to percent at the edges (see OpportunityEngine reasons).
 *
 * PAGINATION CHOICE — offset-style cursor, documented:
 * The grouped per-query set for a window is small (hundreds to low thousands
 * of distinct queries), so the full aggregate is materialised once per request
 * and sliced in memory. The cursor is base64(JSON {"o": <offset>}). This keeps
 * every sort direction / filter combination paginating identically with zero
 * per-sort keyset predicates; the trade-off (rows shifting if the underlying
 * daily data changes between two page requests) is the standard offset
 * caveat and is accepted for this surface (API contract doc 25 recommends
 * cursors for "very large observation tables" — the raw daily tables stay
 * behind a GROUP BY here, and never leave the database).
 *
 * This class also owns every SQL touchpoint for the search-intelligence
 * domain: OpportunityEngine consumes windowAggregates() /
 * windowQueryPageAggregates() and contains no queries of its own.
 */
class SearchExplorer
{
    /**
     * Runaway guard on the grouped in-memory set (ordered by impressions
     * desc before the cut). Far above any realistic query/page cardinality
     * for a single site window; exists so a poisoned dataset cannot OOM a
     * worker, not as a product limit.
     */
    private const MAX_AGGREGATED_ROWS = 50000;

    public function __construct() {}

    /**
     * Explore queries over a window vs the preceding window.
     *
     * @param array{q?: ?string, min_impressions?: ?int, sort?: ?string, dir?: ?string} $filters
     * @return array{data: array, meta: array{next_cursor: ?string, has_more: bool, window: array, total_queries: int}}
     */
    public function exploreQueries(
        Site $site,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?CarbonImmutable $prevFrom = null,
        ?CarbonImmutable $prevTo = null,
        array $filters = [],
        int $perPage = 50,
        ?string $cursor = null,
    ): array {
        return $this->explore('queries', $site, $from, $to, $prevFrom, $prevTo, $filters, $perPage, $cursor);
    }

    /**
     * Explore pages over a window vs the preceding window (same contract as
     * exploreQueries, keyed by page_path instead of query).
     */
    public function explorePages(
        Site $site,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?CarbonImmutable $prevFrom = null,
        ?CarbonImmutable $prevTo = null,
        array $filters = [],
        int $perPage = 50,
        ?string $cursor = null,
    ): array {
        return $this->explore('pages', $site, $from, $to, $prevFrom, $prevTo, $filters, $perPage, $cursor);
    }

    /**
     * Window aggregates for one dimension, keyed by hash — the shared raw
     * material for this explorer and for OpportunityEngine.
     *
     * @param 'queries'|'pages' $dimension
     * @return array<string, array{hash: string, label: string, clicks: int, impressions: int, ctr: ?float, position: ?float}>
     */
    public function windowAggregates(string $dimension, Site $site, CarbonImmutable $from, CarbonImmutable $to): array
    {
        [$model, $hashCol, $labelCol] = $this->bindings($dimension);

        $rows = $model::query()
            ->where('site_id', $site->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->groupBy($hashCol, $labelCol)
            ->select(
                "{$hashCol} as hash",
                "{$labelCol} as label",
                DB::raw('SUM(clicks) as clicks'),
                DB::raw('SUM(impressions) as impressions'),
                DB::raw('SUM(CASE WHEN position IS NOT NULL THEN position * impressions ELSE 0 END) as position_weighted'),
                DB::raw('SUM(CASE WHEN position IS NOT NULL THEN impressions ELSE 0 END) as position_weight'),
            )
            ->orderByRaw('SUM(impressions) desc')
            ->limit(self::MAX_AGGREGATED_ROWS)
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $clicks = (int) $row->clicks;
            $impressions = (int) $row->impressions;
            $positionWeight = (float) $row->position_weight;

            $out[(string) $row->hash] = [
                'hash'        => (string) $row->hash,
                'label'       => (string) $row->label,
                'clicks'      => $clicks,
                'impressions' => $impressions,
                'ctr'         => $impressions > 0 ? round($clicks / $impressions, 6) : null,
                'position'    => $positionWeight > 0 ? round((float) $row->position_weighted / $positionWeight, 2) : null,
            ];
        }

        return $out;
    }

    /**
     * Query x page pair aggregates for the cannibalization report: one row
     * per (query_hash, page_hash) with window sums and a weighted position.
     *
     * @return array<int, array{query_hash: string, page_hash: string, clicks: int, impressions: int, position: ?float}>
     */
    public function windowQueryPageAggregates(Site $site, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = SeoQueryPageDaily::query()
            ->where('site_id', $site->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('query_hash', 'page_hash')
            ->select(
                'query_hash',
                'page_hash',
                DB::raw('SUM(clicks) as clicks'),
                DB::raw('SUM(impressions) as impressions'),
                DB::raw('SUM(CASE WHEN position IS NOT NULL THEN position * impressions ELSE 0 END) as position_weighted'),
                DB::raw('SUM(CASE WHEN position IS NOT NULL THEN impressions ELSE 0 END) as position_weight'),
            )
            ->orderByRaw('SUM(impressions) desc')
            ->limit(self::MAX_AGGREGATED_ROWS)
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $positionWeight = (float) $row->position_weight;

            $out[] = [
                'query_hash'  => (string) $row->query_hash,
                'page_hash'   => (string) $row->page_hash,
                'clicks'      => (int) $row->clicks,
                'impressions' => (int) $row->impressions,
                'position'    => $positionWeight > 0 ? round((float) $row->position_weighted / $positionWeight, 2) : null,
            ];
        }

        return $out;
    }

    private function explore(
        string $dimension,
        Site $site,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?CarbonImmutable $prevFrom,
        ?CarbonImmutable $prevTo,
        array $filters,
        int $perPage,
        ?string $cursor,
    ): array {
        // The API's per_page<=100 cap is enforced by SearchController's
        // validation; the explorer itself only guards against runaway pages
        // (the CSV export legitimately pulls the whole bounded window).
        $perPage = max(1, min(10000, $perPage));
        $labelKey = $dimension === 'queries' ? 'query' : 'page_path';

        $current = $this->windowAggregates($dimension, $site, $from, $to);
        $previous = ($prevFrom !== null && $prevTo !== null)
            ? $this->windowAggregates($dimension, $site, $prevFrom, $prevTo)
            : [];

        // Merge + deltas first, then filter, then sort, then slice.
        $rows = [];
        foreach ($current as $hash => $row) {
            $prevRow = $previous[$hash] ?? null;

            $rows[] = [
                $labelKey          => $row['label'],
                'clicks'           => $row['clicks'],
                'impressions'      => $row['impressions'],
                'ctr'              => $row['ctr'],
                'position'         => $row['position'],
                'prev_clicks'      => $prevRow['clicks'] ?? 0,
                'prev_impressions' => $prevRow['impressions'] ?? 0,
                'prev_position'    => $prevRow['position'] ?? null,
                'clicks_delta'     => $row['clicks'] - ($prevRow['clicks'] ?? 0),
                'impressions_delta' => $row['impressions'] - ($prevRow['impressions'] ?? 0),
                'position_delta'   => ($row['position'] !== null && ($prevRow['position'] ?? null) !== null)
                    ? round($row['position'] - $prevRow['position'], 2) // negative = improved
                    : null,
            ];
        }

        if (! empty($filters['q'])) {
            $needle = mb_strtolower((string) $filters['q']);
            $rows = array_values(array_filter(
                $rows,
                fn (array $r) => mb_strpos(mb_strtolower((string) $r[$labelKey]), $needle) !== false,
            ));
        }

        $minImpressions = isset($filters['min_impressions']) ? (int) $filters['min_impressions'] : null;
        if ($minImpressions !== null && $minImpressions > 0) {
            $rows = array_values(array_filter(
                $rows,
                fn (array $r) => $r['impressions'] >= $minImpressions,
            ));
        }

        [$sort, $dir] = $this->sortAndDir($filters);
        usort($rows, fn (array $a, array $b) => $this->compare($a, $b, $sort, $dir, $labelKey));

        // Offset cursor (see class docblock): base64(JSON {"o": offset}).
        $offset = 0;
        if ($cursor !== null && $cursor !== '') {
            $decoded = json_decode((string) base64_decode($cursor, true), true);
            $offset = (is_array($decoded) && isset($decoded['o']) && (int) $decoded['o'] > 0)
                ? (int) $decoded['o']
                : 0; // a corrupted cursor restarts pagination rather than 500ing
        }

        $total = count($rows);
        $page = array_slice($rows, $offset, $perPage);
        $hasMore = $offset + $perPage < $total;

        return [
            'data' => $page,
            'meta' => [
                'next_cursor'   => $hasMore ? base64_encode(json_encode(['o' => $offset + $perPage])) : null,
                'has_more'      => $hasMore,
                'window'        => [
                    'from'      => $from->toDateString(),
                    'to'        => $to->toDateString(),
                    'prev_from' => $prevFrom?->toDateString(),
                    'prev_to'   => $prevTo?->toDateString(),
                ],
                'total_queries' => $total,
            ],
        ];
    }

    /** @return array{0: string, 1: string, 2: string} [model class, hash column, label column] */
    private function bindings(string $dimension): array
    {
        return $dimension === 'queries'
            ? [SeoQueryDaily::class, 'query_hash', 'query']
            : [SeoPageDaily::class, 'page_hash', 'page_path'];
    }

    /**
     * Default sort clicks desc; position defaults to asc ("best first").
     * An explicit dir always wins. Null sort values (no position/ctr data)
     * sort last in either direction.
     *
     * @return array{0: string, 1: 'asc'|'desc'}
     */
    private function sortAndDir(array $filters): array
    {
        $sort = $filters['sort'] ?? 'clicks';
        if (! in_array($sort, ['clicks', 'impressions', 'ctr', 'position', 'clicks_delta', 'position_delta'], true)) {
            $sort = 'clicks';
        }

        $dir = $filters['dir'] ?? null;
        if ($dir !== 'asc' && $dir !== 'desc') {
            $dir = $sort === 'position' ? 'asc' : 'desc';
        }

        return [$sort, $dir];
    }

    private function compare(array $a, array $b, string $sort, string $dir, string $labelKey): int
    {
        $av = $a[$sort] ?? null;
        $bv = $b[$sort] ?? null;

        // Deterministic tie-break / null handling: impressions desc, then label.
        $fallback = ($b['impressions'] <=> $a['impressions'])
            ?: (strcmp((string) $a[$labelKey], (string) $b[$labelKey]));

        if ($av === null && $bv === null) {
            return $fallback;
        }
        if ($av === null) {
            return 1; // nulls last, regardless of direction
        }
        if ($bv === null) {
            return -1;
        }

        $cmp = $av <=> $bv;
        if ($cmp !== 0) {
            return $dir === 'asc' ? $cmp : -$cmp;
        }

        return $fallback;
    }
}
