<?php

namespace App\Domain\Search;

use App\Models\SeoPageDaily;
use App\Models\SeoQueryDaily;
use App\Models\Site;
use Carbon\CarbonImmutable;

/**
 * Search-intelligence opportunity reports (doc 10-GOOGLE-SEARCH-CONSOLE.md,
 * "Opportunity algorithms"). Every list compares a window against the
 * immediately preceding window of the same length (typically 28d vs the
 * prior 28d), every item carries a human `reason` explaining WHY it was
 * flagged (doc 10 acceptance criterion), and every list applies noise
 * floors — doc 10: "Use noise floors; do not alert on 1-click changes."
 *
 * Floors (class constants, tuned to doc 10's guidance):
 *  - MIN_IMPRESSIONS = 30        — winners/losers/striking/ctr lists ignore
 *                                  queries below this; GSC long-tail noise
 *                                  routinely swings single-digit impressions
 *  - MIN_PREV_CLICKS = 10        — content decay needs a real prior baseline
 *  - CTR_BAND_MIN_IMPRESSIONS = 500 — a position band's site-relative CTR
 *                                  curve is only trusted above this volume
 *
 * Expected-CTR curves, per doc 10: "Expected CTR should be relative to this
 * site's own performance by position band where possible, rather than
 * asserting universal CTR curves as truth."
 *  - ctrOpportunities() builds the curve from THIS SITE's own window data
 *    per band, and only falls back to DEFAULT_CTR_CURVE when a band has
 *    under CTR_BAND_MIN_IMPRESSIONS site impressions.
 *  - strikingDistance() intentionally uses DEFAULT_CTR_CURVE for its score:
 *    the site-relative curve includes the candidate query itself, so a
 *    single dominant query in a band would define its own "expected" CTR
 *    and erase its own gap (self-referential). "Expected at this position"
 *    is a generic reference point here; the site-relative requirement is
 *    implemented by ctrOpportunities().
 *
 * Cannibalization items are CANDIDATES only (doc 10): GSC aggregates do not
 * prove that pages competed in the same result set — they carry
 * `is_candidate: true` and must be labelled as candidates downstream.
 *
 * All SQL lives in SearchExplorer; this class is pure logic over its output.
 */
class OpportunityEngine
{
    /** Minimum window impressions for winners / losers / striking distance / CTR opportunities. */
    public const MIN_IMPRESSIONS = 30;

    /** Minimum prior-window clicks before content decay is even considered. */
    public const MIN_PREV_CLICKS = 10;

    /** Striking-distance average-position band (doc 10: "approximately 4–20"). */
    public const POSITION_MIN = 4.0;
    public const POSITION_MAX = 20.0;

    /** Site impressions a band needs before its own CTR curve is trusted. */
    public const CTR_BAND_MIN_IMPRESSIONS = 500;

    /** A query under 60% of its band's expected CTR is underperforming. */
    public const CTR_UNDERPERFORMANCE_RATIO = 0.6;

    /** Window clicks must fall below half the prior window to count as decay. */
    public const DECAY_RATIO = 0.5;

    /** Cannibalization: a landing page matters from this many impressions up. */
    public const CANNIBALIZATION_MIN_PAGE_IMPRESSIONS = 5;
    public const CANNIBALIZATION_MIN_PAGES = 2;

    /** Every list is capped at this many items. */
    public const CAP = 50;

    /**
     * Built-in fallback CTR-by-position curve (ratios, not percents). Rough
     * industry-shape values used ONLY when the site's own window data can't
     * support a band (band impressions < CTR_BAND_MIN_IMPRESSIONS), or by
     * strikingDistance() (see class docblock). NOT asserted as truth — the
     * site-relative curve always wins when it exists.
     */
    public const DEFAULT_CTR_CURVE = [
        '1'     => 0.28,
        '2'     => 0.15,
        '3'     => 0.10,
        '4-5'   => 0.07,
        '6-8'   => 0.045,
        '9-12'  => 0.03,
        '13-20' => 0.02,
        '21-30' => 0.012,
        '31-50' => 0.007,
        '51+'   => 0.004,
    ];

