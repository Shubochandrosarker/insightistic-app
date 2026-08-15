<?php

namespace App\Query;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;

/**
 * Runs a query inside a transaction with `SET LOCAL statement_timeout`
 * scoped to just that transaction — it reverts automatically at
 * commit/rollback, so it can never leak onto a later query sharing the
 * same pooled connection. Extracted from SafeWooQueryEngine so the
 * SQLSTATE 57014 (query_canceled) → QueryEngineException::timeout()
 * mapping is independently testable with a real, fast, deterministic
 * Postgres timeout (see tests/Feature/Query/StatementTimeoutExecutorTest.php).
 */
class StatementTimeoutExecutor
{
    public function run(ConnectionInterface $connection, int $timeoutMs, \Closure $query): mixed
    {
        try {
            return $connection->transaction(function () use ($connection, $timeoutMs, $query) {
                $connection->statement('SET LOCAL statement_timeout = '.(int) $timeoutMs);

                return $query();
            });
        } catch (QueryException $e) {
            if ($this->isTimeout($e)) {
                throw QueryEngineException::timeout();
            }

            throw $e;
        }
    }

    /** Postgres SQLSTATE 57014 = query_canceled, the code a statement_timeout cancellation raises. */
    private function isTimeout(QueryException $e): bool
    {
        return $e->getCode() === '57014' || str_contains($e->getMessage(), 'statement timeout');
    }
}
