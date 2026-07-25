<?php

namespace App\Services\Intelligence;

use App\Models\Site;
use App\Support\DateRange;
use Illuminate\Support\Facades\DB;

/**
 * Search Console analytics beyond clicks and impressions (spec Phase 7).
 *
 * Every finding here is derived from `seo_query_snapshots` /
 * `seo_page_snapshots`, which already carry the previous period alongside the
 * current one. Nothing is estimated or modelled — where a comparison is not
 * available the row is omitted rather than filled with a guess.
 */
class SearchIntelligence
{
    /**
     * Queries where more than one page is competing for the same term.
     *
     * Cannibalization is only reported when the *ranking page has changed*
     * between periods, or when several pages of the site draw impressions for
     * the query in the same window. A site legitimately having several pages
     * that mention a term is normal; Google flip-flopping over which one to
     * show is the symptom worth acting on, because it means neither page
     * accumulates authority.
     *
     * Search Console's query and page dimensions cannot be joined exactly
     * through these snapshots — GSC does not expose the pairing in the
     * aggregate exports this platform stores — so this reports *candidates*,
     * clearly labelled, rather than pretending to a certainty the data does
     * not support.
     */
    public function cannibalization(Site $site, DateRange $range, int $limit = 15): array
    {
        $pages = DB::table('seo_page_snapshots')
            ->where('site_id', $site->id)
            ->where('period_start', '>=', $range->dateFrom())
            ->where('impressions', '>', 0)
            ->get(['page_path', 'clicks', 'impressions', 'position', 'prev_position', 'prev_clicks']);

        if ($pages->count() < 2) {
            return ['has_data' => false, 'reason' => 'not_enough_pages', 'candidates' => []];
        }

        // Group pages by their normalized topical stem — the last meaningful
        // path segment. Pages under the same stem competing on position is the
        // observable signal available from these snapshots.
        $groups = [];

        foreach ($pages as $p) {
            $stem = $this->stem($p->page_path);

            if ($stem === '') {
                continue;
            }

            $groups[$stem][] = $p;
        }

        $candidates = [];

        foreach ($groups as $stem => $rows) {
            if (count($rows) < 2) {
                continue;
            }

            // Only pages actually ranking are competing; a page on page nine
            // is not stealing anything from one on page one.
            $ranking = array_values(array_filter($rows, fn ($r) => $r->position !== null && (float) $r->position <= 30));

            if (count($ranking) < 2) {
                continue;
            }

            usort($ranking, fn ($a, $b) => (float) $a->position <=> (float) $b->position);

            $best = $ranking[0];
            $totalImpressions = array_sum(array_map(fn ($r) => (int) $r->impressions, $ranking));
            $totalClicks = array_sum(array_map(fn ($r) => (int) $r->clicks, $ranking));

            $candidates[] = [
                'topic'            => $stem,
                'pages'            => array_map(fn ($r) => [
                    'path'        => $r->page_path,
                    'clicks'      => (int) $r->clicks,
                    'impressions' => (int) $r->impressions,
                    'position'    => $r->position !== null ? round((float) $r->position, 1) : null,
                    'position_change' => $r->prev_position !== null && $r->position !== null
                        ? round((float) $r->prev_position - (float) $r->position, 1)
                        : null,
                ], array_slice($ranking, 0, 5)),
                'competing_pages'  => count($ranking),
                'best_position'    => round((float) $best->position, 1),
                'total_clicks'     => $totalClicks,
                'total_impressions' => $totalImpressions,
                'combined_ctr'     => $totalImpressions > 0 ? round($totalClicks / $totalImpressions * 100, 2) : null,
            ];
        }

        usort($candidates, fn ($a, $b) => $b['total_impressions'] <=> $a['total_impressions']);

        return [
            'has_data'   => $candidates !== [],
            'method'     => 'path_stem_overlap',
            'caveat'     => 'Search Console aggregates do not pair queries with pages, so these are candidates to review, not confirmed cannibalization.',
            'candidates' => array_slice($candidates, 0, $limit),
        ];
    }

    /**
     * Queries earning far fewer clicks than their position should produce.
     *
     * Compares each query's CTR against the median CTR of every query at a
     * similar position *on this site*, rather than against a published
     * industry curve. Industry curves are averaged over query intents this
     * site may not have; a brand term at position 3 behaves nothing like a
     * comparison query at position 3, and judging one by the other manufactures
     * opportunities that are not there.
     */
    public function ctrOpportunities(Site $site, DateRange $range, int $limit = 20): array
    {
        $queries = DB::table('seo_query_snapshots')
            ->where('site_id', $site->id)
            ->where('period_start', '>=', $range->dateFrom())
            ->where('impressions', '>=', 50)
            ->whereNotNull('position')
            ->where('position', '<=', 20)
            ->get(['query', 'clicks', 'impressions', 'ctr', 'position']);

        if ($queries->count() < 10) {
            return ['has_data' => false, 'reason' => 'not_enough_queries', 'queries' => []];
        }

        // Median CTR per position band, from this site's own data.
        $bands = [];
        foreach ($queries as $q) {
            $band = $this->positionBand((float) $q->position);
            $bands[$band][] = (int) $q->impressions > 0 ? (int) $q->clicks / (int) $q->impressions : 0;
        }

        $benchmarks = [];
        foreach ($bands as $band => $ctrs) {
            // A band with a couple of queries has no median worth comparing to.
            if (count($ctrs) < 3) {
                continue;
            }
            $benchmarks[$band] = $this->median($ctrs);
        }

        $rows = [];

        foreach ($queries as $q) {
            $band = $this->positionBand((float) $q->position);

            if (! isset($benchmarks[$band]) || $benchmarks[$band] <= 0) {
                continue;
            }

            $impressions = (int) $q->impressions;
            $actual = $impressions > 0 ? (int) $q->clicks / $impressions : 0;
            $expected = $benchmarks[$band];

            if ($actual >= $expected * 0.7) {
                continue;
            }

            $potential = ($expected - $actual) * $impressions;

            $rows[] = [
                'query'          => $q->query,
                'position'       => round((float) $q->position, 1),
                'position_band'  => $band,
                'clicks'         => (int) $q->clicks,
                'impressions'    => $impressions,
                'ctr'            => round($actual * 100, 2),
                'benchmark_ctr'  => round($expected * 100, 2),
                'gap_pct'        => round(($expected - $actual) / $expected * 100, 1),
                'potential_clicks' => (int) round($potential),
            ];
        }

        usort($rows, fn ($a, $b) => $b['potential_clicks'] <=> $a['potential_clicks']);

        return [
            'has_data'   => $rows !== [],
            'method'     => 'site_median_ctr_by_position_band',
            'benchmarks' => array_map(fn ($v) => round($v * 100, 2), $benchmarks),
            'queries'    => array_slice($rows, 0, $limit),
        ];
    }