    /** Position band boundaries: label => [min, max] (inclusive, position 1-based). */
    private const BANDS = [
        '1'     => [1, 1],
        '2'     => [2, 2],
        '3'     => [3, 3],
        '4-5'   => [4, 5],
        '6-8'   => [6, 8],
        '9-12'  => [9, 12],
        '13-20' => [13, 20],
        '21-30' => [21, 30],
        '31-50' => [31, 50],
        '51+'   => [51, PHP_FLOAT_MAX],
    ];

    public function __construct(private readonly SearchExplorer $explorer) {}

    /** Every report at once, each capped at self::CAP items. */
    public function all(Site $site, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $prevFrom, CarbonImmutable $prevTo): array
    {
        return [
            'winners'           => $this->winners($site, $from, $to, $prevFrom, $prevTo),
            'losers'            => $this->losers($site, $from, $to, $prevFrom, $prevTo),
            'striking_distance' => $this->strikingDistance($site, $from, $to, $prevFrom, $prevTo),
            'ctr_opportunities' => $this->ctrOpportunities($site, $from, $to, $prevFrom, $prevTo),
            'content_decay'     => $this->contentDecay($site, $from, $to, $prevFrom, $prevTo),
            'cannibalization'   => $this->cannibalization($site, $from, $to, $prevFrom, $prevTo),
        ];
    }

    /** Window clicks grew vs prior window (noise floor on window impressions). */
    public function winners(Site $site, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $prevFrom, CarbonImmutable $prevTo): array
    {
        [$current, $previous] = $this->queryWindows($site, $from, $to, $prevFrom, $prevTo);

        $items = [];
        foreach ($current as $row) {
            if ($row['impressions'] < self::MIN_IMPRESSIONS) {
                continue;
            }

            $prevClicks = $previous[$row['hash']]['clicks'] ?? 0;
            if ($row['clicks'] <= $prevClicks) {
                continue;
            }

            $items[] = [
                'query'        => $row['label'],
                'clicks'       => $row['clicks'],
                'prev_clicks'  => $prevClicks,
                'clicks_delta' => $row['clicks'] - $prevClicks,
                'impressions'  => $row['impressions'],
                'position'     => $row['position'],
                'reason'       => $prevClicks > 0
                    ? sprintf('clicks up %s (%d → %d)', $this->pct(($row['clicks'] - $prevClicks) / $prevClicks), $prevClicks, $row['clicks'])
                    : sprintf('clicks up from 0 to %d (new query)', $row['clicks']),
            ];
        }

        usort($items, fn (array $a, array $b) => $b['clicks_delta'] <=> $a['clicks_delta']);

        return array_slice($items, 0, self::CAP);
    }

    /** Window clicks fell vs prior window (noise floor on PRIOR impressions). */
    public function losers(Site $site, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $prevFrom, CarbonImmutable $prevTo): array
    {
        [$current, $previous] = $this->queryWindows($site, $from, $to, $prevFrom, $prevTo);

        $items = [];
        foreach ($current as $row) {
            $prev = $previous[$row['hash']] ?? null;
            if ($prev === null || $prev['impressions'] < self::MIN_IMPRESSIONS) {
                continue;
            }

            if ($row['clicks'] >= $prev['clicks']) {
                continue;
            }

            $items[] = [
                'query'        => $row['label'],
                'clicks'       => $row['clicks'],
                'prev_clicks'  => $prev['clicks'],
                'clicks_delta' => $row['clicks'] - $prev['clicks'],
                'impressions'  => $row['impressions'],
                'prev_impressions' => $prev['impressions'],
                'position'     => $row['position'],
                'reason'       => sprintf(
                    'clicks down %s (%d → %d)',
                    $prev['clicks'] > 0 ? $this->pct(($prev['clicks'] - $row['clicks']) / $prev['clicks']) : '∞',
                    $prev['clicks'],
                    $row['clicks'],
                ),
            ];
        }

        // "Lost clicks asc": the most negative delta (biggest loser) first.
        usort($items, fn (array $a, array $b) => $a['clicks_delta'] <=> $b['clicks_delta']);

        return array_slice($items, 0, self::CAP);
    }

