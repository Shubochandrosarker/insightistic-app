<?php

namespace App\Metrics\Catalog;

use App\Metrics\MetricAggregation;
use App\Metrics\MetricDefinition;
use App\Metrics\MetricFreshness;
use App\Metrics\MetricSource;
use App\Metrics\MetricUnit;

/**
 * GA4-sourced traffic metrics, read from `traffic_daily`. That table also
 * stores a 'connector_engagement' source row per day (first-party fallback
 * when GA4 isn't connected) — every metric here is scoped to
 * source = 'ga4' specifically, matching the audit's finding that source
 * discrimination isn't optional in this table.
 */
final class TrafficMetrics
{
    /** @return MetricDefinition[] */
    public static function all(): array
    {
        return [
            new MetricDefinition(
                id: 'ga4_sessions',
                version: 1,
                label: 'Sessions (GA4)',
                description: 'Sum of GA4 sessions within the period.',
                source: MetricSource::GA4,
                aggregation: MetricAggregation::SUM,
                unit: MetricUnit::COUNT,
                table: 'traffic_daily',
                column: 'sessions',
                freshness: MetricFreshness::DAILY,
                allowedFilters: ['date_range'],
                caveats: ["traffic_daily holds rows for multiple sources; this metric is scoped to source = 'ga4' only."],
            ),
            new MetricDefinition(
                id: 'ga4_conversions',
                version: 1,
                label: 'Conversions (GA4)',
                description: 'Sum of GA4-reported conversions within the period.',
                source: MetricSource::GA4,
                aggregation: MetricAggregation::SUM,
                unit: MetricUnit::COUNT,
                table: 'traffic_daily',
                column: 'conversions',
                freshness: MetricFreshness::DAILY,
                allowedFilters: ['date_range'],
                caveats: ["Scoped to source = 'ga4' only."],
            ),
            new MetricDefinition(
                id: 'conversion_rate',
                version: 1,
                label: 'Conversion Rate',
                description: 'GA4 conversions divided by GA4 sessions within the period.',
                source: MetricSource::GA4,
                aggregation: MetricAggregation::RATIO,
                unit: MetricUnit::RATIO,
                table: 'traffic_daily',
                column: null,
                freshness: MetricFreshness::DAILY,
                allowedFilters: ['date_range'],
                dependsOn: ['ga4_conversions', 'ga4_sessions'],
                caveats: ['Null when ga4_sessions is 0 for the period.'],
                formula: 'ga4_conversions / ga4_sessions',
            ),
        ];
    }
}
