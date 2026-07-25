<?php

namespace App\Services\Observability;

use Sentry\Breadcrumb;
use Sentry\Event;

/**
 * Removes secrets and personal data from telemetry before it leaves the
 * process (spec Phase 2.3).
 *
 * Everything here exists because error tracking is a data exfiltration path
 * that nobody thinks of as one. An exception thrown mid-request carries the
 * request that caused it, and for this API that can be a Cloudflare API
 * token, a Google authorization code, a license key, a TOTP secret or a
 * customer's email address.
 *
 * Two deliberate choices:
 *
 *  - **Deny by key name, not by value.** Value-matching heuristics ("looks
 *    like a JWT") miss the formats they were not written for. Matching key
 *    names catches `cloudflare_api_token` whatever its shape.
 *  - **Substring matching, not exact.** `token` redacts `access_token`,
 *    `refresh_token` and `token_expires_at` alike. The occasional harmless
 *    field lost to that is a much better trade than one refresh token
 *    published to a third-party dashboard.
 */
class EventScrubber
{
    /**
     * Key fragments whose values never leave the process.
     *
     * Ordered roughly by how bad the leak would be, though the check is a
     * simple contains() over all of them.
     */
    public const SENSITIVE_KEYS = [
        'password', 'secret', 'token', 'api_key', 'apikey', 'authorization',
        'auth', 'credential', 'private', 'signature', 'hmac', 'nonce',
        'session', 'cookie', 'csrf', 'otp', 'totp', 'mfa', 'recovery',
        'license_key', 'dsn', 'webhook_secret', 'client_secret', 'refresh',
        'access_code', 'bearer', 'salt', 'hash', 'encryption',
        // Personal data. The spec allows aggregated customer data to reach the
        // AI; it does not allow named customers to reach an error tracker.
        'email', 'phone', 'first_name', 'last_name', 'address', 'postcode',
        'zip', 'ip', 'card', 'iban', 'vat',
    ];

    public const REDACTED = '[redacted]';

    /**
     * Headers stripped wholesale, regardless of the key filter above.
     *
     * `cookie` is here as well as in SENSITIVE_KEYS because a session cookie
     * is a working credential — an attacker with the error dashboard should
     * not also get a live session.
     */
    public const SENSITIVE_HEADERS = [
        'authorization', 'cookie', 'set-cookie', 'x-api-key', 'x-insightistic-signature',
        'x-csrf-token', 'x-xsrf-token', 'proxy-authorization',
    ];

    public static function scrub(Event $event, ?\Sentry\EventHint $hint = null): ?Event
    {
        $request = $event->getRequest();

        if ($request !== []) {
            $event->setRequest(self::scrubRequest($request));
        }

        // The user context: keep the id so an error can be traced to an
        // account, drop everything that identifies the human behind it.
        $user = $event->getUser();
        if ($user !== null) {
            $event->setUser(
                \Sentry\UserDataBag::createFromArray(array_filter([
                    'id' => $user->getId(),
                ], fn ($v) => $v !== null))
            );
        }

        $extra = $event->getExtra();
        if ($extra !== []) {
            $event->setExtra(self::scrubArray($extra));
        }

        $tags = $event->getTags();
        if ($tags !== []) {
            foreach ($tags as $key => $value) {
                if (self::isSensitive($key)) {
                    $event->setTag($key, self::REDACTED);
                }
            }
        }

        return $event;
    }

    /** Transactions carry the same request payload as errors do. */
    public static function scrubTransaction(Event $event, ?\Sentry\EventHint $hint = null): ?Event
    {
        return self::scrub($event, $hint);
    }

    /**
     * Breadcrumbs are the sneakiest path: an HTTP-client breadcrumb records
     * the URL of every outbound call, and OAuth callbacks carry the code in
     * the query string.
     */
    public static function scrubBreadcrumb(Breadcrumb $breadcrumb): ?Breadcrumb
    {
        $data = $breadcrumb->getMetadata();

        if ($data === []) {
            return $breadcrumb;
        }

        $clean = self::scrubArray($data);

        foreach (['url', 'uri', 'endpoint'] as $key) {
            if (isset($clean[$key]) && is_string($clean[$key])) {
                $clean[$key] = self::scrubUrl($clean[$key]);
            }
        }

        return $breadcrumb->withMetadata($clean);
    }

    /** Strip the query string and any credentials embedded in a URL. */
    public static function scrubUrl(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false) {
            return self::REDACTED;
        }

        $out = '';

        if (! empty($parts['scheme'])) {
            $out .= $parts['scheme'] . '://';
        }

        // https://user:pass@host — the credentials are the point of the strip.
        if (! empty($parts['host'])) {
            $out .= $parts['host'];
        }

        if (! empty($parts['port'])) {
            $out .= ':' . $parts['port'];
        }

        $out .= $parts['path'] ?? '';

        // Keep the *shape* of the query so a caller can tell which endpoint
        // variant ran, without any of the values.
        if (! empty($parts['query'])) {
            parse_str($parts['query'], $query);
            $out .= '?' . implode('&', array_map(fn ($k) => "{$k}=" . self::REDACTED, array_keys($query)));
        }

        return $out;
    }

    private static function scrubRequest(array $request): array
    {
        // Bodies are dropped outright rather than filtered. A request body can
        // be nested arbitrarily and is the single richest source of secrets in
        // the payload; there is no debugging value worth the risk.
        unset($request['data'], $request['cookies'], $request['env']);

        if (isset($request['url']) && is_string($request['url'])) {
            $request['url'] = self::scrubUrl($request['url']);
        }

        if (isset($request['query_string'])) {
            $request['query_string'] = self::REDACTED;
        }

        if (isset($request['headers']) && is_array($request['headers'])) {
            foreach ($request['headers'] as $name => $value) {
                if (in_array(strtolower((string) $name), self::SENSITIVE_HEADERS, true) || self::isSensitive((string) $name)) {
                    $request['headers'][$name] = self::REDACTED;
                }
            }
        }

        return $request;
    }

    /** Walk a nested array, redacting by key at every level. */
    public static function scrubArray(array $data, int $depth = 0): array
    {
        // Deeply nested structures are almost always dumped objects; stop
        // rather than recursing without bound on a cyclic graph.
        if ($depth > 8) {
            return ['_truncated' => true];
        }

        $out = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSensitive($key)) {
                $out[$key] = self::REDACTED;
                continue;
            }

            $out[$key] = is_array($value) ? self::scrubArray($value, $depth + 1) : $value;
        }

        return $out;
    }

    public static function isSensitive(string $key): bool
    {
        $key = strtolower($key);

        foreach (self::SENSITIVE_KEYS as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }
}