    /**
     * High-impression queries ranked 4–20 whose CTR underperforms the
     * expected CTR at that position. Score = impressions x (expected - actual),
     * i.e. the estimated missed clicks (doc 10: "weighted by CTR gap").
     */
    public function strikingDistance(Site $site, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $prevFrom, CarbonImmutable $prevTo): array
    {
        $current = $this->explorer->windowAggregates('queries', $site, $from, $to);

        $items = [];
        foreach ($current as $row) {
            $position = $row['position'];
            if ($position === null || $position < self::POSITION_MIN || $position > self::POSITION_MAX) {
                continue;
            }
            if ($row['impressions'] < self::MIN_IMPRESSIONS || $row['ctr'] === null) {
                continue;
            }

            $band = $this->bandKey($position);
            $expected = self::DEFAULT_CTR_CURVE[$band];
            $gap = $expected - $row['ctr'];
            if ($gap <= 0.0) {
                continue;
            }

            $score = round($row['impressions'] * $gap, 2);

            $items[] = [
                'query'        => $row['label'],
                'position'     => $position,
                'impressions'  => $row['impressions'],
                'clicks'       => $row['clicks'],
                'ctr'          => $row['ctr'],
                'expected_ctr' => $expected,
                'score'        => $score,
                'reason'       => sprintf(
                    'avg position %.1f · %d impressions · CTR %s vs ~%s expected at this position',
                    $position,
                    $row['impressions'],
                    $this->pct($row['ctr']),
                    $this->pct($expected),
                ),
            ];
        }

        usort($items, fn (array $a, array $b) => $b['score'] <=> $a['score']);

        return array_slice($items, 0, self::CAP);
    }

    /**
     * Queries whose CTR underperforms THIS SITE's own CTR for their position
     * band (doc 10: site-relative expected CTR). Bands with fewer than
     * CTR_BAND_MIN_IMPRESSIONS site impressions fall back to the universal
     * curve; the reason string names which curve was used.
     */
    public function ctrOpportunities(Site $site, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $prevFrom, CarbonImmutable $prevTo): array
    {
        $current = $this->explorer->windowAggregates('queries', $site, $from, $to);
        $curve = $this->buildSiteCurve($current);

        $items = [];
        foreach ($current as $row) {
            if ($row['impressions'] < self::MIN_IMPRESSIONS || $row['ctr'] === null) {
                continue;
            }
            if ($row['position'] === null) {
                continue; // no position → no meaningful band to compare against
            }

            $band = $this->bandKey($row['position']);
            $bandImpressions = $curve[$band]['impressions'] ?? 0;

            if ($bandImpressions >= self::CTR_BAND_MIN_IMPRESSIONS) {
                $expected = $curve[$band]['ctr'];
                $source = "site's own curve";
            } else {
                $expected = self::DEFAULT_CTR_CURVE[$band];
                $source = 'universal fallback curve';
            }

            if ($row['ctr'] >= self::CTR_UNDERPERFORMANCE_RATIO * $expected) {
                continue;
            }

            $missed = round($row['impressions'] * ($expected - $row['ctr']), 2);

            $items[] = [
                'query'        => $row['label'],
                'position'     => $row['position'],
                'band'         => $band,
                'impressions'  => $row['impressions'],
                'clicks'       => $row['clicks'],
                'ctr'          => $row['ctr'],
                'expected_ctr' => $expected,
                'missed_clicks' => $missed,
                'reason'       => sprintf(
                    'position band %s: CTR %s vs ~%s expected (%s)',
                    $band,
                    $this->pct($row['ctr']),
                    $this->pct($expected),
                    $source,
                ),
            ];
        }

        usort($items, fn (array $a, array $b) => $b['missed_clicks'] <=> $a['missed_clicks']);

        return array_slice($items, 0, self::CAP);
    }

