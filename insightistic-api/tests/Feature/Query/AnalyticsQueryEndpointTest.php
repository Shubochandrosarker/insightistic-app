<?php

use App\Models\MetricSnapshot;
use App\Models\Organization;
use App\Models\Site;

// See SafeWooQueryEngineTest.php's docblock: RefreshDatabase's uncommitted
// per-test transaction is invisible to a genuinely separate `readonly`
// connection, so these functional tests fall back to the default one.
beforeEach(function () {
    config(['database.connections.readonly.username' => null]);
});

function endpointSite(Organization $org): Site
{
    return Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id,
        'name' => 'Endpoint Shop',
        'domain' => 'endpoint-'.uniqid().'.example.com',
        'connection_status' => 'connected',
        'timezone' => 'UTC',
    ]);
}

it('requires authentication', function () {
    [, $org] = makeOrgUser('query-ep-auth@example.com');
    $site = endpointSite($org);

    $this->postJson("/api/sites/{$site->id}/analytics/query", ['plan' => ['metrics' => ['net_revenue']]])
        ->assertUnauthorized();
});

it('returns normalized evidence for a valid plan', function () {
    [$user, $org] = makeOrgUser('query-ep-ok@example.com');
    $site = endpointSite($org);
    MetricSnapshot::create([
        'site_id' => $site->id, 'date' => now()->toDateString(),
        'revenue' => 250, 'orders' => 5, 'refunds' => 0, 'average_order_value' => 50,
        'new_customers' => 2, 'returning_customers' => 3, 'products_sold' => 8, 'failed_orders' => 0,
    ]);

    $body = $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/analytics/query", ['plan' => ['metrics' => ['net_revenue', 'orders']]])
        ->assertOk()
        ->json();

    // toEqual (==), not toBe (===): JSON has no distinct int/float type, so a
    // whole-number float like 250.0 decodes back as PHP int 250 — expected,
    // not a bug (the engine-level test below asserts the real float type).
    expect($body['metrics']['net_revenue'])->toEqual(250.0)
        ->and($body['metrics']['orders'])->toBe(5)
        ->and($body['query']['hash'])->toBeString()
        ->and($body)->toHaveKey('period');
});

it('maps plan validation errors to 422 with a stable error code', function () {
    [$user, $org] = makeOrgUser('query-ep-422@example.com');
    $site = endpointSite($org);

    $body = $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/analytics/query", ['plan' => ['metrics' => ['not_a_real_metric']]])
        ->assertStatus(422)
        ->json();

    expect($body['code'])->toBe('unknown_metric');
});

it('rejects a non-object plan without leaking an exception trace', function () {
    [$user, $org] = makeOrgUser('query-ep-shape@example.com');
    $site = endpointSite($org);

    $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/analytics/query", ['plan' => 'not-an-object'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'invalid_plan');
});

it('404s for a site outside the caller\'s organization (site.access, not query-engine logic)', function () {
    [$user] = makeOrgUser('query-ep-scope-a@example.com');
    [, $otherOrg] = makeOrgUser('query-ep-scope-b@example.com');
    $otherSite = endpointSite($otherOrg);

    $this->actingAs($user)
        ->postJson("/api/sites/{$otherSite->id}/analytics/query", ['plan' => ['metrics' => ['net_revenue']]])
        ->assertNotFound();
});

it('defaults plan to an empty array so a missing body fails validation, not a 500', function () {
    [$user, $org] = makeOrgUser('query-ep-missing@example.com');
    $site = endpointSite($org);

    $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/analytics/query", [])
        ->assertStatus(422)
        ->assertJsonPath('code', 'empty_metrics');
});
