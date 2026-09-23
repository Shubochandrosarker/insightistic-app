<?php

namespace App\Support;

use RuntimeException;

/**
 * SSRF guard for outbound fetches of user-supplied URLs (platform
 * detection, uptime, crawlers' first hop). Same posture as
 * Services\Actions\WebhookUrlGuard: http/https only, internal hostnames
 * rejected before DNS, and every resolved A/AAAA address must be public.
 * Kept separate from WebhookUrlGuard deliberately (that one is
 * webhook-delivery shaped: fixed ports, no redirects); this one is
 * fetch-shaped and allows any port so non-standard-but-legit public
 * origins still work.
 */
class PublicHttpGuard
{
    public static function assertFetchable(string $url): array
    {
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) {
            throw new RuntimeException('Malformed URL.');
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException("Scheme \"{$scheme}\" is not allowed — use http or https.");
        }

        $host = strtolower($parts['host']);

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            self::assertPublicIp($host);

            return $parts;
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local') || str_ends_with($host, '.internal')
            || ! str_contains($host, '.')) {
            throw new RuntimeException('Internal hostnames are not allowed.');
        }

        $ips = [];
        foreach (@dns_get_record($host, DNS_A) ?: [] as $r) {
            if (! empty($r['ip'])) {
                $ips[] = $r['ip'];
            }
        }
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $r) {
            if (! empty($r['ipv6'])) {
                $ips[] = $r['ipv6'];
            }
        }
        if ($ips === []) {
            throw new RuntimeException("Could not resolve host \"{$host}\".");
        }
        foreach (array_unique($ips) as $ip) {
            self::assertPublicIp($ip);
        }

        return $parts;
    }

    private static function assertPublicIp(string $ip): void
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw new RuntimeException("URL resolves to a non-public address ({$ip}).");
        }
    }
}
