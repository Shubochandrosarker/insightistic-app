<?php

use App\Models\AutomationRule;
use App\Models\MetricSnapshot;
use App\Models\Organization;
use App\Models\Site;
use App\Services\Anomaly\AnomalyScanner;
use Illuminate\Support\Facades\Mail;

function scannerSite(Organization $org): Site
{
    return Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id,
        'name' => 'Scanner Shop',
        'domain' => 'scanner-' . uniqid() . '.example.com',
        'connection_status' => 'connected',
        'timezone' => 'UTC',
    ]);
}

function seedRevenueSpike(Site $site, int $spikeDay = 3): void
{
    foreach (range(1, 28) as $d) {
        MetricSnapshot::create([
            'site_id' => $site->id, 'date' => now()->subDays($d)->toDateString(),
            'revenue' => $d === $spikeDay ? 50000 : 100 + ($d % 5), 'orders' => 1, 'refunds' => 0,
            'average_order_value' => 100, 'new_customers' => 1, 'returning_customers' => 0,
            'products_sold' => 1, 'failed_orders' => 0,
        ]);
    }
}

it('persists a new anomaly and never duplicates it on a second scan', function () {
    [, $org] = makeOrgUser('scan-dedup@example.com');
    $site = scannerSite($org);
    seedRevenueSpike($site);

    $first = app(AnomalyScanner::class)->scan($site);
    expect($first)->not->toBeEmpty();

    $second = app(AnomalyScanner::class)->scan($site);
    expect($second)->toBe([]); // same day already recorded — dedup
});

it('fires the anomaly_detected automation trigger for a genuinely new anomaly', function () {
    Mail::fake();
    [, $org] = makeOrgUser('scan-automation@example.com');
    $site = scannerSite($org);
    seedRevenueSpike($site);

    $rule = AutomationRule::create([
        'organization_id' => $org->id, 'site_id' => $site->id, 'name' => 'Notify on anomaly',
        'is_active' => true, 'trigger_type' => 'anomaly_detected',
        'trigger_config_json' => ['metric_id' => 'net_revenue'],
        'action_type' => 'send_notification_email',
        'action_params_json' => ['subject' => 'Anomaly found', 'body' => 'Check it out.'],
    ]);

    app(AnomalyScanner::class)->scan($site);

    expect($rule->runs()->count())->toBe(1);
    Mail::assertQueued(\App\Mail\AutomationNotification::class, fn ($mail) => $mail->subjectLine === 'Anomaly found');
});

it('stamps organization_id, severity and direction correctly', function () {
    [, $org] = makeOrgUser('scan-stamp@example.com');
    $site = scannerSite($org);
    seedRevenueSpike($site);

    $created = app(AnomalyScanner::class)->scan($site);
    $revenueAnomaly = collect($created)->firstWhere('metric_id', 'net_revenue');

    expect($revenueAnomaly)->not->toBeNull()
        ->and($revenueAnomaly->organization_id)->toBe($org->id)
        ->and($revenueAnomaly->direction)->toBe('spike')
        ->and($revenueAnomaly->severity)->toBeIn(['low', 'medium', 'high'])
        ->and($revenueAnomaly->is_acknowledged)->toBeFalse();
});
