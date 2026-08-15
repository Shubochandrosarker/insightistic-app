<?php

namespace App\Query;

/**
 * Computes every derived (formula) metric from the base columns the
 * relevant compiler already fetched in one query — one WooCommerce
 * compiler (P1) and, as of P4, one each for GA4 (Ga4QueryCompiler) and GSC
 * (GscQueryCompiler). Formulas are hardcoded here — never evaluated from
 * MetricDefinition::$formula, which is a human-readable documentation
 * string only, not executable.
 *
 * Keep this in sync with app/Metrics/Catalog/{CommerceMetrics,
 * TrafficMetrics,SeoMetrics}.php: every derived metric's $dependsOn there
 * must have a case here, checked by
 * tests/Unit/Query/DerivedMetricCalculatorTest.php.
 */
class DerivedMetricCalculator
{
    /**
     * @param  array<string, int|float>  $base  Base metric id => value — from whichever source compiler resolved the plan (net_revenue/orders/... for woocommerce, ga4_sessions/ga4_conversions for ga4, organic_clicks/organic_impressions for gsc).
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
            'conversion_rate' => $this->ratio($base['ga4_conversions'] ?? 0, $base['ga4_sessions'] ?? 0),
            'organic_ctr' => $this->ratio($base['organic_clicks'] ?? 0, $base['organic_impressions'] ?? 0),
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
