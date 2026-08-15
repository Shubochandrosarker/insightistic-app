<?php

use App\Query\QueryEngineException;
use App\Query\StatementTimeoutExecutor;
use Illuminate\Support\Facades\DB;

/**
 * Proves the real Postgres statement_timeout mechanism, not just the app's
 * own bookkeeping: a 100ms timeout against a 2s pg_sleep must be cancelled
 * by Postgres itself (SQLSTATE 57014) and re-surfaced as
 * QueryEngineException. The margin (100ms vs 2000ms) keeps this fast and
 * non-flaky — Postgres cancels at ~100ms, the test never waits the full sleep.
 */
it('cancels a query that exceeds the statement timeout and maps it to a timeout error', function () {
    $executor = new StatementTimeoutExecutor;

    $executor->run(DB::connection(), 100, fn () => DB::select('select pg_sleep(2)'));
})->throws(QueryEngineException::class);

it('does not cancel a query that finishes well within the timeout', function () {
    $executor = new StatementTimeoutExecutor;

    $result = $executor->run(DB::connection(), 8000, fn () => DB::select('select 1 as one'));

    expect($result[0]->one)->toBe(1);
});

it('reverts statement_timeout after the transaction so it never leaks onto the next query', function () {
    $executor = new StatementTimeoutExecutor;

    try {
        $executor->run(DB::connection(), 100, fn () => DB::select('select pg_sleep(2)'));
    } catch (QueryEngineException) {
        // expected
    }

    // If SET LOCAL had leaked (i.e. a plain SET was used instead), this
    // 2s sleep on the SAME connection would also be cancelled at ~100ms.
    $result = DB::select('select pg_sleep(0.2), 1 as one');
    expect($result[0]->one)->toBe(1);
});
