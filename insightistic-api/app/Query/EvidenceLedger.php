<?php

namespace App\Query;

use App\Metrics\MetricRegistry;
use App\Models\EvidenceRun;
use App\Models\Site;

/**
 * Persists every Safe Query Engine execution — success or failure — as one
 * EvidenceRun (spec: Analytical Evidence Ledger, P2). Never invoked by the
 * engine itself (SafeWooQueryEngine stays a pure computation with no
 * storage side effect, per P1's documented boundary); callers
 * (AnalyticsQueryController, AskInsightisticService, SavedInsightController)
 * record explicitly, right after `run()` succeeds or throws.
 */
class EvidenceLedger
{
    public function __construct(private readonly MetricRegistry $registry)
    {
    }

    /**
     * @param  array{used: bool, model: ?string}|null  $ai  Set by P3 Ask runs that invoked an AI provider (plan generation and/or synthesis) — null for a plain query-engine run.
     */
    public function recordSuccess(Site $site, ?int $userId, string $runType, QueryResult $result, ?array $ai = null): EvidenceRun
    {
        return EvidenceRun::create([
            // Explicit, not relying on BelongsToOrganization's creating()
            // auto-fill: this service may run outside an HTTP-request-bound
            // Tenancy (a queued job, a console command), so the org id it
            // stamps must always come from $site, never from ambient state.
            'organization_id' => $site->organization_id,
            'site_id' => $site->id,
            'requested_by_user_id' => $userId,
            'source' => $result->plan->source,
            'run_type' => $runType,
            'validator_version' => PlanValidator::CONTRACT_VERSION,
            'semantic_plan_json' => $result->plan->toArray(),
            'metric_versions_json' => $this->metricVersionsFor($result->plan->metricIds),
            'query_hash' => $result->queryHash,
            'row_count' => $result->rowCount,
            'duration_ms' => $result->durationMs,
            'read_only_identity_active' => $result->readOnlyIdentityActive,
            'freshness_json' => DataFreshness::forSite($site),
            'status' => 'success',
            'ai_used' => (bool) ($ai['used'] ?? false),
            'ai_model' => $ai['model'] ?? null,
        ]);
    }

    public function recordFailure(Site $site, ?int $userId, string $runType, array $rawPlan, QueryEngineException $e): EvidenceRun
    {
        return EvidenceRun::create([
            'organization_id' => $site->organization_id,
            'site_id' => $site->id,
            'requested_by_user_id' => $userId,
            'source' => is_string($rawPlan['source'] ?? null) ? $rawPlan['source'] : 'unknown',
            'run_type' => $runType,
            'validator_version' => PlanValidator::CONTRACT_VERSION,
            'semantic_plan_json' => $rawPlan,
            'metric_versions_json' => [],
            'status' => 'error',
            'error_code' => $e->errorCode,
            // QueryEngineException messages are always the safe, public-facing
            // text defined in that class — never a raw SQL fragment or trace.
            'error_message' => $e->getMessage(),
        ]);
    }

    /**
     * @return array<string, int> metric id => version actually resolved right now.
     *
     * Public: also used by SavedInsightController (P5) to freeze a saved
     * insight's metric versions at save time, the same way an EvidenceRun
     * freezes them at execution time — both need the identical "what did
     * this id resolve to right now" snapshot.
     */
    public function metricVersionsFor(array $metricIds): array
    {
        $versions = [];
        foreach ($metricIds as $id) {
            if ($this->registry->has($id)) {
                $versions[$id] = $this->registry->get($id)->version;
            }
        }

        return $versions;
    }
}
