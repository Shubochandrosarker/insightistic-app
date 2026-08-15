<?php

use App\Models\MetricSnapshot;
use App\Models\Organization;
use App\Models\Site;

function agencySite(Organization $org, string $name): Site
{
    return Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id,
        'name' => $name,
        'domain' => strtolower(str_replace(' ', '-', $name)).'-'.uniqid().'.example.com',
        'connection_status' => 'connected',
        'timezone' => 'UTC',
    ]);
}

beforeEach(function () {
    config(['database.connections.readonly.username' => null]);
});

it('rolls up revenue and orders across every site in the organization', function () {
    [$user, $org] = makeOrgUser('agency-rollup@example.com');
    $siteA = agencySite($org, 'Client A');
    $siteB = agencySite($org, 'Client B');

    MetricSnapshot::create(['site_id' => $siteA->id, 'date' => now()->toDateString(), 'revenue' => 300, 'orders' => 3, 'refunds' => 0, 'average_order_value' => 100, 'new_customers' => 1, 'returning_customers' => 1, 'products_sold' => 3, 'failed_orders' => 0]);
    MetricSnapshot::create(['site_id' => $siteB->id, 'date' => now()->toDateString(), 'revenue' => 700, 'orders' => 7, 'refunds' => 0, 'average_order_value' => 100, 'new_customers' => 2, 'returning_customers' => 2, 'products_sold' => 7, 'failed_orders' => 0]);

    $body = $this->actingAs($user)
        ->getJson('/api/agency/overview')
        ->assertOk()
        ->json();

    expect($body['site_count'])->toBe(2)
        ->and($body['totals']['net_revenue'])->toEqual(1000.0) // JSON doesn't distinguish 1000 from 1000.0
        ->and($body['totals']['orders'])->toBe(10)
        ->and($body['sites'])->toHaveCount(2);

    $names = collect($body['sites'])->pluck('site_name');
    expect($names)->toContain('Client A', 'Client B');
});

it('flags a site as at_risk when revenue dropped 20% or more, matching AlertDetector\'s own threshold', function () {
    [$user, $org] = makeOrgUser('agency-atrisk@example.com');
    $site = agencySite($org, 'Struggling Client');

    MetricSnapshot::create(['site_id' => $site->id, 'date' => now()->toDateString(), 'revenue' => 100, 'orders' => 1, 'refunds' => 0, 'average_order_value' => 100, 'new_customers' => 1, 'returning_customers' => 0, 'products_sold' => 1, 'failed_orders' => 0]);
    MetricSnapshot::create(['site_id' => $site->id, 'date' => now()->subDays(10)->toDateString(), 'revenue' => 1000, 'orders' => 10, 'refunds' => 0, 'average_order_value' => 100, 'new_customers' => 1, 'returning_customers' => 0, 'products_sold' => 1, 'failed_orders' => 0]);

    $body = $this->actingAs($user)->getJson('/api/agency/overview?period=last_7_days')->assertOk()->json();

    expect($body['sites'][0]['health'])->toBe('at_risk');
});

it('rejects an unknown period', function () {
    [$user, $org] = makeOrgUser('agency-bad-period@example.com');
    agencySite($org, 'X');

    $this->actingAs($user)
        ->getJson('/api/agency/overview?period=last_decade')
        ->assertStatus(422)
        ->assertJsonPath('code', 'invalid_period');
});

it('only ever rolls up the caller\'s own organization\'s sites', function () {
    [$userA, $orgA] = makeOrgUser('agency-iso-a@example.com');
    [$userB, $orgB] = makeOrgUser('agency-iso-b@example.com');
    agencySite($orgA, 'A Site');
    agencySite($orgB, 'B Site 1');
    agencySite($orgB, 'B Site 2');

    $bodyA = $this->actingAs($userA)->getJson('/api/agency/overview')->assertOk()->json();
    $bodyB = $this->actingAs($userB)->getJson('/api/agency/overview')->assertOk()->json();

    expect($bodyA['site_count'])->toBe(1)
        ->and($bodyB['site_count'])->toBe(2)
        ->and(collect($bodyA['sites'])->pluck('site_name'))->not->toContain('B Site 1', 'B Site 2');
});
