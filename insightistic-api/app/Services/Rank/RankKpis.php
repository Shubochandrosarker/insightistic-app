<?php

namespace App\Services\Rank;

/**
 * Rank-tracker KPI math (Phase 10, doc 13). Deliberately pure: no DB, no
 * models in the signatures — the controller resolves "latest observation
 * per keyword" and "the one before it" and hands the maps over as plain
 * arrays, so the formulas are unit-testable without a database.
 *
 * VISIBILITY (doc 13's "visibility score"): a 0..100 quality-of-ranking
 * index over the tracked keyword set. Each keyword contributes a weight
 * from its latest position — top-3 ranks full credit, the credit halves
 * at each band edge, "not found" contributes nothing:
 *
 *   null -> 0.0 | 1-3 -> 1.0 | 4-10 -> 0.5 | 11-20 -> 0.25 | >20 -> 0.1
 *
 * divided by the number of keywords, x100, rounded to 1 decimal.
 *
 * Noise floor (doc 13 "noise control"): a 1-2 position jitter between
 * consecutive checks is SERP volatility, not a trend. improved counts any
 * non-null move toward #1; declined requires the position NUMBER to have
 * worsened by at least 3.
 */
class RankKpis
{
    /** Position movement below this many places is noise, not a decline. */
    public const DECLINE_NOISE_FLOOR = 3;

    /**
     * Aggregate the tracker's overview KPIs from per-keyword observation
     * maps. Both arrays are keyed by keyword id; each value is
     * ['position' => int|null, 'checked_at' => mixed] — $latestPerKeyword
     * holds every keyword's newest observation, $previousPerKeyword the
     * one before it (missing entries simply have no comparison). Every
     * keyword passed in is an active, tracked keyword, so tracked and
     * active are the input count by construction; not_found counts
     * keywords whose latest check found them outside the results.
     *
     * @param array<int, array{position: int|null, checked_at: mixed}> $latestPerKeyword
     * @param array<int, array{position: int|null, checked_at: mixed}> $previousPerKeyword
     * @return array{tracked: int, active: int, top3: int, top10: int, top20: int, not_found: int, improved: int, declined: int, visibility: float}
     */
    public static function kpis(array $latestPerKeyword, array $previousPerKeyword): array
    {
        $tracked = count($latestPerKeyword);

        $top3 = 0;
        $top10 = 0;
        $top20 = 0;
        $notFound = 0;
        $improved = 0;
        $declined = 0;
        $score = 0.0;

        foreach ($latestPerKeyword as $keywordId => $latest) {
            $position = $latest['position'] ?? null;
            $position = $position === null ? null : (int) $position;

            // Cumulative bands: a #2 keyword is inside top3, top10 AND top20.
            if ($position !== null) {
                if ($position <= 3) {
                    $top3++;
                }
                if ($position <= 10) {
                    $top10++;
                }
                if ($position <= 20) {
                    $top20++;
                }
            } else {
                $notFound++;
            }

            $score += self::visibilityWeight($position);

            $previous = $previousPerKeyword[$keywordId] ?? null;
            $previousPosition = $previous === null ? null : ($previous['position'] ?? null);

            if ($position !== null && $previousPosition !== null) {
                $previousPosition = (int) $previousPosition;

                if ($position < $previousPosition) {
                    $improved++;
                } elseif ($position > $previousPosition
                    && ($position - $previousPosition) >= self::DECLINE_NOISE_FLOOR) {
                    $declined++;
                }
            }
        }

        return [
            'tracked'    => $tracked,
            'active'     => $tracked,
            'top3'       => $top3,
            'top10'      => $top10,
            'top20'      => $top20,
            'not_found'  => $notFound,
            'improved'   => $improved,
            'declined'   => $declined,
            'visibility' => $tracked > 0 ? round($score / $tracked * 100, 1) : 0.0,
        ];
    }

    /** Visibility credit for one position, per the band table above. */
    public static function visibilityWeight(?int $position): float
    {
        return match (true) {
            $position === null => 0.0,
            $position <= 3     => 1.0,
            $position <= 10    => 0.5,
            $position <= 20    => 0.25,
            default            => 0.1,
        };
    }

    /**
     * Chronological history rows for the keyword's trend chart: oldest
     * observation first, one {checked_at, position, url} entry per check.
     * Observations that already carry a different keyword_id are dropped —
     * a cheap defense against a caller handing over an unscoped batch.
     *
     * @param object $keyword the RankKeyword the rows belong to
     * @param iterable<object> $observations RankObservation rows (any order)
     * @return array<int, array{checked_at: mixed, position: int|null, url: string|null}>
     */
    public static function history(object $keyword, iterable $observations): array
    {
        $keywordId = $keyword->id ?? null;

        return collect($observations)
            ->filter(fn ($observation) => $keywordId === null
                || ($observation->keyword_id ?? $keywordId) == $keywordId)
            // Charts draw oldest-first regardless of the caller's fetch
            // order — sort by checked_at (Carbon stringifies sortably).
            ->sortBy(fn ($observation) => (string) $observation->checked_at)
            ->map(fn ($observation) => [
                'checked_at' => $observation->checked_at,
                'position'   => $observation->position === null ? null : (int) $observation->position,
                'url'        => $observation->ranking_url,
            ])
            ->values()
            ->all();
    }
}
