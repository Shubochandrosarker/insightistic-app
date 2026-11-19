<?php

use App\Models\Organization;
use App\Models\Site;
use App\Models\UptimeCheck;
use App\Models\UptimeIncident;
use App\Models\UptimeMonitor;
use App\Models\User;
use App\Services\Uptime\SslInspector;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Uptime API (Phase 12, doc 16): tenancy, role gates, the one-monitor-per-
 * site envelope (create-or-update + delete cascade), check-now, and the
 * overview payload shape (monitor + last check + incidents + ssl + availability).
 *
 * The engine (Probe/IncidentManager/ProbeUptimeJob) belongs to the uptime
 * engine workstream; the SSL piggyback is swapped out here because it
 * speaks raw TLS streams that Http::fake cannot intercept.
 */

beforeEach(function () {
    $inspector = Mockery::mock(SslInspector::class);
    $inspector->shouldReceive('check')->zeroOrMoreTimes()->andReturnNull();
    $this->instance(SslInspector::class, $inspector);
});

/** @return array{0: User, 1: Organization, 2: Site} */
function makeUptimeApiSite(string $email): array
{
    [$user, $org] = makeOrgUser($email);

    $site = Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id,
        'name'            => 'API Uptime Co',
        'domain'          => $email,
    ]);

    return [$user, $org, $site];
}

it('hides every uptime surface from another organization', function () {
    [$userA] = makeUptimeApiSite('uptime-a@example.com');
    [, , $siteB] = makeUptimeApiSite('uptime-b@example.com');

    UptimeMonitor::create(['site_id' => $siteB->id]);

    $this->actingAs($userA)->getJson("/api/sites/{$siteB->id}/uptime")->assertNotFound();
    $this->actingAs($userA)->getJson("/api/sites/{$siteB->id}/uptime/incidents")->assertNotFound();
    $this->actingAs($userA)->postJson("/api/sites/{$siteB->id}/uptime/monitors", [])->assertNotFound();
    $this->actingAs($userA)->postJson("/api/sites/{$siteB->id}/uptime/check", [])->assertNotFound();
});

it('refuses to delete a monitor through another site of the same organization', function () {
    [$owner, $org, $site] = makeUptimeApiSite('uptime-pin@example.com');

    // Second site in the SAME org, so site.access passes and the controller's
    // own monitor-to-site pin check is what must reject.
    $otherSite = Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id,
        'name'            => 'Other Co',
        'domain'          => 'other.example.com',
    ]);

    $monitor = UptimeMonitor::create(['site_id' => $site->id]);

    // Route binding finds the monitor (no org scope on the model), but the
    // controller must pin it to the {site} in the URL.
    $this->actingAs($owner)
        ->deleteJson("/api/sites/{$otherSite->id}/uptime/monitors/{$monitor->id}")
        ->assertNotFound();

    expect(UptimeMonitor::query()->find($monitor->id))->not->toBeNull();
});

it('opens uptime reads to analysts but blocks every write', function () {
    [$owner, $org, $site] = makeUptimeApiSite('uptime-owner@example.com');

    $analyst = User::create([
        'name' => 'Analyst', 'email' => 'uptime-analyst@example.com',
        'password' => 'secret-password', 'status' => 'active',
    ]);
    $org->users()->attach($analyst->id, ['role' => 'analyst']);

    UptimeMonitor::create(['site_id' => $site->id]);

    $this->actingAs($analyst)->getJson("/api/sites/{$site->id}/uptime")->assertOk();
    $this->actingAs($analyst)->getJson("/api/sites/{$site->id}/uptime/incidents")->assertOk();

    $this->actingAs($analyst)->postJson("/api/sites/{$site->id}/uptime/monitors", [])->assertForbidden();
    $this->actingAs($analyst)->postJson("/api/sites/{$site->id}/uptime/check", [])->assertForbidden();

    $monitor = UptimeMonitor::query()->sole();
    $this->actingAs($analyst)
        ->deleteJson("/api/sites/{$site->id}/uptime/monitors/{$monitor->id}")
        ->assertForbidden();
});

