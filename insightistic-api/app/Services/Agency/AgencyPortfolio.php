<?php

namespace App\Services\Agency;

use App\Models\AgencyClient;
use App\Models\CrawlIssue;
use App\Models\CrawlRun;
use App\Models\Organization;
use App\Models\SeoDaily;
use App\Models\Site;
use App\Models\UptimeCheck;
use App\Models\UptimeIncident;
use App\Models\UptimeMonitor;
use App\Services\Crawler\CrawlHealthScore;
use Illuminate\Support\Carbon;

/**
 * Agency portfolio (Phase 13, docs/roadmap-2026/20-REPORTS-AGENCY-WHITELABEL.md,
 * "Portfolio dashboard"): one row per site the organization owns, with the
 * health / uptime / search / freshness columns the roadmap card list calls
 * for, plus the optional client grouping.
 *
 * Tenancy follows the exact AgencyRollupService bargain: the site list comes
 * from `Site::where('organization_id', $org->id)` FIRST, and every follow-up
 * aggregate is then constrained to those site ids — no other organization's
 * data can be reached because no other organization's site ids exist here.
 * The site-owned analytics tables (crawl_*, seo_daily, uptime_*) carry no
 * organization_id of their own; the site id is the tenant boundary, exactly
 * as for the uptime scheduler.
 *
 * Query shape: one aggregate query per data domain for the whole site set
 * (uptime, incidents, seo, critical issues, crawl runs), then the per-site
 * health score via `CrawlHealthScore::forSite()` — the documented wrapper the
 * health endpoint itself uses, so the portfolio score is by construction the
 * same number the per-site health page shows. Row count is the number of
 * sites an agency owns (tens to low hundreds), so 2 queries per site there is
 * an acceptable MVP cost; doc 20's "portfolio aggregates are precomputed/
 * cached" remains future work.
 *
 * Health-state thresholds (post-fetch, applied to the score as displayed):
 *   critical : any OPEN severity=critical crawl issue, OR score < 50
 *   warning  : score 50-79, no critical issues
 *   healthy  : score >= 80, no critical issues
 *   unknown  : site has never been crawled — the displayed score is 0
 *              (`has_crawl_data = false`) but "never measured" is not the
 *              same as "measured and broken", so it matches NO health
 *              filter bucket.
 */
class AgencyPortfolio
{
    /** Availability lookback, mirrors UptimeMonitor::availabilityPct(). */
    private const UPTIME_WINDOW_DAYS = 30;

    /** Search Console click/impression lookback, mirrors CrawlHealthScore's GSC impact window. */
    private const SEARCH_WINDOW_DAYS = 28;

    public function __construct(private readonly CrawlHealthScore $healthScore) {}