    /**
     * Queries AND pages whose clicks collapsed vs the prior window while
     * holding a real baseline (prev clicks >= MIN_PREV_CLICKS, window clicks
     * < DECAY_RATIO x prev). Doc 10: rolling 28d vs prior 28d with a noise
     * floor — an 8-click baseline halving is noise, a 40-click one isn't.
     *
     * Known edge: a query/page that vanished from GSC entirely (zero window
     * rows, not just zero clicks) has no window aggregate to compare and is
     * therefore not flagged here — GSC only returns rows for terms with
     * impressions, so "absent" also means "Google stopped showing it", which
     * the alerts layer (doc 10) covers separately.
     */
    public function contentDecay(Site $site, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $prevFrom, CarbonImmutable $prevTo): array
    {
        $items = [];

        foreach (['query' => 'queries', 'page' => 'pages'] as $dimensionLabel => $dimension) {
            $current = $this->explorer->windowAggregates($dimension, $site, $from, $to);
            $previous = $this->explorer->windowAggregates($dimension, $site, $prevFrom, $prevTo);
            $labelKey = $dimensionLabel === 'query' ? 'query' : 'page_path';

            foreach ($current as $hash => $row) {
                $prevClicks = $previous[$hash]['clicks'] ?? 0;
                if ($prevClicks < self::MIN_PREV_CLICKS) {
                    continue;
                }
                if ($row['clicks'] >= self::DECAY_RATIO * $prevClicks) {
                    continue;
                }

                $items[] = [
                    'dimension'    => $dimensionLabel,
                    $labelKey      => $row['label'],
                    'clicks'       => $row['clicks'],
                    'prev_clicks'  => $prevClicks,
                    'impressions'  => $row['impressions'],
                    'prev_impressions' => $previous[$hash]['impressions'] ?? 0,
                    'reason'       => sprintf('decayed from %d to %d clicks', $prevClicks, $row['clicks']),
                ];
            }
        }

        usort($items, fn (array $a, array $b) => ($b['prev_clicks'] - $b['clicks']) <=> ($a['prev_clicks'] - $a['clicks']));

        return array_slice($items, 0, self::CAP);
    }

    /**
     * CANNIBALIZATION CANDIDATES (doc 10: candidates only — GSC aggregates
     * do not prove pages competed in the same result set): window queries
     * with >= CANNIBALIZATION_MIN_PAGES distinct landing pages, each page
     * holding >= CANNIBALIZATION_MIN_PAGE_IMPRESSIONS.
     */
    public function cannibalization(Site $site, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $prevFrom, CarbonImmutable $prevTo): array
    {
        $pairs = $this->explorer->windowQueryPageAggregates($site, $from, $to);
        $queryLabels = $this->explorer->windowAggregates('queries', $site, $from, $to);
        $pageLabels = $this->explorer->windowAggregates('pages', $site, $from, $to);

        // Group pairs by query, keeping only pages above the impression floor.
        $grouped = [];
        foreach ($pairs as $pair) {
            if ($pair['impressions'] < self::CANNIBALIZATION_MIN_PAGE_IMPRESSIONS) {
                continue;
            }
            $grouped[$pair['query_hash']][] = $pair;
        }

        // Labels come straight from the grain tables, not the (row-capped)
        // aggregate maps — a hash with no aggregate row would otherwise leak
        // its sha1 into the UI.
        $queryTexts = $this->labelsFor(SeoQueryDaily::query()->where('site_id', $site->id), 'query_hash', 'query', array_keys($grouped));
        $pageTexts = $this->labelsFor(SeoPageDaily::query()->where('site_id', $site->id), 'page_hash', 'page_path', array_merge(...array_map(fn (array $pages) => array_column($pages, 'page_hash'), $grouped ?: [[]])));

        $items = [];
        foreach ($grouped as $queryHash => $pages) {
            if (count($pages) < self::CANNIBALIZATION_MIN_PAGES) {
                continue;
            }

            usort($pages, fn (array $a, array $b) => $b['clicks'] <=> $a['clicks'] ?: $b['impressions'] <=> $a['impressions']);

            $totalClicks = array_sum(array_column($pages, 'clicks'));
            $totalImpressions = array_sum(array_column($pages, 'impressions'));

            $items[] = [
                'query'        => $queryTexts[$queryHash] ?? $queryLabels[$queryHash]['label'] ?? $queryHash,
                'is_candidate' => true,
                'pages'        => array_map(fn (array $p) => [
                    'page_path'   => $pageTexts[$p['page_hash']] ?? $pageLabels[$p['page_hash']]['label'] ?? $p['page_hash'],
                    'clicks'      => $p['clicks'],
                    'impressions' => $p['impressions'],
                    'position'    => $p['position'],
                ], $pages),
                'total_clicks'      => $totalClicks,
                'total_impressions' => $totalImpressions,
                'reason'       => sprintf(
                    '%d pages compete · top two split %d/%d clicks',
                    count($pages),
                    $pages[0]['clicks'],
                    $pages[1]['clicks'],
                ),
            ];
        }

        usort($items, fn (array $a, array $b) => $b['total_impressions'] <=> $a['total_impressions']);

        return array_slice($items, 0, self::CAP);
    }

