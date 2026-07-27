<?php

use App\Services\Observability\EventScrubber;
use App\Services\Observability\QueueHealth;
use App\Services\Observability\Telemetry;
use App\Services\Observability\Tracing;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Observability (spec Phase 2.3).
 *
 * The tests that matter here are the ones about *not* leaking and *not*
 * lying. Error tracking is a data-exfiltration path nobody thinks of as one,
 * so the scrubber gets the most coverage; telemetry that loses counts under
 * concurrency is worse than no telemetry, because the numbers come out
 * quietly low rather than obviously missing.
 */

// ---------------------------------------------------------------------------
// Scrubbing — what must never leave the process
// ---------------------------------------------------------------------------

it('redacts every kind of secret this platform actually holds', function () {
    $scrubbed = EventScrubber::scrubArray([
        'password'              => 'hunter2',
        'refresh_token'         => 'ya29.a0Af...',
        'cloudflare_api_token'  => 'cf-abc123',
        'license_key'           => 'INS-XXXX-YYYY',
        'mfa_secret'            => 'JBSWY3DPEHPK3PXP',
        'webhook_secret'        => 'whsec_123',
        'X-INS-Signature'       => 'deadbeef',
        'customer_email'        => 'someone@example.com',
        'billing_address'       => '1 Example Street',
        // Not a secret — must survive, or the payload is useless.
        'organization_id'       => 42,
        'route'                 => 'sites/{site}/insights',
        'duration_ms'           => 1234,
    ]);

    foreach (['password', 'refresh_token', 'cloudflare_api_token', 'license_key',
        'mfa_secret', 'webhook_secret', 'X-INS-Signature', 'customer_email', 'billing_address'] as $key) {
        expect($scrubbed[$key])->toBe(EventScrubber::REDACTED, "{$key} was not redacted");
    }

    expect($scrubbed['organization_id'])->toBe(42)
        ->and($scrubbed['route'])->toBe('sites/{site}/insights')
        ->and($scrubbed['duration_ms'])->toBe(1234);
});

it('redacts secrets nested inside arrays', function () {
    $scrubbed = EventScrubber::scrubArray([
        'context' => [
            'connection' => ['access_token' => 'ya29.secret', 'expires_in' => 3600],
            'site'       => ['id' => 7, 'domain' => 'shop.example.com'],
        ],
    ]);

    expect($scrubbed['context']['connection']['access_token'])->toBe(EventScrubber::REDACTED)
        // Sibling non-secrets at the same depth must survive, or the payload
        // is redacted into uselessness.
        ->and($scrubbed['context']['connection']['expires_in'])->toBe(3600)
        ->and($scrubbed['context']['site']['domain'])->toBe('shop.example.com');
});

it('redacts a whole subtree when the container itself is named as secret', function () {
    $scrubbed = EventScrubber::scrubArray([
        'credentials' => ['access_token' => 'ya29.secret', 'expires_in' => 3600],
    ]);

    // Not recursed into: a key called `credentials` is a promise about
    // everything under it, and dropping a harmless sibling is a far better
    // trade than shipping one refresh token to a third party.
    expect($scrubbed['credentials'])->toBe(EventScrubber::REDACTED);
});

it('stops recursing rather than following a runaway structure', function () {
    $deep = ['value' => 'leaf'];
    for ($i = 0; $i < 20; $i++) {
        $deep = ['nested' => $deep];
    }

    $scrubbed = EventScrubber::scrubArray($deep);
    $json = json_encode($scrubbed);

    // Truncation, not a stack overflow, and no leaf smuggled out past the cap.
    expect($json)->toContain('_truncated')
        ->and($json)->not->toContain('leaf');
});

