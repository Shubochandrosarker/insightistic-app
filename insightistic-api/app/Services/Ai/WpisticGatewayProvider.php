<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Calls the wpistic-ai-gateway Worker's /chat endpoint (WordPressistic's
 * shared 22-agent RAG brain), using the "analytics-pro" agent by default.
 *
 * Auth mirrors the gateway's WordPress-bridge contract exactly (see that
 * project's src/auth.ts): a signed principal, not a bearer token.
 *   X-WPistic-Principal : base64url(JSON principal)
 *   X-WPistic-Timestamp : unix seconds
 *   X-WPistic-Signature : hex HMAC-SHA256(timestamp . "." . principal, shared_secret)
 * Laravel plays the role the WordPress bridge normally plays: it asserts a
 * tier for its own callers (already gated by Insightistic's own license
 * check before this class is ever invoked) rather than forwarding an
 * end-user identity the gateway itself would recognize.
 *
 * The gateway has no field for a caller-supplied response schema, so $system
 * (which asks for the ai_insights JSON shape) is folded into the chat
 * "message" alongside $context — same instruction OpenAiProvider sends, just
 * over the gateway's single-message wire format instead of a system role.
 */
class WpisticGatewayProvider implements AiProvider
{
    public function __construct(private array $config) {}

    public function model(): string
    {
        return 'wpistic-gateway:' . ($this->config['agent'] ?? 'analytics-pro');
    }

    public function generate(string $system, array $context, ?int $identityId = null): ?array
    {
        try {
            $timestamp = (string) time();
            $principalRaw = $this->base64UrlEncode(json_encode([
                'userId'         => $identityId ?? 0,
                'tier'           => $this->config['tier'] ?? 'agency',
                'emailVerified'  => true,
                'storageTarget'  => 'cloud',
                'sharedLearning' => false,
            ], JSON_UNESCAPED_SLASHES));
            $signature = hash_hmac('sha256', "{$timestamp}.{$principalRaw}", $this->config['shared_secret'] ?? '');

            $message = $system . "\n\nDATA (JSON — use only this, do not invent numbers):\n"
                . json_encode($context, JSON_UNESCAPED_SLASHES);

            $response = Http::timeout($this->config['timeout'] ?? 30)
                ->withHeaders([
                    'X-WPistic-Principal' => $principalRaw,
                    'X-WPistic-Timestamp' => $timestamp,
                    'X-WPistic-Signature' => $signature,
                ])
                ->post(rtrim($this->config['base_url'], '/') . '/chat', [
                    'agent'   => $this->config['agent'] ?? 'analytics-pro',
                    'message' => $message,
                ]);

            if (! $response->successful()) {
                Log::warning('wpistic-ai-gateway call failed', ['status' => $response->status()]);
                return null;
            }

            $data = $response->json();
            if (! is_array($data) || ! empty($data['refused']) || empty($data['answer'])) {
                return null;
            }

            $parsed = json_decode($data['answer'], true);
            return is_array($parsed) ? $parsed : null;
        } catch (\Throwable $e) {
            Log::warning('wpistic-ai-gateway exception: ' . $e->getMessage());
            return null;
        }
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
