<?php

namespace App\Query;

/**
 * Computes every P1 derived (formula) metric from the base metric_snapshots
 * columns the compiler already fetched in one query. Formulas are hardcoded
 * here — never evaluated from MetricDefinition::$formula, which is a
 * human-readable documentation string only, not executable.
 *
 * Keep this in sync with app/Metrics/Catalog/CommerceMetrics.php: every
 * derived metric's $dependsOn there must have a case here, checked by
 * tests/Unit/Query/DerivedMetricCalculatorTest.php.
 */
class DerivedMetricCalculator
{
    /**
     * @param  array<string, int|float>  $base  Base metric id => value (net_revenue, orders, refund_count, failed_orders, products_sold, new_customers, returning_customers).
     */
    public function calculate(string $derivedId, array $base): ?float
    {
        return match ($derivedId) {
            'aov' => $this->ratio($base['net_revenue'] ?? 0, $base['orders'] ?? 0, zeroDefault: 0.0),
            'refund_rate' => $this->ratio($base['refund_count'] ?? 0, $base['orders'] ?? 0),
            'repeat_purchase_rate' => $this->ratio(
                $base['returning_customers'] ?? 0,
                ($base['new_customers'] ?? 0) + ($base['returning_customers'] ?? 0),
            ),
            default => throw new QueryEngineException('unknown_derived_metric', "No formula registered for derived metric \"{$derivedId}\"."),
        };
    }

    private function ratio(int|float $numerator, int|float $denominator, ?float $zeroDefault = null): ?float
    {
        if ($denominator <= 0) {
            return $zeroDefault;
        }

        return round($numerator / $denominator, 4);
    }
}
