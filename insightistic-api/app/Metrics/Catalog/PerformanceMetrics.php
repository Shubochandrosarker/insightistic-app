<?php

namespace App\Metrics\Catalog;

use App\Metrics\MetricAggregation;
use App\Metrics\MetricDefinition;
use App\Metrics\MetricFreshness;
use App\Metrics\MetricSource;
use App\Metrics\MetricUnit;

/**
 * Core Web Vitals, read from the latest `performance_runs` row for a
 * site/strategy. Limited to the three metrics Google assigns an official
 * good/needs-improvement/poor status to (see the performance_runs migration
 * docblock) — FCP/TBT/Speed Index/TTFB are lab diagnostics without an
 * official threshold and are intentionally not registered here.
 */
final class PerformanceMetrics
{
    /** @return MetricDefinition[] */
    public static function all(): array
    {
        return [
            new MetricDefinition(
                id: 'lcp',
                version: 1,
                label: 'Largest Contentful Paint',
                description: 'Largest Contentful Paint from the most recent completed PageSpeed run.',
                source: MetricSource::PAGESPEED,
                aggregation: MetricAggregation::LATEST,
                unit: MetricUnit::MILLISECONDS,
                table: 'performance_runs',
                column: 'lcp_ms',
                freshness: MetricFreshness::ON_DEMAND,
                allowedFilters: ['strategy'],
                caveats: [
                    'PageSpeed runs are on-demand/rate-limited, not continuous — this can reflect a run more than 24h old. Always pair with the run\'s run_at timestamp before stating confidence.',
                ],
            ),
            new MetricDefinition(
                id: 'inp',
                version: 1,
                label: 'Interaction to Next Paint',
                description: 'Interaction to Next Paint from the most recent completed PageSpeed run.',
                source: MetricSource::PAGESPEED,
                aggregation: MetricAggregation::LATEST,
                unit: MetricUnit::MILLISECONDS,
                table: 'performance_runs',
                column: 'inp_ms',
                freshness: MetricFreshness::ON_DEMAND,
                allowedFilters: ['strategy'],
                caveats: [
                    'Lab-estimated (Lighthouse), not real-user field data unless field_data_available is true on the run. On-demand cadence — pair with run_at.',
                ],
            ),
            new MetricDefinition(
                id: 'cls',
                version: 1,
                label: 'Cumulative Layout Shift',
                description: 'Cumulative Layout Shift score from the most recent completed PageSpeed run.',
                source: MetricSource::PAGESPEED,
                aggregation: MetricAggregation::LATEST,
                unit: MetricUnit::SCORE,
                table: 'performance_runs',
                column: 'cls',
                freshness: MetricFreshness::ON_DEMAND,
                allowedFilters: ['strategy'],
                caveats: ['On-demand cadence — pair with run_at before stating confidence.'],
            ),
        ];
    }
}
