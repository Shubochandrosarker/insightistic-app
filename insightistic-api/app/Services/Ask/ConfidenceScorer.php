<?php

namespace App\Services\Ask;

use App\Models\Site;
use App\Query\DataFreshness;
use App\Query\QueryResult;

/**
 * Computes an "high"|"medium"|"low" confidence label for an Ask answer
 * (spec: Ask Insightistic V2, P3) — and, critically, NEVER from a model's
 * own self-reported confidence. Model self-confidence is not evidence; a
 * provider can say "high confidence" about a wrong or invented number just
 * as easily as a right one. This scorer only looks at things that are
 * actually verifiable: how stale the underlying data sync is, whether the
 * question's keyword match was exact/unambiguous, and whether the query
 * itself returned anything to work with.
 */
class ConfidenceScorer
{
    /**
     * @param  string[]  $matchedMetrics  What QuestionPlanBuilder actually matched, before any dimension restriction — used to flag an ambiguous question (many unrelated metrics all fired at once).
     */
    public function score(Site $site, QueryResult $result, array $matchedMetrics): array
    {
        $freshness = DataFreshness::forSite($site);
        $reasons = [];
        $score = 3; // 1 (low) .. 3 (high), nudged down by each concern below

        if ($freshness['days_stale'] === null) {
            $score -= 2;
            $reasons[] = 'This site has no synced data yet.';
        } elseif ($freshness['days_stale'] > 2) {
            $score -= 1;
            $reasons[] = "Underlying data is {$freshness['days_stale']} day(s) stale.";
        }

        if (count($matchedMetrics) > 3) {
            $score -= 1;
            $reasons[] = 'The question matched several unrelated metrics — the plan used the most specific ones, but the question may be ambiguous.';
        }

        if ($result->plan->isBreakdown() && $result->rowCount === 0) {
            $score -= 1;
            $reasons[] = 'No rows matched this breakdown for the selected period.';
        }

        if ($result->caveats !== []) {
            $reasons[] = 'One or more requested metrics have known caveats — see below.';
        }

        $score = max(1, min(3, $score));
        $label = match ($score) {
            3 => 'high',
            2 => 'medium',
            default => 'low',
        };

        return [
            'label' => $label,
            'reasons' => $reasons,
            'freshness' => $freshness,
        ];
    }
}
