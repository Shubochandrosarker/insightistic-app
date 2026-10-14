<?php

namespace App\Services\Knowledge;

use App\Services\Ai\AiRuntimeSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Read-only adapter for the WPistic AIAgents / knowledge-brain Worker.
 *
 * It imports shared agent metadata, category knowledge summaries, and approved
 * skills. Tenant documents and analytics never leave Insightistic through
 * this client; they remain in the local, tenant-isolated retriever.
 */
class WpisticKnowledgeAgentClient
{
    public function __construct(private AiRuntimeSettings $runtime) {}

    /**
     * @return array{agent: ?array, knowledge: array<int,array>, skills: array<int,array>}
     */
    public function search(string $question, ?string $category = null, ?string $agentSlug = null): array
    {
        $cfg = $this->runtime->resolved()['knowledge_agent'];
        if (! $cfg['enabled'] || trim((string) $cfg['base_url']) === '') {
            return ['agent' => null, 'knowledge' => [], 'skills' => []];
        }

        $category = trim((string) ($category ?: $cfg['category']));
        $agentSlug = trim((string) ($agentSlug ?: $cfg['agent_slug']));
        $key = 'wpistic:brain:'.sha1($question.'|'.$category.'|'.$agentSlug);

        return Cache::remember($key, now()->addMinutes(5), function () use ($cfg, $question, $category, $agentSlug) {
            try {
                $request = Http::acceptJson()->timeout((int) $cfg['timeout']);
                if (! empty($cfg['token'])) {
                    $request = $request->withToken((string) $cfg['token']);
                }

                $base = rtrim((string) $cfg['base_url'], '/');
                $agents = $request->get($base.'/api/agents');
                if (! $agents->successful() || ! is_array($agents->json())) {
                    return ['agent' => null, 'knowledge' => [], 'skills' => []];
                }

                $profiles = collect($agents->json());
                $agent = $profiles->first(fn ($item) => ($item['slug'] ?? null) === $agentSlug)
                    ?: $profiles->first(fn ($item) => strtolower((string) ($item['category'] ?? '')) === strtolower($category));
                $activeSlug = (string) ($agent['slug'] ?? $agentSlug);

                $knowledgeResponse = $request->get($base.'/api/knowledge', ['agent' => $activeSlug]);
                $skillsResponse = $request->get($base.'/api/skills', ['agent' => $activeSlug]);
                $terms = $this->terms($question.' '.$category);

                return [
                    'agent' => $agent,
                    'knowledge' => $this->rank((array) ($knowledgeResponse->json() ?? []), $terms, 5),
                    'skills' => $this->rank((array) ($skillsResponse->json() ?? []), $terms, 3),
                ];
            } catch (\Throwable) {
                // The shared brain is an enrichment source. Local Insightistic
                // evidence must continue working if the Worker is unavailable.
                return ['agent' => null, 'knowledge' => [], 'skills' => []];
            }
        });
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
