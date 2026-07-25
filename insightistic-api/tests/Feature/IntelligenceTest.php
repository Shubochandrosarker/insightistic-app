<?php

use App\Models\Organization;
use App\Models\Site;
use App\Services\Intelligence\AcquisitionIntelligence;
use App\Services\Intelligence\CustomerIntelligence;
use App\Services\Intelligence\ProductIntelligence;
use App\Services\Intelligence\SearchIntelligence;
use App\Support\DateRange;
use Illuminate\Support\Facades\DB;

/**
 * Advanced business intelligence (spec Phase 7).
 *
 * These tests are about the arithmetic, not the plumbing. Every number here
 * is one a store owner could act on — reorder stock, pause a discount, chase a
 * lapsing customer — so the cases that matter are the ones where a plausible
 * shortcut would produce a confident wrong answer: refunds counted as revenue,
 * unpaid orders counted as sales, a quiet cohort reported as churn, an
 * unobservable future month reported as 0% retention.
 */

function intelSite(Organization $org, string $name = 'Shop'): Site
{
    return Site::withoutGlobalScope('organization')->create([
        'organization_id'   => $org->id,
        'name'              => $name,
        'domain'            => strtolower($name) . '-' . uniqid() . '.example.com',
        'connection_status' => 'connected',
        'timezone'          => 'UTC',
    ]);
}

function intelOrder(Site $site, array $attrs = []): int
{
    static $seq = 1000;
    $externalId = $attrs['external_order_id'] ?? $seq++;

    DB::table('wc_orders')->insert(array_merge([
        'site_id'           => $site->id,
        'external_order_id' => $externalId,
        'customer_id'       => 1,
        'status'            => 'completed',
        'currency'          => 'USD',
        'total'             => 100,
        'subtotal'          => 100,
        'tax_total'         => 0,
        'shipping_total'    => 0,
        'discount_total'    => 0,
        'refund_total'      => 0,
        'created_at_store'  => now()->subDays(5),
        'created_at'        => now(),
        'updated_at'        => now(),
    ], $attrs, ['external_order_id' => $externalId]));

    return $externalId;
}

function intelItem(Site $site, int $orderId, int $productId, array $attrs = []): void
{
    DB::table('wc_order_items')->insert(array_merge([
        'site_id'             => $site->id,
        'external_order_id'   => $orderId,
        'external_product_id' => $productId,
        'product_name'        => "Product {$productId}",
        'quantity'            => 1,
        'subtotal'            => 50,
        'total'               => 50,
        'created_at'          => now(),
        'updated_at'          => now(),
    ], $attrs));
}

function intelRange(int $days = 30): DateRange
{
    return DateRange::fromRequest($days === 30 ? 'last_30_days' : 'custom', now()->subDays($days - 1)->toDateString(), now()->toDateString(), 'UTC');
}

// ---------------------------------------------------------------------------
// Customer intelligence
// ---------------------------------------------------------------------------

it('refuses to segment a population too small for quintiles', function () {
    [, $org] = makeOrgUser('intel-rfm-small@example.com');
    $site = intelSite($org);

    foreach (range(1, 5) as $c) {
        intelOrder($site, ['customer_id' => $c]);
    }

    $result = app(CustomerIntelligence::class)->rfm($site);

    // Quintiles over five customers is noise wearing the costume of analysis.
    expect($result['has_data'])->toBeFalse()
        ->and($result['reason'])->toBe('not_enough_customers')
        ->and($result['minimum'])->toBe(CustomerIntelligence::RFM_MIN_CUSTOMERS);
});

