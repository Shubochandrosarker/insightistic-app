<?php

use App\Metrics\MetricRegistry;
use App\Query\DerivedMetricCalculator;
use App\Query\QueryEngineException;

function calc(): DerivedMetricCalculator
{
    return new DerivedMetricCalculator;
}

it('computes aov as net_revenue / orders, zero when orders is 0', function () {
    expect(calc()->calculate('aov', ['net_revenue' => 1000.0, 'orders' => 4]))->toBe(250.0);
    expect(calc()->calculate('aov', ['net_revenue' => 0.0, 'orders' => 0]))->toBe(0.0);
});

it('computes refund_rate as refund_count / orders, null when orders is 0', function () {
    expect(calc()->calculate('refund_rate', ['refund_count' => 3, 'orders' => 30]))->toBe(0.1);
    expect(calc()->calculate('refund_rate', ['refund_count' => 0, 'orders' => 0]))->toBeNull();
});

it('computes repeat_purchase_rate as returning / (new + returning), null when both are 0', function () {
    expect(calc()->calculate('repeat_purchase_rate', ['new_customers' => 3, 'returning_customers' => 7]))->toBe(0.7);
    expect(calc()->calculate('repeat_purchase_rate', ['new_customers' => 0, 'returning_customers' => 0]))->toBeNull();
});

it('throws for a metric id it has no formula for', function () {
    calc()->calculate('not_a_derived_metric', []);
})->throws(QueryEngineException::class);

it('has a formula for every woocommerce derived metric currently in the registry', function () {
    // Scoped to woocommerce: this calculator is P1's WooCommerce-only
    // adapter's — GA4/GSC derived metrics (e.g. conversion_rate,
    // organic_ctr) belong to source adapters P4 (Multi-source Analytics)
    // has not been built yet.
    $registry = new MetricRegistry;

    foreach ($registry->forSource('woocommerce') as $metric) {
        if (! $metric->isDerived()) {
            continue;
        }

        // Every dependsOn key present as a base value should not throw —
        // an uncaught exception here fails the test.
        $base = array_fill_keys($metric->dependsOn, 1);
        $value = calc()->calculate($metric->id, $base);
        expect($value)->not->toBeNull("Expected a non-null result for \"{$metric->id}\" with all-1 base values.");
    }
});
