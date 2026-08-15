<?php

namespace App\Metrics;

/**
 * A single, versioned, canonical metric definition (spec: Semantic Metric
 * Registry). This is the ONE place a metric's meaning, source, allowed
 * shape, and freshness contract are declared — the dashboard, Copilot,
 * client reports and future automations all read the same object instead
 * of re-deriving the definition in each surface.
 *
 * Immutable and dependency-free on purpose: this class describes a metric,
 * it does not compute one. Computation stays in the existing services
 * (MetricsAssembler, MetricSnapshotService, ContextAssembler, ...) so this
 * registry can be introduced without moving or risking working query code.
 * A future typed query planner can use `table`/`column`/`aggregation` as
 * the allowlist it is restricted to.
 */
final class MetricDefinition
{
    /**
     * @param  string  $id  Stable snake_case identifier, e.g. "net_revenue". Never reused for a different meaning — bump $version instead.
     * @param  int  $version  Starts at 1. Increment when the formula, source, or unit changes in a way that would make historical comparisons misleading.
     * @param  string  $label  Short human-readable name for UI.
     * @param  string  $description  One or two sentences explaining what the metric means and how it's computed, in plain language.
     * @param  string  $source  One of MetricSource::* — which connected data source this metric is derived from.
     * @param  string  $aggregation  One of MetricAggregation::* — how raw rows become this metric's value.
     * @param  string  $unit  One of MetricUnit::* — display/formatting contract.
     * @param  string  $table  Canonical table this metric reads from (or is derived from). Used as the read-allowlist by the future safe query engine — never a place for AI- or user-supplied table names.
     * @param  string|null  $column  Canonical column within $table this metric aggregates, when applicable (null for count-only or derived metrics).
     * @param  string  $freshness  One of MetricFreshness::* — how stale this metric's underlying sync is allowed to be before confidence should drop.
     * @param  string[]  $allowedDimensions  Dimension identifiers this metric may be broken down by (e.g. "product", "date"). Empty means site/date-range only.
     * @param  string[]  $allowedFilters  Filter identifiers a query plan may apply to this metric (e.g. "date_range", "status"). Never arbitrary — always resolved against this allowlist.
     * @param  string[]  $dependsOn  Other metric ids this metric's formula is derived from (e.g. "aov" depends on "net_revenue" and "orders"). Empty for base metrics.
     * @param  string[]  $caveats  Known simplifications/limitations a consumer (dashboard, Copilot, report) MUST surface alongside the value — never silently hide them.
     * @param  string|null  $formula  Human-readable formula for derived metrics (e.g. "net_revenue / orders"). Null for base (non-derived) metrics.
     */
    public function __construct(
        public readonly string $id,
        public readonly int $version,
        public readonly string $label,
        public readonly string $description,
        public readonly string $source,
        public readonly string $aggregation,
        public readonly string $unit,
        public readonly string $table,
        public readonly ?string $column,
        public readonly string $freshness,
        public readonly array $allowedDimensions = [],
        public readonly array $allowedFilters = [],
        public readonly array $dependsOn = [],
        public readonly array $caveats = [],
        public readonly ?string $formula = null,
    ) {}

    /** Stable "id@vN" identifier, e.g. "net_revenue@v1". Used in evidence-ledger rows and saved-insight definitions so a re-run can prove which metric version produced a value. */
    public function versionedId(): string
    {
        return "{$this->id}@v{$this->version}";
    }

    public function isDerived(): bool
    {
        return $this->dependsOn !== [];
    }

    /** Wire format for API responses / evidence ledger persistence. Never includes anything beyond the declared metadata — no live values. */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'version' => $this->version,
            'versioned_id' => $this->versionedId(),
            'label' => $this->label,
            'description' => $this->description,
            'source' => $this->source,
            'aggregation' => $this->aggregation,
            'unit' => $this->unit,
            'table' => $this->table,
            'column' => $this->column,
            'freshness' => $this->freshness,
            'allowed_dimensions' => $this->allowedDimensions,
            'allowed_filters' => $this->allowedFilters,
            'depends_on' => $this->dependsOn,
            'caveats' => $this->caveats,
            'formula' => $this->formula,
            'is_derived' => $this->isDerived(),
        ];
    }
}