it('segments customers by recency, frequency and monetary value', function () {
    [, $org] = makeOrgUser('intel-rfm@example.com');
    $site = intelSite($org);

    // 25 customers on a gradient: low ids are recent, frequent, high-spending.
    foreach (range(1, 25) as $c) {
        $orders = max(1, 6 - (int) ceil($c / 5));
        foreach (range(1, $orders) as $n) {
            intelOrder($site, [
                'customer_id'      => $c,
                'total'            => 200 - $c * 5,
                'created_at_store' => now()->subDays($c * 3 + $n),
            ]);
        }
    }

    $result = app(CustomerIntelligence::class)->rfm($site);

    expect($result['has_data'])->toBeTrue()
        ->and($result['customers'])->toBe(25);

    $segments = collect($result['segments']);
    expect($segments)->toHaveCount(count(CustomerIntelligence::SEGMENTS))
        // Every customer must land in exactly one segment.
        ->and($segments->sum('customers'))->toBe(25)
        // Revenue shares are a partition of the whole, so they add to 100.
        ->and(round($segments->sum('revenue_share')))->toBe(100.0);

    // The gradient guarantees at least one champion and one lapsed customer.
    expect($segments->firstWhere('segment', 'champions')['customers'])->toBeGreaterThan(0);
});

it('excludes refunds and unpaid orders from customer value', function () {
    [, $org] = makeOrgUser('intel-refunds@example.com');
    $site = intelSite($org);

    intelOrder($site, ['customer_id' => 1, 'total' => 100, 'refund_total' => 40]);
    intelOrder($site, ['customer_id' => 1, 'total' => 500, 'status' => 'cancelled']);
    intelOrder($site, ['customer_id' => 1, 'total' => 900, 'status' => 'pending']);

    $clv = app(CustomerIntelligence::class)->lifetimeValue($site);

    // 100 - 40 = 60. Anything else means cancelled or refunded money is being
    // reported as revenue the business actually has.
    expect($clv['total_revenue'])->toBe(60.0)
        ->and($clv['customers'])->toBe(1);
});

it('measures repeat rate and concentration', function () {
    [, $org] = makeOrgUser('intel-clv@example.com');
    $site = intelSite($org);

    // 10 customers; 4 of them order twice.
    foreach (range(1, 10) as $c) {
        intelOrder($site, ['customer_id' => $c, 'total' => 100, 'created_at_store' => now()->subDays(60)]);
        if ($c <= 4) {
            intelOrder($site, ['customer_id' => $c, 'total' => 100, 'created_at_store' => now()->subDays(20)]);
        }
    }

    $clv = app(CustomerIntelligence::class)->lifetimeValue($site);

    expect($clv['repeat_customers'])->toBe(4)
        ->and($clv['repeat_rate'])->toBe(40.0)
        ->and($clv['avg_order_value'])->toBe(100.0)
        ->and($clv['avg_orders_per_customer'])->toBe(1.4)
        // Repeat customers spanned 40 days; single-order ones must not drag it to 0.
        ->and($clv['avg_days_between_first_and_last'])->toBe(40.0);
});

it('gives each cohort only the months it has actually lived through', function () {
    [, $org] = makeOrgUser('intel-cohort@example.com');
    $site = intelSite($org);

    // One customer acquired this month, one acquired three months ago.
    intelOrder($site, ['customer_id' => 1, 'created_at_store' => now()->startOfMonth()->addDay()]);
    intelOrder($site, ['customer_id' => 2, 'created_at_store' => now()->subMonthsNoOverflow(3)->startOfMonth()->addDay()]);

    $result = app(CustomerIntelligence::class)->cohorts($site, 6);

    expect($result['has_data'])->toBeTrue();

    $cohorts = collect($result['cohorts'])->keyBy('cohort');
    $current = $cohorts[now()->format('Y-m')];
    $older = $cohorts[now()->subMonthsNoOverflow(3)->format('Y-m')];

    // This month's cohort has exactly one observable month, and it is its own.
    expect($current['customers'])->toBe(1)
        ->and($current['periods'])->toHaveCount(1)
        ->and($current['periods'][0]['rate'])->toBe(100.0);

    // The older cohort has had three further months to come back, so the row
    // is wider — no cell is padded with a 0% that never happened.
    expect($older['periods'])->toHaveCount(4)
        ->and($older['periods'][0]['rate'])->toBe(100.0)
        ->and($older['periods'][1]['rate'])->toBe(0.0);
});

