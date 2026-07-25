<?php

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeSource;
use App\Models\Site;

/**
 * Connector HMAC contract tests — mirrors the WordPress plugin's signing
 * exactly (includes/class-client.php): canonical string
 * METHOD\nPATH\nTIMESTAMP\nNONCE\nsha256(body), hex HMAC-SHA256.
 */

function makeConnectedSite(\App\Models\Organization $org): array
{
    $site = Site::withoutGlobalScope('organization')->create([
        'organization_id'  => $org->id,
        'name'             => 'Test Store',
        'domain'           => 'store.example.com',
        'connector_key_id' => 'ik_' . \Illuminate\Support\Str::random(24),
    ]);
    $secret = 'sk_' . \Illuminate\Support\Str::random(48);
    $site->forceFill(['connector_secret' => $secret])->save();

    return [$site, $secret];
}

function hmacHeaders(string $keyId, string $secret, string $method, string $path, string $body, ?string $nonce = null, ?int $timestamp = null): array
{
    $timestamp = (string) ($timestamp ?? time());
    $nonce = $nonce ?? \Illuminate\Support\Str::uuid()->toString();
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

it('accepts a correctly signed connector request (plugin parity)', function () {
    [, $org] = makeOrgUser('hmac@example.com');
    [$site, $secret] = makeConnectedSite($org);

    $body = json_encode(['site_health' => ['wp_version' => '6.7']]);
    $headers = hmacHeaders($site->connector_key_id, $secret, 'POST', '/api/connector/v1/ping', '{}');

    $this->call('POST', '/api/connector/v1/ping', [], [], [], $this->transformHeadersToServerVars($headers), '{}')
        ->assertOk();
});

it('rejects a bad signature, an expired timestamp and a replayed nonce', function () {
    [, $org] = makeOrgUser('hmac-neg@example.com');
    [$site, $secret] = makeConnectedSite($org);

    // Wrong secret.
    $bad = hmacHeaders($site->connector_key_id, 'sk_wrong', 'POST', '/api/connector/v1/ping', '{}');
    $this->call('POST', '/api/connector/v1/ping', [], [], [], $this->transformHeadersToServerVars($bad), '{}')
        ->assertUnauthorized();

    // Stale timestamp (> 300s skew).
    $stale = hmacHeaders($site->connector_key_id, $secret, 'POST', '/api/connector/v1/ping', '{}', timestamp: time() - 3600);
    $this->call('POST', '/api/connector/v1/ping', [], [], [], $this->transformHeadersToServerVars($stale), '{}')
        ->assertUnauthorized();

    // Nonce replay: same nonce twice.
    $nonce = \Illuminate\Support\Str::uuid()->toString();
    $first = hmacHeaders($site->connector_key_id, $secret, 'POST', '/api/connector/v1/ping', '{}', nonce: $nonce);
    $this->call('POST', '/api/connector/v1/ping', [], [], [], $this->transformHeadersToServerVars($first), '{}')
        ->assertOk();
    $replay = hmacHeaders($site->connector_key_id, $secret, 'POST', '/api/connector/v1/ping', '{}', nonce: $nonce);
    $this->call('POST', '/api/connector/v1/ping', [], [], [], $this->transformHeadersToServerVars($replay), '{}')
        ->assertUnauthorized();
});

it('ingests WordPress content into a site-scoped knowledge base via the connector', function () {
    [, $org] = makeOrgUser('hmac-content@example.com');
    [$site, $secret] = makeConnectedSite($org);

    $body = json_encode(['items' => [[
        'external_id' => 42,
        'type'        => 'page',
        'title'       => 'About us',
        'url'         => 'https://store.example.com/about',
        'content'     => 'We are a family business selling handmade candles since 2010.',
        'modified_at' => now()->toIso8601String(),
    ]]]);

    $headers = hmacHeaders($site->connector_key_id, $secret, 'POST', '/api/connector/v1/content/bulk', $body);
    $res = $this->call('POST', '/api/connector/v1/content/bulk', [], [], [], $this->transformHeadersToServerVars($headers), $body);

    $res->assertOk()->assertJsonPath('accepted', 1);

    $source = KnowledgeSource::withoutGlobalScope('organization')
        ->where('organization_id', $org->id)
        ->where('metadata_json->external_id', 42)
        ->firstOrFail();

    expect($source->status)->toBe('indexed'); // sync queue in tests
    expect($source->site_id)->toBe($site->id);

    // Deletion event removes the source AND its chunks.
    $delBody = json_encode(['items' => [[
        'external_id' => 42, 'type' => 'page', 'title' => '(deleted)', 'content' => '-', 'deleted' => true,
    ]]]);
    $delHeaders = hmacHeaders($site->connector_key_id, $secret, 'POST', '/api/connector/v1/content/bulk', $delBody);
    $this->call('POST', '/api/connector/v1/content/bulk', [], [], [], $this->transformHeadersToServerVars($delHeaders), $delBody)
        ->assertOk()->assertJsonPath('deleted', 1);

    expect(KnowledgeChunk::withoutGlobalScope('organization')->where('knowledge_source_id', $source->id)->count())->toBe(0);
});