    /** @return array{0: array, 1: array} window and prior-window query aggregates */
    private function queryWindows(Site $site, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $prevFrom, CarbonImmutable $prevTo): array
    {
        return [
            $this->explorer->windowAggregates('queries', $site, $from, $to),
            $this->explorer->windowAggregates('queries', $site, $prevFrom, $prevTo),
        ];
    }

    /**
     * Impressions-weighted CTR per position band from the site's own window
     * data: band ctr = SUM(clicks) / SUM(impressions) across the queries in
     * the band (weighted, never an average of per-query CTRs).
     *
     * @param array $rows windowAggregates() output
     * @return array<string, array{impressions: int, clicks: int, ctr: float}>
     */
    private function buildSiteCurve(array $rows): array
    {
        $bands = [];
        foreach ($rows as $row) {
            $band = $this->bandKey($row['position'] ?? 0.0);
            $bands[$band] ??= ['impressions' => 0, 'clicks' => 0];
            $bands[$band]['impressions'] += $row['impressions'];
            $bands[$band]['clicks'] += $row['clicks'];
        }

        foreach ($bands as $band => $agg) {
            $bands[$band]['ctr'] = $agg['impressions'] > 0
                ? round($agg['clicks'] / $agg['impressions'], 6)
                : 0.0;
        }

        return $bands;
    }

    /**
     * Band label for a (1-based) average position. GSC positions are
     * fractional (1.5, 6.5), so a query belongs to the band containing the
     * next whole rank: 1.5 → "2", 6.5 → "6-8", 25.0 → "21-30". Sub-1 and
     * degenerate values round into "1"/"51+".
     */
    private function bandKey(float $position): string
    {
        $rank = max(1, (int) ceil($position));

        foreach (self::BANDS as $label => [$min, $max]) {
            if ($rank >= $min && $rank <= $max) {
                return $label;
            }
        }

        return '51+';
    }

    /** Ratio (0..1) → human percent, trimmed: 0.045 → "4.5%", 0.02 → "2%", 2.0 → "200%". */
    private function pct(float $ratio): string
    {
        $value = (string) round($ratio * 100, 1);

        // Trim decimal trailing zeros only — a blanket rtrim(…, '0') turned
        // "200" into "2" and "20" into "2".
        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }

        return $value . '%';
    }

    /**
     * hash → label straight from a grain table. Any row carrying the hash
     * wins (same query/page text on every row by construction).
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $builder
     * @param  array<int, string>  $hashes
     * @return array<string, string>
     */
    private function labelsFor($builder, string $hashCol, string $labelCol, array $hashes): array
    {
        if ($hashes === []) {
            return [];
        }

        return $builder
            ->whereIn($hashCol, $hashes)
            ->get()
            ->mapWithKeys(fn ($row) => [(string) $row->{$hashCol} => (string) $row->{$labelCol}])
            ->all();
    }
}
