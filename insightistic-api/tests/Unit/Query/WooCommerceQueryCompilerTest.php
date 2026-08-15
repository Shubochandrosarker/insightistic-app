<?php

use App\Metrics\MetricDefinition;
use App\Metrics\MetricRegistry;
use App\Models\Site;
use App\Query\Adapters\WooCommerceQueryCompiler;
use App\Query\QueryEngineException;
use App\Query\ReadOnlySqlGuard;
use App\Support\DateRange;

function compiler(): WooCommerceQueryCompiler
{
    return new WooCommerceQueryCompiler(new MetricRegistry);
}

it('resolves derived metrics to their base metric_snapshots columns', function () {
    expect(compiler()->resolveBaseMetricIds(['net_revenue']))->toBe(['net_revenue']);
    expect(compiler()->resolveBaseMetricIds(['aov']))->toBe(['net_revenue', 'orders']);
    expect(compiler()->resolveBaseMetricIds(['refund_rate']))->toBe(['refund_count', 'orders']);
    expect(compiler()->resolveBaseMetricIds(['repeat_purchase_rate']))->toBe(['returning_customers', 'new_customers']);
});

it('deduplicates overlapping base dependencies across multiple requested metrics', function () {
    $base = compiler()->resolveBaseMetricIds(['aov', 'orders', 'refund_rate']);

    // net_revenue, orders (shared by aov+refund_rate, requested directly too), refund_count
    sort($base);
    expect($base)->toBe(['net_revenue', 'orders', 'refund_count']);
});

it('compiles a golden aggregate SELECT restricted to metric_snapshots for a known site/range', function () {
    $connection = DB::connection();
    $site = new Site;
    $site->id = 42;
    $range = DateRange::fromRequest('custom', '2026-01-01', '2026-01-31', 'UTC');

    $builder = compiler()->compileAggregate($connection, $site, $range, ['net_revenue', 'orders']);

    expect($builder->toSql())->toBe(
        'select COALESCE(SUM(revenue),0) as revenue, COALESCE(SUM(orders),0) as orders from "metric_snapshots" where "site_id" = ? and "date" between ? and ?'
    );
    expect($builder->getBindings())->toBe([42, '2026-01-01', '2026-01-31']);

    // Every compiled query must pass its own adapter's guard against its own allowlist.
    (new ReadOnlySqlGuard)->assertSafe($builder->toSql(), WooCommerceQueryCompiler::ALLOWED_TABLES);
});

it('compiles a golden product-breakdown SELECT joined and scoped to one site', function () {
    $connection = DB::connection();
    $site = new Site;
    $site->id = 7;
    $range = DateRange::fromRequest('custom', '2026-01-01', '2026-01-31', 'UTC');

    $builder = compiler()->compileProductBreakdown($connection, $site, $range, 10);
    $sql = $builder->toSql();

    expect($sql)->toContain('from "wc_order_items" as "oi"')
        ->toContain('inner join "wc_orders" as "o"')
        ->toContain('group by "oi"."external_product_id"')
        ->toContain('order by "revenue" desc')
        ->toContain('limit 10');

    (new ReadOnlySqlGuard)->assertSafe($sql, WooCommerceQueryCompiler::ALLOWED_TABLES);
});

it('throws rather than silently compiling a metric it has no column mapping for', function () {
    // A hypothetical future derived metric whose dependsOn points somewhere
    // this adapter doesn't know how to compile — must fail loudly, not
    // silently emit a wrong or empty query.
    $registry = new class extends MetricRegistry
    {
        public function __construct()
        {
            parent::__construct([
                new MetricDefinition(
                    id: 'weird_derived',
                    version: 1,
                    label: 'Weird',
                    description: 'x',
                    source: 'woocommerce',
                    aggregation: 'derived',
                    unit: 'count',
                    table: 'metric_snapshots',
                    column: null,
                    freshness: 'daily',
                    dependsOn: ['not_a_base_metric'],
                ),
            ]);
        }
    };

    (new WooCommerceQueryCompiler($registry))->resolveBaseMetricIds(['weird_derived']);
})->throws(QueryEngineException::class);
