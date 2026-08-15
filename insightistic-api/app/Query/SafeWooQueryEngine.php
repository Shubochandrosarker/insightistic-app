<?php

namespace App\Query;

use App\Metrics\MetricRegistry;
use App\Metrics\MetricSource;
use App\Models\Site;
use App\Query\Adapters\Ga4QueryCompiler;
use App\Query\Adapters\GscQueryCompiler;
use App\Query\Adapters\WooCommerceQueryCompiler;
use App\Support\DateRange;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates the full pipeline from docs/SAFE-QUERY-ENGINE.md:
 *
 *   raw plan → PlanValidator → SemanticQueryPlan → source adapter (one of
 *   WooCommerceQueryCompiler / Ga4QueryCompiler / GscQueryCompiler, spec:
 *   P4 Multi-source Analytics) → ReadOnlySqlGuard → org/site scope
 *   (constructor args, never plan input) → QueryConcurrencyLimiter →
 *   read-only connection → statement_timeout → execute → normalize into
 *   QueryResult
 *
 * $site and $organizationId always come from the authenticated request
 * context (see Http\Controllers\Api\AnalyticsQueryController) — never from
 * the plan. A plan cannot widen its own scope.
 *
 * The class keeps its P1 name (`SafeWooQueryEngine`) rather than being
 * renamed to something source-neutral: it's already the tested, merged
 * entry point every caller (AnalyticsQueryController, AskInsightisticService)
 * depends on, and a rename buys nothing but churn. Internally it now
 * dispatches by `$plan->source` to the matching adapter — a breakdown
 * dimension is still WooCommerce/product-only, since neither GA4 nor GSC
 * metrics declare an `allowedDimensions` entry in the registry, so
 * `runBreakdown()` is never reached for those sources.
 */
class SafeWooQueryEngine
{
    public function __construct(
        private readonly MetricRegistry $registry,
        private readonly PlanValidator $validator,
        private readonly WooCommerceQueryCompiler $wooCompiler,
        private readonly Ga4QueryCompiler $ga4Compiler,
        private readonly GscQueryCompiler $gscCompiler,
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
        $allowedTables = $this->allowedTablesFor($plan->source);
        $baseIds = $this->resolveBaseMetricIds($plan->source, $plan->metricIds);
        $builder = $this->compileAggregate($plan->source, $connection, $site, $range, $baseIds);
        $sql = $builder->toSql();
        $this->guard->assertSafe($sql, $allowedTables);

        $row = $this->executeWithTimeout($connection, $builder, single: true);
        $base = $this->extractBaseValues($plan->source, $row, $baseIds);
        $metrics = $this->computeRequestedMetrics($plan->metricIds, $base);

        $previous = $deltas = null;
        if ($plan->comparison) {
            $prevRange = $range->previous();
            $prevBuilder = $this->compileAggregate($plan->source, $connection, $site, $prevRange, $baseIds);
            $this->guard->assertSafe($prevBuilder->toSql(), $allowedTables);
            $prevRow = $this->executeWithTimeout($connection, $prevBuilder, single: true);
            $prevBase = $this->extractBaseValues($plan->source, $prevRow, $baseIds);
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

        // WooCommerce/product is the only dimension breakdown any registered
        // metric declares today, so this method is only ever reached for
        // $plan->source === 'woocommerce' — PlanValidator would already have
        // rejected a GA4/GSC plan requesting a dimension no metric of theirs
        // allows.
        $builder = $this->wooCompiler->compileProductBreakdown($connection, $site, $range, $limit);
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

    /** @return string[] this source's allowlisted tables, for ReadOnlySqlGuard. */
    private function allowedTablesFor(string $source): array
    {
        return match ($source) {
            MetricSource::WOOCOMMERCE => WooCommerceQueryCompiler::ALLOWED_TABLES,
            MetricSource::GA4 => Ga4QueryCompiler::ALLOWED_TABLES,
            MetricSource::GSC => GscQueryCompiler::ALLOWED_TABLES,
            default => throw QueryEngineException::unsupportedSource($source),
        };
    }

    /** @return string[] base metric ids, resolved by the matching source adapter. */
    private function resolveBaseMetricIds(string $source, array $metricIds): array
    {
        return match ($source) {
            MetricSource::WOOCOMMERCE => $this->wooCompiler->resolveBaseMetricIds($metricIds),
            MetricSource::GA4 => $this->ga4Compiler->resolveBaseMetricIds($metricIds),
            MetricSource::GSC => $this->gscCompiler->resolveBaseMetricIds($metricIds),
            default => throw QueryEngineException::unsupportedSource($source),
        };
    }

    private function compileAggregate(string $source, ConnectionInterface $connection, Site $site, DateRange $range, array $baseIds): Builder
    {
        return match ($source) {
            MetricSource::WOOCOMMERCE => $this->wooCompiler->compileAggregate($connection, $site, $range, $baseIds),
            MetricSource::GA4 => $this->ga4Compiler->compileAggregate($connection, $site, $range, $baseIds),
            MetricSource::GSC => $this->gscCompiler->compileAggregate($connection, $site, $range, $baseIds),
            default => throw QueryEngineException::unsupportedSource($source),
        };
    }

    private function extractBaseValues(string $source, mixed $row, array $baseIds): array
    {
        $out = [];
        foreach ($baseIds as $id) {
            $column = match ($id) {
                // woocommerce (App\Query\Adapters\WooCommerceQueryCompiler::BASE_METRIC_COLUMNS)
                'net_revenue' => 'revenue',
                'orders' => 'orders',
                'refund_count' => 'refunds',
                'failed_orders' => 'failed_orders',
                'products_sold' => 'products_sold',
                'new_customers' => 'new_customers',
                'returning_customers' => 'returning_customers',
                // ga4 (App\Query\Adapters\Ga4QueryCompiler::BASE_METRIC_COLUMNS)
                'ga4_sessions' => 'sessions',
                'ga4_conversions' => 'conversions',
                // gsc (App\Query\Adapters\GscQueryCompiler::SUM_METRIC_COLUMNS / AVG_METRIC_COLUMNS)
                'organic_clicks' => 'clicks',
                'organic_impressions' => 'impressions',
                'organic_avg_position' => 'avg_position',
                default => throw QueryEngineException::unsafeQuery("no column mapping for base metric \"{$id}\" (source \"{$source}\")"),
            };
            $value = $row->{$column} ?? 0;
            $out[$id] = match ($id) {
                'net_revenue', 'organic_avg_position' => round((float) $value, 2),
                default => (int) $value,
            };
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
