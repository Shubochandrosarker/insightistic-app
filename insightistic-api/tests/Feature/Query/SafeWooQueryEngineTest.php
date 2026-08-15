<?php

use App\Models\MetricSnapshot;
use App\Models\Organization;
use App\Models\Site;
use App\Query\QueryEngineException;
use App\Query\SafeWooQueryEngine;
use Illuminate\Support\Facades\DB;

/**
 * End-to-end tests for the full plan → validate → compile → guard → scope →
 * budget → execute → normalize pipeline, against real Postgres. Unit tests
 * cover each stage's logic in isolation; these prove the stages compose
 * correctly and, critically, that tenant scope holds (spec's #1 acceptance
 * gate: "Foreign organization/site ids cannot be used in plans or evidence
 * filters").
 *
 * Deliberately forced onto the SAME connection Eloquent seeds through
 * (falling back off the `readonly` connection — see beforeEach below):
 * RefreshDatabase wraps each test in an uncommitted transaction on the
 * default connection, and Postgres's read-committed isolation means a
 * genuinely separate connection/session (i.e. the real `readonly` role)
 * cannot see those uncommitted rows at all. The read-only role's actual
 * database-level permissions are proven independently and directly in
 * tests/Feature/Query/ReadOnlyIdentityTest.php, which doesn't depend on
 * seeing same-transaction data.
 */
beforeEach(function () {
    config(['database.connections.readonly.username' => null]);
});
function engineSite(Organization $org, string $name = 'Engine Shop'): Site
{
    return Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id,
        'name' => $name,
        'domain' => strtolower(str_replace(' ', '-', $name)).'-'.uniqid().'.example.com',
        'connection_status' => 'connected',
        'timezone' => 'UTC',
    ]);
}

function engineSnapshot(Site $site, string $date, array $attrs = []): void
{
    MetricSnapshot::create(array_merge([
        'site_id' => $site->id,
        'date' => $date,
        'revenue' => 1000,
        'orders' => 10,
        'refunds' => 1,
        'average_order_value' => 100,
        'new_customers' => 4,
        'returning_customers' => 6,
        'products_sold' => 20,
        'failed_orders' => 2,
    ], $attrs));
}

function engineOrderItem(Site $site, int $orderId, int $productId, array $orderAttrs = [], array $itemAttrs = []): void
{
    DB::table('wc_orders')->updateOrInsert(
        ['site_id' => $site->id, 'external_order_id' => $orderId],
        array_merge([
            'customer_id' => 1,
            'status' => 'completed',
            'currency' => 'USD',
            'total' => 100,
            'created_at_store' => now()->subDays(2),
            'created_at' => now(),
            'updated_at' => now(),
        ], $orderAttrs)
    );

    DB::table('wc_order_items')->insert(array_merge([
        'site_id' => $site->id,
        'external_order_id' => $orderId,
        'external_product_id' => $productId,
        'product_name' => "Product {$productId}",
        'sku' => "SKU-{$productId}",
        'quantity' => 1,
        'subtotal' => 100,
        'total' => 100,
        'created_at' => now(),
        'updated_at' => now(),
    ], $itemAttrs));
}

it('runs a site-level aggregate for base and derived metrics', function () {
    [, $org] = makeOrgUser('engine-agg@example.com');
    $site = engineSite($org);
    engineSnapshot($site, now()->subDay()->toDateString(), ['revenue' => 500, 'orders' => 5]);
    engineSnapshot($site, now()->toDateString(), ['revenue' => 500, 'orders' => 5]);

    $result = app(SafeWooQueryEngine::class)->run(
        ['metrics' => ['net_revenue', 'orders', 'aov'], 'period' => ['preset' => 'last_7_days']],
        $site,
        $org->id,
    );

    expect($result->metrics['net_revenue'])->toBe(1000.0)
        ->and($result->metrics['orders'])->toBe(10)
        ->and($result->metrics['aov'])->toBe(100.0)
        ->and($result->rows)->toBe([])
        ->and($result->rowCount)->toBe(1)
        ->and($result->queryHash)->toBeString()->not->toBeEmpty();
});

