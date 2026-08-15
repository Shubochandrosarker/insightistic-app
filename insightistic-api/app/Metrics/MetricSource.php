<?php

namespace App\Metrics;

/** Allowlisted data sources a metric definition may declare. Mirrors the connected-integration types in Site/DataSourceConnection. */
final class MetricSource
{
    public const WOOCOMMERCE = 'woocommerce';

    public const GA4 = 'ga4';

    public const GSC = 'gsc';

    public const PAGESPEED = 'pagespeed';

    public const ALL = [
        self::WOOCOMMERCE,
        self::GA4,
        self::GSC,
        self::PAGESPEED,
    ];
}
