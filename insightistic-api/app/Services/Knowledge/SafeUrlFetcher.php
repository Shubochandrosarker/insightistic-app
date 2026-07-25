<?php

namespace App\Services\Knowledge;

/**
 * SSRF-hardened HTTP fetcher for knowledge crawling (spec Phase 4.3).
 *
 * Guarantees:
 *  - http/https only, standard ports only (80/443).
 *  - Hostname resolved BEFORE connecting; every resolved address must be a
 *    public unicast IP — private, loopback, link-local, CGN, multicast,
 *    reserved and IPv6 unique-local/link-local ranges are all rejected.
 *  - The connection is PINNED to the vetted IP (curl CURLOPT_RESOLVE), so a
 *    DNS rebinding between check and connect cannot redirect the request.
 *  - Redirects are NOT followed automatically; each hop is re-validated
 *    through the same guard, up to a small limit.
 *  - Response size and time are capped.
 *
 * Throws SsrfViolation for policy rejections, RuntimeException for network
 * failures — callers surface those as source error_code/error_message.
 */
class SafeUrlFetcher
{
    public const MAX_REDIRECTS = 3;

    public function __construct(
        private int $timeoutSeconds = 20,
        private int $maxBytes = 10_485_760, // 10 MB
    ) {}

    /**
     * @return array{body: string, content_type: string, final_url: string, status: int}
     */
    public function fetch(string $url): array
    {
        $current = $url;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            [$vettedUrl, $host, $ip, $port] = $this->vet($current);

            $response = $this->request($vettedUrl, $host, $ip, $port);

            if (in_array($response['status'], [301, 302, 303, 307, 308], true)) {
                $location = $response['redirect'] ?? null;
                if (! $location) {
                    throw new \RuntimeException('Redirect without Location header.');
                }
                // Resolve relative redirects against the current URL.
                $current = $this->absolutize($location, $current);
                continue; // next hop re-vets from scratch
            }

            if ($response['status'] >= 400) {
                throw new \RuntimeException("HTTP {$response['status']} fetching URL.");
            }

            return [
                'body'         => $response['body'],
                'content_type' => $response['content_type'],
                'final_url'    => $current,
                'status'       => $response['status'],
            ];
        }

        throw new SsrfViolation('Too many redirects.');
    }

    /**
     * Validate scheme/host/port and resolve+screen the IP.
     *
     * @return array{0:string,1:string,2:string,3:int} [url, host, pinned ip, port]
     */
    private function vet(string $url): array
    {
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host']) || empty($parts['scheme'])) {
            throw new SsrfViolation('Malformed URL.');
        }

        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new SsrfViolation('Only http/https URLs are allowed.');
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        if (! in_array($port, [80, 443], true)) {
            throw new SsrfViolation('Only ports 80 and 443 are allowed.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new SsrfViolation('Credentials in URLs are not allowed.');
        }

        $host = strtolower($parts['host']);
        // Bracketed IPv6 literal in URL — strip brackets for the checks below.
        $bare = trim($host, '[]');

        // Literal IP host: screen directly.
        if (filter_var($bare, FILTER_VALIDATE_IP)) {
            $this->assertPublicIp($bare);

            return [$url, $host, $bare, $port];
        }

        // Block obvious internal hostnames before touching DNS.
        if ($host === 'localhost' || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local') || str_ends_with($host, '.internal')
            || ! str_contains($host, '.')) {
            throw new SsrfViolation('Internal hostnames are not allowed.');
        }

        // Resolve and screen EVERY address; pin to the first valid one.
        $records = @dns_get_record($host, DNS_A + DNS_AAAA) ?: [];
        $ips = [];
        foreach ($records as $r) {
            if (! empty($r['ip'])) {
                $ips[] = $r['ip'];
            }
            if (! empty($r['ipv6'])) {
                $ips[] = $r['ipv6'];
            }
        }
        if ($ips === []) {
            throw new \RuntimeException('DNS resolution failed.');
        }
        foreach ($ips as $ip) {
            $this->assertPublicIp($ip); // one bad record poisons the whole host
        }

        return [$url, $host, $ips[0], $port];
    }

    private function assertPublicIp(string $ip): void
    {
        // FILTER_FLAG_NO_PRIV_RANGE + NO_RES_RANGE covers RFC1918, loopback,
        // link-local, documentation and reserved blocks for v4 and v6.
        $ok = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );

        if ($ok === false) {
            throw new SsrfViolation("Address {$ip} is not publicly routable.");
        }

        // Extra ranges PHP's filter misses: CGN 100.64/10, IPv4-mapped IPv6,
        // and 0.0.0.0/8.
        if (str_starts_with($ip, '0.')) {
            throw new SsrfViolation('Address is not publicly routable.');
        }
        if (preg_match('/^100\.(6[4-9]|[7-9]\d|1[01]\d|12[0-7])\./', $ip)) {
            throw new SsrfViolation('Carrier-grade NAT range is not allowed.');
        }
        if (stripos($ip, '::ffff:') === 0) {
            $this->assertPublicIp(substr($ip, 7));
        }
    }

    /** @return array{status:int, body:string, content_type:string, redirect:?string} */
    private function request(string $url, string $host, string $ip, int $port): array
    {
        $ch = curl_init();

        $headerBag = ['content_type' => '', 'redirect' => null];
        $body = '';
        $maxBytes = $this->maxBytes;

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            // Pin the vetted IP: DNS rebinding between vet() and connect is dead.
            CURLOPT_RESOLVE        => ["{$host}:{$port}:{$ip}"],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT      => 'InsightisticBot/1.0 (+https://insightistic.com/bot)',
            CURLOPT_HTTPHEADER     => ['Accept: text/html,application/xhtml+xml,application/xml,text/plain,*/*;q=0.5'],
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headerBag) {
                if (stripos($line, 'content-type:') === 0) {
                    $headerBag['content_type'] = trim(substr($line, 13));
                }
                if (stripos($line, 'location:') === 0) {
                    $headerBag['redirect'] = trim(substr($line, 9));
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$body, $maxBytes) {
                $body .= $chunk;
                if (strlen($body) > $maxBytes) {
                    return 0; // abort transfer — response too large
                }

                return strlen($chunk);
            },
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);

        curl_exec($ch);
        $errno  = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($errno === CURLE_WRITE_ERROR && strlen($body) > $maxBytes) {
            throw new \RuntimeException('Response exceeds the size limit.');
        }
        if ($errno !== 0) {
            throw new \RuntimeException('Fetch failed (curl error ' . $errno . ').');
        }

        return [
            'status'       => $status,
            'body'         => $body,
            'content_type' => $headerBag['content_type'],
            'redirect'     => $headerBag['redirect'],
        ];
    }

    private function absolutize(string $location, string $base): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }

        $parts = parse_url($base);
        $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '')
            . (isset($parts['port']) ? ':' . $parts['port'] : '');

        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }

        $dir = rtrim(dirname($parts['path'] ?? '/'), '/');

        return $origin . $dir . '/' . $location;
    }
}
