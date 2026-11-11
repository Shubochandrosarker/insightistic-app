<?php

namespace App\Services\Ai;

use App\Models\Organization;
use App\Models\Site;
use Illuminate\Http\JsonResponse;

/**
 * Central kill switch for variable-cost AI work.
 *
 * A pause can be stored on one organization (the durable tenant control) or
 * configured for a site domain (useful when the same organization owns other
 * sites). The check is intentionally shared by HTTP controllers and scheduled
 * workers so a hidden endpoint or queued job cannot bypass the pause.
 */
final class AiAvailability
{
    public const CODE = 'ai_paused';

    public function isPaused(?Organization $organization = null, ?Site $site = null): bool
    {
        if ($organization && (bool) data_get($organization->ai_settings_json, 'paused', false)) {
            return true;
        }

        if ($site && $this->domainIsPaused($site->domain)) {
            return true;
        }

        return false;
    }

    public function response(): JsonResponse
    {
        return response()->json([
            'code'    => self::CODE,
            'message' => (string) config('insightistic.ai.paused_message'),
            'action'  => 'clear_pending_invoice',
        ], 402);
    }

    private function domainIsPaused(?string $domain): bool
    {
        $normalized = $this->normalizeDomain($domain);
        if ($normalized === null) {
            return false;
        }

        foreach ((array) config('insightistic.ai.paused_domains', []) as $pausedDomain) {
            $paused = $this->normalizeDomain((string) $pausedDomain);
            if ($paused !== null && ($normalized === $paused || str_ends_with($normalized, '.'.$paused))) {
                return true;
            }
        }

        return false;
    }

    private function normalizeDomain(?string $domain): ?string
    {
        $domain = trim(strtolower((string) $domain));
        if ($domain === '') {
            return null;
        }

        if (str_contains($domain, '://')) {
            $domain = (string) parse_url($domain, PHP_URL_HOST);
        }

        $domain = preg_replace('/\/.*$/', '', $domain) ?? $domain;
        $domain = trim($domain, " .\t\n\r\0\x0B");
        $domain = preg_replace('/^www\./', '', $domain) ?? $domain;

        return $domain !== '' ? $domain : null;
    }
}
