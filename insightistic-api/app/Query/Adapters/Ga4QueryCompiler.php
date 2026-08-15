<?php

namespace App\Query\Adapters;

use App\Metrics\MetricRegistry;
use App\Models\Site;
use App\Query\QueryEngineException;
use App\Support\DateRange;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

/**
 * Source adapter for the `ga4` source (spec: Multi-source Analytics, P4).
 * Mirrors WooCommerceQueryCompiler's shape exactly: hardcoded
 * table/column literals — never runtime-derived from the registry — and
 * every SELECT compiled through the query builder, never a raw plan-input
 * string.
 *
 * `traffic_daily` stores more than one `source` per (site, date) — GA4 rows
 * live alongside a first-party `connector_engagement` fallback (see that
 * migration's docblock) — so every query here is scoped to
 * `source = 'ga4'` in addition to site_id, matching the caveat every GA4
 * metric in App\Metrics\Catalog\TrafficMetrics declares.
 */
class Ga4QueryCompiler
{
    /** Every table this adapter may ever reference. Cross-checked by ReadOnlySqlGuard. */
    public const ALLOWED_TABLES = ['traffic_daily'];

    /** Base (non-derived) metric id => literal traffic_daily column. The only mapping this class will ever SELECT by name. */
    public const BASE_METRIC_COLUMNS = [
        'ga4_sessions' => 'sessions',
        'ga4_conversions' => 'conversions',
    ];

    public function __construct(private readonly MetricRegistry $registry) {}

    /**
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

    /** Site-level aggregate over a date range, GA4 rows only — one row, SUM per base metric column. */
    public function compileAggregate(ConnectionInterface $connection, Site $site, DateRange $range, array $baseMetricIds): Builder
    {
        $selects = [];
        foreach ($baseMetricIds as $id) {
            $column = self::BASE_METRIC_COLUMNS[$id] ?? throw QueryEngineException::unsafeQuery("no column mapping for base metric \"{$id}\"");
            $selects[] = "COALESCE(SUM({$column}),0) as {$column}";
        }

        return $connection->table('traffic_daily')
            ->where('site_id', $site->id)
            ->where('source', 'ga4')
            ->whereBetween('date', [$range->dateFrom(), $range->dateTo()])
            ->selectRaw(implode(', ', $selects));
    }
}
