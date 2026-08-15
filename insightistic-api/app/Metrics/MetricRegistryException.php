<?php

namespace App\Metrics;

use RuntimeException;

/** Thrown when a caller (typed planner, controller, report builder) asks the registry for a metric id/version that isn't allowlisted. Never silently falls back to a different metric. */
class MetricRegistryException extends RuntimeException
{
    public static function unknownMetric(string $id): self
    {
        return new self("Unknown metric \"{$id}\". It is not registered in the semantic metric registry.");
    }

    public static function unknownVersion(string $id, int $version): self
    {
        return new self("Metric \"{$id}\" has no version {$version} registered.");
    }
}
