<?php

namespace App\Query;

use App\Metrics\MetricRegistry;

/**
 * The only place a raw (e.g. AI-authored) plan array is turned into a
 * trusted SemanticQueryPlan. Every field is checked against an explicit
 * allowlist — the Semantic Metric Registry for metrics/dimensions, and a
 * hardcoded preset list here for periods. Nothing here ever accepts an
 * arbitrary string as a table, column, or SQL fragment.
 */
class PlanValidator
{
    public const MAX_METRICS = 5;

    public const MIN_LIMIT = 1;

    /** Ceiling before config('insightistic.query_engine.chart_max_rows') clamps further. */
    public const MAX_LIMIT = 100;

    public const DEFAULT_LIMIT = 10;

    /** Mirrors App\Support\DateRange::fromRequest()'s switch cases exactly. */
    private const ALLOWED_PERIOD_PRESETS = [
        'today', 'yesterday', 'last_7_days', 'last_30_days', 'this_month', 'last_month', 'custom',
    ];

    public function __construct(private readonly MetricRegistry $registry) {}

    public function validate(array $raw): SemanticQueryPlan
    {
        $metricIds = $this->validateMetrics($raw['metrics'] ?? null);
        $source = $this->registry->get($metricIds[0])->source;

        if ($source !== 'woocommerce') {
            throw QueryEngineException::unsupportedSource($source);
        }

        $dimension = $this->validateDimension($raw['dimension'] ?? null, $metricIds);
        $this->validateFilters($raw['filters'] ?? []);
        [$preset, $from, $to] = $this->validatePeriod($raw['period'] ?? []);
        $comparison = $this->validateComparison($raw['comparison'] ?? false, $dimension);
        $limit = $this->validateLimit($raw['limit'] ?? null, $dimension);

        return new SemanticQueryPlan(
            metricIds: $metricIds,
            source: $source,
            dimension: $dimension,
            periodPreset: $preset,
            periodFrom: $from,
            periodTo: $to,
            comparison: $comparison,
            limit: $limit,
        );
    }

    /** @return string[] */
    private function validateMetrics(mixed $metrics): array
    {
        if (! is_array($metrics) || $metrics === []) {
            throw QueryEngineException::emptyMetrics();
        }

        if (count($metrics) > self::MAX_METRICS) {
            throw QueryEngineException::tooManyMetrics(self::MAX_METRICS);
        }

        $ids = [];
        foreach ($metrics as $id) {
            if (! is_string($id) || ! $this->registry->has($id)) {
                throw QueryEngineException::unknownMetric(is_string($id) ? $id : '(non-string)');
            }
            $ids[] = $id;
        }

        $sources = array_unique(array_map(fn (string $id) => $this->registry->get($id)->source, $ids));
        if (count($sources) > 1) {
            throw QueryEngineException::mixedSources();
        }

        return array_values(array_unique($ids));
    }

    private function validateDimension(mixed $dimension, array $metricIds): ?string
    {
        if ($dimension === null) {
            return null;
        }

        if (! is_string($dimension) || $dimension === '') {
            throw QueryEngineException::disallowedDimension((string) $dimension, $metricIds[0]);
        }

        foreach ($metricIds as $id) {
            if (! in_array($dimension, $this->registry->get($id)->allowedDimensions, true)) {
                throw QueryEngineException::disallowedDimension($dimension, $id);
            }
        }

        return $dimension;
    }

    private function validateFilters(mixed $filters): void
    {
        if (! is_array($filters)) {
            throw QueryEngineException::disallowedFilter('(non-array)');
        }

        // No woocommerce metric declares a filter beyond date_range today
        // (already handled via period), so P1 accepts only an empty list.
        // The field stays in the wire schema so P4 can add real filters
        // without a breaking change to plan shape.
        if ($filters !== []) {
            $first = $filters[0]['field'] ?? $filters[0] ?? 'unknown';
            throw QueryEngineException::disallowedFilter(is_string($first) ? $first : 'unknown');
        }
    }

    /** @return array{0: string, 1: ?string, 2: ?string} [preset, from, to] */
    private function validatePeriod(mixed $period): array
    {
        if (! is_array($period)) {
            throw QueryEngineException::invalidPeriod('must be an object');
        }

        $preset = $period['preset'] ?? 'last_30_days';
        if (! is_string($preset) || ! in_array($preset, self::ALLOWED_PERIOD_PRESETS, true)) {
            throw QueryEngineException::invalidPeriod('unknown preset');
        }

        $from = $period['from'] ?? null;
        $to = $period['to'] ?? null;

        if ($preset === 'custom') {
            foreach (['from' => $from, 'to' => $to] as $label => $value) {
                if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                    throw QueryEngineException::invalidPeriod("custom period requires a valid \"{$label}\" (YYYY-MM-DD)");
                }
            }
        } else {
            $from = $to = null;
        }

        return [$preset, $from, $to];
    }

    private function validateComparison(mixed $comparison, ?string $dimension): bool
    {
        $comparison = (bool) $comparison;

        if ($comparison && $dimension !== null) {
            throw QueryEngineException::comparisonNotSupportedForBreakdown();
        }

        return $comparison;
    }

    private function validateLimit(mixed $limit, ?string $dimension): ?int
    {
        if ($dimension === null) {
            return null;
        }

        if ($limit === null) {
            return self::DEFAULT_LIMIT;
        }

        if (! is_int($limit) || $limit < self::MIN_LIMIT || $limit > self::MAX_LIMIT) {
            throw QueryEngineException::invalidLimit(self::MIN_LIMIT, self::MAX_LIMIT);
        }

        return $limit;
    }
}
