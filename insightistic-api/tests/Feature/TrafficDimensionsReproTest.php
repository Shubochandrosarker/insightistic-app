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

/**
 * Production symptom (2026-10-27): KPI cards showed GA4 data but every
 * Breakdown tab said "No breakdown data". A 30-day GA4 sync stores its
 * snapshot window as [today-30, today-1], while last_30_days is
 * [today-29, today], so a "window fully inside the range" filter hid it.
 */
it('returns rows for a GA4 sync window that only overlaps the dashboard range', function () {
    [$user, $org] = makeOrgUser('traffic-window@example.com');
    $site = Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id,
        'name'            => 'Traffic window',
        'domain'          => 'traffic-window.example.com',
    ]);

    $snap = fn (string $start, string $end, string $value, int $sessions) => TrafficDimensionSnapshot::create([
        'site_id'         => $site->id,
        'source'          => 'ga4',
        'dimension_type'  => 'channel',
        'dimension_value' => $value,
        'period_start'    => $start,
        'period_end'      => $end,
        'sessions'        => $sessions,
    ]);

    // Older window (yesterday's sync) and the latest window (today's sync).
    $snap(now()->subDays(31)->toDateString(), now()->subDays(2)->toDateString(), 'Organic Search', 50);
    $snap(now()->subDays(30)->toDateString(), now()->subDay()->toDateString(), 'Organic Search', 60);
    $snap(now()->subDays(30)->toDateString(), now()->subDay()->toDateString(), 'Direct', 25);

    $response = $this->actingAs($user)
        ->getJson("/api/sites/{$site->id}/analytics/traffic/dimensions/channel?period=last_30_days")
        ->assertOk();

    // Only the most recent window is read, so nothing is double counted.
    expect($response->json('rows'))->toHaveCount(2)
        ->and($response->json('rows.0.dimension_value'))->toBe('Organic Search')
        ->and($response->json('rows.0.sessions'))->toBe(60)
        ->and($response->json('window.to'))->toBe(now()->subDay()->toDateString());
});

it('returns no rows when no snapshot window overlaps the range', function () {
    [$user, $org] = makeOrgUser('traffic-nowindow@example.com');
    $site = Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id,
        'name'            => 'Traffic none',
        'domain'          => 'traffic-none.example.com',
    ]);

    TrafficDimensionSnapshot::create([
        'site_id'         => $site->id,
        'source'          => 'ga4',
        'dimension_type'  => 'country',
        'dimension_value' => 'Bangladesh',
        'period_start'    => now()->subDays(120)->toDateString(),
        'period_end'      => now()->subDays(91)->toDateString(),
        'sessions'        => 9,
    ]);

    $this->actingAs($user)
        ->getJson("/api/sites/{$site->id}/analytics/traffic/dimensions/country?period=last_30_days")
        ->assertOk()
        ->assertJsonPath('rows', []);
});
