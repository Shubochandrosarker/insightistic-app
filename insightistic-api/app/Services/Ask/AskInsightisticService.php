<?php

namespace App\Services\Ask;

use App\Models\Site;
use App\Query\EvidenceLedger;
use App\Query\QueryEngineException;
use App\Query\SafeWooQueryEngine;

/**
 * Orchestrates Ask Insightistic V2 (spec: P3) end to end:
 *
 *   question → QuestionPlanBuilder → (same) PlanValidator/SafeWooQueryEngine
 *   pipeline as a hand-authored plan → ConfidenceScorer + ChartSpecBuilder
 *   + AnswerSynthesizer → EvidenceLedger (run_type "ask")
 *
 * Deliberately does NOT introduce a parallel query path: a question is only
 * ever a different way of producing the same raw-plan-array shape
 * PlanValidator already accepts, so every safety property P1 established
 * (allowlisted metrics/tables/columns, tenant scope from $site only, read
 * -only identity, timeouts, concurrency caps) applies identically whether
 * the plan came from a form or from this class.
 */
class AskInsightisticService
{
    public function __construct(
        private readonly QuestionPlanBuilder $planBuilder,
        private readonly SafeWooQueryEngine $engine,
        private readonly EvidenceLedger $ledger,
        private readonly ChartSpecBuilder $chartBuilder,
        private readonly ConfidenceScorer $confidenceScorer,
        private readonly AnswerSynthesizer $synthesizer,
    ) {}

    /**
     * @return array{answer: array, chart: array, evidence: array, evidence_run_id: int}
     *
     * @throws QueryEngineException  Caller (AskInsightisticController) maps this to an HTTP error response the same way AnalyticsQueryController does; the failure is always recorded first.
     */
    public function ask(string $question, Site $site, int $organizationId, ?int $userId): array
    {
        try {
            $built = $this->planBuilder->build($question);
        } catch (QueryEngineException $e) {
            $this->ledger->recordFailure($site, $userId, 'ask', ['question' => $question], $e);

            throw $e;
        }

        try {
            $result = $this->engine->run($built['plan'], $site, $organizationId);
        } catch (QueryEngineException $e) {
            $this->ledger->recordFailure($site, $userId, 'ask', $built['plan'], $e);

            throw $e;
        }

        $confidence = $this->confidenceScorer->score($site, $result, $built['matched_metrics']);
        $chart = $this->chartBuilder->build($result);
        $synth = $this->synthesizer->synthesize($site, $question, $result, $confidence);

        $run = $this->ledger->recordSuccess($site, $userId, 'ask', $result, $synth['ai']);

        $answer = $synth['contract'];
        $answer['citation_refs'] = ["evidence_run:{$run->id}"];

        return [
            'answer' => $answer,
            'chart' => $chart,
            'evidence' => $result->toArray(),
            'evidence_run_id' => $run->id,
        ];
    }
}
