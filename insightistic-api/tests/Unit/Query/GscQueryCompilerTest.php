<?php

use App\Metrics\MetricDefinition;
use App\Metrics\MetricRegistry;
use App\Models\Site;
use App\Query\Adapters\GscQueryCompiler;
use App\Query\QueryEngineException;
use App\Query\ReadOnlySqlGuard;
use App\Support\DateRange;

function gscCompiler(): GscQueryCompiler
{
    return new GscQueryCompiler(new MetricRegistry);
}

it('resolves organic_ctr to its base seo_daily columns', function () {
    expect(gscCompiler()->resolveBaseMetricIds(['organic_clicks']))->toBe(['organic_clicks']);
    expect(gscCompiler()->resolveBaseMetricIds(['organic_ctr']))->toBe(['organic_clicks', 'organic_impressions']);
});

it('resolves organic_avg_position as its own base column, not a derived one', function () {
    expect(gscCompiler()->resolveBaseMetricIds(['organic_avg_position']))->toBe(['organic_avg_position']);
});

it('compiles a golden aggregate SELECT restricted to seo_daily, mixing SUM and AVG aggregation', function () {
    $connection = DB::connection();
    $site = new Site;
    $site->id = 7;
    $range = DateRange::fromRequest('custom', '2026-01-01', '2026-01-31', 'UTC');

    $builder = gscCompiler()->compileAggregate(
        $connection, $site, $range, ['organic_clicks', 'organic_impressions', 'organic_avg_position']
    );

    expect($builder->toSql())->toBe(
        'select COALESCE(SUM(clicks),0) as clicks, COALESCE(SUM(impressions),0) as impressions, AVG(avg_position) as avg_position from "seo_daily" where "site_id" = ? and "date" between ? and ?'
    );
    expect($builder->getBindings())->toBe([7, '2026-01-01', '2026-01-31']);

    (new ReadOnlySqlGuard)->assertSafe($builder->toSql(), GscQueryCompiler::ALLOWED_TABLES);
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
                    source: 'gsc',
                    aggregation: 'derived',
                    unit: 'count',
                    table: 'seo_daily',
                    column: null,
                    freshness: 'daily',
                    dependsOn: ['not_a_base_metric'],
                ),
            ]);
        }
    };

    (new GscQueryCompiler($registry))->resolveBaseMetricIds(['weird_derived']);
})->throws(QueryEngineException::class);
