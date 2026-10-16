<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Log;

/** Insightistic AI provider backed by WordPressistic's product-key gateway. */
class WpisticGatewayProvider implements AiProvider
{
    public function __construct(
        private WpisticGatewayClient $gateway,
        private ?AiRuntimeSettings $runtime = null,
    ) {}

    public function model(): string
    {
        $model = $this->runtime?->resolved()['gateway']['model'] ?? config('insightistic.wpistic_gateway.model', 'glm-5.3');

        return 'wpistic-gateway:' . ($model ?: 'glm-5.3');
    }

    public function generate(string $system, array $context, ?int $identityId = null): ?array
    {
        try {
            $result = $this->gateway->complete([
                ['role' => 'system', 'content' => $system],
                [
                    'role'    => 'user',
                    'content' => "DATA (JSON — use only this, do not invent numbers):\n"
                        . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ],
            ], $identityId);

            return $this->parseObject((string) $result['content']);
        } catch (\Throwable $e) {
            Log::warning('wpistic-ai-gateway request failed', ['exception' => $e::class]);

            return null;
        }
    }

    /**
     * Gateways/models sometimes add a short preface around an otherwise valid
     * JSON object despite the strict prompt. Extract the object safely so a
     * formatting slip does not turn a successful provider call into a false
     * provider failure and unnecessary fallback.
     */
    private function parseObject(string $content): ?array
    {
        $content = trim(preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content);

        if (preg_match('/```(?:json)?\s*(.*?)\s*```/is', $content, $matches)) {
            $content = trim($matches[1]);
        }

        $parsed = json_decode($content, true);
        if (is_array($parsed) && ! array_is_list($parsed)) {
            return $parsed;
        }

        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $parsed = json_decode(substr($content, $start, $end - $start + 1), true);

        return is_array($parsed) && ! array_is_list($parsed) ? $parsed : null;
    }
}
