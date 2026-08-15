<?php

namespace App\Metrics;

/** How a metric's raw rows become its value. Derived metrics compose other metrics instead of reading rows directly. */
final class MetricAggregation
{
    public const SUM = 'sum';

    public const COUNT = 'count';

    public const AVERAGE = 'average';

    public const LATEST = 'latest';

    public const RATIO = 'ratio';

    public const DERIVED = 'derived';

    public const ALL = [
        self::SUM,
        self::COUNT,
        self::AVERAGE,
        self::LATEST,
        self::RATIO,
        self::DERIVED,
    ];
}