it('reports a cohort that did not come back as zero, not as missing', function () {
    [, $org] = makeOrgUser('intel-cohort-zero@example.com');
    $site = intelSite($org);

    // Acquired two months ago, never seen again.
    intelOrder($site, ['customer_id' => 1, 'created_at_store' => now()->subMonthsNoOverflow(2)->startOfMonth()->addDay()]);

    $result = app(CustomerIntelligence::class)->cohorts($site, 6);
    $row = collect($result['cohorts'])->firstWhere('cohort', now()->subMonthsNoOverflow(2)->format('Y-m'));

    // Silence in an elapsed month is a real 0%, and must be shown as one.
    expect($row['periods'])->toHaveCount(3)
        ->and($row['periods'][1]['rate'])->toBe(0.0)
        ->and($row['periods'][2]['rate'])->toBe(0.0);
});

it('flags customers overdue against their own rhythm, not a fixed window', function () {
    [, $org] = makeOrgUser('intel-churn@example.com');
    $site = intelSite($org);

    // Weekly buyer, silent for 60 days — badly overdue.
    foreach ([120, 113, 106, 99] as $daysAgo) {
        intelOrder($site, ['customer_id' => 1, 'total' => 300, 'created_at_store' => now()->subDays($daysAgo)]);
    }

    // Quarterly buyer whose last order was 60 days ago — perfectly on schedule.
    foreach ([240, 150, 60] as $daysAgo) {
        intelOrder($site, ['customer_id' => 2, 'total' => 300, 'created_at_store' => now()->subDays($daysAgo)]);
    }

    $result = app(CustomerIntelligence::class)->churnRisk($site);

    $ids = collect($result['customers'])->pluck('customer_id');

    expect($ids)->toContain(1)
        ->and($ids)->not->toContain(2);
});

it('counts single-order customers separately instead of calling them churned', function () {
    [, $org] = makeOrgUser('intel-churn-single@example.com');
    $site = intelSite($org);

    intelOrder($site, ['customer_id' => 1, 'created_at_store' => now()->subDays(400)]);

    $result = app(CustomerIntelligence::class)->churnRisk($site);

    // One order is not a rhythm; there is nothing to be late against.
    expect($result['customers'])->toBe([])
        ->and($result['single_order_customers'])->toBe(1);
});

// ---------------------------------------------------------------------------
// Product intelligence
// ---------------------------------------------------------------------------

it('requires repeated co-occurrence before calling two products an affinity', function () {
    [, $org] = makeOrgUser('intel-affinity-weak@example.com');
    $site = intelSite($org);

    // A and B share exactly one basket — a coincidence, not a pattern.
    $o = intelOrder($site);
    intelItem($site, $o, 1);
    intelItem($site, $o, 2);

    $result = app(ProductIntelligence::class)->affinity($site, intelRange());

    expect($result['pairs'])->toBe([])
        ->and($result['min_support'])->toBe(ProductIntelligence::MIN_PAIR_SUPPORT);
});

it('ranks product pairs by lift rather than raw popularity', function () {
    [, $org] = makeOrgUser('intel-affinity@example.com');
    $site = intelSite($org);

    // Products 1+2 always travel together (4 baskets).
    foreach (range(1, 4) as $n) {
        $o = intelOrder($site);
        intelItem($site, $o, 1);
        intelItem($site, $o, 2);
    }

    // Product 3 is a bestseller that shares baskets with 1 only by volume.
    foreach (range(1, 6) as $n) {
        $o = intelOrder($site);
        intelItem($site, $o, 3);
    }
    foreach (range(1, 3) as $n) {
        $o = intelOrder($site);
        intelItem($site, $o, 1);
        intelItem($site, $o, 3);
    }

    $result = app(ProductIntelligence::class)->affinity($site, intelRange());

    expect($result['has_data'])->toBeTrue();

    $top = $result['pairs'][0];
    $ids = [$top['product_a']['id'], $top['product_b']['id']];
    sort($ids);

    // 1+2 co-occur less often in absolute terms than 1+3 would if 3 were more
    // popular, but they co-occur far more than chance predicts.
    expect($ids)->toBe([1, 2])
        ->and($top['lift'])->toBeGreaterThan(1.0)
        ->and($top['confidence'])->toBeGreaterThan(0);
});

