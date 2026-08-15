<?php

namespace App\Services\Ask;

use App\Metrics\MetricRegistry;
use App\Query\QueryResult;
use App\Query\SemanticQueryPlan;

/**
 * Builds a small declarative chart spec from an already-computed
 * QueryResult (spec: Ask Insightistic V2, P3). Deliberately not a
 * general-purpose charting DSL — two shapes only, matching the two shapes
 * SafeWooQueryEngine can ever return:
 *
 *   - "kpi"  : one or more single-number cards, for a site-level aggregate.
 *   - "bar"  : a labeled bar per dimension row, for a breakdown.
 *
 * Every number in the spec is copied from QueryResult — this class computes
 * nothing and invents nothing; it only reshapes evidence that already
 * passed through the query engine into something a chart component can
 * render directly.
 */
class ChartSpecBuilder
{
    public function __construct(private readonly MetricRegistry $registry) {}

    public function build(QueryResult $result): array
    {
        if ($result->plan->isBreakdown()) {
            return $this->bar($result);
        }

        return $this->kpi($result);
    }

    /**
     * The chart TYPE a plan will produce, with no data at all — just
     * `{"type": "kpi"}` or `{"type": "bar", "dimension": "product"}`. Used
     * by Saved Insights (P5) to record a display hint at save time without
     * executing the plan: a plan's shape (aggregate vs breakdown) is a pure
     * function of `SemanticQueryPlan::isBreakdown()`/`$dimension`, so this
     * needs no query result and can never go stale the way cached numbers
     * would the moment the underlying data changes.
     */
    public function typeFor(SemanticQueryPlan $plan): array
    {
        return $plan->isBreakdown()
            ? ['type' => 'bar', 'dimension' => (string) $plan->dimension]
            : ['type' => 'kpi'];
    }

    private function kpi(QueryResult $result): array
    {
        $items = [];
        foreach ($result->metrics as $id => $value) {
            $definition = $this->registry->get($id);
            $items[] = [
                'metric_id' => $id,
                'label' => $definition->label,
                'unit' => $definition->unit,
                'value' => $value,
                'previous_value' => $result->previous[$id] ?? null,
                'delta_pct' => $result->deltas[$id] ?? null,
            ];
        }

        return [
            'type' => 'kpi',
            'items' => $items,
        ];
    }

    private function bar(QueryResult $result): array
    {
        $dimension = (string) $result->plan->dimension;

        return [
            'type' => 'bar',
            'dimension' => $dimension,
            'x_field' => 'label',
            'y_field' => 'value',
            'items' => array_map(fn (array $row) => [
                'label' => $row['name'] ?? ('#' . ($row['product_id'] ?? '')),
                'value' => $row['revenue'] ?? null,
                'secondary' => $row['units'] ?? null,
            ], $result->rows),
            'truncated' => $result->truncated,
        ];
    }
}