it('creates, updates and deletes the site single monitor with history cascade', function () {
    [$owner, $org, $site] = makeUptimeApiSite('uptime-crud@example.com');

    // Create — url omitted resolves to https://{domain}.
    $this->actingAs($owner)
        ->postJson("/api/sites/{$site->id}/uptime/monitors", [
            'interval_minutes' => 10,
        ])
        ->assertCreated()
        ->assertJsonPath('has_monitor', true)
        ->assertJsonPath('monitor.url', 'https://uptime-crud@example.com')
        ->assertJsonPath('monitor.interval_minutes', 10);

    $monitor = UptimeMonitor::query()->sole();
    expect($monitor->url)->toBeNull()
        ->and($monitor->method)->toBe('GET')
        ->and($monitor->expected_status)->toBe(200)
        ->and($monitor->active)->toBeTrue();

    // Update — still exactly one monitor row.
    $this->actingAs($owner)
        ->postJson("/api/sites/{$site->id}/uptime/monitors", [
            'url'              => 'https://93.184.216.34/health',
            'method'           => 'HEAD',
            'expected_status'  => 204,
            'interval_minutes' => 15,
            'active'           => false,
        ])
        ->assertOk()
        ->assertJsonPath('monitor.url', 'https://93.184.216.34/health')
        ->assertJsonPath('monitor.method', 'HEAD')
        ->assertJsonPath('monitor.expected_status', 204)
        ->assertJsonPath('monitor.interval_minutes', 15)
        ->assertJsonPath('monitor.active', false);

    expect(UptimeMonitor::query()->count())->toBe(1);

    // Delete cascades the history.
    UptimeCheck::create(['monitor_id' => $monitor->id, 'site_id' => $site->id, 'status' => 'up', 'checked_at' => now()]);
    UptimeIncident::create(['monitor_id' => $monitor->id, 'site_id' => $site->id, 'started_at' => now()]);

    $this->actingAs($owner)
        ->deleteJson("/api/sites/{$site->id}/uptime/monitors/{$monitor->id}")
        ->assertOk()
        ->assertJsonPath('deleted', true);

    expect(UptimeMonitor::query()->count())->toBe(0)
        ->and(UptimeCheck::query()->count())->toBe(0)
        ->and(UptimeIncident::query()->count())->toBe(0);
});

it('validates the monitor payload', function () {
    [$owner, $org, $site] = makeUptimeApiSite('uptime-validate@example.com');

    $this->actingAs($owner)
        ->postJson("/api/sites/{$site->id}/uptime/monitors", ['interval_minutes' => 0])
        ->assertStatus(422);

    $this->actingAs($owner)
        ->postJson("/api/sites/{$site->id}/uptime/monitors", ['interval_minutes' => 61])
        ->assertStatus(422);

    $this->actingAs($owner)
        ->postJson("/api/sites/{$site->id}/uptime/monitors", ['method' => 'DELETE'])
        ->assertStatus(422);

    $this->actingAs($owner)
        ->postJson("/api/sites/{$site->id}/uptime/monitors", ['url' => 'not-a-url'])
        ->assertStatus(422);

    expect(UptimeMonitor::query()->count())->toBe(0);
});

it('runs check now and returns the probe result inline', function () {
    [$owner, $org, $site] = makeUptimeApiSite('uptime-check-now@example.com');

    UptimeMonitor::create(['site_id' => $site->id, 'expected_status' => 200]);

    Http::fake(['*' => Http::response('pong', 200)]);

    $this->actingAs($owner)
        ->postJson("/api/sites/{$site->id}/uptime/check")
        ->assertOk()
        ->assertJsonPath('result.status', 'up')
        ->assertJsonPath('result.status_code', 200)
        ->assertJsonPath('incident', null);

    $check = UptimeCheck::query()->sole();

    expect($check->status)->toBe('up')
        ->and($check->response_ms)->not->toBeNull()
        ->and(UptimeMonitor::query()->sole()->last_checked_at)->not->toBeNull();
});

it('rejects check now without a monitor', function () {
    [$owner, $org, $site] = makeUptimeApiSite('uptime-nomon@example.com');

    $this->actingAs($owner)
        ->postJson("/api/sites/{$site->id}/uptime/check")
        ->assertStatus(422);
});

