<?php

use App\Models\MetricSnapshot;
use App\Models\Organization;
use App\Models\Site;
use App\Services\Anomaly\AnomalyDetector;
use App\Support\DateRange;
use Illuminate\Support\Facades\DB;

function detectorSite(Organization $org): Site
{
    return Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id,
        'name' => 'Detector Shop',
        'domain' => 'detector-' . uniqid() . '.example.com',
        'connection_status' => 'connected',
        'timezone' => 'UTC',
    ]);
}

function detectorRange(): DateRange
{
    return DateRange::fromRequest('last_30_days', null, null, 'UTC');
}

it('reports not_enough_history below the minimum day floor', function () {
    [, $org] = makeOrgUser('detect-short@example.com');
    $site = detectorSite($org);
    MetricSnapshot::create(['site_id' => $site->id, 'date' => now()->toDateString(), 'revenue' => 100, 'orders' => 1, 'refunds' => 0, 'average_order_value' => 100, 'new_customers' => 1, 'returning_customers' => 0, 'products_sold' => 1, 'failed_orders' => 0]);

    $result = app(AnomalyDetector::class)->detect($site, 'net_revenue', detectorRange());

    expect($result['has_data'])->toBeFalse()->and($result['reason'])->toBe('not_enough_history');
});

it('detects a revenue spike without letting the spike inflate its own baseline', function () {
    [, $org] = makeOrgUser('detect-spike@example.com');
    $site = detectorSite($org);

    foreach (range(1, 28) as $d) {
        MetricSnapshot::create([
            'site_id' => $site->id, 'date' => now()->subDays($d)->toDateString(),
            'revenue' => $d === 3 ? 50000 : 100 + ($d % 5), 'orders' => 1, 'refunds' => 0,
            'average_order_value' => 100, 'new_customers' => 1, 'returning_customers' => 0,
            'products_sold' => 1, 'failed_orders' => 0,
        ]);
    }

    $result = app(AnomalyDetector::class)->detect($site, 'net_revenue', detectorRange());

    expect($result['has_data'])->toBeTrue()
        ->and($result['anomalies'])->toHaveCount(1)
        ->and($result['anomalies'][0]['direction'])->toBe('spike')
        ->and($result['anomalies'][0]['value'])->toBe(50000.0)
        ->and($result['anomalies'][0]['date'])->toBe(now()->subDays(3)->toDateString());
});

it('scopes ga4_sessions to source = ga4, ignoring the first-party fallback source', function () {
    [, $org] = makeOrgUser('detect-ga4@example.com');
    $site = detectorSite($org);

    foreach (range(1, 28) as $d) {
        DB::table('traffic_daily')->insert([
            'site_id' => $site->id, 'date' => now()->subDays($d)->toDateString(), 'source' => 'ga4',
            // Slight variance in the baseline days (not a perfectly flat
            // 100 every day) — MAD is 0 for a perfectly constant series,
            // which makes the spike's z-score undefined/no-op by design
            // (see AnomalyDetector's "perfectly flat" branch); a realistic
            // day-to-day wobble is what actually exercises the detector.
            'sessions' => $d === 5 ? 9000 : 100 + ($d % 5), 'users' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('traffic_daily')->insert([
            'site_id' => $site->id, 'date' => now()->subDays($d)->toDateString(), 'source' => 'connector_engagement',
            'sessions' => 999999, 'users' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $result = app(AnomalyDetector::class)->detect($site, 'ga4_sessions', detectorRange());

    expect($result['has_data'])->toBeTrue()
        ->and($result['anomalies'])->toHaveCount(1)
        ->and($result['anomalies'][0]['value'])->toBe(9000.0); // never the 999999 fallback-source noise
});

it('finds no anomalies over a perfectly flat series', function () {
    [, $org] = makeOrgUser('detect-flat@example.com');
    $site = detectorSite($org);
    foreach (range(1, 20) as $d) {
        MetricSnapshot::create(['site_id' => $site->id, 'date' => now()->subDays($d)->toDateString(), 'revenue' => 500, 'orders' => 5, 'refunds' => 0, 'average_order_value' => 100, 'new_customers' => 1, 'returning_customers' => 0, 'products_sold' => 1, 'failed_orders' => 0]);
    }

    $result = app(AnomalyDetector::class)->detect($site, 'net_revenue', detectorRange());

    expect($result['has_data'])->toBeTrue()->and($result['anomalies'])->toBe([]);
});

it('throws for a metric it does not support', function () {
    [, $org] = makeOrgUser('detect-unsupported@example.com');
    $site = detectorSite($org);

    app(AnomalyDetector::class)->detect($site, 'lcp', detectorRange());
})->throws(\InvalidArgumentException::class);

it('never returns another site\'s data', function () {
    [, $org] = makeOrgUser('detect-iso-a@example.com');
    [, $otherOrg] = makeOrgUser('detect-iso-b@example.com');
    $site = detectorSite($org);
    $otherSite = detectorSite($otherOrg);

    foreach (range(1, 28) as $d) {
        MetricSnapshot::create(['site_id' => $site->id, 'date' => now()->subDays($d)->toDateString(), 'revenue' => $d === 2 ? 40000 : 100 + ($d % 5), 'orders' => 1, 'refunds' => 0, 'average_order_value' => 100, 'new_customers' => 1, 'returning_customers' => 0, 'products_sold' => 1, 'failed_orders' => 0]);
        MetricSnapshot::create(['site_id' => $otherSite->id, 'date' => now()->subDays($d)->toDateString(), 'revenue' => 999999, 'orders' => 1, 'refunds' => 0, 'average_order_value' => 100, 'new_customers' => 1, 'returning_customers' => 0, 'products_sold' => 1, 'failed_orders' => 0]);
    }

    $result = app(AnomalyDetector::class)->detect($site, 'net_revenue', detectorRange());

    expect($result['anomalies'])->toHaveCount(1)
        ->and($result['anomalies'][0]['value'])->toBe(40000.0);
});
