<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * OpenAI-compatible Chat Completions provider. Requests a strict JSON object so
 * the response maps cleanly onto the ai_insights schema.
 */
class OpenAiProvider implements AiProvider
{
    public function __construct(private array $config) {}

    public function model(): string
    {
        return $this->config['model'] ?? 'gpt-4o-mini';
    }

    public function generate(string $system, array $context): ?array
    {
        try {
            $response = Http::withToken($this->config['api_key'])
                ->timeout($this->config['timeout'] ?? 60)
                ->post(rtrim($this->config['base_url'], '/') . '/chat/completions', [
                    'model'           => $this->model(),
                    'temperature'     => 0.3,
                    'response_format' => ['type' => 'json_object'],
                    'messages'        => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => json_encode($context, JSON_UNESCAPED_SLASHES)],
                    ],
                ]);

            if (! $response->successful()) {
                Log::warning('Insightistic AI call failed', ['status' => $response->status()]);
                return null;
            }

            $content = $response->json('choices.0.message.content');
            if (! $content) {
                return null;
            }

            $parsed = json_decode($content, true);
            return is_array($parsed) ? $parsed : null;
        } catch (\Throwable $e) {
            Log::warning('Insightistic AI exception: ' . $e->getMessage());
            return null;
        }
    }
}
