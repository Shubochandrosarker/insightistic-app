<?php

use App\Models\IndexNowKey;
use App\Models\IndexNowSubmission;
use App\Models\Site;
use Illuminate\Support\Facades\Http;

/**
 * IndexNow connector bridge (Phase 6, docs/roadmap-2026/11-BING-WEBMASTER-
 * INDEXNOW §IndexNow/Plugin workflow): HMAC key roundtrip, key-file
 * confirmation, plugin-submitted URLs and host eligibility.
 *
 * Helper names are deliberately distinct from ConnectorHmacTest's globals —
 * Pest loads every test file in one process, so shared names would fatal.
 */

function indexnowConnectedSite(\App\Models\Organization $org): array
{
    $site = Site::withoutGlobalScope('organization')->create([
        'organization_id'  => $org->id,
        'name'             => 'Store',
        'domain'           => 'shop.example.com',
        'connector_key_id' => 'ik_' . \Illuminate\Support\Str::random(24),
    ]);
    $secret = 'sk_' . \Illuminate\Support\Str::random(48);
    $site->forceFill(['connector_secret' => $secret])->save();

    return [$site, $secret];
}

function indexnowHmac(string $keyId, string $secret, string $method, string $path, string $body = ''): array
{
    $timestamp = (string) time();
    $nonce = \Illuminate\Support\Str::uuid()->toString();
    $canonical = implode("\n", [strtoupper($method), $path, $timestamp, $nonce, hash('sha256', $body)]);

    return [
        'X-INS-Key-Id'    => $keyId,
        'X-INS-Timestamp' => $timestamp,
        'X-INS-Nonce'     => $nonce,
        'X-INS-Signature' => hash_hmac('sha256', $canonical, $secret),
        'Content-Type'    => 'application/json',
        'Accept'          => 'application/json',
    ];
}

it('rejects unsigned connector requests to the IndexNow bridge', function () {
    [, $org] = makeOrgUser('indexnow-conn-open@example.com');
    indexnowConnectedSite($org);

    $this->getJson('/api/connector/v1/indexnow/key')->assertUnauthorized();
    $this->postJson('/api/connector/v1/indexnow/submit', ['urls' => []])->assertUnauthorized();

    expect(IndexNowKey::count())->toBe(0);
});

it('issues a stable key and location for the plugin to host', function () {
    [, $org] = makeOrgUser('indexnow-conn-key@example.com');
    [$site, $secret] = indexnowConnectedSite($org);

    $first = $this->call('GET', '/api/connector/v1/indexnow/key', [], [], [], $this->transformHeadersToServerVars(indexnowHmac($site->connector_key_id, $secret, 'GET', '/api/connector/v1/indexnow/key')))
        ->assertOk();

    $key = $first->json('key');
    expect($key)->toMatch('/^[0-9a-f]{32}$/')
        ->and($first->json('key_location'))->toBe('https://shop.example.com/' . $key . '.txt')
        ->and($first->json('key_file_confirmed'))->toBeFalse();

    // A second read returns the SAME key — no silent rotation (the plugin may
    // already have written the key file).
    $this->call('GET', '/api/connector/v1/indexnow/key', [], [], [], $this->transformHeadersToServerVars(indexnowHmac($site->connector_key_id, $secret, 'GET', '/api/connector/v1/indexnow/key')))
        ->assertOk()->assertJsonPath('key', $key);

    expect(IndexNowKey::where('site_id', $site->id)->count())->toBe(1);
});

it('records the plugin confirmation that the key file is served', function () {
    [, $org] = makeOrgUser('indexnow-conn-confirm@example.com');
    [$site, $secret] = indexnowConnectedSite($org);

    $this->call('GET', '/api/connector/v1/indexnow/key', [], [], [], $this->transformHeadersToServerVars(indexnowHmac($site->connector_key_id, $secret, 'GET', '/api/connector/v1/indexnow/key')))
        ->assertOk()->assertJsonPath('key_file_confirmed', false);

    // Plugin wrote {key}.txt and reports back (?confirmed=1). The HMAC
    // canonical string covers the PATH only — query params ride unsigned.
    $this->call('GET', '/api/connector/v1/indexnow/key?confirmed=1', [], [], [], $this->transformHeadersToServerVars(indexnowHmac($site->connector_key_id, $secret, 'GET', '/api/connector/v1/indexnow/key')))
        ->assertOk()->assertJsonPath('key_file_confirmed', true);

    expect((bool) IndexNowKey::where('site_id', $site->id)->value('key_file_confirmed'))->toBeTrue();
});

