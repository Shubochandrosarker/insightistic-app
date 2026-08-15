<?php

use App\Metrics\MetricRegistry;
use App\Query\PlanValidator;
use App\Query\QueryEngineException;
use App\Query\SemanticQueryPlan;

function planValidator(): PlanValidator
{
    return new PlanValidator(new MetricRegistry);
}

it('accepts a minimal valid plan and fills in defaults', function () {
    $plan = planValidator()->validate(['metrics' => ['net_revenue']]);

    expect($plan)->toBeInstanceOf(SemanticQueryPlan::class)
        ->and($plan->metricIds)->toBe(['net_revenue'])
        ->and($plan->source)->toBe('woocommerce')
        ->and($plan->dimension)->toBeNull()
        ->and($plan->periodPreset)->toBe('last_30_days')
        ->and($plan->comparison)->toBeFalse()
        ->and($plan->limit)->toBeNull();
});

it('rejects an empty or missing metrics list', function (array $raw) {
    planValidator()->validate($raw);
})->throws(QueryEngineException::class)->with([
    [['metrics' => []]],
    [[]],
    [['metrics' => 'net_revenue']],
]);

it('rejects more than the max metric count', function () {
    planValidator()->validate(['metrics' => ['net_revenue', 'orders', 'aov', 'refund_count', 'failed_orders', 'products_sold']]);
})->throws(QueryEngineException::class);

it('rejects an unknown metric id, including an injection-shaped one', function (string $id) {
    planValidator()->validate(['metrics' => [$id]]);
})->throws(QueryEngineException::class)->with([
    'not_a_real_metric',
    'net_revenue; DROP TABLE wc_orders;--',
    "net_revenue' OR '1'='1",
    'wc_orders.total',
    '../../etc/passwd',
]);

it('rejects mixing metrics from different sources', function () {
    planValidator()->validate(['metrics' => ['net_revenue', 'ga4_sessions']]);
})->throws(QueryEngineException::class);

it('accepts ga4 and gsc sources (P4 Multi-source Analytics)', function () {
    expect(planValidator()->validate(['metrics' => ['ga4_sessions']])->source)->toBe('ga4');
    expect(planValidator()->validate(['metrics' => ['organic_clicks']])->source)->toBe('gsc');
});

it('still rejects pagespeed — a "latest run" shape this engine does not compile', function () {
    planValidator()->validate(['metrics' => ['lcp']]);
})->throws(QueryEngineException::class);

it('accepts the product dimension only for a metric that declares it', function () {
    $plan = planValidator()->validate(['metrics' => ['product_revenue'], 'dimension' => 'product']);

    expect($plan->dimension)->toBe('product')
        ->and($plan->limit)->toBe(PlanValidator::DEFAULT_LIMIT);
});

it('rejects a dimension not declared by every requested metric', function () {
    planValidator()->validate(['metrics' => ['net_revenue'], 'dimension' => 'product']);
})->throws(QueryEngineException::class);

it('rejects any non-empty filters list in P1', function () {
    planValidator()->validate(['metrics' => ['net_revenue'], 'filters' => [['field' => 'status', 'op' => '=', 'value' => 'completed']]]);
})->throws(QueryEngineException::class);

it('rejects an unknown period preset', function () {
    planValidator()->validate(['metrics' => ['net_revenue'], 'period' => ['preset' => 'last_decade']]);
})->throws(QueryEngineException::class);

it('requires from/to for a custom period and validates their shape', function (?string $from, ?string $to) {
    planValidator()->validate(['metrics' => ['net_revenue'], 'period' => ['preset' => 'custom', 'from' => $from, 'to' => $to]]);
})->throws(QueryEngineException::class)->with([
    [null, '2026-01-31'],
    ['2026-01-01', null],
    ["2026-01-01'; DROP TABLE wc_orders;--", '2026-01-31'],
    ['not-a-date', '2026-01-31'],
]);

it('accepts a well-formed custom period', function () {
    $plan = planValidator()->validate(['metrics' => ['net_revenue'], 'period' => ['preset' => 'custom', 'from' => '2026-01-01', 'to' => '2026-01-31']]);

    expect($plan->periodFrom)->toBe('2026-01-01')->and($plan->periodTo)->toBe('2026-01-31');
});

it('rejects comparison combined with a dimension breakdown', function () {
    planValidator()->validate(['metrics' => ['product_revenue'], 'dimension' => 'product', 'comparison' => true]);
})->throws(QueryEngineException::class);

it('rejects a limit outside the allowed range', function (int $limit) {
    planValidator()->validate(['metrics' => ['product_revenue'], 'dimension' => 'product', 'limit' => $limit]);
})->throws(QueryEngineException::class)->with([0, -1, 101, 100000]);

it('rejects a limit when there is no dimension breakdown', function () {
    // limit is silently ignored (not an error) when there's no dimension —
    // aggregate plans always return exactly one row regardless.
    $plan = planValidator()->validate(['metrics' => ['net_revenue'], 'limit' => 5]);

    expect($plan->limit)->toBeNull();
});
