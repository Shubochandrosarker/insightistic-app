<?php

/**
 * Semantic Metric Registry HTTP surface (spec Phase 1). The registry itself
 * is unit-tested (tests/Unit/Metrics/MetricRegistryTest.php); this file only
 * covers the controller/route contract: auth, filtering, 404/422 shape.
 */
it('requires authentication', function () {
    $this->getJson('/api/metrics')->assertUnauthorized();
});

it('lists the full catalog for any authenticated tenant user, regardless of site access', function () {
    [$user] = makeOrgUser('metrics-reader@example.com');

    $body = $this->actingAs($user)->getJson('/api/metrics')->assertOk()->json();

    expect($body['metrics'])->not->toBeEmpty();
    expect($body['sources'])->toBe(['woocommerce', 'ga4', 'gsc', 'pagespeed']);

    $ids = array_column($body['metrics'], 'id');
    expect($ids)->toContain('net_revenue', 'aov', 'lcp');

    $netRevenue = collect($body['metrics'])->firstWhere('id', 'net_revenue');
    expect($netRevenue['version'])->toBe(1);
    expect($netRevenue['versioned_id'])->toBe('net_revenue@v1');
    expect($netRevenue)->not->toHaveKey('value'); // metadata only, never a live value
});

it('filters by source', function () {
    [$user] = makeOrgUser('metrics-source@example.com');

    $body = $this->actingAs($user)->getJson('/api/metrics?source=woocommerce')->assertOk()->json();

    foreach ($body['metrics'] as $metric) {
        expect($metric['source'])->toBe('woocommerce');
    }
});

it('rejects an unknown source filter with 422, not a silently empty list', function () {
    [$user] = makeOrgUser('metrics-bad-source@example.com');

    $this->actingAs($user)
        ->getJson('/api/metrics?source=not_real')
        ->assertStatus(422);
});

it('shows a single metric with its full version history', function () {
    [$user] = makeOrgUser('metrics-show@example.com');

    $body = $this->actingAs($user)->getJson('/api/metrics/aov')->assertOk()->json();

    expect($body['metric']['id'])->toBe('aov');
    expect($body['metric']['formula'])->toBe('net_revenue / orders');
    expect($body['versions'])->toHaveCount(1);
});

it('404s for an unknown metric id', function () {
    [$user] = makeOrgUser('metrics-404@example.com');

    $this->actingAs($user)->getJson('/api/metrics/not_a_real_metric')->assertNotFound();
});
