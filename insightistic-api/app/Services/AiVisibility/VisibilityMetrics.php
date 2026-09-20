<?php

namespace App\Services\AiVisibility;

use App\Models\AiVisibilityCitation;
use App\Models\AiVisibilityMention;
use App\Models\AiVisibilityRun;
use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * Visibility metrics for one site, computed ONLY from stored observation
 * rows (doc 14 acceptance: "metrics are computed from stored observations,
 * not regenerated from memory"). Every formula below is doc-linked and
 * exposed verbatim to the UI via definitions(), so the dashboard can
 * "explain exactly how each visibility metric is calculated" (doc 14
 * acceptance).
 *
 * ELIGIBILITY: doc 14 defines mention rate over "eligible runs". A failed
 * run produced no answer, so it observed nothing about the brand — failed
 * runs are excluded from the denominators of every rate (but still counted
 * in runs_total / runs_failed, so the failure rate stays visible).
 *
 * SHARE OF VOICE (doc 14 asks for a transparent definition): competitor
 * share here is "the share of successful runs where a competitor was
 * mentioned and the brand was not" — the loss-of-voice framing. It is
 * documented in definitions(); it is NOT blended across providers
 * (doc 14: combine only with a documented weighting method — by_provider
 * is reported separately instead).
 */
class VisibilityMetrics
{
    /**
     * Metric formulas, doc-linked, for the UI's explainability affordance.
     *
     * @return array<string, string>
     */
    public static function definitions(): array
    {
        return [
            'mention_rate'                 => 'successful runs with the brand mentioned / eligible (successful) runs x 100 (doc 14: runs with brand mention / eligible runs)',
            'citation_rate'                => 'successful runs with at least one owned-domain citation / eligible (successful) runs x 100 (doc 14: runs citing owned domain / eligible runs)',
            'avg_owned_citations_per_run'  => 'total owned-domain citations / eligible (successful) runs',
            'competitor_share'             => 'successful runs where a competitor is mentioned AND the brand is not / eligible (successful) runs x 100 (doc 14 share-of-voice, loss framing)',
        ];
    }

    /**
     * @return array{
     *     days: int,
     *     prompts_tracked: int,
     *     runs_total: int,
     *     runs_failed: int,
     *     mention_rate: float,
     *     citation_rate: float,
     *     avg_owned_citations_per_run: float,
     *     competitor_share: float,
     *     by_provider: list<array{provider: string, runs: int, mention_rate: float}>,
     *     trend: list<array{date: string, runs: int, mentions: int}>,
     *     metric_definitions: array<string, string>,
     * }
     */
    public function forSite(Site $site, ?int $days = 30): array
    {
        $days = max(1, $days ?? 30);
        $since = now()->subDays($days);

        $runs = AiVisibilityRun::query()
            ->where('site_id', $site->id)
            ->where('checked_at', '>=', $since)
            ->orderBy('checked_at')
            ->get();

        $successful = $runs->where('status', AiVisibilityRun::STATUS_SUCCESS)->values();
        $eligible = $successful->count();
        $runIds = $successful->pluck('id');

        $mentionRate = $this->rate($successful->where('brand_mentioned', true)->count(), $eligible);

        // Owned-citation runs: successful runs with >= 1 owned citation.
        $ownedCitationCounts = AiVisibilityCitation::query()
            ->whereIn('run_id', $runIds)
            ->where('is_owned', true)
            ->groupBy('run_id')
            ->selectRaw('run_id, count(*) as owned')
            ->pluck('owned', 'run_id');

        $ownedCitationRuns = $ownedCitationCounts->count();
        $ownedCitationsTotal = (int) $ownedCitationCounts->sum();

        // Competitor-mention run ids (any successful run mentioning one).
        $competitorRunIds = AiVisibilityMention::query()
            ->whereIn('run_id', $runIds)
            ->where('is_competitor', true)
            ->distinct()
            ->pluck('run_id')
            ->flip();

        // Loss framing: competitor present AND brand absent, same run.
        $lostRuns = $successful->filter(
            fn (AiVisibilityRun $run) => ! $run->brand_mentioned && $competitorRunIds->has($run->id),
        )->count();

        return [
            'days'                         => $days,
            'prompts_tracked'              => \App\Models\AiVisibilityPrompt::query()
                ->where('site_id', $site->id)
                ->where('active', true)
                ->count(),
            'runs_total'                   => $runs->count(),
            'runs_failed'                  => $runs->count() - $eligible,
            'mention_rate'                 => $mentionRate,
            'citation_rate'                => $this->rate($ownedCitationRuns, $eligible),
            'avg_owned_citations_per_run'  => $eligible > 0
                ? round($ownedCitationsTotal / $eligible, 2)
                : 0.0,
            'competitor_share'             => $this->rate($lostRuns, $eligible),
            'by_provider'                  => $this->byProvider($successful),
            'trend'                        => $this->trend($runs),
            'metric_definitions'           => self::definitions(),
        ];
    }

    /**
     * Provider coverage (doc 14: report providers separately, never blended):
     * runs and mention_rate per provider label over successful runs.
     *
     * @param  Collection<int, AiVisibilityRun>  $successful
     * @return list<array{provider: string, runs: int, mention_rate: float}>
     */
    private function byProvider(Collection $successful): array
    {
        return $successful
            ->groupBy('provider')
            ->map(function (Collection $group, string $provider) {
                return [
                    'provider'      => $provider,
                    'runs'          => $group->count(),
                    'mention_rate'  => $this->rate(
                        $group->where('brand_mentioned', true)->count(),
                        $group->count(),
                    ),
                ];
            })
            ->sortBy('provider')
            ->values()
            ->all();
    }

    /**
     * Daily runs + brand mentions over the window (the trend chart's raw
     * material — both series straight from stored rows).
     *
     * @param  Collection<int, AiVisibilityRun>  $runs
     * @return list<array{date: string, runs: int, mentions: int}>
     */
    private function trend(Collection $runs): array
    {
        return $runs
            ->groupBy(fn (AiVisibilityRun $run) => $run->checked_at->toDateString())
            ->map(function (Collection $group, string $date) {
                return [
                    'date'     => $date,
                    'runs'     => $group->count(),
                    'mentions' => $group->where('brand_mentioned', true)->count(),
                ];
            })
            ->sortKeys()
            ->values()
            ->all();
    }

    /** Percentage share, 1 decimal; 0.0 when the denominator is empty. */
    private function rate(int $numerator, int $denominator): float
    {
        return $denominator > 0 ? round($numerator / $denominator * 100, 1) : 0.0;
    }
}
