<?php

namespace App\Metrics;

/**
 * How often a metric's underlying data is expected to sync. Not an SLA
 * enforcement mechanism by itself — the evidence layer (P2) compares this
 * against a site's actual last-synced-at timestamp to decide whether a
 * value's confidence should drop and whether "stale" must be surfaced.
 */
final class MetricFreshness
{
    /** Recomputed from snapshots/live tables on every request. */
    public const REALTIME = 'realtime';

    /** Backed by a nightly snapshot/sync job (see routes/console.php schedule). */
    public const DAILY = 'daily';

    /** Backed by an on-demand or periodic run with no fixed daily cadence (e.g. PageSpeed runs). */
    public const ON_DEMAND = 'on_demand';

    public const ALL = [
        self::REALTIME,
        self::DAILY,
        self::ON_DEMAND,
    ];
}
