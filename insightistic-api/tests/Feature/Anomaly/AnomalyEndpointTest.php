<?php

use App\Models\MetricSnapshot;
use App\Models\Organization;
use App\Models\Site;

function anomalyEndpointSite(Organization $org): Site
{
    return Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id,
        'name' => 'Anomaly Endpoint Shop',
        'domain' => 'anomaly-ep-' . uniqid() . '.example.com',
        'connection_status' => 'connected',
        'timezone' => 'UTC',
    ]);
}

it('scans and lists anomalies, then acknowledges one', function () {
    [$user, $org] = makeOrgUser('anomaly-ep-scan@example.com');
    $site = anomalyEndpointSite($org);

    foreach (range(1, 28) as $d) {
        MetricSnapshot::create([
            'site_id' => $site->id, 'date' => now()->subDays($d)->toDateString(),
            'revenue' => $d === 3 ? 50000 : 100 + ($d % 5), 'orders' => 1, 'refunds' => 0,
            'average_order_value' => 100, 'new_customers' => 1, 'returning_customers' => 0,
            'products_sold' => 1, 'failed_orders' => 0,
        ]);
    }

    $scanBody = $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/anomalies/scan")
        ->assertOk()
        ->json();

    expect($scanBody['created'])->toBeGreaterThan(0);

    $listed = $this->actingAs($user)
        ->getJson("/api/sites/{$site->id}/anomalies")
        ->assertOk()
        ->json('anomalies');
    expect($listed)->not->toBeEmpty();

    $anomalyId = $listed[0]['id'];
    $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/anomalies/{$anomalyId}/acknowledge")
        ->assertOk()
        ->assertJsonPath('anomaly.is_acknowledged', true);

    $openOnly = $this->actingAs($user)
        ->getJson("/api/sites/{$site->id}/anomalies?status=open")
        ->assertOk()
        ->json('anomalies');
    expect(collect($openOnly)->pluck('id'))->not->toContain($anomalyId);
});

it('never lets an anomaly leak across organizations', function () {
    [$userA, $orgA] = makeOrgUser('anomaly-ep-iso-a@example.com');
    [$userB, $orgB] = makeOrgUser('anomaly-ep-iso-b@example.com');
    $siteA = anomalyEndpointSite($orgA);

    $this->actingAs($userB)
        ->getJson("/api/sites/{$siteA->id}/anomalies")
        ->assertNotFound();

    $this->actingAs($userB)
        ->postJson("/api/sites/{$siteA->id}/anomalies/scan")
        ->assertNotFound();
});
