<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** OpenRouter's OpenAI-compatible chat endpoint with ordered model fallback. */
class OpenRouterProvider implements AiProvider
{
    private ?string $actualModel = null;

    public function __construct(private array $config) {}

    public function model(): string
    {
        return 'openrouter:' . ($this->actualModel ?: ($this->config['model'] ?? 'unknown'));
    }

    public function generate(string $system, array $context, ?int $identityId = null): ?array
    {
        try {
            $payload = [
                'model'           => $this->config['model'],
                'temperature'     => 0.3,
                'response_format' => ['type' => 'json_object'],
                'messages'        => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => json_encode($context, JSON_UNESCAPED_SLASHES)],
                ],
            ];

            if (! empty($this->config['reasoning_effort'])) {
                $payload['reasoning'] = ['effort' => $this->config['reasoning_effort']];
            }

            if (! empty($this->config['require_parameters'])) {
                $payload['provider'] = ['require_parameters' => true];
            }

            if (! empty($this->config['fallback_models'])) {
                // OpenRouter tries these in order if the primary is unavailable,
                // rate-limited, refuses, or cannot satisfy the request.
                $payload['models'] = array_values($this->config['fallback_models']);
            }

            $response = Http::withToken($this->config['api_key'])
                ->withHeaders([
                    'HTTP-Referer' => config('app.url', 'https://app.insightistic.com'),
                    'X-Title'      => 'Insightistic',
                ])
                ->timeout($this->config['timeout'] ?? 120)
                ->post(rtrim($this->config['base_url'], '/') . '/chat/completions', $payload);

            if (! $response->successful()) {
                Log::warning('Insightistic OpenRouter request failed', [
                    'status' => $response->status(),
                    'model'  => $this->config['model'],
                ]);

                return null;
            }

            $this->actualModel = $response->json('model') ?: ($this->config['model'] ?? 'unknown');
            $content = $response->json('choices.0.message.content');

            if (! is_string($content) || trim($content) === '') {
                return null;
            }

            $parsed = json_decode($content, true);

            return is_array($parsed) ? $parsed : null;
        } catch (\Throwable $e) {
            // Avoid logging provider response bodies or exception text, which
            // can include user data echoed by an upstream service.
            Log::warning('Insightistic OpenRouter request raised an exception', [
                'exception' => $e::class,
                'model'     => $this->config['model'] ?? 'unknown',
            ]);

            return null;
        }
    }
}
