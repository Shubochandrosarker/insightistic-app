<?php

namespace App\Metrics;

use App\Metrics\Catalog\CommerceMetrics;
use App\Metrics\Catalog\PerformanceMetrics;
use App\Metrics\Catalog\SeoMetrics;
use App\Metrics\Catalog\TrafficMetrics;

/**
 * Aggregates every source-specific metric catalog into the full list the
 * registry loads. Adding a new metric means adding it to one of the
 * Catalog\* classes (or a new one, registered here) — never inline in a
 * controller or service.
 */
final class MetricCatalog
{
    /** @return MetricDefinition[] */
    public static function all(): array
    {
        return [
            ...CommerceMetrics::all(),
            ...TrafficMetrics::all(),
            ...SeoMetrics::all(),
            ...PerformanceMetrics::all(),
        ];
    }
}
