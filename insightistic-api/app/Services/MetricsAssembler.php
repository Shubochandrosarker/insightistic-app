<?php

namespace App\Services;

use App\Models\MetricSnapshot;
use App\Models\Site;
use App\Models\WcCustomer;
use App\Models\WcOrder;
use App\Support\DateRange;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for reading aggregated metrics out of snapshots +
 * live tables. Shared by AnalyticsController (charts/KPIs), InsightGenerator
 * (AI context), and ReportBuilder (PDF reports) so the numbers always agree.
 */
class MetricsAssembler
{
    public const PAID_STATUSES   = ['completed', 'processing', 'on-hold'];
    public const FAILED_STATUSES = ['failed', 'cancelled'];

    /** Sum snapshot rows over a range into headline metrics. */
    public function aggregate(int $siteId, DateRange $range): array
    {
        $row = MetricSnapshot::query()
            ->where('site_id', $siteId)
            ->whereBetween('date', [$range->dateFrom(), $range->dateTo()])
            ->selectRaw('
                COALESCE(SUM(revenue),0) as revenue,
                COALESCE(SUM(orders),0) as orders,
                COALESCE(SUM(refunds),0) as refunds,
                COALESCE(SUM(products_sold),0) as products_sold,
                COALESCE(SUM(new_customers),0) as new_customers,
                COALESCE(SUM(returning_customers),0) as returning_customers,
                COALESCE(SUM(failed_orders),0) as failed_orders
            ')
            ->first();

        $revenue = (float) ($row->revenue ?? 0);
        $orders  = (int) ($row->orders ?? 0);

        return [
            'revenue'             => round($revenue, 2),
            'orders'              => $orders,
            'refunds'             => (int) ($row->refunds ?? 0),
            'products_sold'       => (int) ($row->products_sold ?? 0),
            'new_customers'       => (int) ($row->new_customers ?? 0),
            'returning_customers' => (int) ($row->returning_customers ?? 0),
            'failed_orders'       => (int) ($row->failed_orders ?? 0),
            'average_order_value' => $orders > 0 ? round($revenue / $orders, 2) : 0,
        ];
    }

    /** Percentage deltas current vs previous (null when previous is 0). */
    public function deltas(array $current, array $previous): array
    {
        $out = [];
        foreach (['revenue', 'orders', 'average_order_value', 'new_customers', 'returning_customers', 'refunds'] as $key) {
            $cur = $current[$key] ?? 0;
            $prev = $previous[$key] ?? 0;
            $out[$key] = $prev > 0 ? round((($cur - $prev) / $prev) * 100, 1) : null;
        }
        return $out;
    }

    /** Daily revenue/orders series for charts. */
    public function dailySeries(int $siteId, DateRange $range)
    {
        return MetricSnapshot::query()
            ->where('site_id', $siteId)
            ->whereBetween('date', [$range->dateFrom(), $range->dateTo()])
            ->orderBy('date')
            ->get(['date', 'revenue', 'orders', 'refunds', 'failed_orders']);
    }

    public function topProducts(Site $site, DateRange $range, int $limit = 10)
    {
        return DB::table('wc_order_items as oi')
            ->join('wc_orders as o', function ($j) use ($site) {
                $j->on('o.external_order_id', '=', 'oi.external_order_id')
                  ->where('o.site_id', '=', $site->id);
            })
            ->where('oi.site_id', $site->id)
            ->whereNotNull('o.created_at_store')
            ->where('o.created_at_store', '>=', $range->utcStart())
            ->where('o.created_at_store', '<', $range->utcEnd())
            ->whereIn('o.status', self::PAID_STATUSES)
            ->groupBy('oi.external_product_id')
            ->selectRaw('oi.external_product_id,
                MAX(oi.product_name) as name,
                MAX(oi.sku) as sku,
                SUM(oi.quantity) as units,
                SUM(oi.total) as revenue')
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get();
    }

    public function topCustomers(Site $site, int $limit = 10)
    {
        return WcCustomer::query()
            ->where('site_id', $site->id)
            ->orderByDesc('total_spent')
            ->limit($limit)
            ->get(['external_customer_id', 'first_name', 'last_name', 'city', 'country', 'total_spent', 'order_count']);
    }

    /** Full bundle for AI context + reports. */
    public function bundle(Site $site, DateRange $range): array
    {
        $prev    = $range->previous();
        $current = $this->aggregate($site->id, $range);
        $previous = $this->aggregate($site->id, $prev);

        return [
            'site'     => ['name' => $site->name, 'currency' => $site->currency, 'timezone' => $site->timezone],
            'period'   => ['label' => $range->label, 'from' => $range->dateFrom(), 'to' => $range->dateTo()],
            'metrics'  => $current,
            'previous' => $previous,
            'deltas'   => $this->deltas($current, $previous),
            'top_products' => $this->topProducts($site, $range, 5)->map(fn ($p) => [
                'name' => $p->name, 'units' => (int) $p->units, 'revenue' => (float) $p->revenue,
            ])->all(),
            'top_customers' => $this->topCustomers($site, 5)->map(fn ($c) => [
                'name' => trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '')) ?: ('#' . $c->external_customer_id),
                'orders' => (int) $c->order_count, 'spent' => (float) $c->total_spent,
            ])->all(),
        ];
    }
}
