<?php

namespace App\Query;

use App\Models\MetricSnapshot;
use App\Models\Site;
use Carbon\CarbonImmutable;

/**
 * How stale a site's underlying data sync is, independent of any specific
 * query — the last date `metric_snapshots` actually has a row for, vs
 * today in the site's own timezone. Extracted out of EvidenceLedger (P2)
 * so App\Services\Ask\ConfidenceScorer (P3) can compute the exact same
 * freshness a stored evidence run would show, without duplicating the
 * logic or querying it twice under slightly different rules.
 */
class DataFreshness
{
    /** @return array{data_as_of: ?string, days_stale: ?int} */
    public static function forSite(Site $site): array
    {
        $latest = MetricSnapshot::query()->where('site_id', $site->id)->max('date');

        if (! $latest) {
            return ['data_as_of' => null, 'days_stale' => null];
        }

        $tz = $site->timezone ?: config('app.timezone', 'UTC');
        $today = CarbonImmutable::now($tz)->startOfDay();
        $latestDate = CarbonImmutable::parse($latest, $tz)->startOfDay();

        return [
            'data_as_of' => $latestDate->toDateString(),
            'days_stale' => max(0, $today->diffInDays($latestDate)),
        ];
    }
}
