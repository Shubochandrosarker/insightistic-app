<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\WcCustomer;
use App\Models\WcOrder;
use App\Models\WcProduct;
use App\Services\MetricsAssembler;
use App\Support\DateRange;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    public function __construct(private MetricsAssembler $metrics) {}

    /** KPI cards + current-vs-previous deltas. Reads precomputed snapshots (fast). */
    public function overview(Request $request)
    {
        $site  = $this->site($request);
        $range = $this->range($request, $site);
        $prev  = $range->previous();

        $current  = $this->metrics->aggregate($site->id, $range);
        $previous = $this->metrics->aggregate($site->id, $prev);

        return response()->json([
            'range'    => ['period' => $range->label, 'from' => $range->dateFrom(), 'to' => $range->dateTo(), 'timezone' => $range->tz],
            'metrics'  => $current,
            'previous' => $previous,
            'deltas'   => $this->metrics->deltas($current, $previous),
        ]);
    }

    public function revenue(Request $request)
    {
        $site  = $this->site($request);
        $range = $this->range($request, $site);

        $rows = $this->metrics->dailySeries($site->id, $range);

        return response()->json([
            'series' => $rows->map(fn ($r) => [
                'date'    => $r->date->toDateString(),
                'revenue' => (float) $r->revenue,
                'orders'  => (int) $r->orders,
                'refunds' => (int) $r->refunds,
            ]),
        ]);
    }

    public function orders(Request $request)
    {
        $site  = $this->site($request);
        $range = $this->range($request, $site);

        $rows = $this->metrics->dailySeries($site->id, $range);

        return response()->json([
            'series' => $rows->map(fn ($r) => [
                'date'   => $r->date->toDateString(),
                'orders' => (int) $r->orders,
                'failed' => (int) $r->failed_orders,
            ]),
        ]);
    }

    public function products(Request $request)
    {
        $site  = $this->site($request);
        $range = $this->range($request, $site);

        $top = $this->metrics->topProducts($site, $range, 20);

        $lowStock = WcProduct::query()
            ->where('site_id', $site->id)
            ->whereIn('stock_status', ['outofstock', 'onbackorder'])
            ->orderBy('stock_quantity')
            ->limit(20)
            ->get(['external_product_id', 'name', 'sku', 'stock_quantity', 'stock_status']);

        return response()->json([
            'top_products' => $top->map(fn ($p) => [
                'product_id' => (int) $p->external_product_id,
                'name'       => $p->name,
                'sku'        => $p->sku,
                'units'      => (int) $p->units,
                'revenue'    => (float) $p->revenue,
            ]),
            'low_stock'    => $lowStock,
        ]);
    }

    public function customers(Request $request)
    {
        $site  = $this->site($request);
        $range = $this->range($request, $site);
        $agg   = $this->metrics->aggregate($site->id, $range);

        $top = $this->metrics->topCustomers($site, 20);

        $byCountry = WcCustomer::query()
            ->where('site_id', $site->id)
            ->whereNotNull('country')
            ->groupBy('country')
            ->selectRaw('country, COUNT(*) as customers, SUM(total_spent) as spent')
            ->orderByDesc('customers')
            ->limit(15)
            ->get();

        return response()->json([
            'new_customers'       => $agg['new_customers'],
            'returning_customers' => $agg['returning_customers'],
            'top_customers'       => $top,
            'by_country'          => $byCountry,
        ]);
    }

    public function refunds(Request $request)
    {
        $site  = $this->site($request);
        $range = $this->range($request, $site);

        $base = fn () => WcOrder::query()
            ->where('site_id', $site->id)
            ->whereNotNull('created_at_store')
            ->where('created_at_store', '>=', $range->utcStart())
            ->where('created_at_store', '<', $range->utcEnd())
            ->where('refund_total', '>', 0);

        return response()->json([
            'count'  => (int) $base()->count(),
            'total'  => (float) $base()->sum('refund_total'),
            'orders' => $base()->orderByDesc('created_at_store')->limit(50)
                ->get(['external_order_id', 'order_number', 'status', 'total', 'refund_total', 'created_at_store']),
        ]);
    }

    /** Recent order feed for the Orders page. */
    public function ordersList(Request $request)
    {
        $site = $this->site($request);

        $orders = WcOrder::query()
            ->where('site_id', $site->id)
            ->orderByDesc('created_at_store')
            ->limit(50)
            ->get(['external_order_id', 'order_number', 'customer_id', 'status', 'total', 'currency', 'payment_method', 'created_at_store']);

        $names = WcCustomer::query()
            ->where('site_id', $site->id)
            ->whereIn('external_customer_id', $orders->pluck('customer_id')->filter()->unique()->values())
            ->get(['external_customer_id', 'first_name', 'last_name'])
            ->keyBy('external_customer_id');

        return response()->json([
            'orders' => $orders->map(function ($o) use ($names) {
                $c = $names->get($o->customer_id);
                $name = $c ? trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '')) : null;

                return [
                    'order_number'   => $o->order_number ?: ('#' . $o->external_order_id),
                    'customer'       => $name ?: 'Guest',
                    'status'         => $o->status,
                    'total'          => (float) $o->total,
                    'currency'       => $o->currency,
                    'payment_method' => $o->payment_method,
                    'placed_at'      => optional($o->created_at_store)->toIso8601String(),
                ];
            }),
        ]);
    }

    public function compare(Request $request)
    {
        $site  = $this->site($request);
        $range = $this->range($request, $site);
        $prev  = $range->previous();

        $current  = $this->metrics->aggregate($site->id, $range);
        $previous = $this->metrics->aggregate($site->id, $prev);

        return response()->json([
            'current'  => ['from' => $range->dateFrom(), 'to' => $range->dateTo(), 'metrics' => $current],
            'previous' => ['from' => $prev->dateFrom(), 'to' => $prev->dateTo(), 'metrics' => $previous],
            'deltas'   => $this->metrics->deltas($current, $previous),
        ]);
    }

    // --- helpers -----------------------------------------------------------

    private function site(Request $request): Site
    {
        return $request->attributes->get('site');
    }

    private function range(Request $request, Site $site): DateRange
    {
        return DateRange::fromRequest(
            $request->query('period'),
            $request->query('from'),
            $request->query('to'),
            $site->timezone ?: config('app.timezone', 'UTC'),
        );
    }
}
