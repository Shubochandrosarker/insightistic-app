<?php

namespace App\Metrics\Catalog;

use App\Metrics\MetricAggregation;
use App\Metrics\MetricDefinition;
use App\Metrics\MetricFreshness;
use App\Metrics\MetricSource;
use App\Metrics\MetricUnit;

/** Google Search Console-sourced SEO metrics, read from `seo_daily`. */
final class SeoMetrics
{
    /** @return MetricDefinition[] */
    public static function all(): array
    {
        return [
            new MetricDefinition(
                id: 'organic_clicks',
                version: 1,
                label: 'Organic Clicks',
                description: 'Sum of Search Console clicks within the period.',
                source: MetricSource::GSC,
                aggregation: MetricAggregation::SUM,
                unit: MetricUnit::COUNT,
                table: 'seo_daily',
                column: 'clicks',
                freshness: MetricFreshness::DAILY,
                allowedFilters: ['date_range'],
            ),
            new MetricDefinition(
                id: 'organic_impressions',
                version: 1,
                label: 'Organic Impressions',
                description: 'Sum of Search Console impressions within the period.',
                source: MetricSource::GSC,
                aggregation: MetricAggregation::SUM,
                unit: MetricUnit::COUNT,
                table: 'seo_daily',
                column: 'impressions',
                freshness: MetricFreshness::DAILY,
                allowedFilters: ['date_range'],
            ),
            new MetricDefinition(
                id: 'organic_ctr',
                version: 1,
                label: 'Organic CTR',
                description: 'Organic clicks divided by organic impressions within the period.',
                source: MetricSource::GSC,
                aggregation: MetricAggregation::RATIO,
                unit: MetricUnit::RATIO,
                table: 'seo_daily',
                column: null,
                freshness: MetricFreshness::DAILY,
                allowedFilters: ['date_range'],
                dependsOn: ['organic_clicks', 'organic_impressions'],
                caveats: ['A period-total ratio, not Search Console\'s own daily-weighted CTR figure. Null when organic_impressions is 0.'],
                formula: 'organic_clicks / organic_impressions',
            ),
            new MetricDefinition(
                id: 'organic_avg_position',
                version: 1,
                label: 'Average Position',
                description: 'Average Search Console ranking position across days in the period.',
                source: MetricSource::GSC,
                aggregation: MetricAggregation::AVERAGE,
                unit: MetricUnit::SCORE,
                table: 'seo_daily',
                column: 'avg_position',
                freshness: MetricFreshness::DAILY,
                allowedFilters: ['date_range'],
                caveats: [
                    'A simple average of each day\'s already-averaged position — not impression-weighted across the period. Days with zero impressions still contribute their stored value; treat as directional, not exact.',
                ],
            ),
        ];
    }
}