it('rates stock risk by days of cover, not by absolute stock level', function () {
    [, $org] = makeOrgUser('intel-stock@example.com');
    $site = intelSite($org);

    // Fast seller: 30 units sold in 30 days, 10 left -> 10 days of cover.
    // Slow seller: 3 units sold, 10 left -> 100 days of cover.
    foreach (range(1, 30) as $n) {
        $o = intelOrder($site, ['created_at_store' => now()->subDays(($n % 28) + 1)]);
        intelItem($site, $o, 1, ['quantity' => 1]);
    }
    foreach (range(1, 3) as $n) {
        $o = intelOrder($site, ['created_at_store' => now()->subDays($n)]);
        intelItem($site, $o, 2, ['quantity' => 1]);
    }

    foreach ([1, 2] as $pid) {
        DB::table('wc_products')->insert([
            'site_id' => $site->id, 'external_product_id' => $pid, 'name' => "Product {$pid}",
            'price' => 50, 'regular_price' => 50, 'stock_quantity' => 10, 'stock_status' => 'instock',
            'total_sales' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $result = app(ProductIntelligence::class)->stockRisk($site, intelRange());
    $byId = collect($result['products'])->keyBy('product_id');

    expect($byId[1]['risk'])->toBeIn(['critical', 'high'])
        // Same stock level, very different urgency.
        ->and($byId[2]['risk'])->toBe('none')
        ->and($byId[1]['days_of_cover'])->toBeLessThan($byId[2]['days_of_cover']);
});

it('does not treat untracked stock as an imminent stockout', function () {
    [, $org] = makeOrgUser('intel-stock-null@example.com');
    $site = intelSite($org);

    $o = intelOrder($site);
    intelItem($site, $o, 1, ['quantity' => 5]);

    DB::table('wc_products')->insert([
        'site_id' => $site->id, 'external_product_id' => 1, 'name' => 'Untracked',
        'price' => 50, 'regular_price' => 50, 'stock_quantity' => null, 'stock_status' => 'instock',
        'total_sales' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $result = app(ProductIntelligence::class)->stockRisk($site, intelRange());

    // A null stock quantity means "not managed", not "zero left".
    expect($result['has_data'])->toBeFalse()
        ->and($result['reason'])->toBe('no_managed_stock');
});

it('compares discounted against full-price baskets', function () {
    [, $org] = makeOrgUser('intel-discount@example.com');
    $site = intelSite($org);

    foreach (range(1, 4) as $n) {
        intelOrder($site, ['total' => 80, 'subtotal' => 100, 'discount_total' => 20]);
    }
    foreach (range(1, 6) as $n) {
        intelOrder($site, ['total' => 100, 'subtotal' => 100, 'discount_total' => 0]);
    }

    $result = app(ProductIntelligence::class)->discountImpact($site, intelRange());

    expect($result['discounted']['orders'])->toBe(4)
        ->and($result['full_price']['orders'])->toBe(6)
        ->and($result['discounted']['avg_order_value'])->toBe(80.0)
        ->and($result['full_price']['avg_order_value'])->toBe(100.0)
        // Discounted baskets are smaller here — the promotion is buying nothing.
        ->and($result['aov_difference'])->toBe(-20.0)
        ->and($result['discounted']['total_discount'])->toBe(80.0);
});

// ---------------------------------------------------------------------------
// Search intelligence
// ---------------------------------------------------------------------------

it('finds CTR gaps against the site\'s own position benchmark', function () {
    [, $org] = makeOrgUser('intel-ctr@example.com');
    $site = intelSite($org);

    // Twelve queries at position 2, all clicking well except one.
    foreach (range(1, 11) as $n) {
        DB::table('seo_query_snapshots')->insert([
            'site_id' => $site->id, 'period_start' => now()->subDays(7)->toDateString(),
            'period_end' => now()->toDateString(), 'query' => "healthy query {$n}",
            'clicks' => 100, 'impressions' => 1000, 'ctr' => 0.10, 'position' => 2,
            'created_at' => now(),
        ]);
    }

    DB::table('seo_query_snapshots')->insert([
        'site_id' => $site->id, 'period_start' => now()->subDays(7)->toDateString(),
        'period_end' => now()->toDateString(), 'query' => 'underperforming query',
        'clicks' => 10, 'impressions' => 1000, 'ctr' => 0.01, 'position' => 2,
        'created_at' => now(),
    ]);

    $result = app(SearchIntelligence::class)->ctrOpportunities($site, intelRange());

    expect($result['has_data'])->toBeTrue()
        ->and($result['queries'][0]['query'])->toBe('underperforming query')
        // ~9pp of a 10% benchmark across 1,000 impressions.
        ->and($result['queries'][0]['potential_clicks'])->toBeGreaterThan(50);
});

it('separates a page that lost visibility from one that lost only clicks', function () {
    [, $org] = makeOrgUser('intel-decay@example.com');
    $site = intelSite($org);

    // Still shown as often, clicked far less.
    DB::table('seo_page_snapshots')->insert([
        'site_id' => $site->id, 'period_start' => now()->subDays(7)->toDateString(),
        'period_end' => now()->toDateString(), 'page_path' => '/stale-title',
        'clicks' => 20, 'impressions' => 1000, 'ctr' => 0.02, 'position' => 4,
        'prev_clicks' => 100, 'prev_impressions' => 1000, 'prev_ctr' => 0.10, 'prev_position' => 4,
        'created_at' => now(),
    ]);

    // Dropped out of the results entirely.
    DB::table('seo_page_snapshots')->insert([
        'site_id' => $site->id, 'period_start' => now()->subDays(7)->toDateString(),
        'period_end' => now()->toDateString(), 'page_path' => '/deindexed',
        'clicks' => 5, 'impressions' => 100, 'ctr' => 0.05, 'position' => 30,
        'prev_clicks' => 80, 'prev_impressions' => 1000, 'prev_ctr' => 0.08, 'prev_position' => 5,
        'created_at' => now(),
    ]);

    $result = app(SearchIntelligence::class)->contentDecay($site, intelRange());
    $byPath = collect($result['pages'])->keyBy('path');

    expect($byPath['/stale-title']['diagnosis'])->toBe('shown_as_often_clicked_less')
        ->and($byPath['/deindexed']['diagnosis'])->toBe('ranking_slipped');
});

it('surfaces pages competing on the same topic as cannibalization candidates', function () {
    [, $org] = makeOrgUser('intel-cannibal@example.com');
    $site = intelSite($org);

    foreach (['/blog/best-running-shoes', '/guides/best-running-shoes'] as $i => $path) {
        DB::table('seo_page_snapshots')->insert([
            'site_id' => $site->id, 'period_start' => now()->subDays(7)->toDateString(),
            'period_end' => now()->toDateString(), 'page_path' => $path,
            'clicks' => 50, 'impressions' => 2000, 'ctr' => 0.025, 'position' => 6 + $i,
            'prev_position' => 5 + $i, 'prev_clicks' => 60, 'prev_impressions' => 2000,
            'created_at' => now(),
        ]);
    }

    $result = app(SearchIntelligence::class)->cannibalization($site, intelRange());

    expect($result['has_data'])->toBeTrue()
        ->and($result['candidates'][0]['topic'])->toBe('best running shoes')
        ->and($result['candidates'][0]['competing_pages'])->toBe(2)
        // The finding is labelled as a candidate, not a confirmed diagnosis.
        ->and($result['caveat'])->toContain('candidates');
});

// ---------------------------------------------------------------------------
// Acquisition intelligence
// ---------------------------------------------------------------------------

it('reports channel performance and names the attribution model', function () {
    [, $org] = makeOrgUser('intel-channels@example.com');
    $site = intelSite($org);

    foreach ([['Organic Search', 1000, 50, 5000], ['Paid Search', 500, 40, 6000]] as [$channel, $sessions, $conversions, $revenue]) {
        DB::table('traffic_dimension_snapshots')->insert([
            'site_id' => $site->id, 'period_start' => now()->subDays(7)->toDateString(),
            'period_end' => now()->subDay()->toDateString(), 'source' => 'ga4',
            'dimension_type' => 'channel', 'dimension_value' => $channel,
            'sessions' => $sessions, 'users' => $sessions, 'views' => $sessions,
            'conversions' => $conversions, 'revenue' => $revenue, 'created_at' => now(),
        ]);
    }

    $result = app(AcquisitionIntelligence::class)->channels($site, intelRange());
    $byChannel = collect($result['channels'])->keyBy('channel');

    expect($result['model'])->toBe('last_click')
        ->and($result['caveat'])->toContain('last-click')
        ->and($byChannel['Paid Search']['revenue_per_session'])->toBe(12.0)
        ->and($byChannel['Organic Search']['revenue_per_session'])->toBe(5.0)
        // Paid brings half the sessions but more revenue — the point of the table.
        ->and($byChannel['Paid Search']['conversion_rate'])->toBe(8.0);
});

it('needs enough history before calling any day abnormal', function () {
    [, $org] = makeOrgUser('intel-anomaly-short@example.com');
    $site = intelSite($org);

    foreach (range(1, 5) as $d) {
        DB::table('traffic_daily')->insert([
            'site_id' => $site->id, 'date' => now()->subDays($d)->toDateString(), 'source' => 'ga4',
            'sessions' => 100, 'users' => 90, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $result = app(AcquisitionIntelligence::class)->trafficAnomalies($site, intelRange());

    expect($result['has_data'])->toBeFalse()
        ->and($result['reason'])->toBe('not_enough_history');
});

it('detects a spike without letting the spike hide itself', function () {
    [, $org] = makeOrgUser('intel-anomaly@example.com');
    $site = intelSite($org);

    // 27 steady days plus one enormous launch day. A mean-and-stddev detector
    // would inflate both and miss it; the median/MAD pair does not.
    foreach (range(1, 28) as $d) {
        DB::table('traffic_daily')->insert([
            'site_id'  => $site->id,
            'date'     => now()->subDays($d)->toDateString(),
            'source'   => 'ga4',
            'sessions' => $d === 3 ? 5000 : 100 + ($d % 5),
            'users'    => 90,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $result = app(AcquisitionIntelligence::class)->trafficAnomalies($site, intelRange());

    expect($result['has_data'])->toBeTrue()
        ->and($result['anomalies'])->toHaveCount(1)
        ->and($result['anomalies'][0]['direction'])->toBe('spike')
        ->and($result['anomalies'][0]['sessions'])->toBe(5000)
        ->and($result['anomalies'][0]['date'])->toBe(now()->subDays(3)->toDateString());
});

it('refuses to publish a correlation from too few paired days', function () {
    [, $org] = makeOrgUser('intel-corr-short@example.com');
    $site = intelSite($org);

    foreach (range(1, 4) as $d) {
        DB::table('performance_runs')->insert([
            'site_id' => $site->id, 'url' => 'https://shop.example.com/', 'strategy' => 'mobile', 'status' => 'complete',
            'performance_score' => 80, 'run_at' => now()->subDays($d),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        intelOrder($site, ['created_at_store' => now()->subDays($d)]);
    }

    $result = app(AcquisitionIntelligence::class)->performanceRevenueCorrelation($site, intelRange());

    expect($result['has_data'])->toBeFalse()
        ->and($result['reason'])->toBe('not_enough_paired_days')
        ->and($result['minimum'])->toBe(AcquisitionIntelligence::CORRELATION_MIN_DAYS);
});

it('computes a correlation coefficient and labels it as correlation', function () {
    [, $org] = makeOrgUser('intel-corr@example.com');
    $site = intelSite($org);

    // Perfectly co-varying series: score and revenue rise together.
    foreach (range(1, 14) as $d) {
        DB::table('performance_runs')->insert([
            'site_id' => $site->id, 'url' => 'https://shop.example.com/', 'strategy' => 'mobile', 'status' => 'complete',
            'performance_score' => 50 + $d, 'lcp_ms' => 4000 - $d * 100,
            'run_at' => now()->subDays($d)->setHour(12),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        intelOrder($site, [
            'total'            => 100 + $d * 10,
            'created_at_store' => now()->subDays($d)->setHour(13),
        ]);
    }

    $result = app(AcquisitionIntelligence::class)->performanceRevenueCorrelation($site, intelRange());

    expect($result['has_data'])->toBeTrue()
        ->and($result['paired_days'])->toBe(14)
        ->and($result['performance_score_vs_revenue']['r'])->toBeGreaterThan(0.9)
        ->and($result['performance_score_vs_revenue']['strength'])->toBe('strong')
        // Faster (lower) LCP with higher revenue is a negative coefficient.
        ->and($result['lcp_vs_revenue']['r'])->toBeLessThan(-0.9)
        ->and($result['caveat'])->toContain('not causation');
});

// ---------------------------------------------------------------------------
// HTTP surface
// ---------------------------------------------------------------------------

it('serves every intelligence report over the API', function () {
    [$user, $org] = makeOrgUser('intel-http@example.com');
    $site = intelSite($org);

    $endpoints = [
        'rfm', 'cohorts', 'lifetime-value', 'affinity', 'stock-risk', 'discounts',
        'declining-products', 'cannibalization', 'ctr-opportunities', 'content-decay',
        'channels', 'anomalies', 'performance-correlation', 'churn-risk',
    ];

    foreach ($endpoints as $endpoint) {
        $this->actingAs($user)
            ->getJson("/api/sites/{$site->id}/intelligence/{$endpoint}")
            ->assertOk()
            // An empty site must answer "no data", not 500 or an empty body.
            ->assertJsonStructure(['has_data']);
    }
});

it('keeps per-customer churn data away from client viewers', function () {
    [$owner, $org] = makeOrgUser('intel-perm-owner@example.com');
    $site = intelSite($org);

    $viewer = \App\Models\User::create([
        'name' => 'Viewer', 'email' => 'intel-viewer@example.com',
        'password' => 'secret-password', 'status' => 'active',
    ]);
    $org->users()->attach($viewer->id, ['role' => 'client_viewer']);
    DB::table('site_user_access')->insert([
        'site_id' => $site->id, 'user_id' => $viewer->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // The viewer can see aggregate reporting...
    $this->actingAs($viewer)
        ->getJson("/api/sites/{$site->id}/intelligence/channels")
        ->assertOk();

    app('auth')->forgetGuards();

    // ...but not a list of identifiable lapsing customers.
    $this->actingAs($viewer)
        ->getJson("/api/sites/{$site->id}/intelligence/churn-risk")
        ->assertForbidden()
        ->assertJsonPath('code', 'insufficient_role');

    app('auth')->forgetGuards();

    $this->actingAs($owner)
        ->getJson("/api/sites/{$site->id}/intelligence/churn-risk")
        ->assertOk();
});

it('scopes every report to the caller\'s own organization', function () {
    [, $orgA] = makeOrgUser('intel-tenant-a@example.com');
    [$userB] = makeOrgUser('intel-tenant-b@example.com');

    $siteA = intelSite($orgA, 'AShop');

    // 404, not 403 — a cross-tenant site id must not be confirmable.
    $this->actingAs($userB)
        ->getJson("/api/sites/{$siteA->id}/intelligence/rfm")
        ->assertNotFound();
});
