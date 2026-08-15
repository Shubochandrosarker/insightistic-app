<?php

namespace App\Query;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Per-organization interactive query concurrency cap (spec: query budgets).
 * A `Cache::lock()` mutex guards the read-modify-write of the counter so
 * two simultaneous requests can't both read "2 of 3 slots used" and both
 * proceed — the increment itself is atomic; only the counter's TTL-based
 * cleanup is best-effort (documented below).
 */
class QueryConcurrencyLimiter
{
    public function __construct(
        private readonly int $maxConcurrentPerOrg,
        private readonly int $lockTtlSeconds,
    ) {}

    /**
     * Run $callback while holding one of this org's concurrency slots.
     * Throws QueryEngineException::concurrencyLimitExceeded() without
     * running $callback if none are free. Releases the slot in a `finally`
     * even if $callback throws.
     *
     * If the process crashes between acquiring the slot and releasing it,
     * the slot self-heals after $lockTtlSeconds via the counter key's own
     * TTL — a stuck slot is a temporary false-positive 429, never a
     * permanent lockout.
     */
    public function run(int $organizationId, \Closure $callback): mixed
    {
        $counterKey = "query-engine:concurrency:org:{$organizationId}";
        $mutexKey = "query-engine:concurrency-lock:org:{$organizationId}";

        try {
            $acquired = Cache::lock($mutexKey, 5)->block(2, function () use ($counterKey) {
                $current = (int) Cache::get($counterKey, 0);
                if ($current >= $this->maxConcurrentPerOrg) {
                    return false;
                }
                Cache::put($counterKey, $current + 1, $this->lockTtlSeconds);

                return true;
            });
        } catch (LockTimeoutException) {
            // Couldn't even get the tiny counter mutex within 2s — treat the
            // same as "no slot available" rather than a hard 500.
            $acquired = false;
        }

        if (! $acquired) {
            throw QueryEngineException::concurrencyLimitExceeded($this->maxConcurrentPerOrg);
        }

        try {
            return $callback();
        } finally {
            try {
                Cache::lock($mutexKey, 5)->block(2, function () use ($counterKey) {
                    $current = (int) Cache::get($counterKey, 1);
                    $next = max(0, $current - 1);
                    if ($next === 0) {
                        Cache::forget($counterKey);
                    } else {
                        Cache::put($counterKey, $next, $this->lockTtlSeconds);
                    }
                });
            } catch (LockTimeoutException) {
                // Slot self-heals via $lockTtlSeconds — see class docblock.
            }
        }
    }
}
