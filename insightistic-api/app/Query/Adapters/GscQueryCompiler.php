<?php

namespace App\Query\Adapters;

use App\Metrics\MetricRegistry;
use App\Models\Site;
use App\Query\QueryEngineException;
use App\Support\DateRange;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

/**
 * Source adapter for the `gsc` source (spec: Multi-source Analytics, P4).
 * Mirrors WooCommerceQueryCompiler's shape: hardcoded table/column
 * literals, every SELECT compiled through the query builder.
 *
 * Unlike net_revenue/orders/etc, `organic_avg_position` is declared with
 * `MetricAggregation::AVERAGE` in the registry (App\Metrics\Catalog\SeoMetrics)
 * — a period total wouldn't mean anything for a ranking position — so this
 * adapter, unlike the Woo/GA4 ones, compiles two different SQL aggregate
 * functions across its base columns instead of SUM for everything.
 */
class GscQueryCompiler
{
    /** Every table this adapter may ever reference. Cross-checked by ReadOnlySqlGuard. */
    public const ALLOWED_TABLES = ['seo_daily'];

    /** SUM-aggregated base metric id => literal seo_daily column. */
    public const SUM_METRIC_COLUMNS = [
        'organic_clicks' => 'clicks',
        'organic_impressions' => 'impressions',
    ];

    /** AVG-aggregated base metric id => literal seo_daily column. */
    public const AVG_METRIC_COLUMNS = [
        'organic_avg_position' => 'avg_position',
    ];

    public function __construct(private readonly MetricRegistry $registry) {}

    /**
     * @param  string[]  $requestedIds
     * @return string[] base metric ids (keys of SUM_METRIC_COLUMNS / AVG_METRIC_COLUMNS)
     */
    public function resolveBaseMetricIds(array $requestedIds): array
    {
        $base = [];

        foreach ($requestedIds as $id) {
            if ($this->isKnownBaseColumn($id)) {
                $base[] = $id;

                continue;
            }

            $definition = $this->registry->get($id);
            foreach ($definition->dependsOn as $dep) {
                if (! $this->isKnownBaseColumn($dep)) {
                    throw QueryEngineException::unsafeQuery("metric \"{$id}\" depends on \"{$dep}\", which this adapter cannot compile");
                }
                $base[] = $dep;
            }
        }

        return array_values(array_unique($base));
    }

    /** Site-level aggregate over a date range — one row, SUM for click/impression columns, AVG for position. */
    public function compileAggregate(ConnectionInterface $connection, Site $site, DateRange $range, array $baseMetricIds): Builder
    {
        $selects = [];
        foreach ($baseMetricIds as $id) {
            if (isset(self::SUM_METRIC_COLUMNS[$id])) {
                $column = self::SUM_METRIC_COLUMNS[$id];
                $selects[] = "COALESCE(SUM({$column}),0) as {$column}";
            } elseif (isset(self::AVG_METRIC_COLUMNS[$id])) {
                $column = self::AVG_METRIC_COLUMNS[$id];
                $selects[] = "AVG({$column}) as {$column}";
            } else {
                throw QueryEngineException::unsafeQuery("no column mapping for base metric \"{$id}\"");
            }
        }

        return $connection->table('seo_daily')
            ->where('site_id', $site->id)
            ->whereBetween('date', [$range->dateFrom(), $range->dateTo()])
            ->selectRaw(implode(', ', $selects));
    }

    private function isKnownBaseColumn(string $id): bool
    {
        return isset(self::SUM_METRIC_COLUMNS[$id]) || isset(self::AVG_METRIC_COLUMNS[$id]);
    }
}
