<?php

namespace App\Query;

use App\Metrics\MetricRegistry;
use App\Models\Site;
use App\Query\Adapters\WooCommerceQueryCompiler;
use App\Support\DateRange;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates the full pipeline from docs/SAFE-QUERY-ENGINE.md:
 *
 *   raw plan → PlanValidator → SemanticQueryPlan → WooCommerceQueryCompiler
 *   → ReadOnlySqlGuard → org/site scope (constructor args, never plan input)
 *   → QueryConcurrencyLimiter → read-only connection → statement_timeout
 *   → execute → normalize into QueryResult
 *
 * $site and $organizationId always come from the authenticated request
 * context (see Http\Controllers\Api\AnalyticsQueryController) — never from
 * the plan. A plan cannot widen its own scope.
 */
class SafeWooQueryEngine
{
    public function __construct(
        private readonly MetricRegistry $registry,
        private readonly PlanValidator $validator,
        private readonly WooCommerceQueryCompiler $compiler,
        private readonly ReadOnlySqlGuard $guard,
        private readonly QueryConcurrencyLimiter $concurrency,
        private readonly DerivedMetricCalculator $derived,
        private readonly StatementTimeoutExecutor $timeoutExecutor,
    ) {}

    public function run(array $rawPlan, Site $site, int $organizationId): QueryResult
    {
        $plan = $this->validator->validate($rawPlan);

        return $this->concurrency->run($organizationId, function () use ($plan, $site) {
            $start = microtime(true);
            [$connection, $readOnlyActive] = $this->resolveConnection();
            $range = DateRange::fromRequest(
                $plan->periodPreset,
                $plan->periodFrom,
                $plan->periodTo,
                $site->timezone ?: config('app.timezone', 'UTC'),
            );

            if ($plan->isBreakdown()) {
                $result = $this->runBreakdown($connection, $site, $range, $plan);
            } else {
                $result = $this->runAggregate($connection, $site, $range, $plan);
            }

            $durationMs = round((microtime(true) - $start) * 1000, 1);

            return new QueryResult(
                queryHash: $result['hash'],
                plan: $plan,
                range: $range,
                metrics: $result['metrics'],
                previous: $result['previous'],
                deltas: $result['deltas'],
                rows: $result['rows'],
                rowCount: $result['row_count'],
                truncated: $result['truncated'],
                durationMs: $durationMs,
                readOnlyIdentityActive: $readOnlyActive,
                caveats: $this->collectCaveats($plan->metricIds),
            );
        });
    }

    private function runAggregate(ConnectionInterface $connection, Site $site, DateRange $range, SemanticQueryPlan $plan): array
    {
        $baseIds = $this->compiler->resolveBaseMetricIds($plan->metricIds);
        $builder = $this->compiler->compileAggregate($connection, $site, $range, $baseIds);
        $sql = $builder->toSql();
        $this->guard->assertSafe($sql, WooCommerceQueryCompiler::ALLOWED_TABLES);

        $row = $this->executeWithTimeout($connection, $builder, single: true);
        $base = $this->extractBaseValues($row, $baseIds);
        $metrics = $this->computeRequestedMetrics($plan->metricIds, $base);

        $previous = $deltas = null;
        if ($plan->comparison) {
            $prevRange = $range->previous();
            $prevBuilder = $this->compiler->compileAggregate($connection, $site, $prevRange, $baseIds);
            $this->guard->assertSafe($prevBuilder->toSql(), WooCommerceQueryCompiler::ALLOWED_TABLES);
            $prevRow = $this->executeWithTimeout($connection, $prevBuilder, single: true);
            $prevBase = $this->extractBaseValues($prevRow, $baseIds);
            $previous = $this->computeRequestedMetrics($plan->metricIds, $prevBase);
            $deltas = $this->computeDeltas($metrics, $previous);
        }

        return [
            'hash' => $this->hashQuery($site, $sql, $builder->getBindings()),
            'metrics' => $metrics,
            'previous' => $previous,
            'deltas' => $deltas,
            'rows' => [],
            'row_count' => 1,
            'truncated' => false,
        ];
    }