    /**
     * @param  int|null  $clientId      filter rows to one agency client (null = whole org)
     * @param  string|null  $healthFilter  'critical'|'warning'|'healthy' (null = all states)
     * @return array{organization_id: int, filters: array, summary: array{sites: int, avg_health: float|null, sites_with_critical: int, clients: int}, sites: array<int, array>}
     */
    public function portfolio(Organization $org, ?int $clientId = null, ?string $healthFilter = null): array
    {
        $sites = Site::query()
            ->where('organization_id', $org->id)
            ->when($clientId !== null, fn ($q) => $q->where('client_id', $clientId))
            ->orderBy('name')
            ->get();

        $siteIds = $sites->pluck('id')->all();

        // --- one aggregate per data domain, scoped to the org's site ids ---
        $uptimeBySite = $this->uptimeBySite($siteIds);
        $searchBySite = $this->searchBySite($siteIds);
        $criticalBySite = $this->criticalIssuesBySite($siteIds);
        $crawlRunBySite = $this->lastCrawlRunBySite($siteIds);

        $clientNames = AgencyClient::query()
            ->where('organization_id', $org->id)
            ->pluck('name', 'id');

        $rows = [];
        $scoreSum = 0;
        $scored = 0;
        $withCritical = 0;
        $clientsSeen = [];

        foreach ($sites as $site) {
            $lastCrawlAt = $crawlRunBySite[$site->id] ?? null;
            $hasCrawlData = $lastCrawlAt !== null;
            $criticalIssues = (int) ($criticalBySite[$site->id] ?? 0);

            // Same number the per-site health endpoint returns; displayed as
            // 0 when the site has never been crawled (has_crawl_data=false).
            $score = $hasCrawlData
                ? $this->healthScore->forSite($site)['score']
                : 0;

            $state = $this->healthState($score, $criticalIssues, $hasCrawlData);

            if ($criticalIssues > 0) {
                $withCritical++;
            }

            if ($site->client_id !== null) {
                $clientsSeen[$site->client_id] = true;
            }

            $lastSeoDate = $searchBySite[$site->id]['latest_date'] ?? null;
            $dataAsOf = $this->latestOf($lastCrawlAt, $lastSeoDate !== null ? Carbon::parse($lastSeoDate) : null);

            if ($hasCrawlData) {
                $scoreSum += $score;
                $scored++;
            }

            $rows[] = [
                'site_id'         => $site->id,
                'site_name'       => $site->name,
                'domain'          => $site->domain,
                'client_id'       => $site->client_id,
                'client_name'     => $site->client_id !== null ? $clientNames[$site->client_id] : null,
                'health_score'    => $score,
                'has_crawl_data'  => $hasCrawlData,
                'health_state'    => $state,
                'uptime'          => $uptimeBySite[$site->id] ?? null, // null = no monitor exists
                'search'          => $searchBySite[$site->id]['search'] ?? null, // null = no GSC data yet
                'critical_issues' => $criticalIssues,
                'freshness'       => [
                    'last_sync_at' => $site->last_sync_at?->toIso8601String(),
                    'data_as_of'   => $dataAsOf?->toIso8601String(),
                ],
            ];
        }

        $filtered = array_values(array_filter(
            $rows,
            fn (array $row) => $healthFilter === null || $row['health_state'] === $healthFilter,
        ));

        // Portfolio order (doc 20): worst first — open critical issues
        // descending, then site name ascending.
        usort($filtered, fn (array $a, array $b) => $b['critical_issues'] <=> $a['critical_issues']
            ?: strcasecmp($a['site_name'], $b['site_name']));

        return [
            'organization_id' => $org->id,
            'filters' => [
                'client_id' => $clientId,
                'health'    => $healthFilter,
            ],
            'summary' => [
                'sites'               => count($filtered),
                'avg_health'          => $scored > 0 ? round($scoreSum / $scored, 1) : null,
                'sites_with_critical' => $withCritical,
                'clients'             => count($clientsSeen),
            ],
            'sites' => $filtered,
        ];
    }

    /**
     * Health-state bucket for one site.
     *
     * critical : any open critical issue (even when the per-category caps
     *            keep the score >= 50 — a critical is critical regardless of
     *            the arithmetic), or score < 50.
     * warning  : 50-79 with no critical issue.
     * healthy  : >= 80 with no critical issue.
     * unknown  : never crawled — excluded from every bucket so "no data"
     *            can never masquerade as a verdict.
     */
    private function healthState(int $score, int $criticalIssues, bool $hasCrawlData): string
    {
        if (! $hasCrawlData) {
            return 'unknown';
        }

        if ($criticalIssues > 0 || $score < 50) {
            return 'critical';
        }

        return $score < 80 ? 'warning' : 'healthy';
    }

