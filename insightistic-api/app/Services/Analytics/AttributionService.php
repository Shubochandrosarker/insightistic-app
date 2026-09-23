<?php

namespace App\Services\Analytics;

use App\Models\Organization;
use App\Models\ProductEvent;
use App\Models\User;

/**
 * Attribution capture (spec §32) + the funnel event logger (spec §33).
 *
 * The SPA captures landing/referrer/UTM/click-id context client-side
 * (lib/attribution.ts), keeps first-touch in localStorage, updates
 * last-touch on every page load, and sends the bundle with the register
 * call. THIS class whitelists and length-caps everything before it is
 * stored — the client is never trusted.
 *
 * EventLogger side: only the fixed ProductEvent::EVENTS taxonomy is
 * accepted, props are filtered to ProductEvent::PROP_KEYS, so no token /
 * password / customer PII can ever enter the advertising trail.
 */
class AttributionService
{
    /** Keys we keep from an attribution payload. */
    private const ATTRIBUTION_KEYS = [
        'landing_url', 'landing_path', 'referrer', 'utm_source', 'utm_medium',
        'utm_campaign', 'utm_content', 'utm_term', 'gclid', 'fbclid', 'wbraid',
    ];

    private const MAX_LEN = 300;

    /** Sanitize a client attribution bundle → clean array (or null). */
    public function sanitize(?array $payload): ?array
    {
        if (! is_array($payload)) {
            return null;
        }

        $clean = [];
        foreach (self::ATTRIBUTION_KEYS as $key) {
            $value = $payload[$key] ?? null;
            if (is_string($value) && $value !== '') {
                $clean[$key] = mb_substr(trim($value), 0, self::MAX_LEN);
            }
        }

        // Scheme-guard the URLs we store.
        foreach (['landing_url', 'referrer'] as $urlKey) {
            if (isset($clean[$urlKey]) && ! preg_match('#^https?://#i', $clean[$urlKey])) {
                unset($clean[$urlKey]);
            }
        }

        return $clean === [] ? null : $clean + ['captured_at' => now()->toIso8601String()];
    }

    /** Persist first/last touch on the org at signup (first-touch wins forever). */
    public function applyToOrganization(Organization $org, ?array $firstTouch, ?array $lastTouch): void
    {
        $first = $this->sanitize($firstTouch);
        $last = $this->sanitize($lastTouch) ?? $first;

        if ($first && ! $org->first_touch_json) {
            $org->first_touch_json = $first;
        }
        if ($last) {
            $org->last_touch_json = $last;
        }
        $org->save();
    }

    /** Record one funnel event. Unknown events/props are dropped, never fatal. */
    public function event(
        string $event,
        ?Organization $org = null,
        ?User $user = null,
        array $props = [],
        ?\DateTimeInterface $occurredAt = null,
    ): ?ProductEvent {
        if (! in_array($event, ProductEvent::EVENTS, true)) {
            return null;
        }

        $cleanProps = [];
        foreach ($props as $key => $value) {
            if (in_array($key, ProductEvent::PROP_KEYS, true)
                && (is_scalar($value) || $value === null)) {
                $cleanProps[$key] = is_string($value) ? mb_substr($value, 0, 300) : $value;
            }
        }

        return ProductEvent::withoutGlobalScope('organization')->create([
            'organization_id' => $org?->id,
            'user_id' => $user?->id,
            'event' => $event,
            'props_json' => $cleanProps === [] ? null : $cleanProps,
            'occurred_at' => $occurredAt ?? now(),
        ]);
    }
}
