<?php

namespace App\Services\Outcome;

use App\Models\ActionProposal;
use App\Models\Anomaly;
use App\Models\Investigation;
use App\Models\OutcomeVerification;
use App\Models\Site;
use App\Query\EvidenceLedger;
use App\Query\QueryEngineException;
use App\Query\SafeWooQueryEngine;

/**
 * Closes the loop P9 (Bridgistic Approved Actions) opens (spec: Outcome
 * Verification, P11): for an executed ActionProposal, capture a baseline
 * reading of whatever metric its source implicates, then later re-measure
 * the same metric and record whether it actually improved. See
 * docs/OUTCOME-VERIFICATION.md.
 *
 * Runs through the exact same SafeWooQueryEngine/EvidenceLedger pipeline
 * (`run_type = "query"`, the same type a direct analytics/query call
 * uses) every other analytical surface does — this class computes nothing
 * itself, it only re-runs the engine twice and compares the two real
 * values it measured. Those values are stored directly on
 * `outcome_verifications` at the moment they're computed (`baseline_value`/
 * `followup_value`) rather than re-derived from `evidence_runs`
 * afterward — `evidence_runs` persists the plan/hash/outcome of a run
 * (P2's documented scope), not a copy of the computed metric value, so
 * there is nothing to "look up" later; the linked `evidence_runs` ids
 * exist purely as citations to the reproducible run, not as a value store.
 */
class OutcomeVerificationService
{
    /** Percent-point band around 0 that counts as "no meaningful change" — smaller moves are noise, not a verified outcome either way. */
    private const NOISE_BAND_PCT = 5.0;

    /** Minimum time between baseline and follow-up before a re-measurement means anything. */
    private const MIN_HOURS_BEFORE_VERIFY = 24;

    public function __construct(
        private readonly SafeWooQueryEngine $engine,
        private readonly EvidenceLedger $ledger,
    ) {}

    /**
     * Called right after ActionProposalService::approve() executes an
     * action successfully. Always creates a row (never silently skips) —
     * when no metric can be derived from the proposal's source, the row is
     * created already-terminal with outcome = 'no_metric_to_verify' rather
     * than left dangling with a null outcome forever.
     */
    public function captureBaseline(ActionProposal $proposal, Site $site): OutcomeVerification
    {
        $metricId = $this->watchedMetricFor($proposal);

        if ($metricId === null) {
            return OutcomeVerification::create([
                'organization_id' => $site->organization_id,
                'site_id' => $site->id,
                'action_proposal_id' => $proposal->id,
                'watched_metric_id' => null,
                'outcome' => 'no_metric_to_verify',
                'verified_at' => now(),
            ]);
        }

        [$runId, $value] = $this->runAndRecord($site, $proposal, $metricId);

        return OutcomeVerification::create([
            'organization_id' => $site->organization_id,
            'site_id' => $site->id,
            'action_proposal_id' => $proposal->id,
            'watched_metric_id' => $metricId,
            'baseline_evidence_run_id' => $runId,
            'baseline_value' => $value,
        ]);
    }

    /** True once enough time has passed since the baseline for a re-measurement to be meaningful. */
    public function isDue(OutcomeVerification $verification): bool
    {
        return ! $verification->isVerified()
            && $verification->watched_metric_id !== null
            && $verification->created_at->diffInHours(now()) >= self::MIN_HOURS_BEFORE_VERIFY;
    }

    public function verify(OutcomeVerification $verification, Site $site): OutcomeVerification
    {
        if ($verification->isVerified()) {
            throw new \RuntimeException('This outcome has already been verified.');
        }
        if ($verification->watched_metric_id === null) {
            throw new \RuntimeException('This outcome has no metric to verify.');
        }

        $proposal = $verification->actionProposal;
        $metricId = $verification->watched_metric_id;
        [$followupRunId, $followupValue] = $this->runAndRecord($site, $proposal, $metricId);

        [$outcome, $deltaPct] = $this->classify($verification->baseline_value, $followupValue);

        $verification->update([
            'followup_evidence_run_id' => $followupRunId,
            'followup_value' => $followupValue,
            'verified_at' => now(),
            'outcome' => $outcome,
            'delta_pct' => $deltaPct,
        ]);

        return $verification->fresh();
    }

    private function watchedMetricFor(ActionProposal $proposal): ?string
    {
        return match ($proposal->source_type) {
            'anomaly' => Anomaly::find($proposal->source_id)?->metric_id,
            'wpagentistic_investigation' => $this->primaryMetricFromInvestigation($proposal->source_id),
            default => null,
        };
    }

    private function primaryMetricFromInvestigation(?int $investigationId): ?string
    {
        if ($investigationId === null) {
            return null;
        }

        $investigation = Investigation::find($investigationId);
        if (! $investigation) {
            return null;
        }

        $firstSuccess = collect($investigation->findings_json ?? [])->firstWhere('status', 'success');

        return $firstSuccess['metric_id'] ?? null;
    }

    /**
     * Runs the metric now (aggregate, no comparison — a plain
     * point-in-time reading) and returns both the evidence_runs id (a
     * citation to the reproducible run) and the value it actually
     * measured, since evidence_runs itself does not persist that value.
     *
     * @return array{0: ?int, 1: ?float}
     */
    private function runAndRecord(Site $site, ActionProposal $proposal, string $metricId): array
    {
        $rawPlan = ['metrics' => [$metricId]];

        try {
            $result = $this->engine->run($rawPlan, $site, $site->organization_id);
        } catch (QueryEngineException $e) {
            $this->ledger->recordFailure($site, $proposal->reviewed_by_user_id, 'query', $rawPlan, $e);

            return [null, null];
        }

        $run = $this->ledger->recordSuccess($site, $proposal->reviewed_by_user_id, 'query', $result);
        $value = $result->metrics[$metricId] ?? null;

        return [$run->id, $value === null ? null : (float) $value];
    }

    /** @return array{0: string, 1: ?float} [outcome, delta_pct] */
    private function classify(?float $baseline, ?float $followup): array
    {
        if ($baseline === null || $followup === null) {
            return ['unchanged', null];
        }
        if ($baseline == 0.0) {
            return [$followup > 0 ? 'improved' : 'unchanged', null];
        }

        $deltaPct = round((($followup - $baseline) / $baseline) * 100, 2);

        $outcome = match (true) {
            $deltaPct >= self::NOISE_BAND_PCT => 'improved',
            $deltaPct <= -self::NOISE_BAND_PCT => 'worsened',
            default => 'unchanged',
        };

        return [$outcome, $deltaPct];
    }
}
