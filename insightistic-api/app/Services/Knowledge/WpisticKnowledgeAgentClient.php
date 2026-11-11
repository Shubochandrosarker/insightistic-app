<?php

namespace App\Services\Knowledge;

use App\Services\Ai\AiRuntimeSettings;
use App\Support\SafeLog;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Read-only adapter for the shared WPistic knowledge brain.
 *
 * Two backends:
 *  - rest: the brain-api Worker (/api/agents, /api/knowledge, /api/skills)
 *  - mcp:  the WPistic memory MCP server (tools/call memory_search), e.g. the
 *          VPS brain at https://brain.insightistic.com/mcp behind Cloudflare
 *          Access (service-token headers).
 *
 * Tenant documents and analytics never leave Insightistic through this
 * client; they stay in the local, tenant-isolated retriever.
 *
 * The brain is enrichment only, so it must fail fast and quietly:
 *  - connect timeout + one total time budget for the whole lookup;
 *  - redirects are not followed (a 302 to a login page means auth failed);
 *  - any failure trips a cooldown during which the brain is not called,
 *    logs one warning with the reason, and returns the empty shape so the
 *    answer is built from local knowledge.
 */
class WpisticKnowledgeAgentClient
{
    public const DOWN_KEY = 'wpistic:brain:down';

    private const EMPTY = ['agent' => null, 'knowledge' => [], 'skills' => []];

    private float $startedAt = 0.0;

    public function __construct(private AiRuntimeSettings $runtime) {}

    /**
     * @return array{agent: ?array, knowledge: array<int,array>, skills: array<int,array>}
     */
    public function search(string $question, ?string $category = null, ?string $agentSlug = null): array
    {
        $cfg = $this->runtime->resolved()['knowledge_agent'];
        if (! $cfg['enabled'] || trim((string) $cfg['base_url']) === '') {
            return self::EMPTY;
        }

        if (Cache::has(self::DOWN_KEY)) {
            return self::EMPTY; // cooling down after a failure
        }

        $category = trim((string) ($category ?: $cfg['category']));
        $agentSlug = trim((string) ($agentSlug ?: $cfg['agent_slug']));
        $key = 'wpistic:brain:'.sha1($cfg['driver'].'|'.$question.'|'.$category.'|'.$agentSlug);

        if (is_array($cached = Cache::get($key))) {
            return $cached;
        }

        $this->startedAt = microtime(true);

        try {
            $result = $cfg['driver'] === 'mcp'
                ? $this->searchMcp($cfg, $question)
                : $this->searchRest($cfg, $question, $category, $agentSlug);
        } catch (BrainUnavailable $e) {
            $this->trip($cfg, $e->getMessage());

            return self::EMPTY;
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $this->trip($cfg, 'timeout_or_connect_error');

            return self::EMPTY;
        } catch (\Throwable $e) {
            $this->trip($cfg, 'exception:'.class_basename($e));

            return self::EMPTY;
        }

        Cache::put($key, $result, now()->addMinutes(5));

        return $result;
    }

    private function searchRest(array $cfg, string $question, string $category, string $agentSlug): array
    {
        $base = rtrim((string) $cfg['base_url'], '/');

        $agents = $this->json($this->request($cfg)->get($base.'/api/agents'));
        $agents = isset($agents['agents']) && is_array($agents['agents']) ? $agents['agents'] : $agents;

        $profiles = collect($agents);
        $agent = $profiles->first(fn ($item) => ($item['slug'] ?? null) === $agentSlug)
            ?: $profiles->first(fn ($item) => strtolower((string) ($item['category'] ?? '')) === strtolower($category));
        $activeSlug = (string) ($agent['slug'] ?? $agentSlug);

        $knowledge = $this->json($this->request($cfg)->get($base.'/api/knowledge', ['agent' => $activeSlug]));
        $skills = $this->json($this->request($cfg)->get($base.'/api/skills', ['agent' => $activeSlug]));
        $terms = $this->terms($question.' '.$category);

        return [
            'agent' => $agent,
            'knowledge' => $this->rank($knowledge, $terms, 5),
            'skills' => $this->rank($skills, $terms, 3),
        ];
    }

    private function searchMcp(array $cfg, string $question): array
    {
        $url = rtrim((string) $cfg['base_url'], '/').'/'.ltrim((string) $cfg['mcp_path'], '/');

        $response = $this->request($cfg)
            ->withHeaders(['Accept' => 'application/json, text/event-stream'])
            ->post($url, [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => 'memory_search', 'arguments' => ['query' => $question, 'limit' => 20]],
            ]);

        $this->guard($response);
        $envelope = $this->mcpEnvelope($response);

        if (isset($envelope['error'])) {
            throw new BrainUnavailable('mcp_error');
        }

