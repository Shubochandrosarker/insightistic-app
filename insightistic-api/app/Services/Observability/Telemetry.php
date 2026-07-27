<?php

namespace App\Services\Observability;

use Illuminate\Support\Facades\DB;

/**
 * Records request and AI-spend metrics into hourly buckets (spec Phase 2.3).
 *
 * Writes go through `INSERT ... ON CONFLICT DO UPDATE` so concurrent workers
 * incrementing the same bucket cannot lose each other's counts. A
 * read-modify-write would drop increments under any real load, and the loss is
 * silent — the numbers just quietly come out low, which is worse than not
 * having them.
 *
 * Recording never throws. Telemetry that can break the request it is measuring
 * is a liability, so every failure is reported and swallowed.
 */
class Telemetry
{
    public const KINDS = ['copilot', 'insight', 'embedding', 'report'];

    public function recordRequest(string $route, string $method, int $status, float $durationMs): void
    {
        if (! config('otel.request_metrics', true)) {
            return;
        }

        $ms = (int) round($durationMs);

        try {
            $this->upsert('telemetry_requests', [
                'bucket_hour' => $this->bucket(),
                'route'       => mb_substr($route, 0, 200),
                'method'      => mb_substr($method, 0, 10),
                'status'      => $status,
            ], [
                'requests'          => 1,
                'duration_ms_total' => $ms,
                'over_500ms'        => $ms > 500 ? 1 : 0,
                'over_1s'           => $ms > 1000 ? 1 : 0,
                'over_3s'           => $ms > 3000 ? 1 : 0,
            ], maxColumns: ['duration_ms_max' => $ms]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * One AI call's latency, tokens and cost.
     *
     * Called for every provider interaction, not just Copilot — insight
     * generation and embedding runs are the two biggest sources of spend and
     * neither was visible before, which made "why is the OpenAI bill that
     * size" an unanswerable question.
     */
    public function recordAiCall(
        string $kind,
        string $provider,
        string $model,
        ?int $organizationId = null,
        ?int $latencyMs = null,
        ?int $tokensIn = null,
        ?int $tokensOut = null,
        ?float $costUsd = null,
        bool $failed = false,
    ): void {
        try {
            $this->upsert('telemetry_ai_usage', [
                'bucket_hour'     => $this->bucket(),
                'organization_id' => $organizationId,
                'kind'            => mb_substr($kind, 0, 40),
                'provider'        => mb_substr($provider, 0, 60),
                'model'           => mb_substr($model, 0, 120),
            ], [
                'calls'            => 1,
                'failures'         => $failed ? 1 : 0,
                'latency_ms_total' => $latencyMs ?? 0,
                'tokens_in'        => $tokensIn ?? 0,
                'tokens_out'       => $tokensOut ?? 0,
                'cost_usd'         => $costUsd ?? 0,
            ], maxColumns: ['latency_ms_max' => $latencyMs ?? 0]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Atomic bucket increment.
     *
     * PostgreSQL's ON CONFLICT with a partial-nullable key needs care:
     * `organization_id` is nullable and NULLs are never equal in a unique
     * index, so platform-level AI usage (no organization) creates a new row
     * per call rather than accumulating. That is accepted rather than worked
     * around with a sentinel id — a sentinel would collide with a real
     * organization the day someone renumbers, and the daily rollup groups
     * these rows back together anyway.
     */
    private function upsert(string $table, array $keys, array $increments, array $maxColumns = []): void
    {
        /*
         * Wrapped in a savepoint because this runs inside whatever transaction
         * the caller happens to be in. In PostgreSQL any failed statement
         * aborts the surrounding transaction, so without this a telemetry
         * write that errors would make every subsequent query in the request
         * fail with SQLSTATE 25P02 — the measurement taking down the thing it
         * was measuring, and reporting a confusing error instead of the real
         * one. The savepoint confines the damage to this statement.
         */
        if (DB::transactionLevel() > 0) {
            DB::statement('SAVEPOINT insightistic_telemetry');

            try {
                $this->write($table, $keys, $increments, $maxColumns);
                DB::statement('RELEASE SAVEPOINT insightistic_telemetry');
            } catch (\Throwable $e) {
                DB::statement('ROLLBACK TO SAVEPOINT insightistic_telemetry');
                throw $e;
            }

            return;
        }

        $this->write($table, $keys, $increments, $maxColumns);
    }

    private function write(string $table, array $keys, array $increments, array $maxColumns): void
    {
        $now = now();
        $row = array_merge($keys, $increments, ['created_at' => $now, 'updated_at' => $now]);

        foreach ($maxColumns as $column => $value) {
            $row[$column] = $value;
        }

        $sets = [];
        foreach (array_keys($increments) as $column) {
            $sets[] = "{$column} = {$table}.{$column} + excluded.{$column}";
        }
        foreach (array_keys($maxColumns) as $column) {
            $sets[] = "{$column} = GREATEST({$table}.{$column}, excluded.{$column})";
        }
        $sets[] = 'updated_at = excluded.updated_at';

        $conflict = implode(', ', array_keys($keys));
        $columns = implode(', ', array_keys($row));
        $placeholders = implode(', ', array_fill(0, count($row), '?'));

        DB::statement(
            "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders}) "
            . "ON CONFLICT ({$conflict}) DO UPDATE SET " . implode(', ', $sets),
            array_values($row)
        );
    }

    private function bucket(): string
    {
        return now()->utc()->startOfHour()->toDateTimeString();
    }

    // ------------------------------------------------------------------
    // Reads
    // ------------------------------------------------------------------

    /** Slowest and busiest routes over a window, worst first. */
    public function requestSummary(int $hours = 24, int $limit = 20): array
    {
        $since = now()->utc()->subHours($hours)->startOfHour();

        $rows = DB::table('telemetry_requests')
            ->where('bucket_hour', '>=', $since)
            ->groupBy('route', 'method')
            ->selectRaw('route, method')
            ->selectRaw('SUM(requests) as requests')
            ->selectRaw('SUM(duration_ms_total) as total_ms')
            ->selectRaw('MAX(duration_ms_max) as max_ms')
            ->selectRaw('SUM(over_1s) as over_1s')
            ->selectRaw('SUM(over_3s) as over_3s')
            ->selectRaw('SUM(CASE WHEN status >= 500 THEN requests ELSE 0 END) as server_errors')
            ->selectRaw('SUM(CASE WHEN status >= 400 AND status < 500 THEN requests ELSE 0 END) as client_errors')
            ->get();

        $totals = [
            'requests'      => (int) $rows->sum('requests'),
            'server_errors' => (int) $rows->sum('server_errors'),
            'client_errors' => (int) $rows->sum('client_errors'),
        ];

        $routes = $rows->map(fn ($r) => [
            'route'         => $r->route,
            'method'        => $r->method,
            'requests'      => (int) $r->requests,
            'avg_ms'        => (int) $r->requests > 0 ? (int) round($r->total_ms / $r->requests) : null,
            'max_ms'        => (int) $r->max_ms,
            'over_1s'       => (int) $r->over_1s,
            'over_3s'       => (int) $r->over_3s,
            'server_errors' => (int) $r->server_errors,
            'client_errors' => (int) $r->client_errors,
            'error_rate'    => (int) $r->requests > 0
                ? round(((int) $r->server_errors) / (int) $r->requests * 100, 2)
                : null,
        ])->sortByDesc('avg_ms')->take($limit)->values()->all();

        return [
            'has_data'    => $rows->isNotEmpty(),
            'window_hours' => $hours,
            'totals'      => $totals + [
                'error_rate' => $totals['requests'] > 0
                    ? round($totals['server_errors'] / $totals['requests'] * 100, 2)
                    : null,
            ],
            'routes'      => $routes,
        ];
    }

    /** AI spend and latency over a window, most expensive first. */
    public function aiSummary(int $hours = 24, ?int $organizationId = null): array
    {
        $since = now()->utc()->subHours($hours)->startOfHour();

        $rows = DB::table('telemetry_ai_usage')
            ->where('bucket_hour', '>=', $since)
            ->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId))
            ->groupBy('kind', 'provider', 'model')
            ->selectRaw('kind, provider, model')
            ->selectRaw('SUM(calls) as calls')
            ->selectRaw('SUM(failures) as failures')
            ->selectRaw('SUM(latency_ms_total) as total_ms')
            ->selectRaw('MAX(latency_ms_max) as max_ms')
            ->selectRaw('SUM(tokens_in) as tokens_in')
            ->selectRaw('SUM(tokens_out) as tokens_out')
            ->selectRaw('SUM(cost_usd) as cost_usd')
            ->get();

        $breakdown = $rows->map(fn ($r) => [
            'kind'      => $r->kind,
            'provider'  => $r->provider,
            'model'     => $r->model,
            'calls'     => (int) $r->calls,
            'failures'  => (int) $r->failures,
            'failure_rate' => (int) $r->calls > 0 ? round((int) $r->failures / (int) $r->calls * 100, 2) : null,
            'avg_ms'    => (int) $r->calls > 0 ? (int) round($r->total_ms / $r->calls) : null,
            'max_ms'    => (int) $r->max_ms,
            'tokens_in' => (int) $r->tokens_in,
            'tokens_out' => (int) $r->tokens_out,
            'cost_usd'  => round((float) $r->cost_usd, 6),
        ])->sortByDesc('cost_usd')->values()->all();

        return [
            'has_data'     => $rows->isNotEmpty(),
            'window_hours' => $hours,
            'totals'       => [
                'calls'      => (int) $rows->sum('calls'),
                'failures'   => (int) $rows->sum('failures'),
                'tokens_in'  => (int) $rows->sum('tokens_in'),
                'tokens_out' => (int) $rows->sum('tokens_out'),
                'cost_usd'   => round((float) $rows->sum('cost_usd'), 4),
            ],
            'breakdown'    => $breakdown,
        ];
    }

    /** Hourly series for charting a single metric family. */
    public function requestSeries(int $hours = 24): array
    {
        $since = now()->utc()->subHours($hours)->startOfHour();

        return DB::table('telemetry_requests')
            ->where('bucket_hour', '>=', $since)
            ->groupBy('bucket_hour')
            ->selectRaw('bucket_hour')
            ->selectRaw('SUM(requests) as requests')
            ->selectRaw('SUM(duration_ms_total) as total_ms')
            ->selectRaw('SUM(CASE WHEN status >= 500 THEN requests ELSE 0 END) as server_errors')
            ->orderBy('bucket_hour')
            ->get()
            ->map(fn ($r) => [
                'hour'          => (string) $r->bucket_hour,
                'requests'      => (int) $r->requests,
                'avg_ms'        => (int) $r->requests > 0 ? (int) round($r->total_ms / $r->requests) : null,
                'server_errors' => (int) $r->server_errors,
            ])->all();
    }

    /** Drop buckets past the retention window. */
    public function prune(int $days): int
    {
        $cutoff = now()->utc()->subDays($days)->startOfHour();

        return DB::table('telemetry_requests')->where('bucket_hour', '<', $cutoff)->delete()
            + DB::table('telemetry_ai_usage')->where('bucket_hour', '<', $cutoff)->delete();
    }
}
