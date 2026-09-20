<?php

use App\Jobs\ProbeUptimeJob;
use App\Models\Alert;
use App\Models\AutomationRun;
use App\Models\AutomationRule;
use App\Models\Organization;
use App\Models\Site;
use App\Models\UptimeCheck;
use App\Models\UptimeIncident;
use App\Models\UptimeMonitor;
use App\Services\Uptime\IncidentManager;
use App\Services\Uptime\SslInspector;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Engine mechanics for Phase 12 uptime monitoring
 * (docs/roadmap-2026/16-PERFORMANCE-UPTIME.md): the two-probe confirmation
 * rule, the suspected -> confirmed -> recovered lifecycle, alert
 * raising/dedupe/resolution, network-failure handling and availability
 * math — all against Http::fake'd probes, real PostgreSQL.
 */

beforeEach(function () {
    // SslInspector speaks raw TLS streams (not Guzzle), so Http::fake cannot
    // intercept it — swap it out so the job's SSL piggyback never leaves the
    // test process. The threshold policy is covered separately below.
    $inspector = Mockery::mock(SslInspector::class);
    $inspector->shouldReceive('check')->zeroOrMoreTimes()->andReturnNull();
    $this->instance(SslInspector::class, $inspector);
});

/** @return array{0: Site, 1: UptimeMonitor} */
function uptimeMonitoredSite(Organization $org, array $monitorOverrides = []): array
{
    $site = Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id,
        'name'            => 'Uptime Co',
        'domain'          => 'uptime.example.com',
    ]);

    $monitor = UptimeMonitor::create(array_replace([
        'site_id'          => $site->id,
        'url'              => null, // resolves to https://uptime.example.com
        'method'           => 'GET',
        'expected_status'  => 200,
        'interval_minutes' => 5,
        'active'           => true,
    ], $monitorOverrides));

    return [$site, $monitor];
}

/** Probe once, inline (QUEUE_CONNECTION=sync), and return the fresh monitor. */
function uptimeProbe(UptimeMonitor $monitor): UptimeMonitor
{
    ProbeUptimeJob::dispatch($monitor->id);

    return $monitor->fresh();
}

it('records a first failed probe as a suspected incident without alerting', function () {
    [, $org] = makeOrgUser('uptime-first-down@example.com');
    [$site, $monitor] = uptimeMonitoredSite($org);

    Http::fake(['*' => Http::response('internal error', 500)]);

    $monitor = uptimeProbe($monitor);

    $incident = UptimeIncident::query()->sole();

    expect($incident->lifecycleStatus())->toBe(UptimeIncident::STATUS_SUSPECTED)
        ->and($incident->started_at)->not->toBeNull()
        ->and($incident->confirmed_at)->toBeNull()
        ->and($incident->recovered_at)->toBeNull()
        ->and($incident->status_code)->toBe(500)
        ->and($incident->cause)->toBe('HTTP 500')
        // Acceptance: one failed probe does NOT create a false outage alert.
        ->and(Alert::query()->count())->toBe(0)
        ->and(UptimeCheck::query()->where('status', 'down')->count())->toBe(1)
        ->and(UptimeCheck::query()->sole()->status_code)->toBe(500);

    expect($monitor->last_checked_at)->not->toBeNull();
});

