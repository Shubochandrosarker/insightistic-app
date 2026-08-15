<?php

namespace App\Services\Intelligence;

use App\Metrics\CommerceStatus;
use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Customer analytics derived from order history (spec Phase 7).
 *
 * Everything here is computed from `wc_orders` rather than the denormalized
 * counters on `wc_customers`. Those counters are whatever the store reported
 * at sync time and include cancelled and refunded orders; recomputing from
 * the order rows means a refund actually moves the numbers, and the same
 * definition of "a paying order" applies to every metric on this page.
 *
 * No personal data leaves this class. Segments carry counts and money, and
 * the only per-customer identifier exposed is the store's own id — enough to
 * look someone up in WooCommerce, not enough to build a mailing list from an
 * API response.
 */
class CustomerIntelligence
{
    /**
     * Orders that represent money actually taken.
     *
     * WooCommerce statuses: `completed` and `processing` are paid; everything
     * else (pending, on-hold, cancelled, refunded, failed, draft) is not, and
     * counting them inflates every metric that follows.
     *
     * Canonical source: App\Metrics\CommerceStatus::CONFIRMED_PAID. This is
     * deliberately narrower than the on-hold-inclusive list MetricsAssembler
     * uses for headline revenue KPIs — see that class's docblock.
     */
    private const PAID_STATUSES = CommerceStatus::CONFIRMED_PAID;

    /**
     * RFM segmentation.
     *
     * Scored on quintiles of the site's own customers rather than fixed
     * thresholds: "recent" for a site selling coffee weekly is a different
     * number from one selling mattresses, and absolute cutoffs would label
     * every customer of a slow-cycle store as churned.
     *
     * Requires a meaningful population — quintiles over eleven customers are
     * noise wearing the costume of analysis — so below the floor this reports
     * insufficient data instead of confident nonsense.
     */
    public const RFM_MIN_CUSTOMERS = 20;

    /** Segments, in the order the dashboard shows them. */
    public const SEGMENTS = [
        'champions', 'loyal', 'potential_loyalist', 'new', 'promising',
        'needs_attention', 'at_risk', 'hibernating', 'lost',
    ];

    public function rfm(Site $site, ?Carbon $asOf = null): array
    {
        $asOf = $asOf ?: now();

        $customers = $this->customerAggregates($site);

        if ($customers->count() < self::RFM_MIN_CUSTOMERS) {
            return [
                'has_data'       => false,
                'reason'         => 'not_enough_customers',
                'customers'      => $customers->count(),
                'minimum'        => self::RFM_MIN_CUSTOMERS,
                'segments'       => [],
                'total_value'    => 0.0,
            ];
        }

        $rows = $customers->map(function ($c) use ($asOf) {
            return [
                'customer_id' => (int) $c->customer_id,
                'recency'     => Carbon::parse($c->last_order_at)->diffInDays($asOf),
                'frequency'   => (int) $c->orders,
                'monetary'    => (float) $c->revenue,
            ];
        })->values();

        // Recency is scored inverted: fewer days since the last order is better.
        $rScores = $this->quintileScores($rows->pluck('recency')->all(), invert: true);
        $fScores = $this->quintileScores($rows->pluck('frequency')->all());
        $mScores = $this->quintileScores($rows->pluck('monetary')->all());

        $segments = array_fill_keys(self::SEGMENTS, ['customers' => 0, 'revenue' => 0.0, 'orders' => 0]);
        $scored = [];

        foreach ($rows as $i => $row) {
            $r = $rScores[$i];
            $f = $fScores[$i];
            $m = $mScores[$i];
            $segment = $this->segmentFor($r, $f, $m);

            $segments[$segment]['customers']++;
            $segments[$segment]['revenue'] += $row['monetary'];
            $segments[$segment]['orders'] += $row['frequency'];

            $scored[] = $row + ['r' => $r, 'f' => $f, 'm' => $m, 'segment' => $segment];
        }

        $total = array_sum(array_column($segments, 'revenue'));

        $out = [];
        foreach (self::SEGMENTS as $key) {
            $s = $segments[$key];
            $out[] = [
                'segment'         => $key,
                'label'           => $this->segmentLabel($key),
                'description'     => $this->segmentDescription($key),
                'customers'       => $s['customers'],
                'orders'          => $s['orders'],
                'revenue'         => round($s['revenue'], 2),
                'revenue_share'   => $total > 0 ? round($s['revenue'] / $total * 100, 1) : 0.0,
                'avg_order_value' => $s['orders'] > 0 ? round($s['revenue'] / $s['orders'], 2) : null,
            ];
        }

        return [
            'has_data'    => true,
            'customers'   => $rows->count(),
            'total_value' => round($total, 2),
            'currency'    => $this->currency($site),
            'as_of'       => $asOf->toDateString(),
            'segments'    => $out,
        ];
    }

