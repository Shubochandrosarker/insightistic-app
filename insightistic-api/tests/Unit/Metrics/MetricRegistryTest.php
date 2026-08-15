<?php

use App\Metrics\CommerceStatus;
use App\Metrics\MetricRegistry;
use App\Metrics\MetricRegistryException;
use App\Metrics\MetricSource;
use App\Services\Intelligence\AcquisitionIntelligence;
use App\Services\Intelligence\CustomerIntelligence;
use App\Services\Intelligence\ProductIntelligence;
use App\Services\MetricsAssembler;
use App\Services\MetricSnapshotService;

/**
 * Semantic Metric Registry (spec Phase 1) — pure unit tests, no database.
 * Covers: registry resolution/versioning, catalog internal consistency
 * (every dependsOn id actually exists, every derived metric declares a
 * formula), and the commerce-status-drift regression the audit flagged.
 */
function registry(): MetricRegistry
{
    return new MetricRegistry;
}

it('resolves every metric named in the product spec\'s starter list', function (string $id) {
    expect(registry()->has($id))->toBeTrue("Expected \"{$id}\" to be registered.");
})->with([
    'net_revenue', 'orders', 'aov', 'refund_rate', 'product_revenue',
    'new_customers', 'repeat_purchase_rate', 'organic_clicks',
    'organic_impressions', 'ga4_sessions', 'conversion_rate', 'lcp', 'inp', 'cls',
]);

it('throws for an unknown metric id', function () {
    registry()->get('made_up_metric');
})->throws(MetricRegistryException::class);

it('throws for an unknown version of a known metric', function () {
    registry()->get('net_revenue', 99);
})->throws(MetricRegistryException::class);

it('defaults to the latest version when none is given', function () {
    $registry = registry();
    $latest = $registry->latestVersion('orders');

    expect($registry->get('orders'))->toBe($registry->get('orders', $latest));
});

it('formats a stable versioned id', function () {
    $definition = registry()->get('net_revenue', 1);

    expect($definition->versionedId())->toBe('net_revenue@v1');
});

it('has() reports false for an unregistered metric without throwing', function () {
    expect(registry()->has('not_a_real_metric'))->toBeFalse();
});

it('groups metrics by source and covers all four sources', function () {
    $registry = registry();

    foreach (MetricSource::ALL as $source) {
        $metrics = $registry->forSource($source);
        expect($metrics)->not->toBeEmpty("Expected at least one metric for source \"{$source}\".");
        foreach ($metrics as $metric) {
            expect($metric->source)->toBe($source);
        }
    }
});

it('gives every derived metric a formula and a non-empty dependsOn, and every base metric neither', function () {
    foreach (registry()->all() as $metric) {
        if ($metric->isDerived()) {
            expect($metric->dependsOn)->not->toBeEmpty("Derived metric \"{$metric->id}\" must declare dependsOn.");
            expect($metric->formula)->not->toBeNull("Derived metric \"{$metric->id}\" must declare a formula.");
        } else {
            expect($metric->dependsOn)->toBeEmpty("Base metric \"{$metric->id}\" should not declare dependsOn.");
        }
    }
});

it('only references dependsOn ids that are themselves registered', function () {
    $registry = registry();

    foreach ($registry->all() as $metric) {
        foreach ($metric->dependsOn as $dependencyId) {
            expect($registry->has($dependencyId))
                ->toBeTrue("\"{$metric->id}\" depends on unregistered metric \"{$dependencyId}\".");
        }
    }
});

it('serializes every metric to a wire-safe array with no live values', function () {
    foreach (registry()->all() as $metric) {
        $array = $metric->toArray();

        expect($array)->toHaveKeys([
            'id', 'version', 'versioned_id', 'label', 'description', 'source',
            'aggregation', 'unit', 'table', 'column', 'freshness',
            'allowed_dimensions', 'allowed_filters', 'depends_on', 'caveats',
            'formula', 'is_derived',
        ]);
        expect($array['id'])->toBe($metric->id);
    }
});

it('rejects a source filter query for an unregistered source, never returns an empty catalog silently', function () {
    expect(registry()->forSource('not_a_real_source'))->toBe([]);
});

/*
|--------------------------------------------------------------------------
| Commerce status classification — drift regression
|--------------------------------------------------------------------------
| Before App\Metrics\CommerceStatus existed, five services each hand-typed
| their own paid/failed status array. These assertions lock in that (a) the
| REVENUE_PAID/CONFIRMED_PAID split is intentional and (b) the values every
| consumer resolves to did not change as part of the P0 consolidation.
*/

it('keeps MetricsAssembler\'s revenue status lists byte-identical to CommerceStatus::REVENUE_PAID/FAILED', function () {
    expect(MetricsAssembler::PAID_STATUSES)->toBe(CommerceStatus::REVENUE_PAID);
    expect(MetricsAssembler::FAILED_STATUSES)->toBe(CommerceStatus::FAILED);
});

it('keeps MetricSnapshotService reading the same canonical status lists via reflection', function () {
    $service = new MetricSnapshotService;
    $ref = new ReflectionClass($service);

    $paid = $ref->getProperty('paidStatuses');
    $paid->setAccessible(true);
    expect($paid->getValue($service))->toBe(CommerceStatus::REVENUE_PAID);

    $failed = $ref->getProperty('failedStatuses');
    $failed->setAccessible(true);
    expect($failed->getValue($service))->toBe(CommerceStatus::FAILED);
});

it('keeps the intelligence services on the narrower CONFIRMED_PAID definition (on-hold excluded)', function (string $class) {
    $ref = new ReflectionClass($class);
    $const = $ref->getConstant('PAID_STATUSES');

    expect($const)->toBe(CommerceStatus::CONFIRMED_PAID);
    expect($const)->not->toContain('on-hold');
})->with([CustomerIntelligence::class, ProductIntelligence::class]);

it('documents REVENUE_PAID and CONFIRMED_PAID as genuinely different sets, not a naming split of the same list', function () {
    expect(CommerceStatus::REVENUE_PAID)->toContain('on-hold');
    expect(CommerceStatus::CONFIRMED_PAID)->not->toContain('on-hold');
    expect(array_values(array_diff(CommerceStatus::REVENUE_PAID, CommerceStatus::CONFIRMED_PAID)))->toBe(['on-hold']);
});

it('has AcquisitionIntelligence read status from CommerceStatus, not an inline literal', function () {
    $source = file_get_contents((new ReflectionClass(AcquisitionIntelligence::class))->getFileName());

    expect($source)->not->toContain("['completed', 'processing']");
    expect($source)->toContain('CommerceStatus::CONFIRMED_PAID');
});
