<?php

use App\Query\QueryConcurrencyLimiter;
use App\Query\QueryEngineException;
use Illuminate\Support\Facades\Cache;

/**
 * True concurrent races aren't reproducible in PHPUnit's single-threaded
 * runner — these tests cover the sequential logic (slot acquired/released,
 * rejected once exhausted, released on both success and failure) that a
 * real race would exercise the same way, per the class's own documented
 * best-effort scope.
 */
function limiter(int $max = 2): QueryConcurrencyLimiter
{
    return new QueryConcurrencyLimiter($max, lockTtlSeconds: 30);
}

beforeEach(function () {
    Cache::flush();
});

it('runs the callback and returns its value when a slot is free', function () {
    $result = limiter()->run(1, fn () => 'ok');

    expect($result)->toBe('ok');
});

it('releases the slot after a successful run', function () {
    $l = limiter(max: 1);
    $l->run(1, fn () => 'first');

    // A second sequential call must succeed too — the first slot was released.
    $result = $l->run(1, fn () => 'second');

    expect($result)->toBe('second');
});

it('releases the slot even when the callback throws', function () {
    $l = limiter(max: 1);

    try {
        $l->run(1, function () {
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
        // expected
    }

    // Slot must be free again despite the failure.
    $result = $l->run(1, fn () => 'ok');
    expect($result)->toBe('ok');
});

it('rejects once the per-org cap is reached', function () {
    $l = limiter(max: 1);

    // Simulate an in-flight query by holding the slot open via a nested call.
    $l->run(1, function () use ($l) {
        $l->run(1, fn () => 'should not run');
    });
})->throws(QueryEngineException::class);

it('tracks each organization independently', function () {
    $l = limiter(max: 1);
    $captured = null;

    $l->run(100, function () use ($l, &$captured) {
        // A different org must still get its own slot.
        $captured = $l->run(200, fn () => 'org 200 ok');
    });

    expect($captured)->toBe('org 200 ok');
});
