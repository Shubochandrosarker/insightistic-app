<?php

namespace App\Services\Actions;

/**
 * SSRF-safe validation for a `send_webhook` action's target URL, before
 * this system ever makes an outbound HTTP call to it. Independent of
 * App\Services\Knowledge\SafeUrlFetcher (the knowledge-crawler's own SSRF
 * guard) on purpose — that class is GET/redirect-following/content-
 * fetching shaped for crawling untrusted pages; a webhook delivery is a
 * single POST to a URL an org admin configured once, with no redirect
 * following at all (an unexpected redirect on a webhook is treated as a
 * delivery failure, not silently followed). Duplicating this one check
 * rather than reshaping the crawler's fetcher avoids risking already-
 * shipped knowledge-ingestion code for an unrelated feature.
 *
 * A webhook URL is admin-configured (only an authenticated org member can
 * create/update an AutomationRule or ActionProposal), not attacker-
 * supplied — but "the org's own admin could point this at
 * 169.254.169.254" is exactly the kind of self-inflicted-but-preventable
 * mistake this guard exists to catch, matching the same "verified, not
 * just claimed" posture as every other outbound-request path in this app.
 */
class WebhookUrlGuard
{
    public const ALLOWED_SCHEMES = ['http', 'https'];

    public const ALLOWED_PORTS = [80, 443];

    public function assertSafe(string $url): void
    {
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host']) || empty($parts['scheme'])) {
            throw new WebhookUrlRejected('Malformed webhook URL.');
        }

        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            throw new WebhookUrlRejected("Scheme \"{$scheme}\" is not allowed — use http or https.");
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if (! in_array($port, self::ALLOWED_PORTS, true)) {
            throw new WebhookUrlRejected("Port {$port} is not allowed.");
        }

        $host = strtolower($parts['host']);

        // Literal IP host: screen directly, no DNS involved.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $this->assertPublicIp($host);

            return;
        }

        // Block obvious internal hostnames before ever touching DNS — same
        // rule SafeUrlFetcher (the knowledge crawler's SSRF guard) applies,
        // kept in sync deliberately even though the two classes don't share
        // code (see this class's docblock for why).
        if ($host === 'localhost' || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local') || str_ends_with($host, '.internal')
            || ! str_contains($host, '.')) {
            throw new WebhookUrlRejected('Internal hostnames are not allowed.');
        }

        $ips = $this->resolve($host);

        if ($ips === []) {
            throw new WebhookUrlRejected("Could not resolve host \"{$host}\".");
        }

        foreach ($ips as $ip) {
            $this->assertPublicIp($ip);
        }
    }

    /** @return string[] */
    private function resolve(string $host): array
    {
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

        return array_values(array_unique($ips));
    }

    private function assertPublicIp(string $ip): void
    {
        // FILTER_FLAG_NO_PRIV_RANGE + FILTER_FLAG_NO_RES_RANGE reject
        // private (10/8, 172.16/12, 192.168/16, fc00::/7, ...), loopback,
        // link-local, and other reserved ranges for both IPv4 and IPv6 in
        // one call.
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw new WebhookUrlRejected("Webhook URL resolves to a non-public address ({$ip}).");
        }
    }
}
