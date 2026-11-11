<?php

use App\Services\Knowledge\WpisticKnowledgeAgentClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Shared knowledge brain: Cloudflare Access service-token headers, the MCP
 * (VPS memory) driver, and the clean fallback when the brain is slow,
 * redirecting to a login page, or unauthorized.
 */
function brainConfig(array $overrides = []): void
{
    config(['insightistic.knowledge_agent' => array_merge([
        'enabled' => true,
        'base_url' => 'https://brain.example.test',
        'token' => null,
        'agent_slug' => 'analytics-pro',
        'category' => 'analytics',
        'timeout' => 4,
        'driver' => 'mcp',
        'mcp_path' => '/mcp',
        'mcp_kinds' => ['project', 'system'],
        'cf_access_client_id' => 'test-id.access',
        'cf_access_client_secret' => 'test-secret',
        'connect_timeout' => 2,
        'budget_ms' => 5000,
        'cooldown_seconds' => 120,
    ], $overrides)]);
    Cache::forget(WpisticKnowledgeAgentClient::DOWN_KEY);
}

function mcpSearchResponse(array $results): array
{
    return [
        'jsonrpc' => '2.0',
        'id' => 1,
        'result' => ['content' => [['type' => 'text', 'text' => json_encode(['results' => $results])]]],
    ];
}

it('calls the MCP brain with Cloudflare Access headers and keeps only allowed kinds', function () {
    brainConfig();
    Http::fake(['brain.example.test/mcp' => Http::response(mcpSearchResponse([
        ['id' => 'project:insightistic', 'kind' => 'project', 'label' => 'Insightistic', 'path' => 'products/insightistic.md', 'todos' => ['internal']],
        ['id' => 'client:acme', 'kind' => 'client', 'label' => 'Acme'],
        ['id' => 'decision:x', 'kind' => 'decision', 'label' => 'Internal decision'],
    ]))]);

    $out = app(WpisticKnowledgeAgentClient::class)->search('insightistic analytics');

    expect($out['knowledge'])->toHaveCount(1)
        ->and($out['knowledge'][0]['id'])->toBe('project:insightistic')
        ->and($out['knowledge'][0])->not->toHaveKey('todos');

    Http::assertSent(fn (Request $r) => $r->url() === 'https://brain.example.test/mcp'
        && $r->hasHeader('CF-Access-Client-Id', 'test-id.access')
        && $r->hasHeader('CF-Access-Client-Secret', 'test-secret')
        && $r['method'] === 'tools/call'
        && $r['params']['name'] === 'memory_search');
});

it('parses an SSE-framed MCP answer', function () {
    brainConfig();
    Http::fake(['brain.example.test/mcp' => Http::response(
        "event: message\ndata: ".json_encode(mcpSearchResponse([['id' => 'system:vps', 'kind' => 'system', 'label' => 'VPS']]))."\n\n",
        200,
        ['Content-Type' => 'text/event-stream'],
    )]);

    expect(app(WpisticKnowledgeAgentClient::class)->search('vps')['knowledge'])->toHaveCount(1);
});

it('treats a redirect to a login page as unavailable and stops calling during the cooldown', function () {
    brainConfig();
    Http::fake(['*' => Http::response('', 302, ['Location' => 'https://brain.example.test/login'])]);

    $client = app(WpisticKnowledgeAgentClient::class);
    expect($client->search('first question'))->toBe(['agent' => null, 'knowledge' => [], 'skills' => []])
        ->and(Cache::get(WpisticKnowledgeAgentClient::DOWN_KEY))->toBe('redirected_http_302_auth_rejected');

    $client->search('second question');
    Http::assertSentCount(1); // the cooldown skipped the second call
});

it('falls back cleanly when the brain times out', function () {
    brainConfig();
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

    $out = app(WpisticKnowledgeAgentClient::class)->search('slow brain');

    expect($out)->toBe(['agent' => null, 'knowledge' => [], 'skills' => []])
        ->and(Cache::get(WpisticKnowledgeAgentClient::DOWN_KEY))->toBe('timeout_or_connect_error');
});

it('reports unauthorized on the REST driver and returns the empty shape', function () {
    brainConfig(['driver' => 'rest', 'cf_access_client_id' => null, 'cf_access_client_secret' => null]);
    Http::fake(['brain.example.test/api/agents' => Http::response(['error' => 'unauthorized'], 401)]);

    expect(app(WpisticKnowledgeAgentClient::class)->search('anything')['knowledge'])->toBe([])
        ->and(Cache::get(WpisticKnowledgeAgentClient::DOWN_KEY))->toBe('unauthorized_http_401');

    Http::assertSent(fn (Request $r) => ! $r->hasHeader('CF-Access-Client-Id'));
});

it('makes no request when the brain is disabled', function () {
    brainConfig(['enabled' => false]);
    Http::fake();

    app(WpisticKnowledgeAgentClient::class)->search('anything');

    Http::assertNothingSent();
});

it('exposes only booleans about the Access credentials in the public settings', function () {
    brainConfig();
    $public = app(\App\Services\Ai\AiRuntimeSettings::class)->public();

    expect($public['credential_status']['cf_access_configured'])->toBeTrue()
        ->and(json_encode($public))->not->toContain('test-secret');
});
