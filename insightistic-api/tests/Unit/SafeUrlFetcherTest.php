<?php

use App\Services\Knowledge\SafeUrlFetcher;
use App\Services\Knowledge\SsrfViolation;

/**
 * SSRF policy unit tests — every private/internal/reserved target must be
 * rejected BEFORE any connection is attempted (these tests run offline).
 */

function fetcher(): SafeUrlFetcher
{
    return new SafeUrlFetcher(timeoutSeconds: 2, maxBytes: 1024);
}

it('rejects private, loopback, link-local and metadata IPs', function (string $url) {
    fetcher()->fetch($url);
})->throws(SsrfViolation::class)->with([
    'http://127.0.0.1/latest',
    'http://10.0.0.8/internal',
    'http://172.16.5.5/admin',
    'http://192.168.1.1/router',
    'http://169.254.169.254/latest/meta-data/', // cloud metadata
    'http://0.0.0.0/x',
    'http://100.64.10.10/cgn',
    'http://[::1]/v6-loopback',
    'http://[fd00::1]/v6-ula',
    'http://[fe80::1]/v6-link-local',
]);

it('rejects internal hostnames without touching DNS', function (string $url) {
    fetcher()->fetch($url);
})->throws(SsrfViolation::class)->with([
    'http://localhost/x',
    'http://foo.localhost/x',
    'http://intranet/x',           // no dot — single-label host
    'http://db.internal/x',
    'http://printer.local/x',
]);

it('rejects non-http schemes, odd ports and credentialed URLs', function (string $url) {
    fetcher()->fetch($url);
})->throws(SsrfViolation::class)->with([
    'ftp://example.com/file.txt',
    'file:///etc/passwd',
    'gopher://example.com/x',
    'http://example.com:8080/x',
    'http://example.com:22/x',
    'http://user:pass@example.com/x',
]);

it('rejects malformed URLs', function () {
    fetcher()->fetch('not-a-url');
})->throws(SsrfViolation::class);