    private function runBreakdown(ConnectionInterface $connection, Site $site, DateRange $range, SemanticQueryPlan $plan): array
    {
        $chartMaxRows = (int) config('insightistic.query_engine.chart_max_rows', 500);
        $limit = min($plan->limit ?? PlanValidator::DEFAULT_LIMIT, $chartMaxRows);

        $builder = $this->compiler->compileProductBreakdown($connection, $site, $range, $limit);
        $sql = $builder->toSql();
        $this->guard->assertSafe($sql, WooCommerceQueryCompiler::ALLOWED_TABLES);

        $rows = $this->executeWithTimeout($connection, $builder, single: false);
        $normalized = collect($rows)->map(fn ($r) => [
            'product_id' => (int) $r->external_product_id,
            'name' => $r->name,
            'sku' => $r->sku,
            'units' => (int) $r->units,
            'revenue' => round((float) $r->revenue, 2),
        ])->all();

        return [
            'hash' => $this->hashQuery($site, $sql, $builder->getBindings()),
            'metrics' => [],
            'previous' => null,
            'deltas' => null,
            'rows' => $normalized,
            'row_count' => count($normalized),
            'truncated' => count($normalized) >= $limit,
        ];
    }

    private function executeWithTimeout(ConnectionInterface $connection, Builder $builder, bool $single): mixed
    {
        $timeoutMs = (int) config('insightistic.query_engine.timeout_ms', 8000);

        return $this->timeoutExecutor->run($connection, $timeoutMs, fn () => $single ? $builder->first() : $builder->get());
    }

    /** @return array{0: ConnectionInterface, 1: bool} */
    private function resolveConnection(): array
    {
        $configured = filled(config('database.connections.readonly.username'));

        if ($configured) {
            return [DB::connection('readonly'), true];
        }

        if (app()->environment('production')) {
            throw QueryEngineException::readOnlyIdentityNotConfigured();
        }

        Log::warning('SafeWooQueryEngine: no read-only DB identity configured, falling back to the default connection. Run `php artisan db:provision-readonly-role` and set READONLY_DB_USERNAME before production use.');

        return [DB::connection(config('database.default')), false];
    }

    private function extractBaseValues(mixed $row, array $baseIds): array
    {
        $out = [];
        foreach ($baseIds as $id) {
            $column = match ($id) {
                'net_revenue' => 'revenue',
                'orders' => 'orders',
                'refund_count' => 'refunds',
                'failed_orders' => 'failed_orders',
                'products_sold' => 'products_sold',
                'new_customers' => 'new_customers',
                'returning_customers' => 'returning_customers',
                default => throw QueryEngineException::unsafeQuery("no column mapping for base metric \"{$id}\""),
            };
            $value = $row->{$column} ?? 0;
            $out[$id] = $id === 'net_revenue' ? round((float) $value, 2) : (int) $value;
        }

        return $out;
    }

    private function computeRequestedMetrics(array $requestedIds, array $base): array
    {
        $out = [];
        foreach ($requestedIds as $id) {
            $out[$id] = array_key_exists($id, $base) ? $base[$id] : $this->derived->calculate($id, $base);
        }

        return $out;
    }

    private function computeDeltas(array $current, array $previous): array
    {
        $out = [];
        foreach ($current as $id => $value) {
            $prev = $previous[$id] ?? null;
            $out[$id] = ($prev !== null && $prev > 0 && $value !== null)
                ? round((($value - $prev) / $prev) * 100, 1)
                : null;
        }

        return $out;
    }

    private function collectCaveats(array $metricIds): array
    {
        $caveats = [];
        foreach ($metricIds as $id) {
            $definition = $this->registry->get($id);
            foreach ($definition->caveats as $c) {
                $caveats["{$id}: {$c}"] = "{$definition->label}: {$c}";
            }
        }

        return array_values($caveats);
    }

    private function hashQuery(Site $site, string $sql, array $bindings): string
    {
        return hash('sha256', $site->id.'|'.$sql.'|'.json_encode($bindings));
    }
}
