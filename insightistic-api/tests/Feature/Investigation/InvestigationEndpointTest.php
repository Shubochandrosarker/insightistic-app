<?php

use App\Models\MetricSnapshot;
use App\Models\Organization;
use App\Models\Site;

function invEndpointSite(Organization $org): Site
{
    return Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id,
        'name' => 'Investigation Endpoint Shop',
        'domain' => 'inv-ep-' . uniqid() . '.example.com',
        'connection_status' => 'connected',
        'timezone' => 'UTC',
    ]);
}

beforeEach(function () {
    config(['database.connections.readonly.username' => null]);
});

it('creates and returns an investigation', function () {
    [$user, $org] = makeOrgUser('inv-ep-create@example.com');
    $site = invEndpointSite($org);
    MetricSnapshot::create(['site_id' => $site->id, 'date' => now()->toDateString(), 'revenue' => 300, 'orders' => 3, 'refunds' => 0, 'average_order_value' => 100, 'new_customers' => 1, 'returning_customers' => 1, 'products_sold' => 3, 'failed_orders' => 0]);

    $body = $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/investigations", ['subject' => 'Why did revenue move?', 'metric_ids' => ['net_revenue']])
        ->assertCreated()
        ->json('investigation');

    expect($body['status'])->toBe('completed')
        ->and($body['subject'])->toBe('Why did revenue move?');

    $this->actingAs($user)
        ->getJson("/api/sites/{$site->id}/investigations")
        ->assertOk()
        ->assertJsonCount(1, 'investigations');

    $this->actingAs($user)
        ->getJson("/api/sites/{$site->id}/investigations/{$body['id']}")
        ->assertOk()
        ->assertJsonPath('investigation.id', $body['id']);
});

it('rejects a missing subject or empty metric_ids', function () {
    [$user, $org] = makeOrgUser('inv-ep-invalid@example.com');
    $site = invEndpointSite($org);

    $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/investigations", ['metric_ids' => ['net_revenue']])
        ->assertStatus(422)
        ->assertJsonPath('code', 'invalid_subject');

    $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/investigations", ['subject' => 'X', 'metric_ids' => []])
        ->assertStatus(422)
        ->assertJsonPath('code', 'invalid_metric_ids');
});

it('never lets an investigation leak across organizations', function () {
    [$userA, $orgA] = makeOrgUser('inv-ep-iso-a@example.com');
    [$userB, $orgB] = makeOrgUser('inv-ep-iso-b@example.com');
    $siteA = invEndpointSite($orgA);

    $this->actingAs($userB)
        ->postJson("/api/sites/{$siteA->id}/investigations", ['subject' => 'X', 'metric_ids' => ['net_revenue']])
        ->assertNotFound();
});
