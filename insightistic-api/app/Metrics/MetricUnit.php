<?php

namespace App\Metrics;

/** Display/formatting contract for a metric's value. Consumers (dashboard, Copilot, reports) format by this, not by guessing from the metric id. */
final class MetricUnit
{
    public const CURRENCY = 'currency';

    public const COUNT = 'count';

    public const RATIO = 'ratio';

    public const MILLISECONDS = 'milliseconds';

    public const SCORE = 'score';

    public const ALL = [
        self::CURRENCY,
        self::COUNT,
        self::RATIO,
        self::MILLISECONDS,
        self::SCORE,
    ];
}