it('confirms the incident and raises exactly one alert on the second consecutive failure', function () {
    [, $org] = makeOrgUser('uptime-confirm@example.com');
    [$site, $monitor] = uptimeMonitoredSite($org);

    AutomationRule::create([
        'organization_id'     => $org->id,
        'site_id'             => $site->id,
        'name'                => 'Uptime pager',
        'is_active'           => true,
        'trigger_type'        => 'alert_created',
        'trigger_config_json' => [],
        'action_type'         => 'send_notification_email',
        'action_params_json'  => ['subject' => 'S', 'body' => 'B'],
    ]);

    Http::fake(['*' => Http::response('down', 500)]);

    uptimeProbe($monitor); // first down: suspected, silent

    // The scheduler would not probe again before the interval passes.
    Carbon::setTestNow(Carbon::now()->addMinutes($monitor->interval_minutes + 1));

    $result = app(IncidentManager::class)->record(
        $monitor->fresh(),
        ['up' => false, 'status_code' => 500, 'response_ms' => 120, 'error' => null],
    );

    expect($result['event'])->toBe(IncidentManager::EVENT_CONFIRMED)
        ->and($result['incident']->lifecycleStatus())->toBe(UptimeIncident::STATUS_CONFIRMED);

    $alert = Alert::query()->sole();

    expect($alert->type)->toBe(IncidentManager::ALERT_TYPE)
        ->and($alert->severity)->toBe('high')
        ->and($alert->is_resolved)->toBeFalse()
        ->and($alert->message)->toContain('confirmed after 2 probes')
        ->and($alert->message)->toContain('HTTP 500');

    // The alert_created hook fired for Automation Studio (one run, not two).
    expect(AutomationRun::query()->count())->toBe(1)
        ->and(AutomationRun::query()->sole()->trigger_context_json['alert_type'])
        ->toBe(IncidentManager::ALERT_TYPE);

    // A third consecutive failure must raise NOTHING new (dedupe).
    Carbon::setTestNow(Carbon::now()->addMinutes($monitor->interval_minutes + 1));

    uptimeProbe($monitor);

    expect(Alert::query()->count())->toBe(1)
        ->and(AutomationRun::query()->count())->toBe(1);
});

it('marks the incident recovered on the next successful probe and resolves the alert', function () {
    [, $org] = makeOrgUser('uptime-recover@example.com');
    [$site, $monitor] = uptimeMonitoredSite($org);

    Http::fake(['*' => Http::response('down', 503)]);
    uptimeProbe($monitor);

    Carbon::setTestNow(Carbon::now()->addMinutes($monitor->interval_minutes + 1));
    uptimeProbe($monitor); // confirms

    expect(Alert::query()->where('is_resolved', false)->count())->toBe(1);

    Carbon::setTestNow(Carbon::now()->addMinutes($monitor->interval_minutes + 1));
    Http::fake(['*' => Http::response('ok', 200)]);

    $result = app(IncidentManager::class)->record(
        $monitor->fresh(),
        ['up' => true, 'status_code' => 200, 'response_ms' => 80, 'error' => null],
    );

    expect($result['event'])->toBe(IncidentManager::EVENT_RECOVERED);

    $incident = UptimeIncident::query()->sole();

    // History stays forever; lifecycle is complete.
    expect($incident->lifecycleStatus())->toBe(UptimeIncident::STATUS_RECOVERED)
        ->and($incident->recovered_at)->not->toBeNull()
        ->and($incident->confirmed_at)->not->toBeNull()
        // Recovery auto-resolves the confirmation alert (AlertDetector::clear idiom).
        ->and($incident->durationSeconds())->toBeGreaterThan(0)
        ->and(Alert::query()->sole()->is_resolved)->toBeTrue();
});

it('never opens incidents for an all-green site', function () {
    [, $org] = makeOrgUser('uptime-green@example.com');
    [$site, $monitor] = uptimeMonitoredSite($org);

    Http::fake(['*' => Http::response('pong', 200)]);

    uptimeProbe($monitor);
    uptimeProbe($monitor);
    uptimeProbe($monitor);

    expect(UptimeIncident::query()->count())->toBe(0)
        ->and(Alert::query()->count())->toBe(0)
        ->and(UptimeCheck::query()->where('status', 'up')->count())->toBe(3)
        ->and($monitor->fresh()->last_checked_at)->not->toBeNull();
});

it('counts a network exception as a down check carrying the error', function () {
    [, $org] = makeOrgUser('uptime-netfail@example.com');
    [$site, $monitor] = uptimeMonitoredSite($org);

    Http::fake(function () {
        throw new ConnectionException('cURL error 7: Failed to connect to uptime.example.com port 443');
    });

    uptimeProbe($monitor);

    $check = UptimeCheck::query()->sole();
    $incident = UptimeIncident::query()->sole();

    expect($check->status)->toBe('down')
        ->and($check->status_code)->toBeNull()
        ->and($check->error)->toContain('Failed to connect')
        // A silent network blackhole is still an outage observation...
        ->and($incident->lifecycleStatus())->toBe(UptimeIncident::STATUS_SUSPECTED)
        ->and($incident->status_code)->toBeNull()
        // ...and still NOT an alert (same two-probe rule).
        ->and(Alert::query()->count())->toBe(0);
});