it('returns the full overview shape including ssl days and tone', function () {
    [$owner, $org, $site] = makeUptimeApiSite('uptime-shape@example.com');

    $monitor = UptimeMonitor::create([
        'site_id'          => $site->id,
        'url'              => 'https://watched.example.com/',
        'interval_minutes' => 5,
        'ssl_expires_at'   => Carbon::now()->addDays(30)->toDateString(),
        'ssl_checked_at'   => now(),
    ]);

    // Two checks in the window (one up, one down) + one incident lifecycle.
    UptimeCheck::create(['monitor_id' => $monitor->id, 'site_id' => $site->id, 'status' => 'up', 'status_code' => 200, 'response_ms' => 90, 'checked_at' => now()->subDays(1)]);
    UptimeCheck::create(['monitor_id' => $monitor->id, 'site_id' => $site->id, 'status' => 'down', 'status_code' => 500, 'error' => 'unexpected status', 'checked_at' => now()->subHours(2)]);

    $incident = UptimeIncident::create([
        'monitor_id'   => $monitor->id,
        'site_id'      => $site->id,
        'started_at'   => now()->subHours(3),
        'confirmed_at' => now()->subHours(2),
        'recovered_at' => now()->subHours(1),
        'cause'        => 'HTTP 500',
        'status_code'  => 500,
    ]);

    $this->actingAs($owner)->getJson("/api/sites/{$site->id}/uptime")
        ->assertOk()
        ->assertJsonPath('has_monitor', true)
        ->assertJsonPath('monitor.url', 'https://watched.example.com/')
        ->assertJsonPath('monitor.last_check.status', 'down')
        ->assertJsonPath('monitor.open_incident', null)
        ->assertJsonPath('incidents.0.id', $incident->id)
        ->assertJsonPath('incidents.0.status', UptimeIncident::STATUS_RECOVERED)
        ->assertJsonPath('ssl.days_left', 30)
        ->assertJsonPath('ssl.tone', 'ok')
        // Whole-number percentages serialize as JSON ints (no zero-fraction
        // flag); compare numerically rather than by PHP type.
        ->assertJsonPath('availability_pct', fn ($pct) => abs($pct - 50.0) < 0.001);

    // Tone escalates as expiry approaches.
    $monitor->forceFill(['ssl_expires_at' => Carbon::now()->addDays(10)->toDateString()])->save();
    $this->actingAs($owner)->getJson("/api/sites/{$site->id}/uptime")
        ->assertOk()
        ->assertJsonPath('ssl.tone', 'warning');

    $monitor->forceFill(['ssl_expires_at' => Carbon::now()->addDays(3)->toDateString()])->save();
    $this->actingAs($owner)->getJson("/api/sites/{$site->id}/uptime")
        ->assertOk()
        ->assertJsonPath('ssl.tone', 'critical');
});

it('reports an empty overview for a site without a monitor', function () {
    [$owner, $org, $site] = makeUptimeApiSite('uptime-empty@example.com');

    $this->actingAs($owner)->getJson("/api/sites/{$site->id}/uptime")
        ->assertOk()
        ->assertJsonPath('has_monitor', false)
        ->assertJsonPath('monitor', null)
        ->assertJsonPath('incidents', [])
        ->assertJsonPath('availability_pct', null);
});

it('lists incidents with duration', function () {
    [$owner, $org, $site] = makeUptimeApiSite('uptime-list@example.com');

    $monitor = UptimeMonitor::create(['site_id' => $site->id]);

    UptimeIncident::create([
        'monitor_id'   => $monitor->id,
        'site_id'      => $site->id,
        'started_at'   => now()->subMinutes(30),
        'confirmed_at' => now()->subMinutes(25),
        'recovered_at' => now()->subMinutes(10),
        'cause'        => 'HTTP 502',
        'status_code'  => 502,
    ]);

    $this->actingAs($owner)->getJson("/api/sites/{$site->id}/uptime/incidents")
        ->assertOk()
        ->assertJsonCount(1, 'incidents')
        ->assertJsonPath('incidents.0.cause', 'HTTP 502')
        ->assertJsonPath('incidents.0.status', UptimeIncident::STATUS_RECOVERED);

    $duration = $this->actingAs($owner)
        ->getJson("/api/sites/{$site->id}/uptime/incidents")
        ->json('incidents.0.duration_seconds');

    expect($duration)->toBeGreaterThanOrEqual(19 * 60)
        ->and($duration)->toBeLessThanOrEqual(20 * 60);
});