    /**
     * Monthly acquisition cohorts and their repeat behaviour.
     *
     * Each cohort is the set of customers whose *first* paid order fell in
     * that month; the row then shows what share of them ordered again in each
     * subsequent month. Later cohorts have had less time to come back, so the
     * grid deliberately leaves those cells null rather than showing 0% —
     * "hasn't happened yet" and "didn't happen" are different findings and a
     * zero there reads as a retention collapse that never occurred.
     */
    public function cohorts(Site $site, int $months = 12): array
    {
        $months = max(1, min(24, $months));
        $start = now()->startOfMonth()->subMonths($months - 1);

        $orders = DB::table('wc_orders')
            ->where('site_id', $site->id)
            ->whereIn('status', self::PAID_STATUSES)
            ->whereNotNull('customer_id')
            ->whereNotNull('created_at_store')
            ->orderBy('created_at_store')
            ->get(['customer_id', 'created_at_store', 'total', 'refund_total']);

        if ($orders->isEmpty()) {
            return ['has_data' => false, 'cohorts' => [], 'months' => $months];
        }

        $firstOrder = [];
        $activity = [];   // [customerId][YYYY-MM] => revenue

        foreach ($orders as $o) {
            $month = Carbon::parse($o->created_at_store)->format('Y-m');
            $cid = (int) $o->customer_id;

            $firstOrder[$cid] ??= $month;
            $activity[$cid][$month] = ($activity[$cid][$month] ?? 0) + $this->net($o);
        }

        $cohortMonths = [];
        for ($i = 0; $i < $months; $i++) {
            $cohortMonths[] = $start->copy()->addMonths($i)->format('Y-m');
        }

        $rows = [];

        foreach ($cohortMonths as $index => $cohortMonth) {
            $members = array_keys(array_filter($firstOrder, fn ($m) => $m === $cohortMonth));

            if ($members === []) {
                continue;
            }

            /*
             * Row width is what this cohort has actually had the chance to do.
             * The window ends at the current month, so a cohort at index i has
             * exactly `months - 1 - i` months of observable history — this
             * month's cohort gets a single cell, last year's gets a full row.
             * Padding shorter rows out to a uniform width would mean printing
             * 0% for months that have not happened, and a reader cannot tell
             * that apart from a cohort that never came back.
             */
            $periods = [];
            for ($offset = 0; $offset <= $months - 1 - $index; $offset++) {
                $month = Carbon::createFromFormat('Y-m', $cohortMonth)->addMonths($offset)->format('Y-m');
                $active = 0;
                $revenue = 0.0;

                foreach ($members as $cid) {
                    if (isset($activity[$cid][$month])) {
                        $active++;
                        $revenue += $activity[$cid][$month];
                    }
                }

                $periods[] = [
                    'offset'    => $offset,
                    'customers' => $active,
                    'rate'      => round($active / count($members) * 100, 1),
                    'revenue'   => round($revenue, 2),
                ];
            }

            $rows[] = [
                'cohort'    => $cohortMonth,
                'customers' => count($members),
                'periods'   => $periods,
            ];
        }

        return [
            'has_data' => $rows !== [],
            'months'   => $months,
            'currency' => $this->currency($site),
            'cohorts'  => $rows,
        ];
    }

