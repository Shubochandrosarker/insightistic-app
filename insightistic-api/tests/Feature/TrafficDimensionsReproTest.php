<?php

use App\Models\TrafficDaily;
use App\Models\TrafficDimensionSnapshot;
use App\Models\Site;

/**
 * Reproduction for the production symptom: every /dashboard/traffic
 * Breakdown tab returns 422 "Unknown dimension type." even though the
 * frontend sends keys this controller's allowlist accepts.
 */
it('accepts every dimension key the traffic page sends', function () {
    [$user, $org] = makeOrgUser('traffic-dims@example.com');
    $site = Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id,
        'name'            => 'Traffic dims',
        'domain'          => 'traffic-dims.example.com',
    ]);

    TrafficDaily::create([
        'site_id'  => $site->id,
        'source'   => 'connector',
        'date'     => now()->subDay()->toDateString(),
        'sessions' => 10,
        'users'    => 8,
    ]);

    TrafficDimensionSnapshot::create([
        'site_id'         => $site->id,
        'source'          => 'connector',
        'dimension_type'  => 'channel',
        'dimension_value' => 'organic',
        'period_start'    => now()->subDay()->toDateString(),
        'period_end'      => now()->subDay()->toDateString(),
        'sessions'        => 10,
    ]);

    foreach (['channel', 'source_medium', 'country', 'device', 'landing_page'] as $type) {
        $response = $this->actingAs($user)
            ->getJson("/api/sites/{$site->id}/analytics/traffic/dimensions/{$type}?period=last_30_days");

        expect($response->status())->toBe(200, "dimension {$type} returned ".$response->status());
    }
});