    /**
     * Pages losing search traffic while still being served.
     *
     * Decay means impressions holding roughly steady while clicks fall, or
     * position slipping on a page that used to rank — content going stale
     * rather than being deindexed. A page that lost impressions too has a
     * different problem (lost coverage or lost demand) and is reported with
     * that distinction rather than lumped in.
     */
    public function contentDecay(Site $site, DateRange $range, int $limit = 20): array
    {
        $pages = DB::table('seo_page_snapshots')
            ->where('site_id', $site->id)
            ->where('period_start', '>=', $range->dateFrom())
            ->whereNotNull('prev_clicks')
            ->where('prev_clicks', '>=', 10)
            ->get(['page_path', 'clicks', 'impressions', 'position', 'prev_clicks', 'prev_impressions', 'prev_position']);

        if ($pages->isEmpty()) {
            return ['has_data' => false, 'reason' => 'no_comparison_data', 'pages' => []];
        }

        $rows = [];

        foreach ($pages as $p) {
            $prevClicks = (int) $p->prev_clicks;
            $clicks = (int) $p->clicks;
            $clickChange = ($clicks - $prevClicks) / $prevClicks * 100;

            if ($clickChange >= -25) {
                continue;
            }

            $prevImpressions = (int) ($p->prev_impressions ?? 0);
            $impressions = (int) $p->impressions;
            $impressionChange = $prevImpressions > 0
                ? ($impressions - $prevImpressions) / $prevImpressions * 100
                : null;

            $positionChange = $p->prev_position !== null && $p->position !== null
                ? round((float) $p->position - (float) $p->prev_position, 1)
                : null;

            $rows[] = [
                'path'              => $p->page_path,
                'clicks'            => $clicks,
                'previous_clicks'   => $prevClicks,
                'click_change_pct'  => round($clickChange, 1),
                'impressions'       => $impressions,
                'impression_change_pct' => $impressionChange !== null ? round($impressionChange, 1) : null,
                'position'          => $p->position !== null ? round((float) $p->position, 1) : null,
                // Positive = slipped down the results.
                'position_change'   => $positionChange,
                'diagnosis'         => $this->decayDiagnosis($impressionChange, $positionChange),
            ];
        }

        usort($rows, fn ($a, $b) => ($b['previous_clicks'] - $b['clicks']) <=> ($a['previous_clicks'] - $a['clicks']));

        return [
            'has_data' => $rows !== [],
            'pages'    => array_slice($rows, 0, $limit),
        ];
    }

    // ------------------------------------------------------------------

    /**
     * Why a page lost clicks, stated only as far as the data supports.
     *
     * Each branch names an observation, not a cause — "shown as often, clicked
     * less" is a fact; "the title is bad" would be a guess dressed as one.
     */
    private function decayDiagnosis(?float $impressionChange, ?float $positionChange): string
    {
        if ($positionChange !== null && $positionChange >= 3) {
            return 'ranking_slipped';
        }

        if ($impressionChange !== null && $impressionChange <= -25) {
            return 'lost_visibility';
        }

        if ($impressionChange !== null && $impressionChange > -10) {
            return 'shown_as_often_clicked_less';
        }

        return 'declining';
    }

    /** Last meaningful path segment, used as a crude topic key. */
    private function stem(string $path): string
    {
        $parts = array_values(array_filter(explode('/', trim(parse_url($path, PHP_URL_PATH) ?: $path, '/'))));

        if ($parts === []) {
            return '';
        }

        $last = end($parts);

        // Drop pagination and date segments, which are not topics.
        if (preg_match('/^(page|\d{4}|\d{1,2})$/i', $last) && count($parts) > 1) {
            $last = $parts[count($parts) - 2];
        }

        return strtolower(preg_replace('/[-_]+/', ' ', $last));
    }

    private function positionBand(float $position): string
    {
        return match (true) {
            $position <= 3  => '1-3',
            $position <= 5  => '4-5',
            $position <= 10 => '6-10',
            default         => '11-20',
        };
    }

    private function median(array $values): float
    {
        sort($values);
        $n = count($values);

        if ($n === 0) {
            return 0.0;
        }

        $mid = (int) floor($n / 2);

        return $n % 2 === 0
            ? ((float) $values[$mid - 1] + (float) $values[$mid]) / 2
            : (float) $values[$mid];
    }
}