    /**
     * Customer lifetime value and repeat-purchase behaviour.
     *
     * This is *historic* CLV — revenue already taken — not a prediction.
     * Forecast CLV needs a churn model and an assumed margin, and a number
     * that looks like a forecast but is really an average would get planned
     * against. `projected_annual` is offered separately and labelled as the
     * arithmetic it is.
     */
    public function lifetimeValue(Site $site): array
    {
        $customers = $this->customerAggregates($site);

        if ($customers->isEmpty()) {
            return ['has_data' => false];
        }

        $revenues = $customers->pluck('revenue')->map(fn ($v) => (float) $v)->sort()->values();
        $orderCounts = $customers->pluck('orders')->map(fn ($v) => (int) $v);

        $total = $revenues->sum();
        $count = $revenues->count();
        $repeat = $orderCounts->filter(fn ($n) => $n > 1)->count();

        // Average lifespan of customers who have actually repeated; single-order
        // customers have a lifespan of zero and would drag it to meaninglessness.
        $spans = $customers
            ->filter(fn ($c) => (int) $c->orders > 1)
            ->map(fn ($c) => Carbon::parse($c->first_order_at)->diffInDays(Carbon::parse($c->last_order_at)));

        $avgOrders = $orderCounts->avg();
        $avgValue = $total / $count;
        $avgSpanDays = $spans->isNotEmpty() ? $spans->avg() : null;

        return [
            'has_data'            => true,
            'currency'            => $this->currency($site),
            'customers'           => $count,
            'total_revenue'       => round($total, 2),
            'avg_lifetime_value'  => round($avgValue, 2),
            'median_lifetime_value' => round($this->median($revenues->all()), 2),
            'avg_orders_per_customer' => round($avgOrders, 2),
            'avg_order_value'     => round($total / max(1, $orderCounts->sum()), 2),
            'repeat_customers'    => $repeat,
            'repeat_rate'         => round($repeat / $count * 100, 1),
            'avg_days_between_first_and_last' => $avgSpanDays !== null ? round($avgSpanDays, 1) : null,
            // Plain arithmetic, not a model: value per customer per year, given
            // how often the average repeat customer has actually ordered.
            'projected_annual'    => $avgSpanDays !== null && $avgSpanDays > 0
                ? round($avgValue / max(1, $avgSpanDays) * 365, 2)
                : null,
            'top_decile_share'    => $this->topDecileShare($revenues->all()),
        ];
    }

