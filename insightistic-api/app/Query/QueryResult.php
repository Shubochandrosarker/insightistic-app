<?php

namespace App\Query;

use App\Support\DateRange;

/**
 * Normalized evidence returned by SafeWooQueryEngine — the "execution →
 * normalized evidence" step of the pipeline. This does not persist
 * anything; P2 (Analytical Evidence Ledger) is expected to store this
 * shape (or close to it) alongside org/site/run/query-hash metadata.
 *
 * Deliberately does NOT include a confidence score, narrative explanation,
 * or citations — those are P3 (Ask Insightistic V2)'s job, computed from
 * evidence like this plus source availability/coverage, not something a
 * single query result can determine on its own.
 */
final class QueryResult
{
    /**
     * @param  array<string, int|float|null>  $metrics  metric id => value, for an aggregate (non-breakdown) plan.
     * @param  array<string, int|float|null>|null  $previous  Same shape as $metrics, for the prior equal-length period, when the plan requested a comparison.
     * @param  array<string, float|null>|null  $deltas  metric id => percentage change vs $previous, null when no comparison was requested.
     * @param  array<int, array<string, mixed>>  $rows  Dimension-breakdown rows (e.g. per product), empty for an aggregate plan.
     * @param  string[]  $caveats  Union of every involved metric's declared caveats — always surfaced, never dropped.
     */
    public function __construct(
        public readonly string $queryHash,
        public readonly SemanticQueryPlan $plan,
        public readonly DateRange $range,
        public readonly array $metrics,
        public readonly ?array $previous,
        public readonly ?array $deltas,
        public readonly array $rows,
        public readonly int $rowCount,
        public readonly bool $truncated,
        public readonly float $durationMs,
        public readonly bool $readOnlyIdentityActive,
        public readonly array $caveats,
    ) {}

    public function toArray(): array
    {
        return [
            'plan' => $this->plan->toArray(),
            'period' => [
                'label' => $this->range->label,
                'from' => $this->range->dateFrom(),
                'to' => $this->range->dateTo(),
                'timezone' => $this->range->tz,
            ],
            'metrics' => $this->metrics,
            'previous' => $this->previous,
            'deltas' => $this->deltas,
            'rows' => $this->rows,
            'caveats' => $this->caveats,
            'query' => [
                'hash' => $this->queryHash,
                'duration_ms' => $this->durationMs,
                'row_count' => $this->rowCount,
                'truncated' => $this->truncated,
                'read_only_identity_active' => $this->readOnlyIdentityActive,
            ],
        ];
    }
}
