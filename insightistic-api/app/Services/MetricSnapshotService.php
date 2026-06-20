<?php

namespace App\Services;

use App\Models\MetricSnapshot;
use App\Models\Site;
use App\Models\WcOrder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class MetricSnapshotService
{
    /** Statuses that count as real sales. */
    private array $paidStatuses = ['completed', 'processing', 'on-hold'];

    /** Statuses that count as failed/lost. */
    private array $failedStatuses = ['failed', 'cancelled'];

    /**
     * Compute + upsert the snapshot for one site on one LOCAL date (Y-m-d).
     * "Local" = the store's timezone, so a day matches what the owner sees in WP.
     */
    public function build(Site $site, string $localDate): MetricSnapshot
    {
        $tz    = $site->timezone ?: config('app.timezone', 'UTC');
        $start = CarbonImmutable::parse($localDate . ' 00:00:00', $tz)->utc();
        $end   = $start->addDay();

        $window = fn () => WcOrder::query()
            ->where('site_id', $site->id)
            ->whereNotNull('created_at_store')
            ->where('created_at_store', '>=', $start)
            ->where('created_at_store', '<', $end);

        $revenue = (float) $window()->whereIn('status', $this->paidStatuses)->sum('total');
        $orders  = (int)   $window()->whereIn('status', $this->paidStatuses)->count();
        $failed  = (int)   $window()->whereIn('status', $this->failedStatuses)->count();
        $refunds = (int)   $window()->where('refund_total', '>', 0)->count();
        $aov     = $orders > 0 ? round($revenue / $orders, 2) : 0;

        $productsSold = (int) DB::table('wc_order_items as oi')
            ->join('wc_orders as o', function ($j) use ($site) {
                $j->on('o.external_order_id', '=', 'oi.external_order_id')
                  ->where('o.site_id', '=', $site->id);
            })
            ->where('oi.site_id', $site->id)
            ->whereNotNull('o.created_at_store')
            ->where('o.created_at_store', '>=', $start)
            ->where('o.created_at_store', '<', $end)
            ->whereIn('o.status', $this->paidStatuses)
            ->sum('oi.quantity');

        [$new, $returning] = $this->newVsReturning($site, $start, $end);

        return MetricSnapshot::updateOrCreate(
            ['site_id' => $site->id, 'date' => $localDate],
            [
                'revenue'             => $revenue,
                'orders'              => $orders,
                'refunds'             => $refunds,
                'average_order_value' => $aov,
                'new_customers'       => $new,
                'returning_customers' => $returning,
                'products_sold'       => $productsSold,
                'failed_orders'       => $failed,
            ]
        );
    }

    /**
     * New vs returning registered customers (customer_id > 0) active in window.
     * "New" = their earliest paid order on this site falls inside the window.
     * Guests (customer_id 0) are not attributed here.
     */
    private function newVsReturning(Site $site, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $active = WcOrder::query()
            ->where('site_id', $site->id)
            ->whereIn('status', $this->paidStatuses)
            ->where('customer_id', '>', 0)
            ->whereNotNull('created_at_store')
            ->where('created_at_store', '>=', $start)
            ->where('created_at_store', '<', $end)
            ->distinct()
            ->pluck('customer_id');

        if ($active->isEmpty()) {
            return [0, 0];
        }

        // Earliest paid order per active customer (single grouped query).
        $firstOrders = WcOrder::query()
            ->where('site_id', $site->id)
            ->whereIn('status', $this->paidStatuses)
            ->whereIn('customer_id', $active->all())
            ->groupBy('customer_id')
            ->selectRaw('customer_id, MIN(created_at_store) as first_dt')
            ->pluck('first_dt', 'customer_id');

        $new = 0;
        foreach ($active as $cid) {
            $first = $firstOrders[$cid] ?? null;
            if ($first) {
                $firstDt = CarbonImmutable::parse($first);
                if ($firstDt->greaterThanOrEqualTo($start) && $firstDt->lessThan($end)) {
                    $new++;
                }
            }
        }

        return [$new, $active->count() - $new];
    }

    /**
     * Backfill every day from the site's earliest order to today (site tz).
     * Returns the number of days built. Safe to re-run (idempotent upsert).
     */
    public function rebuild(Site $site): int
    {
        $min = WcOrder::query()
            ->where('site_id', $site->id)
            ->whereNotNull('created_at_store')
            ->min('created_at_store');

        if (! $min) {
            return 0;
        }

        $tz    = $site->timezone ?: config('app.timezone', 'UTC');
        $day   = CarbonImmutable::parse($min)->setTimezone($tz)->startOfDay();
        $today = CarbonImmutable::now($tz)->startOfDay();

        $count = 0;
        while ($day->lessThanOrEqualTo($today)) {
            $this->build($site, $day->toDateString());
            $day = $day->addDay();
            $count++;
        }

        return $count;
    }

    /** Convenience: (re)build today + yesterday for incremental nightly runs. */
    public function buildRecent(Site $site): void
    {
        $tz    = $site->timezone ?: config('app.timezone', 'UTC');
        $today = CarbonImmutable::now($tz)->startOfDay();
        $this->build($site, $today->subDay()->toDateString());
        $this->build($site, $today->toDateString());
    }
}