    /**
     * Customers whose next order is overdue relative to their own rhythm.
     *
     * Risk is measured against each customer's median gap between orders, not
     * a site-wide window: someone who reliably buys every 90 days is not at
     * risk on day 60, and someone who buys weekly already is. Customers with
     * a single order have no rhythm to be late against and are reported
     * separately rather than being called churned.
     */
    public function churnRisk(Site $site, int $limit = 25): array
    {
        $orders = DB::table('wc_orders')
            ->where('site_id', $site->id)
            ->whereIn('status', self::PAID_STATUSES)
            ->whereNotNull('customer_id')
            ->whereNotNull('created_at_store')
            ->orderBy('created_at_store')
            ->get(['customer_id', 'created_at_store', 'total', 'refund_total']);

        if ($orders->isEmpty()) {
            return ['has_data' => false, 'customers' => [], 'single_order_customers' => 0];
        }

        $byCustomer = [];
        foreach ($orders as $o) {
            $byCustomer[(int) $o->customer_id][] = $o;
        }

        $now = now();
        $risks = [];
        $singleOrder = 0;

        foreach ($byCustomer as $cid => $rows) {
            if (count($rows) < 2) {
                $singleOrder++;
                continue;
            }

            $gaps = [];
            for ($i = 1; $i < count($rows); $i++) {
                $gaps[] = Carbon::parse($rows[$i - 1]->created_at_store)
                    ->diffInDays(Carbon::parse($rows[$i]->created_at_store));
            }

            $typicalGap = $this->median($gaps);

            // A customer who ordered twice in one day has no usable cadence.
            if ($typicalGap < 1) {
                continue;
            }

            $daysSince = Carbon::parse(end($rows)->created_at_store)->diffInDays($now);
            $overdue = $daysSince / $typicalGap;

            if ($overdue < 1.5) {
                continue;
            }

            $risks[] = [
                'customer_id'    => $cid,
                'orders'         => count($rows),
                'revenue'        => round(array_sum(array_map(fn ($r) => $this->net($r), $rows)), 2),
                'typical_gap_days' => (int) round($typicalGap),
                'days_since_last'  => (int) $daysSince,
                'overdue_factor'   => round($overdue, 1),
                'risk'             => $overdue >= 3 ? 'high' : ($overdue >= 2 ? 'medium' : 'low'),
            ];
        }

        // Highest-value customers first: a lapsed £2,000 customer is worth more
        // attention than a lapsed £20 one who is technically later.
        usort($risks, fn ($a, $b) => $b['revenue'] <=> $a['revenue']);

        $atRisk = array_slice($risks, 0, $limit);

        return [
            'has_data'               => true,
            'currency'               => $this->currency($site),
            'customers'              => $atRisk,
            'total_at_risk'          => count($risks),
            'revenue_at_risk'        => round(array_sum(array_column($risks, 'revenue')), 2),
            'single_order_customers' => $singleOrder,
        ];
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /** Per-customer totals recomputed from paid orders, net of refunds. */
    private function customerAggregates(Site $site)
    {
        return DB::table('wc_orders')
            ->where('site_id', $site->id)
            ->whereIn('status', self::PAID_STATUSES)
            ->whereNotNull('customer_id')
            ->whereNotNull('created_at_store')
            ->groupBy('customer_id')
            ->selectRaw('customer_id')
            ->selectRaw('COUNT(*) as orders')
            ->selectRaw('SUM(total - COALESCE(refund_total, 0)) as revenue')
            ->selectRaw('MIN(created_at_store) as first_order_at')
            ->selectRaw('MAX(created_at_store) as last_order_at')
            ->get()
            ->filter(fn ($c) => (float) $c->revenue > 0);
    }

    private function net(object $order): float
    {
        return (float) $order->total - (float) ($order->refund_total ?? 0);
    }

    /**
     * Score each value 1–5 by which quintile of the population it falls in.
     *
     * Ties share a score. Without that, two customers with identical order
     * counts could land in different segments purely because of row order,
     * which makes the segmentation unreproducible between runs.
     */
    private function quintileScores(array $values, bool $invert = false): array
    {
        $sorted = $values;
        sort($sorted);
        $n = count($sorted);

        $cuts = [];
        foreach ([0.2, 0.4, 0.6, 0.8] as $q) {
            $cuts[] = $sorted[max(0, (int) floor($q * $n) - 1)];
        }

        $scores = [];
        foreach ($values as $value) {
            $score = 1;
            foreach ($cuts as $cut) {
                if ($value > $cut) {
                    $score++;
                }
            }
            $scores[] = $invert ? 6 - $score : $score;
        }

        return $scores;
    }

    /** The standard RFM grid, collapsed to the nine segments worth acting on. */
    private function segmentFor(int $r, int $f, int $m): string
    {
        $fm = (int) round(($f + $m) / 2);

        return match (true) {
            $r >= 4 && $fm >= 4 => 'champions',
            $r >= 3 && $fm >= 4 => 'loyal',
            $r >= 4 && $fm === 3 => 'potential_loyalist',
            $r === 5 && $fm <= 2 => 'new',
            $r >= 3 && $fm <= 2 => 'promising',
            $r === 3 && $fm === 3 => 'needs_attention',
            $r === 2 && $fm >= 3 => 'at_risk',
            $r <= 2 && $fm === 2 => 'hibernating',
            default => 'lost',
        };
    }

    private function segmentLabel(string $key): string
    {
        return match ($key) {
            'champions'          => 'Champions',
            'loyal'              => 'Loyal customers',
            'potential_loyalist' => 'Potential loyalists',
            'new'                => 'New customers',
            'promising'          => 'Promising',
            'needs_attention'    => 'Needs attention',
            'at_risk'            => 'At risk',
            'hibernating'        => 'Hibernating',
            'lost'               => 'Lost',
        };
    }

    private function segmentDescription(string $key): string
    {
        return match ($key) {
            'champions'          => 'Bought recently, buy often, and spend the most.',
            'loyal'              => 'Spend well and come back regularly.',
            'potential_loyalist' => 'Recent buyers with room to become regulars.',
            'new'                => 'Bought very recently, only once so far.',
            'promising'          => 'Recent, low spend — worth a nudge.',
            'needs_attention'    => 'Middling on everything; slipping quietly.',
            'at_risk'            => 'Used to spend well, have not been back.',
            'hibernating'        => 'Long gone and low value while they lasted.',
            'lost'               => 'No recent activity and little history.',
        };
    }

    private function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        sort($values);
        $n = count($values);
        $mid = (int) floor($n / 2);

        return $n % 2 === 0
            ? ((float) $values[$mid - 1] + (float) $values[$mid]) / 2
            : (float) $values[$mid];
    }

    /** Concentration check: how much of revenue the top 10% of customers hold. */
    private function topDecileShare(array $revenues): ?float
    {
        $n = count($revenues);

        if ($n < 10) {
            return null;
        }

        rsort($revenues);
        $top = array_slice($revenues, 0, (int) ceil($n / 10));
        $total = array_sum($revenues);

        return $total > 0 ? round(array_sum($top) / $total * 100, 1) : null;
    }

    private function currency(Site $site): ?string
    {
        return DB::table('wc_orders')
            ->where('site_id', $site->id)
            ->whereNotNull('currency')
            ->value('currency');
    }
}
