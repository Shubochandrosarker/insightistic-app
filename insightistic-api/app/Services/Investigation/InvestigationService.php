<?php

namespace App\Services\Investigation;

use App\Metrics\MetricRegistry;
use App\Models\Investigation;
use App\Models\Site;
use App\Query\DataFreshness;
use App\Query\EvidenceLedger;
use App\Query\PlanValidator;
use App\Query\QueryEngineException;
use App\Query\SafeWooQueryEngine;

/**
 * Orchestrates a WPAgentistic Investigation (spec: P8) — read-only,
 * evidence-gathering across several metrics to answer a question like "why
 * did revenue drop?" See docs/WPAGENTISTIC-INVESTIGATIONS.md.
 *
 * Runs the exact same PlanValidator/SafeWooQueryEngine/EvidenceLedger
 * pipeline every other analytical surface uses — once per requested
 * metric, each with a previous-period comparison — and records every
 * execution with `run_type = "investigation"`. This class never mutates
 * anything outside `investigations` itself; it cannot resolve an alert,
 * change a setting, or take any action. Its output can *recommend* a
 * follow-up (surfaced in the narrative's `recommended_actions`), which a
 * human or P9's `ActionProposalController` may turn into an actual
 * `ActionProposal` — but that is always a separate, explicit step, never
 * automatic.
 */
class InvestigationService
{
    public function __construct(
        private readonly MetricRegistry $registry,
        private readonly SafeWooQueryEngine $engine,
        private readonly EvidenceLedger $ledger,
        private readonly InvestigationSynthesizer $synthesizer,
    ) {}

    /**
     * @param  string[]  $metricIds  Capped to PlanValidator::MAX_METRICS — the same cap a single query plan is held to.
     */
    public function investigate(
        Site $site,
        int $organizationId,
        string $subject,
        array $metricIds,
        ?int $userId,
        string $triggerSource = 'manual',
        ?int $triggerRefId = null,
    ): Investigation {
        $metricIds = array_slice(array_values(array_unique($metricIds)), 0, PlanValidator::MAX_METRICS);

        $findings = [];
        $results = [];

        foreach ($metricIds as $metricId) {
            if (! $this->registry->has($metricId)) {
                $findings[] = ['metric_id' => $metricId, 'status' => 'error', 'error' => 'unknown_metric'];

                continue;
            }

            $rawPlan = ['metrics' => [$metricId], 'period' => ['preset' => 'last_30_days'], 'comparison' => true];

            try {
                $result = $this->engine->run($rawPlan, $site, $organizationId);
                $run = $this->ledger->recordSuccess($site, $userId, 'investigation', $result);
                $results[$metricId] = $result;
                $findings[] = [
                    'metric_id' => $metricId,
                    'status' => 'success',
                    'evidence_run_id' => $run->id,
                    'value' => $result->metrics[$metricId] ?? null,
                    'previous' => $result->previous[$metricId] ?? null,
                    'delta_pct' => $result->deltas[$metricId] ?? null,
                ];
            } catch (QueryEngineException $e) {
                $run = $this->ledger->recordFailure($site, $userId, 'investigation', $rawPlan, $e);
                $findings[] = [
                    'metric_id' => $metricId,
                    'status' => 'error',
                    'error' => $e->errorCode,
                    'evidence_run_id' => $run->id,
                ];
            }
        }

        $freshness = DataFreshness::forSite($site);
        $synth = $this->synthesizer->synthesize($site, $subject, $results, $findings, $freshness);

        $narrative = $synth['contract'];
        $narrative['citation_refs'] = array_values(array_filter(array_map(
            fn (array $f) => $f['evidence_run_id'] ?? null,
            $findings,
        )));
        $narrative['citation_refs'] = array_map(fn ($id) => "evidence_run:{$id}", $narrative['citation_refs']);

        $status = $findings !== [] && collect($findings)->every(fn ($f) => $f['status'] === 'error') ? 'error' : 'completed';

        return Investigation::create([
            'organization_id' => $site->organization_id,
            'site_id' => $site->id,
            'requested_by_user_id' => $userId,
            'trigger_source' => $triggerSource,
            'trigger_ref_id' => $triggerRefId,
            'subject' => $subject,
            'status' => $status,
            'findings_json' => $findings,
            'narrative_json' => $narrative,
        ]);
    }
}
