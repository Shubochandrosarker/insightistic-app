<?php

use App\Models\MetricSnapshot;
use App\Models\Organization;
use App\Models\Site;

function evSite(Organization $org): Site
{
    return Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id,
        'name' => 'Evidence Shop',
        'domain' => 'evidence-' . uniqid() . '.example.com',
        'connection_status' => 'connected',
        'timezone' => 'UTC',
    ]);
}

beforeEach(function () {
    config(['database.connections.readonly.username' => null]);
});

it('records an evidence run and returns its id from the query endpoint', function () {
    [$user, $org] = makeOrgUser('ev-ep-record@example.com');
    $site = evSite($org);
    MetricSnapshot::create([
        'site_id' => $site->id, 'date' => now()->toDateString(),
        'revenue' => 100, 'orders' => 1, 'refunds' => 0, 'average_order_value' => 100,
        'new_customers' => 1, 'returning_customers' => 0, 'products_sold' => 1, 'failed_orders' => 0,
    ]);

    $body = $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/analytics/query", ['plan' => ['metrics' => ['net_revenue']]])
        ->assertOk()
        ->json();

    expect($body)->toHaveKey('evidence_run_id');

    $run = $this->actingAs($user)
        ->getJson("/api/sites/{$site->id}/evidence/{$body['evidence_run_id']}")
        ->assertOk()
        ->json('run');

    expect($run['status'])->toBe('success')
        ->and($run['query_hash'])->toBe($body['query']['hash']);
});

it('records a failed query as an evidence run too', function () {
    [$user, $org] = makeOrgUser('ev-ep-fail@example.com');
    $site = evSite($org);

    $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/analytics/query", ['plan' => ['metrics' => ['not_a_real_metric']]])
        ->assertStatus(422);

    $runs = $this->actingAs($user)->getJson("/api/sites/{$site->id}/evidence")->assertOk()->json('runs');

    expect($runs)->toHaveCount(1)
        ->and($runs[0]['status'])->toBe('error')
        ->and($runs[0]['error_code'])->toBe('unknown_metric');
});

it('lists most-recent-first and supports the status filter', function () {
    [$user, $org] = makeOrgUser('ev-ep-list@example.com');
    $site = evSite($org);
    MetricSnapshot::create([
        'site_id' => $site->id, 'date' => now()->toDateString(),
        'revenue' => 100, 'orders' => 1, 'refunds' => 0, 'average_order_value' => 100,
        'new_customers' => 1, 'returning_customers' => 0, 'products_sold' => 1, 'failed_orders' => 0,
    ]);

    $this->actingAs($user)->postJson("/api/sites/{$site->id}/analytics/query", ['plan' => ['metrics' => ['net_revenue']]])->assertOk();
    $this->actingAs($user)->postJson("/api/sites/{$site->id}/analytics/query", ['plan' => ['metrics' => ['not_a_real_metric']]])->assertStatus(422);

    $successOnly = $this->actingAs($user)
        ->getJson("/api/sites/{$site->id}/evidence?status=success")
        ->assertOk()
        ->json('runs');

    expect($successOnly)->toHaveCount(1)->and($successOnly[0]['status'])->toBe('success');
});

it('hides another organization\'s evidence runs entirely — 404, not an empty/foreign row', function () {
    [$userA, $orgA] = makeOrgUser('ev-ep-iso-a@example.com');
    [$userB, $orgB] = makeOrgUser('ev-ep-iso-b@example.com');
    $siteA = evSite($orgA);
    MetricSnapshot::create([
        'site_id' => $siteA->id, 'date' => now()->toDateString(),
        'revenue' => 100, 'orders' => 1, 'refunds' => 0, 'average_order_value' => 100,
        'new_customers' => 1, 'returning_customers' => 0, 'products_sold' => 1, 'failed_orders' => 0,
    ]);

    $runId = $this->actingAs($userA)
        ->postJson("/api/sites/{$siteA->id}/analytics/query", ['plan' => ['metrics' => ['net_revenue']]])
        ->assertOk()
        ->json('evidence_run_id');

    // B has no access to A's site at all — the route itself 404s.
    $this->actingAs($userB)
        ->getJson("/api/sites/{$siteA->id}/evidence/{$runId}")
        ->assertNotFound();
});

it('404s for a run id that exists but belongs to a different site', function () {
    [$user, $org] = makeOrgUser('ev-ep-wrong-site@example.com');
    $siteA = evSite($org);
    $siteB = evSite($org);
    MetricSnapshot::create([
        'site_id' => $siteA->id, 'date' => now()->toDateString(),
        'revenue' => 100, 'orders' => 1, 'refunds' => 0, 'average_order_value' => 100,
        'new_customers' => 1, 'returning_customers' => 0, 'products_sold' => 1, 'failed_orders' => 0,
    ]);

    $runId = $this->actingAs($user)
        ->postJson("/api/sites/{$siteA->id}/analytics/query", ['plan' => ['metrics' => ['net_revenue']]])
        ->assertOk()
        ->json('evidence_run_id');

    $this->actingAs($user)
        ->getJson("/api/sites/{$siteB->id}/evidence/{$runId}")
        ->assertNotFound();
});