it('includes a previous-period comparison and deltas when requested', function () {
    [, $org] = makeOrgUser('engine-compare@example.com');
    $site = engineSite($org);
    // Current window (last 7 days).
    engineSnapshot($site, now()->toDateString(), ['revenue' => 200, 'orders' => 2]);
    // Previous window.
    engineSnapshot($site, now()->subDays(10)->toDateString(), ['revenue' => 100, 'orders' => 1]);

    $result = app(SafeWooQueryEngine::class)->run(
        ['metrics' => ['net_revenue'], 'period' => ['preset' => 'last_7_days'], 'comparison' => true],
        $site,
        $org->id,
    );

    expect($result->metrics['net_revenue'])->toBe(200.0)
        ->and($result->previous['net_revenue'])->toBe(100.0)
        ->and($result->deltas['net_revenue'])->toBe(100.0); // +100%
});

it('runs a product-dimension breakdown, scoped and ordered by revenue', function () {
    [, $org] = makeOrgUser('engine-breakdown@example.com');
    $site = engineSite($org);
    engineOrderItem($site, 1, 101, [], ['total' => 50]);
    engineOrderItem($site, 2, 102, [], ['total' => 150]);

    $result = app(SafeWooQueryEngine::class)->run(
        ['metrics' => ['product_revenue'], 'dimension' => 'product', 'period' => ['preset' => 'last_7_days']],
        $site,
        $org->id,
    );

    expect($result->rows)->toHaveCount(2)
        ->and($result->rows[0]['product_id'])->toBe(102)
        ->and($result->rows[0]['revenue'])->toBe(150.0)
        ->and($result->metrics)->toBe([]);
});

it('never returns another site\'s data for an aggregate query', function () {
    [, $org] = makeOrgUser('engine-isolation-a@example.com');
    [, $otherOrg] = makeOrgUser('engine-isolation-b@example.com');
    $site = engineSite($org, 'Mine');
    $otherSite = engineSite($otherOrg, 'Theirs');

    engineSnapshot($site, now()->toDateString(), ['revenue' => 111, 'orders' => 1]);
    engineSnapshot($otherSite, now()->toDateString(), ['revenue' => 999999, 'orders' => 999]);

    $result = app(SafeWooQueryEngine::class)->run(
        ['metrics' => ['net_revenue']],
        $site,
        $org->id,
    );

    expect($result->metrics['net_revenue'])->toBe(111.0);
});

it('never returns another site\'s rows for a product breakdown', function () {
    [, $org] = makeOrgUser('engine-isolation-c@example.com');
    [, $otherOrg] = makeOrgUser('engine-isolation-d@example.com');
    $site = engineSite($org, 'Mine2');
    $otherSite = engineSite($otherOrg, 'Theirs2');

    engineOrderItem($site, 10, 201, [], ['total' => 10]);
    engineOrderItem($otherSite, 20, 999, [], ['total' => 99999]);

    $result = app(SafeWooQueryEngine::class)->run(
        ['metrics' => ['product_revenue'], 'dimension' => 'product'],
        $site,
        $org->id,
    );

    expect($result->rows)->toHaveCount(1)
        ->and($result->rows[0]['product_id'])->toBe(201);
});

it('surfaces the metric\'s declared caveats in the result', function () {
    [, $org] = makeOrgUser('engine-caveats@example.com');
    $site = engineSite($org);
    engineSnapshot($site, now()->toDateString());

    $result = app(SafeWooQueryEngine::class)->run(['metrics' => ['refund_rate']], $site, $org->id);

    expect($result->caveats)->not->toBeEmpty();
});

it('propagates plan validation failures as QueryEngineException', function () {
    [, $org] = makeOrgUser('engine-invalid@example.com');
    $site = engineSite($org);

    app(SafeWooQueryEngine::class)->run(['metrics' => ['not_a_real_metric']], $site, $org->id);
})->throws(QueryEngineException::class);

it('releases its concurrency slot so a later sequential query still succeeds', function () {
    [, $org] = makeOrgUser('engine-concurrency@example.com');
    $site = engineSite($org);
    engineSnapshot($site, now()->toDateString());

    config(['insightistic.query_engine.max_concurrent_per_org' => 1]);
    $engine = app(SafeWooQueryEngine::class);

    $engine->run(['metrics' => ['net_revenue']], $site, $org->id); // slot released after this returns

    // A single sequential call after release must still succeed — proving
    // the limiter isn't stuck open. Concurrency rejection itself is unit-
    // tested directly against QueryConcurrencyLimiter (nested calls).
    $result = $engine->run(['metrics' => ['net_revenue']], $site, $org->id);
    expect($result->metrics['net_revenue'])->not->toBeNull();
});
