<?php

namespace App\Query;

/**
 * A typed, already-validated analytical request (spec: Safe WooCommerce
 * Query Engine, P1). Nothing constructs this except `PlanValidator::validate()`
 * — there is no public constructor call site elsewhere in the app, so a
 * `SemanticQueryPlan` object existing at all is itself proof every field was
 * checked against the Semantic Metric Registry's allowlists.
 *
 * This is the boundary an AI-authored (or hand-authored) request must cross
 * before touching a database query: the caller may choose *which* metrics,
 * dimension and period from allowlisted values, but can never name a table,
 * column, or arbitrary SQL fragment directly.
 */
final class SemanticQueryPlan
{
    /**
     * @param  string[]  $metricIds  Registered metric ids, all from the same MetricDefinition::$source.
     * @param  string|null  $dimension  A single breakdown dimension (e.g. "product"), or null for a site-level aggregate.
     * @param  string  $periodPreset  One of App\Support\DateRange's presets ("last_7_days", "custom", ...).
     * @param  string|null  $periodFrom  Y-m-d, required when $periodPreset === 'custom'.
     * @param  string|null  $periodTo  Y-m-d, required when $periodPreset === 'custom'.
     * @param  bool  $comparison  Include the previous equal-length period. Only valid when $dimension is null.
     * @param  int|null  $limit  Row cap for a dimension breakdown; null when $dimension is null.
     */
    public function __construct(
        public readonly array $metricIds,
        public readonly string $source,
        public readonly ?string $dimension,
        public readonly string $periodPreset,
        public readonly ?string $periodFrom,
        public readonly ?string $periodTo,
        public readonly bool $comparison,
        public readonly ?int $limit,
    ) {}

    public function isBreakdown(): bool
    {
        return $this->dimension !== null;
    }

    public function toArray(): array
    {
        return [
            'metrics' => $this->metricIds,
            'source' => $this->source,
            'dimension' => $this->dimension,
            'period' => array_filter([
                'preset' => $this->periodPreset,
                'from' => $this->periodFrom,
                'to' => $this->periodTo,
            ], fn ($v) => $v !== null),
            'comparison' => $this->comparison,
            'limit' => $this->limit,
        ];
    }
}
