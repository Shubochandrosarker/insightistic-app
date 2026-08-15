<?php

use App\Models\MetricSnapshot;
use App\Models\Organization;
use App\Models\Site;

function commandAnomalySite(Organization $org): Site
{
    return Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id,
        'name' => 'Command Anomaly Shop',
        'domain' => 'cmd-anomaly-' . uniqid() . '.example.com',
        'connection_status' => 'connected',
        'timezone' => 'UTC',
    ]);
}

it('scans every site and reports how many anomalies it found', function () {
    [, $org] = makeOrgUser('cmd-anomaly-scan@example.com');
    $site = commandAnomalySite($org);

    foreach (range(1, 28) as $d) {
        MetricSnapshot::create([
            'site_id' => $site->id, 'date' => now()->subDays($d)->toDateString(),
            'revenue' => $d === 3 ? 50000 : 100 + ($d % 5), 'orders' => 1, 'refunds' => 0,
            'average_order_value' => 100, 'new_customers' => 1, 'returning_customers' => 0,
            'products_sold' => 1, 'failed_orders' => 0,
        ]);
    }

    $this->artisan('insightistic:anomalies-scan', ['--site' => $site->id])
        ->assertSuccessful();

    expect(\App\Models\Anomaly::where('site_id', $site->id)->count())->toBeGreaterThan(0);
});
