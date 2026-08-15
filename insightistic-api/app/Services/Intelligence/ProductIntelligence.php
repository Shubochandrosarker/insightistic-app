<?php

namespace App\Services\Intelligence;

use App\Metrics\CommerceStatus;
use App\Models\Site;
use App\Support\DateRange;
use Illuminate\Support\Facades\DB;

/**
 * Product, inventory and pricing analytics (spec Phase 7).
 *
 * Like CustomerIntelligence, everything is recomputed from order rows rather
 * than trusting the store's own `total_sales` counter, which never decreases
 * on refund and counts unpaid orders.
 */
class ProductIntelligence
{
    /** Canonical source: App\Metrics\CommerceStatus::CONFIRMED_PAID (see CustomerIntelligence for why this differs from MetricsAssembler's revenue-KPI status list). */
    private const PAID_STATUSES = CommerceStatus::CONFIRMED_PAID;

    /**
     * A pair must appear together this many times before it is reported.
     *
     * Two products sharing one basket is a coincidence, not an affinity, and
     * a "frequently bought together" list built from coincidences produces
     * recommendations that make a store look broken.
     */
    public const MIN_PAIR_SUPPORT = 3;

    /**
     * Frequently bought together.
     *
     * Reports lift as well as raw co-occurrence. Raw counts alone just
     * rediscover the bestsellers — of course the two most popular items share
     * baskets — whereas lift asks whether they appear together *more often
     * than their individual popularity would predict*, which is the part that
     * is actually actionable.
     */
    public function affinity(Site $site, DateRange $range, int $limit = 15): array
    {
        $items = DB::table('wc_order_items as i')
            ->join('wc_orders as o', function ($j) {
                $j->on('o.external_order_id', '=', 'i.external_order_id')
                  ->on('o.site_id', '=', 'i.site_id');
            })
            ->where('i.site_id', $site->id)
            ->whereIn('o.status', self::PAID_STATUSES)
            ->whereBetween('o.created_at_store', [$range->utcStart(), $range->utcEnd()])
            ->whereNotNull('i.external_product_id')
            ->get(['i.external_order_id', 'i.external_product_id', 'i.product_name']);

        if ($items->isEmpty()) {
            return ['has_data' => false, 'pairs' => [], 'baskets' => 0];
        }

        $baskets = [];
        $names = [];

        foreach ($items as $row) {
            $pid = (int) $row->external_product_id;
            $baskets[(int) $row->external_order_id][$pid] = true;
            $names[$pid] ??= $row->product_name;
        }

        $productCounts = [];
        $pairCounts = [];
        $multiItemBaskets = 0;

        foreach ($baskets as $products) {
            $ids = array_keys($products);
            sort($ids);

            foreach ($ids as $id) {
                $productCounts[$id] = ($productCounts[$id] ?? 0) + 1;
            }

            if (count($ids) < 2) {
                continue;
            }

            $multiItemBaskets++;

            for ($i = 0; $i < count($ids); $i++) {
                for ($j = $i + 1; $j < count($ids); $j++) {
                    $key = $ids[$i] . ':' . $ids[$j];
                    $pairCounts[$key] = ($pairCounts[$key] ?? 0) + 1;
                }
            }
        }

        if ($multiItemBaskets === 0) {
            return [
                'has_data' => false,
                'reason'   => 'no_multi_item_baskets',
                'pairs'    => [],
                'baskets'  => count($baskets),
            ];
        }

        $totalBaskets = count($baskets);
        $pairs = [];

        foreach ($pairCounts as $key => $count) {
            if ($count < self::MIN_PAIR_SUPPORT) {
                continue;
            }

            [$a, $b] = array_map('intval', explode(':', $key));

            $pA = $productCounts[$a] / $totalBaskets;
            $pB = $productCounts[$b] / $totalBaskets;
            $pAB = $count / $totalBaskets;

            $pairs[] = [
                'product_a'      => ['id' => $a, 'name' => $names[$a] ?? "Product #{$a}"],
                'product_b'      => ['id' => $b, 'name' => $names[$b] ?? "Product #{$b}"],
                'together'       => $count,
                'support'        => round($pAB * 100, 2),
                // P(B | A) — of the baskets containing A, how many also had B.
                'confidence'     => round($count / $productCounts[$a] * 100, 1),
                'lift'           => $pA > 0 && $pB > 0 ? round($pAB / ($pA * $pB), 2) : null,
            ];
        }

        // Lift first: the surprising pairs are the ones worth merchandising.
        usort($pairs, fn ($x, $y) => ($y['lift'] ?? 0) <=> ($x['lift'] ?? 0));

        return [
            'has_data'            => $pairs !== [],
            'baskets'             => $totalBaskets,
            'multi_item_baskets'  => $multiItemBaskets,
            'min_support'         => self::MIN_PAIR_SUPPORT,
            'pairs'               => array_slice($pairs, 0, $limit),
        ];
    }