it('computes availability over the 30-day window and returns null without data', function () {
    [, $org] = makeOrgUser('uptime-availability@example.com');
    [$site, $monitor] = uptimeMonitoredSite($org);

    // No checks yet: honest null, not a misleading 100%.
    expect($monitor->availabilityPct())->toBeNull();

    $now = Carbon::now();

    $mk = fn (string $status, Carbon $at) => UptimeCheck::create([
        'monitor_id' => $monitor->id,
        'site_id'    => $site->id,
        'status'     => $status,
        'checked_at' => $at,
    ]);

    $mk('up', $now->copy()->subDays(1));
    $mk('up', $now->copy()->subDays(2));
    $mk('down', $now->copy()->subDays(3));
    $mk('up', $now->copy()->subDays(4));
    $mk('up', $now->copy()->subDays(40)); // outside the 30-day window

    expect($monitor->availabilityPct())->toBe(75.0);
});

it('dispatches probes only for active monitors whose interval has elapsed', function () {
    [, $org] = makeOrgUser('uptime-scheduler@example.com');
    [, $due] = uptimeMonitoredSite($org); // never checked -> due
    [, $fresh] = uptimeMonitoredSite($org, ['url' => 'https://other.example.com/health']);
    $fresh->forceFill(['last_checked_at' => Carbon::now()->subMinute()])->save(); // not due (5-min interval)
    [, $inactive] = uptimeMonitoredSite($org);
    $inactive->forceFill(['active' => false])->save(); // never dispatched

    Queue::fake();

    $this->artisan('insightistic:uptime-check')->assertSuccessful();

    Queue::assertPushed(ProbeUptimeJob::class, 1);
    Queue::assertPushed(ProbeUptimeJob::class, fn (ProbeUptimeJob $job) => $job->monitorId === $due->id);
});

it('does not dispatch anything on a dry run', function () {
    [, $org] = makeOrgUser('uptime-dry@example.com');
    uptimeMonitoredSite($org);

    Queue::fake();

    $this->artisan('insightistic:uptime-check', ['--dry-run' => true])->assertSuccessful();

    Queue::assertNothingPushed();
});

it('raises ssl expiry alerts at the 21- and 7-day crossings with open-alert dedupe', function () {
    [, $org] = makeOrgUser('uptime-ssl@example.com');
    [$site] = uptimeMonitoredSite($org);

    // Rebind the REAL inspector (beforeEach swapped in a silent mock).
    $this->instance(SslInspector::class, new SslInspector(app(\App\Services\Automation\AutomationDispatcher::class)));
    $inspector = app(SslInspector::class);
    $host = 'uptime.example.com';

    // 30 days out: healthy — nothing raised.
    $inspector->applyExpiryThreshold($site, $host, 30);
    expect(Alert::query()->count())->toBe(0);

    // Crossing under 21 days: one low warning.
    $inspector->applyExpiryThreshold($site, $host, 15);
    $warning = Alert::query()->sole();
    expect($warning->type)->toBe(SslInspector::ALERT_TYPE_WARNING)
        ->and($warning->severity)->toBe('low')
        ->and($warning->is_resolved)->toBeFalse()
        ->and($warning->message)->toContain('15 day(s)');

    // Next daily check still under 21: deduped, no new row.
    $inspector->applyExpiryThreshold($site, $host, 14);
    expect(Alert::query()->count())->toBe(1);

    // Crossing under 7 days: warning superseded by one critical.
    $inspector->applyExpiryThreshold($site, $host, 3);
    $critical = Alert::query()->where('type', SslInspector::ALERT_TYPE_CRITICAL)->sole();

    expect($critical->severity)->toBe('critical')
        ->and($critical->is_resolved)->toBeFalse()
        ->and($warning->fresh()->is_resolved)->toBeTrue();

    // Certificate renewed: both types auto-resolve.
    $inspector->applyExpiryThreshold($site, $host, 365);

    expect(Alert::query()->where('is_resolved', false)->count())->toBe(0);
});