        $text = data_get($envelope, 'result.content.0.text');
        $payload = is_string($text) ? json_decode($text, true) : data_get($envelope, 'result.structuredContent');
        $results = is_array($payload) ? ($payload['results'] ?? null) : null;
        if (! is_array($results)) {
            throw new BrainUnavailable('unexpected_payload');
        }

        $kinds = array_map('strtolower', (array) $cfg['mcp_kinds']);

        $cards = collect($results)
            ->filter(fn ($card) => is_array($card) && in_array(strtolower((string) ($card['kind'] ?? '')), $kinds, true))
            // Internal to-do lists are never customer material.
            ->map(fn (array $card) => array_diff_key($card, ['todos' => true]))
            ->take(5)
            ->values()
            ->all();

        return ['agent' => null, 'knowledge' => $cards, 'skills' => []];
    }

    private function request(array $cfg): PendingRequest
    {
        $remainingMs = (int) $cfg['budget_ms'] - (int) ((microtime(true) - $this->startedAt) * 1000);
        if ($remainingMs <= 0) {
            throw new BrainUnavailable('time_budget_exceeded');
        }

        $request = Http::acceptJson()
            ->withoutRedirecting()
            ->connectTimeout((int) $cfg['connect_timeout'])
            ->timeout(max(1, min((int) $cfg['timeout'], (int) ceil($remainingMs / 1000))));

        if (! empty($cfg['token'])) {
            $request = $request->withToken((string) $cfg['token']);
        }

        if (! empty($cfg['cf_access_client_id']) && ! empty($cfg['cf_access_client_secret'])) {
            $request = $request->withHeaders([
                'CF-Access-Client-Id' => (string) $cfg['cf_access_client_id'],
                'CF-Access-Client-Secret' => (string) $cfg['cf_access_client_secret'],
            ]);
        }

        return $request;
    }

    private function guard(Response $response): void
    {
        $status = $response->status();
        if ($status >= 300 && $status < 400) {
            throw new BrainUnavailable('redirected_http_'.$status.'_auth_rejected');
        }
        if ($status === 401 || $status === 403) {
            throw new BrainUnavailable('unauthorized_http_'.$status);
        }
        if (! $response->successful()) {
            throw new BrainUnavailable('http_'.$status);
        }
    }

    private function json(Response $response): array
    {
        $this->guard($response);
        $data = $response->json();
        if (! is_array($data)) {
            throw new BrainUnavailable('non_json_response');
        }

        return $data;
    }

    /** JSON or a single-event SSE body (streamable HTTP may answer either way). */
    private function mcpEnvelope(Response $response): array
    {
        $body = (string) $response->body();
        if (str_contains((string) $response->header('Content-Type'), 'text/event-stream')) {
            foreach (preg_split('/\r?\n/', $body) ?: [] as $line) {
                if (str_starts_with($line, 'data:')) {
                    $decoded = json_decode(trim(substr($line, 5)), true);
                    if (is_array($decoded) && (isset($decoded['result']) || isset($decoded['error']))) {
                        return $decoded;
                    }
                }
            }
            throw new BrainUnavailable('unexpected_payload');
        }

        $decoded = json_decode($body, true);
        if (! is_array($decoded)) {
            throw new BrainUnavailable('non_json_response');
        }

        return $decoded;
    }

    private function trip(array $cfg, string $reason): void
    {
        $cooldown = (int) $cfg['cooldown_seconds'];
        if ($cooldown > 0) {
            Cache::put(self::DOWN_KEY, $reason, now()->addSeconds($cooldown));
        }

        SafeLog::warning('Shared knowledge brain unavailable; answering from local knowledge only', [
            'reason' => $reason,
            'driver' => $cfg['driver'],
            'host' => parse_url((string) $cfg['base_url'], PHP_URL_HOST),
            'elapsed_ms' => (int) ((microtime(true) - $this->startedAt) * 1000),
            'cooldown_seconds' => $cooldown,
        ]);
    }

    private function rank(array $items, array $terms, int $limit): array
    {
        return collect($items)
            ->filter(fn ($item) => is_array($item))
            ->map(function (array $item) use ($terms) {
                $haystack = strtolower(json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
                $score = collect($terms)->sum(fn ($term) => str_contains($haystack, $term) ? 1 : 0);

                return ['item' => $item, 'score' => $score];
            })
            ->filter(fn ($row) => $row['score'] > 0)
            ->sortByDesc('score')
            ->take($limit)
            ->pluck('item')
            ->values()
            ->all();
    }

    private function terms(string $text): array
    {
        return array_values(array_unique(array_filter(
            preg_split('/[^a-z0-9-]+/i', strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [],
            fn ($term) => strlen($term) >= 3,
        )));
    }
}
