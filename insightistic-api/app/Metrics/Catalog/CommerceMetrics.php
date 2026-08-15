<?php

namespace App\Metrics\Catalog;

use App\Metrics\MetricAggregation;
use App\Metrics\MetricDefinition;
use App\Metrics\MetricFreshness;
use App\Metrics\MetricSource;
use App\Metrics\MetricUnit;

/**
 * WooCommerce commerce metrics. Base values are read from `metric_snapshots`
 * (the nightly/post-sync daily rollup built by MetricSnapshotService and
 * read by MetricsAssembler — see docs/SEMANTIC-METRICS.md for why the
 * registry declares the snapshot table as canonical rather than the live
 * wc_orders table for range aggregates).
 *
 * Order-status classification for every metric here is
 * App\Metrics\CommerceStatus::REVENUE_PAID / ::FAILED — the "attributable
 * revenue" definition (includes on-hold), matching MetricsAssembler and
 * MetricSnapshotService. This is deliberately NOT the same status list
 * CustomerIntelligence/ProductIntelligence use for RFM/cohort/LTV work —
 * see CommerceStatus's docblock for why that's an open product decision,
 * not a bug this registry silently resolved.
 */
final class CommerceMetrics
{
    /** @return MetricDefinition[] */
    public static function all(): array
    {
        return [
            new MetricDefinition(
                id: 'net_revenue',
                version: 1,
                label: 'Net Revenue',
                description: 'Sum of order totals for orders in a paid status (completed, processing, on-hold) within the period.',
                source: MetricSource::WOOCOMMERCE,
                aggregation: MetricAggregation::SUM,
                unit: MetricUnit::CURRENCY,
                table: 'metric_snapshots',
                column: 'revenue',
                freshness: MetricFreshness::DAILY,
                allowedFilters: ['date_range'],
                caveats: [
                    'Order status classification is App\\Metrics\\CommerceStatus::REVENUE_PAID (includes on-hold); refunded amounts are not subtracted from this total (see refund_count).',
                ],
            ),
            new MetricDefinition(
                id: 'orders',
                version: 1,
                label: 'Orders',
                description: 'Count of orders in a paid status within the period.',
                source: MetricSource::WOOCOMMERCE,
                aggregation: MetricAggregation::SUM,
                unit: MetricUnit::COUNT,
                table: 'metric_snapshots',
                column: 'orders',
                freshness: MetricFreshness::DAILY,
                allowedFilters: ['date_range'],
            ),
            new MetricDefinition(
                id: 'aov',
                version: 1,
                label: 'Average Order Value',
                description: 'Net revenue divided by order count for the period.',
                source: MetricSource::WOOCOMMERCE,
                aggregation: MetricAggregation::DERIVED,
                unit: MetricUnit::CURRENCY,
                table: 'metric_snapshots',
                column: null,
                freshness: MetricFreshness::DAILY,
                allowedFilters: ['date_range'],
                dependsOn: ['net_revenue', 'orders'],
                caveats: ['Zero when orders is 0 for the period (never divides by zero).'],
                formula: 'net_revenue / orders',
            ),
            new MetricDefinition(
                id: 'refund_count',
                version: 1,
                label: 'Refunded Orders',
                description: 'Count of orders with a non-zero refund amount within the period.',
                source: MetricSource::WOOCOMMERCE,
                aggregation: MetricAggregation::SUM,
                unit: MetricUnit::COUNT,
                table: 'metric_snapshots',
                column: 'refunds',
                freshness: MetricFreshness::DAILY,
                allowedFilters: ['date_range'],
                caveats: [
                    'This counts ORDERS with any refund, not the refunded dollar amount. There is no aggregate "total refunded" metric yet — wc_orders.refund_total exists per-order but is not currently rolled up into metric_snapshots.',
                ],
            ),
            new MetricDefinition(
                id: 'refund_rate',
                version: 1,
                label: 'Refund Rate',
                description: 'Share of orders in the period that had a refund.',
                source: MetricSource::WOOCOMMERCE,
                aggregation: MetricAggregation::RATIO,
                unit: MetricUnit::RATIO,
                table: 'metric_snapshots',
                column: null,
                freshness: MetricFreshness::DAILY,
                allowedFilters: ['date_range'],
                dependsOn: ['refund_count', 'orders'],
                caveats: [
                    'Order-count based, not dollar-weighted — a rate of "orders with a refund", not "revenue refunded". Null when orders is 0.',
                ],
                formula: 'refund_count / orders',
            ),
            new MetricDefinition(
                id: 'failed_orders',
                version: 1,
                label: 'Failed Orders',
                description: 'Count of orders in a failed status (failed, cancelled) within the period.',
                source: MetricSource::WOOCOMMERCE,
                aggregation: MetricAggregation::SUM,
                unit: MetricUnit::COUNT,
                table: 'metric_snapshots',
                column: 'failed_orders',
                freshness: MetricFreshness::DAILY,
                allowedFilters: ['date_range'],
            ),
            new MetricDefinition(
                id: 'products_sold',
                version: 1,
                label: 'Products Sold',
                description: 'Sum of line-item quantities across paid orders within the period.',
                source: MetricSource::WOOCOMMERCE,
                aggregation: MetricAggregation::SUM,
                unit: MetricUnit::COUNT,
                table: 'metric_snapshots',
                column: 'products_sold',
                freshness: MetricFreshness::DAILY,
                allowedFilters: ['date_range'],
            ),
            new MetricDefinition(
                id: 'product_revenue',
                version: 1,
                label: 'Product Revenue',
                description: 'Revenue attributed to a single product (order-item total, summed) within the period, on paid orders only.',
                source: MetricSource::WOOCOMMERCE,
                aggregation: MetricAggregation::SUM,
                unit: MetricUnit::CURRENCY,
                table: 'wc_order_items',
                column: 'total',
                freshness: MetricFreshness::DAILY,
                allowedDimensions: ['product'],
                allowedFilters: ['date_range'],
                caveats: [
                    'Read live from wc_order_items joined to wc_orders (not from metric_snapshots, which has no per-product breakdown) — slower on wide date ranges than the other commerce metrics.',
                ],
            ),
            new MetricDefinition(
                id: 'new_customers',
                version: 1,
                label: 'New Customers',
                description: 'Count of registered customers whose earliest paid order falls within the period.',
                source: MetricSource::WOOCOMMERCE,
                aggregation: MetricAggregation::SUM,
                unit: MetricUnit::COUNT,
                table: 'metric_snapshots',
                column: 'new_customers',
                freshness: MetricFreshness::DAILY,
                allowedFilters: ['date_range'],
                caveats: ['Registered customers only (external customer id > 0) — guest checkouts are not attributed to a customer and are excluded.'],
            ),
            new MetricDefinition(
                id: 'returning_customers',
                version: 1,
                label: 'Returning Customers',
                description: 'Count of registered customers with a paid order in the period whose earliest paid order was before the period.',
                source: MetricSource::WOOCOMMERCE,
                aggregation: MetricAggregation::SUM,
                unit: MetricUnit::COUNT,
                table: 'metric_snapshots',
                column: 'returning_customers',
                freshness: MetricFreshness::DAILY,
                allowedFilters: ['date_range'],
                caveats: ['Registered customers only — guest checkouts are excluded.'],
            ),
            new MetricDefinition(
                id: 'repeat_purchase_rate',
                version: 1,
                label: 'Repeat Purchase Rate',
                description: 'Share of active registered customers in the period who were returning rather than new.',
                source: MetricSource::WOOCOMMERCE,
                aggregation: MetricAggregation::RATIO,
                unit: MetricUnit::RATIO,
                table: 'metric_snapshots',
                column: null,
                freshness: MetricFreshness::DAILY,
                allowedFilters: ['date_range'],
                dependsOn: ['returning_customers', 'new_customers'],
                caveats: [
                    'Registered customers only — guest checkouts are excluded from both the numerator and denominator. Null when there were no active customers in the period.',
                ],
                formula: 'returning_customers / (new_customers + returning_customers)',
            ),
        ];
    }
}
