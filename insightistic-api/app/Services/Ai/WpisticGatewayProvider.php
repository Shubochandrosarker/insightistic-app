<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Log;

/** Insightistic AI provider backed by WordPressistic's product-key gateway. */
class WpisticGatewayProvider implements AiProvider
{
    public function __construct(private WpisticGatewayClient $gateway) {}

    public function model(): string
    {
        return 'wpistic-gateway:' . (config('insightistic.wpistic_gateway.model') ?: 'glm-5.3');
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

            $content = trim($result['content']);
            if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/is', $content, $matches)) {
                $content = trim($matches[1]);
            }

            $parsed = json_decode($content, true);

            return is_array($parsed) ? $parsed : null;
        } catch (\Throwable $e) {
            Log::warning('wpistic-ai-gateway request failed', ['exception' => $e::class]);

            return null;
        }
    }
}