it('refuses plugin submissions before the key is initialized', function () {
    [, $org] = makeOrgUser('indexnow-conn-init@example.com');
    [$site, $secret] = indexnowConnectedSite($org);

    $body = json_encode(['urls' => ['https://shop.example.com/a']]);
    $this->call('POST', '/api/connector/v1/indexnow/submit', [], [], [], $this->transformHeadersToServerVars(indexnowHmac($site->connector_key_id, $secret, 'POST', '/api/connector/v1/indexnow/submit', $body)), $body)
        ->assertStatus(422)->assertJsonPath('code', 'indexnow_not_initialized');

    expect(IndexNowSubmission::count())->toBe(0)
        ->and(IndexNowKey::where('site_id', $site->id)->count())->toBe(0);
});

it('logs plugin submissions with source=plugin', function () {
    [, $org] = makeOrgUser('indexnow-conn-submit@example.com');
    [$site, $secret] = indexnowConnectedSite($org);

    $this->call('GET', '/api/connector/v1/indexnow/key', [], [], [], $this->transformHeadersToServerVars(indexnowHmac($site->connector_key_id, $secret, 'GET', '/api/connector/v1/indexnow/key')))
        ->assertOk();

    Http::fake(['*api.indexnow.org*' => Http::response([], 202)]);

    $body = json_encode(['urls' => ['https://shop.example.com/new-post', 'https://shop.example.com/updated-page#top']]);
    $this->call('POST', '/api/connector/v1/indexnow/submit', [], [], [], $this->transformHeadersToServerVars(indexnowHmac($site->connector_key_id, $secret, 'POST', '/api/connector/v1/indexnow/submit', $body)), $body)
        ->assertOk()->assertJson([
            'submitted' => 2, 'accepted' => 2, 'failed' => 0,
            'skipped_duplicate' => 0, 'skipped_invalid_host' => 0,
        ]);

    $rows = IndexNowSubmission::where('site_id', $site->id)->get();
    expect($rows)->toHaveCount(2)
        ->and($rows->every(fn ($r) => $r->source === 'plugin'))->toBeTrue()
        ->and($rows->every(fn ($r) => $r->status === 'accepted'))->toBeTrue()
        // Fragment stripped during normalization before hashing/sending.
        ->and($rows->pluck('url')->contains('https://shop.example.com/updated-page'))->toBeTrue();
});

it('enforces host eligibility for plugin submissions', function () {
    [, $org] = makeOrgUser('indexnow-conn-host@example.com');
    [$site, $secret] = indexnowConnectedSite($org);

    $this->call('GET', '/api/connector/v1/indexnow/key', [], [], [], $this->transformHeadersToServerVars(indexnowHmac($site->connector_key_id, $secret, 'GET', '/api/connector/v1/indexnow/key')))
        ->assertOk();

    Http::fake(['*api.indexnow.org*' => Http::response([], 200)]);

    $body = json_encode(['urls' => ['https://other-site.example.net/injected']]);
    $this->call('POST', '/api/connector/v1/indexnow/submit', [], [], [], $this->transformHeadersToServerVars(indexnowHmac($site->connector_key_id, $secret, 'POST', '/api/connector/v1/indexnow/submit', $body)), $body)
        ->assertOk()->assertJson(['submitted' => 0, 'skipped_invalid_host' => 1]);

    Http::assertSentCount(0); // nothing left the building
    expect(IndexNowSubmission::where('site_id', $site->id)->count())->toBe(0);
});
