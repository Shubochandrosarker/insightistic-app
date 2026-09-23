<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Canonical domain normalization for site identity (2026-09-23 milestone).
 *
 * Explicit merge rules — "equivalent" means the SAME website, not the same
 * registrable domain:
 *   example.com ≡ www.example.com ≡ https://example.com/ ≡ http://example.com
 *   shop.example.com  ≢  example.com          (subdomains stay distinct)
 *   example.com/de    ≢  example.com          (non-root paths stay distinct)
 *
 * hostKey() is the dedupe key stored on sites.normalized_domain: lowercase
 * host, leading "www." stripped, default ports dropped, IDNA-ASCII. Paths
 * are NOT part of the key (two sites differing only by trailing path on the
 * same root are the same project for our purposes) — but parse() still
 * returns the path so GSC url-prefix imports can keep the exact property.
 */
class DomainNormalizer
{
    /** Parse any user-ish URL/domain input into canonical parts. */
    public static function parse(string $input): array
    {
        $input = trim($input);
        if ($input === '') {
            throw new InvalidArgumentException('A website URL is required.');
        }

        if (! preg_match('#^[a-z][a-z0-9+.\-]*://#i', $input)) {
            $input = 'https://'.$input;
        }

        $parts = parse_url($input);
        $host = strtolower(idn_to_ascii($parts['host'] ?? '', IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46) ?: ($parts['host'] ?? ''));
        if ($host === '' || ! str_contains($host, '.') || str_contains($host, ' ')) {
            throw new InvalidArgumentException("\"{$input}\" is not a valid website URL.");
        }

        $scheme = strtolower($parts['scheme'] ?? 'https');
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('Only http and https URLs are supported.');
        }

        $nonDefaultPort = isset($parts['port']) && ! in_array($parts['port'], [80, 443], true)
            ? ':'.$parts['port'] : '';
        $path = trim($parts['path'] ?? '', '/');

        return [
            'scheme' => $scheme,
            'host' => $host,
            'host_key' => self::hostKey($host),
            'port' => $nonDefaultPort,
            'path' => $path,
            'canonical' => $scheme.'://'.$host.$nonDefaultPort.($path !== '' ? '/'.$path : ''),
        ];
    }

    /**
     * The dedupe key: lowercase host with leading www. removed. Accepts a
     * bare host ("www.Example.com") or a full URL.
     */
    public static function hostKey(string $input): string
    {
        $input = trim($input);
        if ($input === '') {
            return '';
        }
        if (! preg_match('#^[a-z][a-z0-9+.\-]*://#i', $input)) {
            $input = 'https://'.$input;
        }
        $host = strtolower(parse_url($input, PHP_URL_HOST) ?? '');
        $host = preg_replace('/^www\./', '', $host) ?? $host;
        // A non-default port is part of the site identity (rare but real).
        $port = parse_url($input, PHP_URL_PORT);
        if ($port && ! in_array($port, [80, 443], true)) {
            $host .= ':'.$port;
        }

        return $host;
    }

    /**
     * Environment classification so staging/local URLs never masquerade as
     * production projects. Mirrors LicenseService::classifyEnvironment.
     */
    public static function environment(string $hostKey): string
    {
        $host = explode(':', $hostKey)[0];

        if ($host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP)
            || str_ends_with($host, '.local') || str_ends_with($host, '.test')
            || str_ends_with($host, '.example') || str_ends_with($host, '.invalid')) {
            return 'local';
        }
        foreach (['staging.', 'stage.', 'dev.', 'test.'] as $prefix) {
            if (str_starts_with($host, $prefix)) {
                return 'staging';
            }
        }
        if (str_contains($host, '.staging.') || str_contains($host, 'wpengine.com')
            || str_contains($host, 'kinsta.cloud') || str_contains($host, 'pantheonsite.io')
            || str_contains($host, '.cloudwaysapps.com')) {
            return 'staging';
        }

        return 'production';
    }
}