    /**
     * site_id => ['availability_pct' => ?float, 'open_incidents' => int]
     * for sites that HAVE a monitor; sites without one are absent (the row
     * renders `uptime: null` — an honest "no monitor" beats a fake 100%).
     * Availability mirrors UptimeMonitor::availabilityPct(): ups / total
     * checks in the last 30 days, one decimal, null when no checks yet.
     *
     * @param  array<int, int>  $siteIds
     * @return array<int, array{availability_pct: float|null, open_incidents: int}>
     */
    private function uptimeBySite(array $siteIds): array
    {
        if ($siteIds === []) {
            return [];
        }

        $monitors = UptimeMonitor::query()
            ->whereIn('site_id', $siteIds)
            ->get(['id', 'site_id'])
            ->keyBy('site_id');

        if ($monitors->isEmpty()) {
            return [];
        }

        $windowStart = Carbon::now()->subDays(self::UPTIME_WINDOW_DAYS);

        $availability = UptimeCheck::query()
            ->whereIn('monitor_id', $monitors->pluck('id'))
            ->where('checked_at', '>=', $windowStart)
            ->groupBy('monitor_id')
            ->selectRaw("monitor_id, count(*) as total, sum(case when status = 'up' then 1 else 0 end) as ups")
            ->get()
            ->keyBy('monitor_id');

        $openIncidents = UptimeIncident::query()
            ->whereIn('site_id', $monitors->keys())
            ->whereNull('recovered_at')
            ->groupBy('site_id')
            ->selectRaw('site_id, count(*) as open_count')
            ->pluck('open_count', 'site_id');

        $result = [];
        foreach ($monitors as $siteId => $monitor) {
            $counts = $availability[$monitor->id] ?? null;

            $result[$siteId] = [
                'availability_pct' => $counts !== null && (int) $counts->total > 0
                    ? round(((int) $counts->ups / (int) $counts->total) * 100, 1)
                    : null,
                'open_incidents' => (int) ($openIncidents[$siteId] ?? 0),
            ];
        }

        return $result;
    }

    /**
     * site_id => ['search' => ['clicks' => int, 'impressions' => int]|null,
     *             'latest_date' => ?string] — GSC-sourced 28-day totals.
     * Sites with no seo_daily rows are absent (row renders `search: null`).
     *
     * @param  array<int, int>  $siteIds
     */
    private function searchBySite(array $siteIds): array
    {
        if ($siteIds === []) {
            return [];
        }

        $totals = SeoDaily::query()
            ->whereIn('site_id', $siteIds)
            ->where('source', 'gsc')
            ->where('date', '>=', Carbon::now()->subDays(self::SEARCH_WINDOW_DAYS)->startOfDay()->toDateString())
            ->groupBy('site_id')
            ->selectRaw('site_id, sum(clicks) as clicks, sum(impressions) as impressions, max(date) as latest_date')
            ->get();

        $result = [];
        foreach ($totals as $row) {
            $result[$row->site_id] = [
                'search' => [
                    'clicks'      => (int) $row->clicks,
                    'impressions' => (int) $row->impressions,
                ],
                'latest_date' => $row->latest_date !== null ? (string) $row->latest_date : null,
            ];
        }

        return $result;
    }

    /** site_id => open critical-issue count (0 absent when none). */
    private function criticalIssuesBySite(array $siteIds): array
    {
        if ($siteIds === []) {
            return [];
        }

        return CrawlIssue::query()
            ->whereIn('site_id', $siteIds)
            ->where('status', 'open')
            ->where('severity', 'critical')
            ->groupBy('site_id')
            ->selectRaw('site_id, count(*) as critical_count')
            ->pluck('critical_count', 'site_id')
            ->all();
    }

    /** site_id => newest crawl-run creation time (null absent when never crawled). */
    private function lastCrawlRunBySite(array $siteIds): array
    {
        if ($siteIds === []) {
            return [];
        }

        return CrawlRun::query()
            ->whereIn('site_id', $siteIds)
            ->groupBy('site_id')
            ->selectRaw('site_id, max(created_at) as last_run')
            ->pluck('last_run', 'site_id')
            ->map(fn ($run) => $run !== null ? Carbon::parse($run) : null)
            ->all();
    }

    private function latestOf(?Carbon $a, ?Carbon $b): ?Carbon
    {
        if ($a === null) {
            return $b;
        }

        if ($b === null) {
            return $a;
        }

        return $a->greaterThan($b) ? $a : $b;
    }
}
