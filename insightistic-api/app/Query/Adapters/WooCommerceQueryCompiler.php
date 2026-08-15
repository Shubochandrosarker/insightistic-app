<?php

namespace App\Query\Adapters;

use App\Metrics\CommerceStatus;
use App\Metrics\MetricRegistry;
use App\Models\Site;
use App\Query\QueryEngineException;
use App\Support\DateRange;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

/**
 * Source adapter for the `woocommerce` source (spec: Safe WooCommerce Query
 * Engine, P1). Compiles a validated SemanticQueryPlan into a Laravel query
 * builder call — never a raw SQL string built from plan input.
 *
 * Table and column names below are literal strings written in this file,
 * not values read out of MetricDefinition at runtime. That is deliberate:
 * the registry stays the source of truth for a metric's *meaning*, but the
 * set of tables/columns this compiler is physically capable of emitting SQL
 * against is a separate, hardcoded boundary — even a corrupted or
 * misconfigured registry entry could never make this class touch a table
 * outside ALLOWED_TABLES.
 */
class WooCommerceQueryCompiler
{
    /** Every table this adapter may ever reference. Cross-checked by ReadOnlySqlGuard. */
    public const ALLOWED_TABLES = ['metric_snapshots', 'wc_orders', 'wc_order_items'];

    /** Base (non-derived) metric id => literal metric_snapshots column. The only mapping this class will ever SELECT by name. */
    private const BASE_METRIC_COLUMNS = [
        'net_revenue' => 'revenue',
        'orders' => 'orders',
        'refund_count' => 'refunds',
        'failed_orders' => 'failed_orders',
        'products_sold' => 'products_sold',
        'new_customers' => 'new_customers',
        'returning_customers' => 'returning_customers',
    ];

    public function __construct(private readonly MetricRegistry $registry) {}

    /**
     * Every base metric_snapshots column needed to satisfy $requestedIds,
     * following derived metrics' dependsOn one level (P1's derived metrics
     * are all direct combinations of base metrics — see DerivedMetricCalculator).
     *
     * @param  string[]  $requestedIds
     * @return string[] base metric ids (keys of BASE_METRIC_COLUMNS)
     */
    public function resolveBaseMetricIds(array $requestedIds): array
    {
        $base = [];

        foreach ($requestedIds as $id) {
            if (isset(self::BASE_METRIC_COLUMNS[$id])) {
                $base[] = $id;

                continue;
            }

            $definition = $this->registry->get($id);
            foreach ($definition->dependsOn as $dep) {
                if (! isset(self::BASE_METRIC_COLUMNS[$dep])) {
                    throw QueryEngineException::unsafeQuery("metric \"{$id}\" depends on \"{$dep}\", which this adapter cannot compile");
                }
                $base[] = $dep;
            }
        }

        return array_values(array_unique($base));
    }

    /** Site-level aggregate over a date range — one row, SUM per base metric column. */
    public function compileAggregate(ConnectionInterface $connection, Site $site, DateRange $range, array $baseMetricIds): Builder
    {
        $selects = [];
        foreach ($baseMetricIds as $id) {
            $column = self::BASE_METRIC_COLUMNS[$id] ?? throw QueryEngineException::unsafeQuery("no column mapping for base metric \"{$id}\"");
            $selects[] = "COALESCE(SUM({$column}),0) as {$column}";
        }

        return $connection->table('metric_snapshots')
            ->where('site_id', $site->id)
            ->whereBetween('date', [$range->dateFrom(), $range->dateTo()])
            ->selectRaw(implode(', ', $selects));
    }

    /** Per-product revenue breakdown for the "product" dimension — the only dimension P1 supports. */
    public function compileProductBreakdown(ConnectionInterface $connection, Site $site, DateRange $range, int $limit): Builder
    {
        return $connection->table('wc_order_items as oi')
            ->join('wc_orders as o', function ($join) use ($site) {
                $join->on('o.external_order_id', '=', 'oi.external_order_id')
                    ->where('o.site_id', '=', $site->id);
            })
            ->where('oi.site_id', $site->id)
            ->whereNotNull('o.created_at_store')
            ->where('o.created_at_store', '>=', $range->utcStart())
            ->where('o.created_at_store', '<', $range->utcEnd())
            ->whereIn('o.status', CommerceStatus::REVENUE_PAID)
            ->groupBy('oi.external_product_id')
            ->selectRaw('oi.external_product_id, MAX(oi.product_name) as name, MAX(oi.sku) as sku, SUM(oi.quantity) as units, SUM(oi.total) as revenue')
            ->orderByDesc('revenue')
            ->limit($limit);
    }
}
