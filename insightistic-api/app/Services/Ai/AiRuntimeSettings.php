<?php

namespace App\Services\Ai;

use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * Resolves the platform owner's non-secret AI routing policy.
 *
 * API keys remain environment-managed. This service only persists routing,
 * model, and shared knowledge-brain metadata so an owner can tune the system
 * without putting credentials into the dashboard database or browser.
 */
class AiRuntimeSettings
{
    public const OPENROUTER_GLM = 'z-ai/glm-5.3-flash';

    public function resolved(): array
    {
        $cfg = config('insightistic');
        $stored = $this->stored();
        $router = $cfg['openrouter'] ?? [];
        $gateway = $cfg['wpistic_gateway'] ?? [];
        $brain = $cfg['knowledge_agent'] ?? [];

        $paidModel = (string) ($stored['openrouter_paid_model'] ?? $router['paid_model'] ?? self::OPENROUTER_GLM);
        $freeModel = (string) ($stored['openrouter_free_model'] ?? $router['free_model'] ?? self::OPENROUTER_GLM);
        $paidFallbacks = $this->csv($stored['openrouter_paid_fallback_models'] ?? $router['paid_fallback_models'] ?? []);
        $freeFallbacks = $this->csv($stored['openrouter_free_fallback_models'] ?? $router['free_fallback_models'] ?? []);

        return [
            'gateway' => [
                'base_url' => (string) ($gateway['base_url'] ?? 'https://ai.wpistic.com'),
                'chat_path' => (string) ($gateway['chat_path'] ?? '/compat/chat/completions'),
                'product_key' => $gateway['product_key'] ?? null,
                'product_id' => (string) ($gateway['product_id'] ?? 'insightistic'),
                'model' => (string) ($stored['wpistic_model'] ?? $gateway['model'] ?? 'glm-5.3'),
                'max_tokens' => (int) ($gateway['max_tokens'] ?? 1200),
                'timeout' => (int) ($gateway['timeout'] ?? 30),
            ],
            'openrouter' => [
                'api_key' => $router['api_key'] ?? null,
                'base_url' => (string) ($router['base_url'] ?? 'https://openrouter.ai/api/v1'),
                'timeout' => (int) ($router['timeout'] ?? 180),
                'free_model' => $freeModel,
                'free_reasoning_effort' => $router['free_reasoning_effort'] ?? 'low',
                'free_fallback_models' => $freeFallbacks,
                'paid_model' => $paidModel,
                'paid_fallback_models' => $paidFallbacks,
            ],
            'knowledge_agent' => [
                'enabled' => (bool) ($stored['knowledge_agent_enabled'] ?? $brain['enabled'] ?? false),
                'base_url' => (string) ($stored['knowledge_agent_url'] ?? $brain['base_url'] ?? ''),
                'token' => $brain['token'] ?? null,
                'agent_slug' => (string) ($stored['knowledge_agent_slug'] ?? $brain['agent_slug'] ?? 'analytics-pro'),
                'category' => (string) ($stored['knowledge_agent_category'] ?? $brain['category'] ?? 'analytics'),
                'timeout' => (int) ($brain['timeout'] ?? 4),
                'driver' => in_array($brain['driver'] ?? 'rest', ['rest', 'mcp'], true) ? $brain['driver'] : 'rest',
                'mcp_path' => (string) ($brain['mcp_path'] ?? '/mcp'),
                'mcp_kinds' => (array) ($brain['mcp_kinds'] ?? ['project', 'system']),
                'cf_access_client_id' => $brain['cf_access_client_id'] ?? null,
                'cf_access_client_secret' => $brain['cf_access_client_secret'] ?? null,
                'connect_timeout' => max(1, (int) ($brain['connect_timeout'] ?? 2)),
                'budget_ms' => max(500, (int) ($brain['budget_ms'] ?? 5000)),
                'cooldown_seconds' => max(0, (int) ($brain['cooldown_seconds'] ?? 120)),
            ],
            // The order is deliberately fixed in code. An owner can change
            // models, but cannot accidentally bypass the shared WPistic brain.
            'provider_order' => ['wpistic_gateway', 'openrouter', 'rule'],
        ];
    }

    public function public(): array
    {
        $settings = $this->resolved();
        $gateway = $settings['gateway'];
        $router = $settings['openrouter'];
        $brain = $settings['knowledge_agent'];

        return [
            'provider' => 'plan_aware',
            'configured' => ! empty($gateway['product_key']) || ! empty($router['api_key']),
            'provider_order' => $settings['provider_order'],
            'wpistic' => [
                'configured' => ! empty($gateway['product_key']),
                'model' => $gateway['model'],
                'product_id' => $gateway['product_id'],
                'base_url' => $gateway['base_url'],
            ],
            'openrouter' => [
                'configured' => ! empty($router['api_key']),
                'model' => $router['paid_model'],
                'free_model' => $router['free_model'],
                'fallback_models' => $router['paid_fallback_models'],
                'base_url' => $router['base_url'],
            ],
            'knowledge_agent' => [
                'enabled' => $brain['enabled'],
                'configured' => $brain['enabled'] && $brain['base_url'] !== '',
                'base_url' => $brain['base_url'],
                'agent_slug' => $brain['agent_slug'],
                'category' => $brain['category'],
                'driver' => $brain['driver'],
                // Last failure reason while the cooldown is active (no secrets).
                'unavailable_reason' => \Illuminate\Support\Facades\Cache::get(\App\Services\Knowledge\WpisticKnowledgeAgentClient::DOWN_KEY),
            ],
            'credential_status' => [
                'wpistic_configured' => ! empty($gateway['product_key']),
                'openrouter_configured' => ! empty($router['api_key']),
                'agent_token_configured' => ! empty($brain['token']),
                'cf_access_configured' => ! empty($brain['cf_access_client_id']) && ! empty($brain['cf_access_client_secret']),
                'managed_by' => 'environment',
            ],
        ];
    }

    public function update(array $input, User $actor): array
    {
        $allowed = [
            'wpistic_model',
            'openrouter_paid_model',
            'openrouter_free_model',
            'openrouter_paid_fallback_models',
            'openrouter_free_fallback_models',
            'knowledge_agent_enabled',
            'knowledge_agent_url',
            'knowledge_agent_slug',
            'knowledge_agent_category',
        ];
        $current = $this->stored();

        foreach ($allowed as $key) {
            if (array_key_exists($key, $input)) {
                $current[$key] = $input[$key];
            }
        }

        if (Schema::hasTable('platform_settings')) {
            PlatformSetting::updateOrCreate(
                ['key' => 'ai_routing'],
                ['value_json' => $current, 'updated_by' => $actor->id],
            );
        }

        return $this->resolved();
    }

    private function stored(): array
    {
        if (! Schema::hasTable('platform_settings')) {
            return [];
        }

        $setting = PlatformSetting::where('key', 'ai_routing')->first();

        return (array) ($setting?->value_json ?? []);
    }

    private function csv(mixed $value): array
    {
        $items = is_array($value) ? $value : explode(',', (string) $value);

        return array_values(array_filter(array_map(
            static fn ($item) => trim((string) $item),
            $items,
        )));
    }
}
