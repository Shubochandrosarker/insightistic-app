<?php

namespace App\Query;

use RuntimeException;

/**
 * Every failure mode of the Safe WooCommerce Query Engine, each with a
 * stable machine-readable `$errorCode` (named to avoid colliding with the
 * inherited, non-readonly `Exception::$code`) and a message safe to return
 * to an API caller (never a raw SQL fragment, stack trace, or internal
 * identifier).
 */
class QueryEngineException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public static function emptyMetrics(): self
    {
        return new self('empty_metrics', 'At least one metric must be requested.');
    }

    public static function tooManyMetrics(int $max): self
    {
        return new self('too_many_metrics', "A plan may request at most {$max} metrics.");
    }

    public static function unknownMetric(string $id): self
    {
        return new self('unknown_metric', "Unknown metric \"{$id}\".");
    }

    public static function mixedSources(): self
    {
        return new self('mixed_sources', 'All requested metrics must belong to the same source.');
    }

    public static function unsupportedSource(string $source): self
    {
        return new self('unsupported_source', "Source \"{$source}\" is not yet supported by this query engine.");
    }

    public static function disallowedDimension(string $dimension, string $metricId): self
    {
        return new self('disallowed_dimension', "Metric \"{$metricId}\" does not support the \"{$dimension}\" dimension.");
    }

    public static function disallowedFilter(string $filter): self
    {
        return new self('disallowed_filter', "Filter \"{$filter}\" is not supported.");
    }

    public static function invalidPeriod(string $reason): self
    {
        return new self('invalid_period', "Invalid period: {$reason}.");
    }

    public static function invalidLimit(int $min, int $max): self
    {
        return new self('invalid_limit', "limit must be between {$min} and {$max}.");
    }

    public static function comparisonNotSupportedForBreakdown(): self
    {
        return new self('comparison_not_supported', 'comparison is only supported for site-level aggregates, not dimension breakdowns.');
    }

    public static function unsafeQuery(string $reason): self
    {
        return new self('unsafe_query', "Compiled query failed a safety check: {$reason}.");
    }

    public static function readOnlyIdentityNotConfigured(): self
    {
        return new self('read_only_identity_not_configured', 'The query engine has no read-only database identity configured.');
    }

    public static function concurrencyLimitExceeded(int $max): self
    {
        return new self('concurrency_limit_exceeded', "This organization already has {$max} analytical queries in flight. Try again shortly.");
    }

    public static function timeout(): self
    {
        return new self('timeout', 'The query took too long and was cancelled.');
    }
}
