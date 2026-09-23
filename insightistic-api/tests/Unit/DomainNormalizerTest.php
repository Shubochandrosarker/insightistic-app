<?php

use App\Support\DomainNormalizer;

it('merges equivalent URLs into one host key', function (string $a, string $b) {
    expect(DomainNormalizer::hostKey($a))->toBe(DomainNormalizer::hostKey($b));
})->with([
    ['example.com', 'https://example.com'],
    ['example.com/', 'http://example.com/'],
    ['www.example.com', 'https://WWW.Example.com'],
    ['https://example.com/some/path', 'example.com'],
]);

it('keeps genuinely different properties distinct', function (string $a, string $b) {
    expect(DomainNormalizer::hostKey($a))->not->toBe(DomainNormalizer::hostKey($b));
})->with([
    ['shop.example.com', 'example.com'],
    ['example.com', 'different.example.com'],
    ['example.com', 'example.org'],
]);

it('parse returns canonical parts including scheme and path', function () {
    $parts = DomainNormalizer::parse('https://www.Example.com/Shop/');
    expect($parts['host'])->toBe('www.example.com')
        ->and($parts['host_key'])->toBe('example.com')
        ->and($parts['scheme'])->toBe('https')
        ->and($parts['path'])->toBe('Shop')
        ->and($parts['canonical'])->toBe('https://www.example.com/Shop');
});

it('defaults the scheme to https and lowercases the host', function () {
    $parts = DomainNormalizer::parse('Example.COM');
    expect($parts['scheme'])->toBe('https')
        ->and($parts['host'])->toBe('example.com')
        ->and($parts['host_key'])->toBe('example.com')
        ->and($parts['canonical'])->toBe('https://example.com');
});

it('rejects invalid input with a useful exception', function (string $bad) {
    expect(fn () => DomainNormalizer::parse($bad))->toThrow(InvalidArgumentException::class);
})->with([
    '',
    'not a url at all',
    'ftp://example.com',
]);

it('classifies local and staging environments', function (string $host, string $expected) {
    expect(DomainNormalizer::environment($host))->toBe($expected);
})->with([
    ['localhost', 'local'],
    ['192.168.1.10', 'local'],
    ['mysite.test', 'local'],
    ['staging.example.com', 'staging'],
    ['example.kinsta.cloud', 'staging'],
    ['example.com', 'production'],
]);
