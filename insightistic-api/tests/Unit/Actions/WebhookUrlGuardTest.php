<?php

use App\Services\Actions\WebhookUrlGuard;
use App\Services\Actions\WebhookUrlRejected;

function webhookGuard(): WebhookUrlGuard
{
    return new WebhookUrlGuard;
}

it('rejects a malformed URL', function () {
    webhookGuard()->assertSafe('not a url');
})->throws(WebhookUrlRejected::class);

it('rejects a non-http(s) scheme', function () {
    webhookGuard()->assertSafe('ftp://example.com/hook');
})->throws(WebhookUrlRejected::class);

it('rejects a non-standard port', function () {
    webhookGuard()->assertSafe('https://example.com:8443/hook');
})->throws(WebhookUrlRejected::class);

it('rejects a loopback address', function () {
    webhookGuard()->assertSafe('http://127.0.0.1/hook');
})->throws(WebhookUrlRejected::class);

it('rejects a link-local / cloud-metadata address', function () {
    webhookGuard()->assertSafe('http://169.254.169.254/latest/meta-data/');
})->throws(WebhookUrlRejected::class);

it('rejects a private RFC1918 address', function () {
    webhookGuard()->assertSafe('http://10.0.0.5/hook');
    webhookGuard()->assertSafe('http://192.168.1.1/hook');
})->throws(WebhookUrlRejected::class);

it('rejects an internal hostname without ever touching DNS', function (string $url) {
    webhookGuard()->assertSafe($url);
})->throws(WebhookUrlRejected::class)->with([
    'http://localhost/hook',
    'http://foo.localhost/hook',
    'http://db.internal/hook',
    'http://printer.local/hook',
    'http://intranet/hook', // no dot — single-label host
]);

it('accepts a literal public IP address', function () {
    webhookGuard()->assertSafe('http://8.8.8.8/hook');
})->throwsNoExceptions();
