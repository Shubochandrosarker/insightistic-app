<?php

namespace App\Services\Knowledge;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * OpenAI-compatible /embeddings client. Works against api.openai.com or any
 * compatible gateway (set insightistic.embeddings.base_url), including the
 * WordPressistic AI Gateway's embedding passthrough.
 */
class OpenAiEmbeddingProvider implements EmbeddingProvider
{
    public function __construct(private array $config) {}

    public function model(): string
    {
        return (string) ($this->config['model'] ?? 'text-embedding-3-small');
    }

    public function dimension(): int
    {
        return (int) ($this->config['dimension'] ?? 1536);
    }

    public function embed(array $texts): ?array
    {
        if ($texts === []) {
            return [];
        }

        try {
            $response = Http::timeout((int) ($this->config['timeout'] ?? 30))
                ->withToken((string) $this->config['api_key'])
                ->post(rtrim((string) $this->config['base_url'], '/') . '/embeddings', [
                    'model'      => $this->model(),
                    'input'      => array_values($texts),
                    'dimensions' => $this->dimension(),
                ]);

            if (! $response->successful()) {
                Log::warning('Embedding call failed', ['status' => $response->status()]);

                return null;
            }

            $rows = $response->json('data');
            if (! is_array($rows) || count($rows) !== count($texts)) {
                return null;
            }

            // The API may return out of order; sort by index to stay aligned.
            usort($rows, fn ($a, $b) => ($a['index'] ?? 0) <=> ($b['index'] ?? 0));

            $vectors = [];
            foreach ($rows as $row) {
                $vector = $row['embedding'] ?? null;
                if (! is_array($vector) || count($vector) !== $this->dimension()) {
                    return null; // partial/malformed batch — reject entirely
                }
                $vectors[] = array_map('floatval', $vector);
            }

            return $vectors;
        } catch (\Throwable $e) {
            Log::warning('Embedding exception: ' . $e->getMessage());

            return null;
        }
    }
}