    /**
     * Stock cover: how many days of sales each product's stock will last.
     *
     * Velocity comes from the selected window's actual sales, so a product
     * that stopped selling reports a long cover rather than being flagged as
     * urgent — the risk is running out of things people are buying, not
     * holding stock of things they are not.
     *
     * Products the store does not track stock for are excluded rather than
     * treated as zero: a null stock quantity means "not managed", and
     * reporting it as an imminent stockout would bury the real ones.
     */
    public function stockRisk(Site $site, DateRange $range, int $limit = 25): array
    {
        $days = max(1, $range->days());

        $velocity = $this->unitsByProduct($site, $range->utcStart(), $range->utcEnd());

        $products = DB::table('wc_products')
            ->where('site_id', $site->id)
            ->whereNotNull('stock_quantity')
            ->get(['external_product_id', 'name', 'sku', 'stock_quantity', 'stock_status', 'price']);

        if ($products->isEmpty()) {
            return [
                'has_data' => false,
                'reason'   => 'no_managed_stock',
                'products' => [],
            ];
        }

        $rows = [];

        foreach ($products as $p) {
            $pid = (int) $p->external_product_id;
            $units = (int) ($velocity[$pid]->units ?? 0);
            $perDay = $units / $days;
            $stock = (int) $p->stock_quantity;

            $coverDays = $perDay > 0 ? $stock / $perDay : null;

            $rows[] = [
                'product_id'      => $pid,
                'name'            => $p->name,
                'sku'             => $p->sku,
                'stock'           => $stock,
                'stock_status'    => $p->stock_status,
                'units_sold'      => $units,
                'units_per_day'   => round($perDay, 2),
                'days_of_cover'   => $coverDays !== null ? round($coverDays, 1) : null,
                'stockout_on'     => $coverDays !== null ? now()->addDays((int) floor($coverDays))->toDateString() : null,
                'window_revenue'  => round((float) ($velocity[$pid]->revenue ?? 0), 2),
                'risk'            => $this->stockRiskLevel($coverDays, $stock, $units),
            ];
        }

        // Sort by urgency, then by what it costs to run out — a fast seller
        // seven days from empty matters more than a slow one at five.
        $order = ['critical' => 0, 'high' => 1, 'medium' => 2, 'none' => 3, 'idle' => 4];
        usort($rows, function ($a, $b) use ($order) {
            return [$order[$a['risk']], -$a['window_revenue']] <=> [$order[$b['risk']], -$b['window_revenue']];
        });

        $atRisk = array_values(array_filter($rows, fn ($r) => in_array($r['risk'], ['critical', 'high', 'medium'], true)));

        return [
            'has_data'        => true,
            'window_days'     => $days,
            'tracked_products' => count($rows),
            'at_risk'         => count($atRisk),
            'products'        => array_slice($rows, 0, $limit),
        ];
    }

    /**
     * What discounting is actually doing to the business.
     *
     * Compares discounted against undiscounted orders in the same window.
     * The interesting number is not how much was given away but whether
     * discounted baskets are bigger: if they are smaller *and* cost margin,
     * the promotion is buying orders that would have happened anyway.
     */
    public function discountImpact(Site $site, DateRange $range): array
    {
        $orders = DB::table('wc_orders')
            ->where('site_id', $site->id)
            ->whereIn('status', self::PAID_STATUSES)
            ->whereBetween('created_at_store', [$range->utcStart(), $range->utcEnd()])
            ->get(['total', 'subtotal', 'discount_total', 'refund_total', 'customer_id']);

        if ($orders->isEmpty()) {
            return ['has_data' => false];
        }

        $discounted = $orders->filter(fn ($o) => (float) $o->discount_total > 0);
        $full = $orders->reject(fn ($o) => (float) $o->discount_total > 0);

        $net = fn ($o) => (float) $o->total - (float) ($o->refund_total ?? 0);

        $discountedRevenue = $discounted->sum($net);
        $fullRevenue = $full->sum($net);
        $givenAway = $discounted->sum(fn ($o) => (float) $o->discount_total);

        $discountedAov = $discounted->isNotEmpty() ? $discountedRevenue / $discounted->count() : null;
        $fullAov = $full->isNotEmpty() ? $fullRevenue / $full->count() : null;

        return [
            'has_data' => true,
            'currency' => DB::table('wc_orders')->where('site_id', $site->id)->whereNotNull('currency')->value('currency'),
            'orders'   => $orders->count(),
            'discounted' => [
                'orders'          => $discounted->count(),
                'share'           => round($discounted->count() / $orders->count() * 100, 1),
                'revenue'         => round($discountedRevenue, 2),
                'avg_order_value' => $discountedAov !== null ? round($discountedAov, 2) : null,
                'total_discount'  => round($givenAway, 2),
                'avg_discount'    => $discounted->isNotEmpty() ? round($givenAway / $discounted->count(), 2) : null,
                'discount_rate'   => $discountedRevenue + $givenAway > 0
                    ? round($givenAway / ($discountedRevenue + $givenAway) * 100, 1)
                    : null,
            ],
            'full_price' => [
                'orders'          => $full->count(),
                'share'           => round($full->count() / $orders->count() * 100, 1),
                'revenue'         => round($fullRevenue, 2),
                'avg_order_value' => $fullAov !== null ? round($fullAov, 2) : null,
            ],
            // Positive means discounted baskets are bigger. Both sides need
            // orders for the comparison to mean anything.
            'aov_difference' => $discountedAov !== null && $fullAov !== null
                ? round($discountedAov - $fullAov, 2)
                : null,
            'aov_difference_pct' => $discountedAov !== null && $fullAov !== null && $fullAov > 0
                ? round(($discountedAov - $fullAov) / $fullAov * 100, 1)
                : null,
            'revenue_share_discounted' => $discountedRevenue + $fullRevenue > 0
                ? round($discountedRevenue / ($discountedRevenue + $fullRevenue) * 100, 1)
                : null,
        ];
    }