it('strips query values from URLs while keeping the endpoint shape', function () {
    // An OAuth callback carries the authorization code in the query string,
    // and HTTP-client breadcrumbs record the full URL.
    $url = EventScrubber::scrubUrl('https://user:pass@oauth.example.com/token?code=4/0AY0e&state=abc');

    expect($url)->toContain('oauth.example.com/token')
        ->and($url)->toContain('code=' . EventScrubber::REDACTED)
        ->and($url)->toContain('state=' . EventScrubber::REDACTED)
        ->and($url)->not->toContain('4/0AY0e')
        // Credentials embedded in the authority are gone entirely.
        ->and($url)->not->toContain('pass');
});

it('treats every documented sensitive key as sensitive', function () {
    foreach (EventScrubber::SENSITIVE_KEYS as $key) {
        expect(EventScrubber::isSensitive($key))->toBeTrue("{$key} should be sensitive")
            // Substring matching is the point: prefixes and suffixes count.
            ->and(EventScrubber::isSensitive('wc_' . $key . '_value'))->toBeTrue();
    }

    expect(EventScrubber::isSensitive('sessions'))->toBeTrue()
        ->and(EventScrubber::isSensitive('requests'))->toBeFalse()
        ->and(EventScrubber::isSensitive('latency_ms'))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Request + AI telemetry
// ---------------------------------------------------------------------------

it('accumulates request timings into one hourly bucket', function () {
    $telemetry = app(Telemetry::class);

    $telemetry->recordRequest('sites/{site}/insights', 'GET', 200, 120);
    $telemetry->recordRequest('sites/{site}/insights', 'GET', 200, 2400);
    $telemetry->recordRequest('sites/{site}/insights', 'GET', 200, 80);

    $rows = DB::table('telemetry_requests')->where('route', 'sites/{site}/insights');
    $row = $rows->first();

    // Three requests, one row — a per-request table would be unbounded.
    // Scoped to this route rather than counting the table: not every test in
    // the suite runs inside a transaction, so unrelated rows can be present.
    expect($rows->count())->toBe(1)
        ->and((int) $row->requests)->toBe(3)
        ->and((int) $row->duration_ms_total)->toBe(2600)
        ->and((int) $row->duration_ms_max)->toBe(2400)
        ->and((int) $row->over_1s)->toBe(1)
        ->and((int) $row->over_3s)->toBe(0);
});

it('separates buckets by status so errors do not hide in the average', function () {
    $telemetry = app(Telemetry::class);

    $telemetry->recordRequest('sites/{site}/reports', 'POST', 201, 100);
    $telemetry->recordRequest('sites/{site}/reports', 'POST', 500, 50);

    $summary = $telemetry->requestSummary(24);
    $route = collect($summary['routes'])->firstWhere('route', 'sites/{site}/reports');

    expect(DB::table('telemetry_requests')->where('route', 'sites/{site}/reports')->count())->toBe(2)
        ->and($route['requests'])->toBe(2)
        ->and($route['server_errors'])->toBe(1)
        ->and($route['error_rate'])->toBe(50.0);
});

it('sums AI spend across kinds and models', function () {
    [, $org] = makeOrgUser('obs-ai-spend@example.com');
    $telemetry = app(Telemetry::class);

    $telemetry->recordAiCall('insight', 'openai', 'gpt-4o-mini', $org->id, 800, 1000, 200, 0.000450);
    $telemetry->recordAiCall('insight', 'openai', 'gpt-4o-mini', $org->id, 1200, 900, 150, 0.000390);
    $telemetry->recordAiCall('embedding', 'openai', 'text-embedding-3-small', $org->id, 200, 5000, 0, 0.000100);

    $summary = $telemetry->aiSummary(24);

    expect($summary['totals']['calls'])->toBe(3)
        ->and($summary['totals']['tokens_in'])->toBe(6900)
        // Sub-cent costs must survive: rounding to 2dp reports every call free.
        ->and($summary['totals']['cost_usd'])->toBe(0.0009)
        ->and($summary['breakdown'])->toHaveCount(2);

    $insight = collect($summary['breakdown'])->firstWhere('kind', 'insight');
    expect($insight['calls'])->toBe(2)
        ->and($insight['avg_ms'])->toBe(1000)
        ->and($insight['max_ms'])->toBe(1200);
});

it('counts AI failures separately from successes', function () {
    [, $org] = makeOrgUser('obs-ai-fail@example.com');
    $telemetry = app(Telemetry::class);

    $telemetry->recordAiCall('insight', 'openai', 'gpt-4o-mini', $org->id, 500, null, null, null, failed: true);
    $telemetry->recordAiCall('insight', 'openai', 'gpt-4o-mini', $org->id, 500, null, null, null);

    $row = collect($telemetry->aiSummary(24)['breakdown'])->firstWhere('kind', 'insight');

    expect($row['calls'])->toBe(2)
        ->and($row['failures'])->toBe(1)
        ->and($row['failure_rate'])->toBe(50.0);
});

it('never lets a telemetry failure break the thing it is measuring', function () {
    $telemetry = app(Telemetry::class);

    // Drop the table out from under it: recording must swallow and continue.
    DB::statement('DROP TABLE telemetry_requests');

    $telemetry->recordRequest('sites/{site}/insights', 'GET', 200, 100);

    // The failure was contained — an unrelated write still succeeds, which is
    // what "did not break the request" actually means.
    $telemetry->recordAiCall('insight', 'openai', 'gpt-4o-mini', null, 100);

    expect(DB::table('telemetry_ai_usage')->count())->toBe(1);
});

it('does not poison the caller\'s transaction when a telemetry write fails', function () {
    // The failure mode this guards against: in PostgreSQL a failed statement
    // aborts the whole transaction, so telemetry erroring mid-request would
    // make every subsequent query fail with SQLSTATE 25P02 — the measurement
    // killing the thing it measures, and reporting the wrong error while
    // doing it. The savepoint has to confine the damage.
    DB::beginTransaction();

    DB::table('telemetry_requests')->insert([
        'bucket_hour' => now()->utc()->startOfHour(), 'route' => 'before', 'method' => 'GET', 'status' => 200,
        'requests' => 1, 'duration_ms_total' => 5, 'duration_ms_max' => 5,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // A NOT NULL violation inside the caller's open transaction.
    app(Telemetry::class)->recordRequest('sites/{site}/insights', 'GET', 200, 100);
    DB::table('telemetry_ai_usage')->insert(['bucket_hour' => now()->utc()->startOfHour(), 'kind' => 'x', 'provider' => 'p', 'model' => 'm']);

    app(Telemetry::class)->recordAiCall('insight', str_repeat('x', 5000), 'model', null, 10);

    // The transaction must still be usable afterwards.
    $survived = DB::table('telemetry_requests')->where('route', 'before')->exists();

    DB::rollBack();

    expect($survived)->toBeTrue();
});

it('prunes buckets past the retention window and keeps the rest', function () {
    $telemetry = app(Telemetry::class);

    DB::table('telemetry_requests')->insert([
        ['bucket_hour' => now()->utc()->subDays(60)->startOfHour(), 'route' => 'old', 'method' => 'GET', 'status' => 200,
            'requests' => 1, 'duration_ms_total' => 10, 'duration_ms_max' => 10, 'created_at' => now(), 'updated_at' => now()],
        ['bucket_hour' => now()->utc()->startOfHour(), 'route' => 'new', 'method' => 'GET', 'status' => 200,
            'requests' => 1, 'duration_ms_total' => 10, 'duration_ms_max' => 10, 'created_at' => now(), 'updated_at' => now()],
    ]);

    $telemetry->prune(30);

    $routes = DB::table('telemetry_requests')->whereIn('route', ['old', 'new'])->pluck('route')->all();

    expect($routes)->toBe(['new']);
});

it('prunes on the scheduled command and refuses a nonsense window', function () {
    DB::table('telemetry_requests')->insert([
        'bucket_hour' => now()->utc()->subDays(90)->startOfHour(), 'route' => 'ancient', 'method' => 'GET',
        'status' => 200, 'requests' => 1, 'duration_ms_total' => 10, 'duration_ms_max' => 10,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // A nonsense window is rejected outright rather than silently falling
    // back to the configured default.
    $this->artisan('insightistic:telemetry-prune', ['--days' => 0])->assertFailed();
    expect(DB::table('telemetry_requests')->where('route', 'ancient')->count())->toBe(1);

    $this->artisan('insightistic:telemetry-prune')->assertSuccessful();
    expect(DB::table('telemetry_requests')->where('route', 'ancient')->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Queue health
// ---------------------------------------------------------------------------

it('reads queue depth and the oldest waiting job on the database driver', function () {
    config(['queue.default' => 'database']);

    DB::table('jobs')->insert([
        ['queue' => 'default', 'payload' => '{}', 'attempts' => 0,
            'available_at' => now()->subMinutes(45)->timestamp, 'created_at' => now()->timestamp],
        ['queue' => 'default', 'payload' => '{}', 'attempts' => 0,
            'available_at' => now()->subMinute()->timestamp, 'created_at' => now()->timestamp],
    ]);

    Cache::put(QueueHealth::HEARTBEAT_KEY, now()->toIso8601String(), now()->addDay());

    $snapshot = app(QueueHealth::class)->snapshot();

    expect($snapshot['pending'])->toBe(2)
        ->and($snapshot['oldest_pending_age_seconds'])->toBeGreaterThan(2400)
        // Depth alone is not the signal — a 45-minute-old job is the finding.
        ->and($snapshot['status']['level'])->toBe('critical');
});

it('calls a silent worker critical only when work is actually waiting', function () {
    config(['queue.default' => 'database']);
    Cache::forget(QueueHealth::HEARTBEAT_KEY);

    // Nothing queued and no worker seen: idle looks identical to dead, and
    // paging on it is how alerts get muted.
    $idle = app(QueueHealth::class)->snapshot();
    expect($idle['status']['level'])->toBe('warning')
        ->and($idle['worker']['stale'])->toBeTrue();

    DB::table('jobs')->insert([
        'queue' => 'default', 'payload' => '{}', 'attempts' => 0,
        'available_at' => now()->timestamp, 'created_at' => now()->timestamp,
    ]);

    // Same silence, but now something is waiting on it.
    expect(app(QueueHealth::class)->snapshot()['status']['level'])->toBe('critical');
});

it('reports a healthy queue when a worker is live and nothing is stuck', function () {
    config(['queue.default' => 'database']);
    QueueHealth::recordHeartbeat();

    $snapshot = app(QueueHealth::class)->snapshot();

    expect($snapshot['status']['level'])->toBe('ok')
        ->and($snapshot['worker']['stale'])->toBeFalse()
        ->and($snapshot['pending'])->toBe(0);
});

it('summarises failed jobs without dumping the whole trace', function () {
    config(['queue.default' => 'database']);

    DB::table('failed_jobs')->insert([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'connection' => 'database', 'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\SyncDataSource']),
        'exception' => "RuntimeException: token expired\n#0 /app/vendor/deep/stack/trace.php(1)\n#1 more",
        'failed_at' => now(),
    ]);

    $failed = app(QueueHealth::class)->snapshot()['failed'];

    expect($failed['total'])->toBe(1)
        ->and($failed['last_24h'])->toBe(1)
        ->and($failed['recent'][0]['job'])->toBe('App\\Jobs\\SyncDataSource')
        ->and($failed['recent'][0]['error'])->toContain('token expired')
        // Traces are megabytes and carry job payloads, which carry tenant data.
        ->and($failed['recent'][0]['error'])->not->toContain('stack/trace.php');
});

// ---------------------------------------------------------------------------
// Tracing
// ---------------------------------------------------------------------------

it('stays inert and still runs the work when tracing is disabled', function () {
    config(['otel.enabled' => false, 'otel.endpoint' => null]);

    $tracing = app(Tracing::class);
    $ran = false;

    $result = $tracing->span('test.span', function () use (&$ran) {
        $ran = true;

        return 'value';
    });

    expect($tracing->enabled())->toBeFalse()
        ->and($ran)->toBeTrue()
        ->and($result)->toBe('value');
});

it('stays disabled when enabled is on but no exporter endpoint is set', function () {
    // An exporter with nowhere to send is just latency.
    config(['otel.enabled' => true, 'otel.endpoint' => null]);

    expect(app(Tracing::class)->enabled())->toBeFalse();
});

it('lets an exception out of a span rather than swallowing it', function () {
    config(['otel.enabled' => false]);

    expect(fn () => app(Tracing::class)->span('boom', fn () => throw new \RuntimeException('nope')))
        ->toThrow(\RuntimeException::class, 'nope');
});

// ---------------------------------------------------------------------------
// HTTP surface
// ---------------------------------------------------------------------------

it('serves the observability dashboard to a super admin', function () {
    [$user] = makeOrgUser('obs-admin@example.com');
    $user->forceFill(['is_super_admin' => true])->save();

    app(Telemetry::class)->recordRequest('sites/{site}/insights', 'GET', 200, 150);

    $body = $this->actingAs($user)->getJson('/api/admin/observability')->assertOk()->json();

    expect($body['requests']['has_data'])->toBeTrue()
        ->and($body['queue'])->toHaveKey('status')
        ->and($body['exporters']['errors']['driver'])->toBe('sentry')
        ->and($body['exporters']['tracing']['driver'])->toBe('opentelemetry');
});

it('never returns the Sentry DSN or exporter credentials', function () {
    [$user] = makeOrgUser('obs-secrets@example.com');
    $user->forceFill(['is_super_admin' => true])->save();

    config([
        'sentry.dsn'    => 'https://publickey@o123.ingest.sentry.io/456',
        'otel.endpoint' => 'https://collector.example.com/v1/traces',
        'otel.headers'  => 'x-api-key=super-secret-value',
    ]);

    $raw = $this->actingAs($user)->getJson('/api/admin/observability')->assertOk()->content();

    expect($raw)->not->toContain('publickey')
        ->and($raw)->not->toContain('ingest.sentry.io')
        ->and($raw)->not->toContain('super-secret-value')
        // Configured/not-configured is the useful signal, and it survives.
        ->and(json_decode($raw, true)['exporters']['errors']['configured'])->toBeTrue();
});

it('keeps platform observability away from ordinary organization users', function () {
    [$owner] = makeOrgUser('obs-owner@example.com');

    // Route latency spans every tenant and AI spend is aggregate — exposing
    // either would leak the shape of other customers' usage.
    $this->actingAs($owner)->getJson('/api/admin/observability')->assertForbidden();

    app('auth')->forgetGuards();

    $this->getJson('/api/admin/observability')->assertUnauthorized();
});

it('bounds the reporting window instead of scanning all history', function () {
    [$user] = makeOrgUser('obs-window@example.com');
    $user->forceFill(['is_super_admin' => true])->save();

    $body = $this->actingAs($user)->getJson('/api/admin/observability?hours=99999')->assertOk()->json();

    expect($body['window_hours'])->toBe(720);
});

it('records timings for real requests through the middleware', function () {
    [$user] = makeOrgUser('obs-middleware@example.com');

    $this->actingAs($user)->getJson('/api/auth/me')->assertOk();

    $row = DB::table('telemetry_requests')->where('route', 'api/auth/me')->first();

    expect($row)->not->toBeNull()
        ->and((int) $row->requests)->toBe(1)
        ->and((int) $row->status)->toBe(200);
});

it('collapses unmatched paths into one bucket so scanners cannot flood the table', function () {
    foreach (['/api/nope-one', '/api/nope-two', '/api/nope-three'] as $path) {
        $this->getJson($path)->assertNotFound();
    }

    // One bucket for all three: a scanner probing a thousand random paths
    // would otherwise write a thousand rows an hour.
    expect((int) DB::table('telemetry_requests')->where('route', '__unmatched')->value('requests'))->toBe(3)
        ->and(DB::table('telemetry_requests')->where('route', '__unmatched')->count())->toBe(1);
});
