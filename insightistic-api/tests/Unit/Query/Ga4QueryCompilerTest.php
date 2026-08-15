<?php

use App\Metrics\MetricDefinition;
use App\Metrics\MetricRegistry;
use App\Models\Site;
use App\Query\Adapters\Ga4QueryCompiler;
use App\Query\QueryEngineException;
use App\Query\ReadOnlySqlGuard;
use App\Support\DateRange;

function ga4Compiler(): Ga4QueryCompiler
{
    return new Ga4QueryCompiler(new MetricRegistry);
}

it('resolves conversion_rate to its base traffic_daily columns', function () {
    expect(ga4Compiler()->resolveBaseMetricIds(['ga4_sessions']))->toBe(['ga4_sessions']);
    expect(ga4Compiler()->resolveBaseMetricIds(['conversion_rate']))->toBe(['ga4_conversions', 'ga4_sessions']);
});

it('deduplicates overlapping base dependencies', function () {
    $base = ga4Compiler()->resolveBaseMetricIds(['conversion_rate', 'ga4_sessions']);
    sort($base);

    expect($base)->toBe(['ga4_conversions', 'ga4_sessions']);
});

it('compiles a golden aggregate SELECT restricted to traffic_daily, scoped to source = ga4', function () {
    $connection = DB::connection();
    $site = new Site;
    $site->id = 42;
    $range = DateRange::fromRequest('custom', '2026-01-01', '2026-01-31', 'UTC');

    $builder = ga4Compiler()->compileAggregate($connection, $site, $range, ['ga4_sessions', 'ga4_conversions']);

    expect($builder->toSql())->toBe(
        'select COALESCE(SUM(sessions),0) as sessions, COALESCE(SUM(conversions),0) as conversions from "traffic_daily" where "site_id" = ? and "source" = ? and "date" between ? and ?'
    );
    expect($builder->getBindings())->toBe([42, 'ga4', '2026-01-01', '2026-01-31']);

    (new ReadOnlySqlGuard)->assertSafe($builder->toSql(), Ga4QueryCompiler::ALLOWED_TABLES);
});

it('throws rather than silently compiling a derived metric whose dependency this adapter cannot resolve', function () {
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
                    source: 'ga4',
                    aggregation: 'derived',
                    unit: 'count',
                    table: 'traffic_daily',
                    column: null,
                    freshness: 'daily',
                    dependsOn: ['not_a_base_metric'],
                ),
            ]);
        }
    };

    (new Ga4QueryCompiler($registry))->resolveBaseMetricIds(['weird_derived']);
})->throws(QueryEngineException::class);