    /**
     * Products whose sales are falling relative to the preceding window.
     *
     * The comparison window is the same length immediately before the
     * selected one, so a 30-day view compares against the 30 days before it
     * rather than a fixed month — otherwise every mid-month check would show
     * a collapse that is really just fewer elapsed days.
     */
    public function decliningProducts(Site $site, DateRange $range, int $limit = 15): array
    {
        $days = max(1, $range->days());
        $prev = $range->previous();

        $current = $this->unitsByProduct($site, $range->utcStart(), $range->utcEnd());
        $previous = $this->unitsByProduct($site, $prev->utcStart(), $prev->utcEnd());

        if ($current->isEmpty() && $previous->isEmpty()) {
            return ['has_data' => false, 'products' => []];
        }

        $names = DB::table('wc_products')
            ->where('site_id', $site->id)
            ->pluck('name', 'external_product_id');

        $rows = [];

        foreach ($previous as $pid => $prev) {
            $prevUnits = (int) $prev->units;

            // Products that barely sold before cannot meaningfully decline.
            if ($prevUnits < 3) {
                continue;
            }

            $nowUnits = (int) ($current[$pid]->units ?? 0);
            $change = ($nowUnits - $prevUnits) / $prevUnits * 100;

            if ($change >= -20) {
                continue;
            }

            $rows[] = [
                'product_id'     => (int) $pid,
                'name'           => $names[$pid] ?? "Product #{$pid}",
                'units'          => $nowUnits,
                'previous_units' => $prevUnits,
                'change_pct'     => round($change, 1),
                'revenue'        => round((float) ($current[$pid]->revenue ?? 0), 2),
                'previous_revenue' => round((float) $prev->revenue, 2),
            ];
        }

        usort($rows, fn ($a, $b) => $b['previous_revenue'] <=> $a['previous_revenue']);

        return [
            'has_data'        => $rows !== [],
            'window_days'     => $days,
            'compared_against' => $prev->dateFrom() . ' to ' . $prev->dateTo(),
            'products'        => array_slice($rows, 0, $limit),
        ];
    }

    // ------------------------------------------------------------------

    private function unitsByProduct(Site $site, string $from, string $to)
    {
        return DB::table('wc_order_items as i')
            ->join('wc_orders as o', function ($j) {
                $j->on('o.external_order_id', '=', 'i.external_order_id')
                  ->on('o.site_id', '=', 'i.site_id');
            })
            ->where('i.site_id', $site->id)
            ->whereIn('o.status', self::PAID_STATUSES)
            ->whereBetween('o.created_at_store', [$from, $to])
            ->whereNotNull('i.external_product_id')
            ->groupBy('i.external_product_id')
            ->selectRaw('i.external_product_id, SUM(i.quantity) as units, SUM(i.total) as revenue')
            ->get()
            ->keyBy('external_product_id');
    }

    private function stockRiskLevel(?float $coverDays, int $stock, int $unitsSold): string
    {
        if ($unitsSold === 0) {
            // Sitting stock is a working-capital question, not a stockout risk.
            return $stock > 0 ? 'idle' : 'none';
        }

        if ($coverDays === null) {
            return 'none';
        }

        return match (true) {
            $coverDays <= 7  => 'critical',
            $coverDays <= 14 => 'high',
            $coverDays <= 30 => 'medium',
            default          => 'none',
        };
    }
}
