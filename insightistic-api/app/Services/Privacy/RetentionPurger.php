<?php

namespace App\Services\Privacy;

use App\Models\Organization;
use App\Domain\Billing\EntitlementResolver;
use Illuminate\Support\Facades\DB;

/**
 * Enforces each plan's `data_retention_days` (spec §10).
 *
 * The limit existed in the plan registry but nothing ever acted on it, so
 * "90-day retention" was a marketing line rather than a behaviour. This is
 * what makes it true.
 *
 * Only time-series analytics are purged. Orders, customers, licenses and
 * subscriptions are commercial records the customer still needs, and
 * silently deleting a two-year-old order because a plan says 90 days would
 * destroy data the user did not expect to lose. Retention here means "how
 * far back the dashboards keep detail", which is what the plan advertises.
 */
class RetentionPurger
{
    /** table => date column used to age rows out. */
    private const TIME_SERIES = [
        'traffic_daily'              => 'date',
        'seo_daily'                  => 'date',
        'engagement_daily'           => 'date',
        'traffic_dimension_snapshots' => 'period_end',
        'seo_query_snapshots'        => 'period_end',
        'seo_page_snapshots'         => 'period_end',
        'performance_runs'           => 'created_at',
        'broken_link_snapshots'      => 'created_at',
        'sync_logs'                  => 'created_at',
    ];

    /**
     * Purge one organization's aged analytics.
     *
     * @return array<string,int> rows removed per table
     */
    public function purgeOrganization(Organization $org): array
    {
        $days = $this->retentionDays($org);

        // Null/0 means unlimited retention — the enterprise case.
        if ($days === null || $days <= 0) {
            return [];
        }

        $cutoff = now()->subDays($days);
        $siteIds = DB::table('sites')->where('organization_id', $org->id)->pluck('id')->all();

        if ($siteIds === []) {
            return [];
        }

        $removed = [];

        foreach (self::TIME_SERIES as $table => $column) {
            if (! DB::getSchemaBuilder()->hasTable($table)) {
                continue;
            }

            $count = DB::table($table)
                ->whereIn('site_id', $siteIds)
                ->where($column, '<', $cutoff)
                ->delete();

            if ($count > 0) {
                $removed[$table] = $count;
            }
        }

        return $removed;
    }

    /**
     * Retention window for an organization, or null for unlimited.
     *
     * Reads the plan's limits_json rather than a hardcoded map, so changing
     * a plan in the database changes behaviour with no deploy.
     */
    public function retentionDays(Organization $org): ?int
    {
        $resolved = app(EntitlementResolver::class)->resolve($org);
        $days = $resolved['limits']['data_retention_days'] ?? null;

        if ($days === null && $org->plan) {
            $days = $org->plan->limit('data_retention_days');
        }

        return $days === null ? null : (int) $days;
    }
}
