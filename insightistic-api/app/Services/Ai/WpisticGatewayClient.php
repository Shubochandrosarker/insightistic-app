<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;

/** OpenAI-compatible client for WordPressistic's product-key AI gateway. */
class WpisticGatewayClient
{
    /**
     * @param  array<int,array{role:string,content:string}>  $messages
     * @return array{content:string,model:string,tokens_in:?int,tokens_out:?int}
     */
    public function complete(array $messages, ?int $organizationId): array
    {
        $config = config('insightistic.wpistic_gateway');
        $productKey = trim((string) ($config['product_key'] ?? ''));

        if ($productKey === '') {
            throw new \RuntimeException('WPistic gateway product key is not configured.');
        }

        $productId = trim((string) ($config['product_id'] ?? 'insightistic'));
        $model = trim((string) ($config['model'] ?? 'glm-5.3'));
        $maxTokens = min(4000, max(256, (int) ($config['max_tokens'] ?? 1200)));
        $baseUrl = rtrim((string) ($config['base_url'] ?? 'https://ai.wpistic.com'), '/');
        $chatPath = '/' . ltrim((string) ($config['chat_path'] ?? '/compat/chat/completions'), '/');
        // Accept either a gateway origin or a fully qualified chat endpoint.
        // This keeps production overrides from accidentally duplicating the path.
        $endpoint = preg_match('~/chat/completions$~', $baseUrl) === 1
            ? $baseUrl
            : $baseUrl . $chatPath;

        $response = Http::timeout((int) ($config['timeout'] ?? 30))
            ->acceptJson()
            ->withHeaders([
                'X-Wpistic-Product' => $productId,
                'X-Wpistic-Key'     => $productKey,
                'X-Wpistic-User'    => $organizationId ? 'org-' . $organizationId : 'insightistic-service',
            ])
            ->post($endpoint, [
                'model'       => $model,
                'messages'    => array_values($messages),
                'max_tokens'  => $maxTokens,
                'temperature' => 0.2,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('WPistic gateway request failed with HTTP ' . $response->status() . '.');
        }

        $content = $response->json('choices.0.message.content');
        if (! is_string($content) || trim($content) === '') {
            throw new \RuntimeException('WPistic gateway returned an empty completion.');
        }

        $usage = $response->json('usage', []);

        return [
            'content'    => $content,
            'model'      => (string) ($response->json('model') ?: $model),
            'tokens_in'  => isset($usage['prompt_tokens']) ? (int) $usage['prompt_tokens'] : null,
            'tokens_out' => isset($usage['completion_tokens']) ? (int) $usage['completion_tokens'] : null,
        ];
    }
}
