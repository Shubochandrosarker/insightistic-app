<?php

namespace App\Http\Controllers\Api\Connector;

use App\Http\Controllers\Controller;
use App\Models\BrokenLinkSnapshot;
use App\Models\DataSourceConnection;
use App\Models\EngagementDaily;
use App\Models\PerformanceIssue;
use App\Models\PerformanceRun;
use App\Models\SeoDaily;
use App\Models\SeoPageSnapshot;
use App\Models\SeoQuerySnapshot;
use App\Models\Site;
use App\Models\SyncLog;
use App\Models\TrafficDaily;
use App\Models\TrafficDimensionSnapshot;
use App\Services\Integrations\CloudflareAnalytics;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Connector v2 — expanded ingestion (spec §8/§7.2, Milestone 3). Everything
 * v1's SyncController didn't cover: traffic (GA4), SEO (Search Console),
 * performance (PageSpeed/Lighthouse), first-party engagement, and 404/
 * broken-link tracking. The plugin already fetches all of this data itself
 * (Insightistic_GA/_GSC/_Pagespeed/_Engagement, service-account auth, no
 * OAuth popups) for its own local dashboard — these endpoints are purely
 * the ingestion side that lets it forward the same data here.
 *
 * Every endpoint here is idempotent by design: daily tables upsert on their
 * natural (site, date[, source]) key; period-scoped snapshot tables
 * (dimensions/queries/pages) delete-then-insert the exact (site, period,
 * type) slice being reported, matching the comment on their own migrations
 * ("ingestion truncates before insert") — so a retried or resent batch
 * converges to the same state, never duplicates.
 */
class SyncV2Controller extends Controller
{
    /**
     * POST connector/v1/sync/start — begin a batch. The plugin includes the
     * returned sync_batch_id on every v2 call in this run, giving Super
     * Admin sync-log views one correlated batch instead of N unrelated rows.
     */
    public function start(Request $request)
    {
        $site = $this->site($request);
        $batchId = (string) Str::uuid();

        $this->log($site, $batchId, 'sync_start', 'success', 0);

        return response()->json(['sync_batch_id' => $batchId]);
    }

    /** POST connector/v1/sync/traffic/daily — GA4 (or other) daily rollups. */
    public function trafficDaily(Request $request)
    {
        $site = $this->site($request);

        $data = $request->validate([
            'source'                              => ['required', 'string', 'max:64'],
            'days'                                 => ['required', 'array', 'max:400'],
            'days.*.date'                          => ['required', 'date'],
            'days.*.users'                         => ['nullable', 'integer', 'min:0'],
            'days.*.new_users'                     => ['nullable', 'integer', 'min:0'],
            'days.*.sessions'                      => ['nullable', 'integer', 'min:0'],
            'days.*.engaged_sessions'               => ['nullable', 'integer', 'min:0'],
            'days.*.page_views'                    => ['nullable', 'integer', 'min:0'],
            'days.*.engagement_rate'               => ['nullable', 'numeric', 'min:0', 'max:100'],
            'days.*.bounce_rate'                   => ['nullable', 'numeric', 'min:0', 'max:100'],
            'days.*.avg_engagement_duration_seconds' => ['nullable', 'integer', 'min:0'],
            'days.*.conversions'                   => ['nullable', 'integer', 'min:0'],
            'days.*.transactions'                  => ['nullable', 'integer', 'min:0'],
            'days.*.revenue'                       => ['nullable', 'numeric', 'min:0'],
        ]);

        $now = now();
        $rows = array_map(fn ($d) => [
            'site_id'                          => $site->id,
            'date'                              => $d['date'],
            'source'                            => $data['source'],
            'users'                             => $d['users'] ?? 0,
            'new_users'                         => $d['new_users'] ?? 0,
            'sessions'                          => $d['sessions'] ?? 0,
            'engaged_sessions'                  => $d['engaged_sessions'] ?? 0,
            'page_views'                        => $d['page_views'] ?? 0,
            'engagement_rate'                   => $d['engagement_rate'] ?? null,
            'bounce_rate'                       => $d['bounce_rate'] ?? null,
            'avg_engagement_duration_seconds'   => $d['avg_engagement_duration_seconds'] ?? null,
            'conversions'                       => $d['conversions'] ?? 0,
            'transactions'                      => $d['transactions'] ?? 0,
            'revenue'                           => $d['revenue'] ?? null,
            'created_at'                        => $now,
            'updated_at'                        => $now,
        ], $data['days']);

        TrafficDaily::upsert(
            $rows,
            ['site_id', 'source', 'date'],
            ['users', 'new_users', 'sessions', 'engaged_sessions', 'page_views', 'engagement_rate',
             'bounce_rate', 'avg_engagement_duration_seconds', 'conversions', 'transactions', 'revenue', 'updated_at'],
        );

        $this->recordSource($site, 'ga4', true);
        $this->log($site, $this->batchId($request), 'traffic_daily', 'success', count($rows));

        return response()->json(['status' => 'ok', 'received' => count($rows)]);
    }

    /**
     * POST connector/v1/sync/traffic/dimensions — top-N breakdown for one
     * dimension_type over one period. Replaces (not merges) that exact slice.
     */
    public function trafficDimensions(Request $request)
    {
        $site = $this->site($request);

        $data = $request->validate([
            'source'                    => ['required', 'string', 'max:64'],
            'period_start'              => ['required', 'date'],
            'period_end'                => ['required', 'date', 'after_or_equal:period_start'],
            'dimension_type'            => ['required', 'string', 'in:channel,source_medium,country,device,landing_page,page,content'],
            'rows'                      => ['required', 'array', 'max:100'],
            'rows.*.dimension_value'    => ['required', 'string', 'max:190'],
            'rows.*.url'                => ['nullable', 'string', 'max:1000'],
            'rows.*.title'              => ['nullable', 'string', 'max:255'],
            'rows.*.sessions'           => ['nullable', 'integer', 'min:0'],
            'rows.*.users'              => ['nullable', 'integer', 'min:0'],
            'rows.*.views'              => ['nullable', 'integer', 'min:0'],
            'rows.*.conversions'        => ['nullable', 'integer', 'min:0'],
            'rows.*.revenue'            => ['nullable', 'numeric', 'min:0'],
            'rows.*.engagement_rate'    => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $now = now();
        $rows = array_values(array_map(fn ($r, $i) => [
            'site_id'          => $site->id,
            'period_start'     => $data['period_start'],
            'period_end'       => $data['period_end'],
            'source'           => $data['source'],
            'dimension_type'   => $data['dimension_type'],
            'dimension_value'  => $r['dimension_value'],
            'url'              => $r['url'] ?? null,
            'title'            => $r['title'] ?? null,
            'sessions'         => $r['sessions'] ?? 0,
            'users'            => $r['users'] ?? 0,
            'views'            => $r['views'] ?? 0,
            'conversions'      => $r['conversions'] ?? 0,
            'revenue'          => $r['revenue'] ?? null,
            'engagement_rate'  => $r['engagement_rate'] ?? null,
            'rank'             => $i + 1,
            'created_at'       => $now,
        ], $data['rows'], array_keys($data['rows'])));

        DB::transaction(function () use ($site, $data, $rows) {
            TrafficDimensionSnapshot::where('site_id', $site->id)
                ->where('source', $data['source'])
                ->where('dimension_type', $data['dimension_type'])
                ->where('period_start', $data['period_start'])
                ->where('period_end', $data['period_end'])
                ->delete();

            if ($rows) {
                TrafficDimensionSnapshot::insert($rows);
            }
        });

        $this->log($site, $this->batchId($request), 'traffic_dimensions', 'success', count($rows));

        return response()->json(['status' => 'ok', 'received' => count($rows)]);
    }

    /** POST connector/v1/sync/seo/daily — Search Console daily rollups. */
    public function seoDaily(Request $request)
    {
        $site = $this->site($request);

        $data = $request->validate([
            'days'                    => ['required', 'array', 'max:400'],
            'days.*.date'             => ['required', 'date'],
            'days.*.clicks'           => ['nullable', 'integer', 'min:0'],
            'days.*.impressions'      => ['nullable', 'integer', 'min:0'],
            // ctr is the raw 0-1 fraction Search Console returns, not a percentage.
            'days.*.ctr'              => ['nullable', 'numeric', 'min:0', 'max:1'],
            'days.*.avg_position'     => ['nullable', 'numeric', 'min:0'],
        ]);

        $now = now();
        $rows = array_map(fn ($d) => [
            'site_id'      => $site->id,
            'source'       => 'gsc',
            'date'         => $d['date'],
            'clicks'       => $d['clicks'] ?? 0,
            'impressions'  => $d['impressions'] ?? 0,
            'ctr'          => $d['ctr'] ?? null,
            'avg_position' => $d['avg_position'] ?? null,
            'created_at'   => $now,
            'updated_at'   => $now,
        ], $data['days']);

        SeoDaily::upsert($rows, ['site_id', 'source', 'date'], ['clicks', 'impressions', 'ctr', 'avg_position', 'updated_at']);

        $this->recordSource($site, 'gsc', true);
        $this->log($site, $this->batchId($request), 'seo_daily', 'success', count($rows));

        return response()->json(['status' => 'ok', 'received' => count($rows)]);
    }

    /** POST connector/v1/sync/seo/queries — top queries for one period. Replaces that period's slice. */
    public function seoQueries(Request $request)
    {
        $site = $this->site($request);

        $data = $request->validate([
            'period_start'          => ['required', 'date'],
            'period_end'            => ['required', 'date', 'after_or_equal:period_start'],
            'rows'                  => ['required', 'array', 'max:250'],
            'rows.*.query'          => ['required', 'string', 'max:500'],
            'rows.*.clicks'         => ['nullable', 'integer', 'min:0'],
            'rows.*.impressions'    => ['nullable', 'integer', 'min:0'],
            'rows.*.ctr'            => ['nullable', 'numeric', 'min:0', 'max:1'],
            'rows.*.position'       => ['nullable', 'numeric', 'min:0'],
            'rows.*.prev_clicks'    => ['nullable', 'integer', 'min:0'],
            'rows.*.prev_impressions' => ['nullable', 'integer', 'min:0'],
            'rows.*.prev_ctr'       => ['nullable', 'numeric', 'min:0', 'max:1'],
            'rows.*.prev_position'  => ['nullable', 'numeric', 'min:0'],
        ]);

        $now = now();
        $rows = array_map(function ($r) use ($site, $data, $now) {
            return [
                'site_id'          => $site->id,
                'period_start'     => $data['period_start'],
                'period_end'       => $data['period_end'],
                'query'            => $r['query'],
                'clicks'           => $r['clicks'] ?? 0,
                'impressions'      => $r['impressions'] ?? 0,
                'ctr'              => $r['ctr'] ?? null,
                'position'         => $r['position'] ?? null,
                'prev_clicks'      => $r['prev_clicks'] ?? null,
                'prev_impressions' => $r['prev_impressions'] ?? null,
                'prev_ctr'         => $r['prev_ctr'] ?? null,
                'prev_position'    => $r['prev_position'] ?? null,
                'opportunity'      => $this->classifyOpportunity($r),
                'created_at'       => $now,
            ];
        }, $data['rows']);

        DB::transaction(function () use ($site, $data, $rows) {
            SeoQuerySnapshot::where('site_id', $site->id)
                ->where('period_start', $data['period_start'])
                ->where('period_end', $data['period_end'])
                ->delete();

            if ($rows) {
                SeoQuerySnapshot::insert($rows);
            }
        });

        $this->log($site, $this->batchId($request), 'seo_queries', 'success', count($rows));

        return response()->json(['status' => 'ok', 'received' => count($rows)]);
    }

    /** POST connector/v1/sync/seo/pages — top pages for one period. Replaces that period's slice. */
    public function seoPages(Request $request)
    {
        $site = $this->site($request);

        $data = $request->validate([
            'period_start'          => ['required', 'date'],
            'period_end'            => ['required', 'date', 'after_or_equal:period_start'],
            'rows'                  => ['required', 'array', 'max:250'],
            'rows.*.page_path'      => ['required', 'string', 'max:1000'],
            'rows.*.clicks'         => ['nullable', 'integer', 'min:0'],
            'rows.*.impressions'    => ['nullable', 'integer', 'min:0'],
            'rows.*.ctr'            => ['nullable', 'numeric', 'min:0', 'max:1'],
            'rows.*.position'       => ['nullable', 'numeric', 'min:0'],
            'rows.*.prev_clicks'    => ['nullable', 'integer', 'min:0'],
            'rows.*.prev_impressions' => ['nullable', 'integer', 'min:0'],
            'rows.*.prev_ctr'       => ['nullable', 'numeric', 'min:0', 'max:1'],
            'rows.*.prev_position'  => ['nullable', 'numeric', 'min:0'],
        ]);

        $now = now();
        $rows = array_map(fn ($r) => [
            'site_id'          => $site->id,
            'period_start'     => $data['period_start'],
            'period_end'       => $data['period_end'],
            'page_path'        => $r['page_path'],
            'clicks'           => $r['clicks'] ?? 0,
            'impressions'      => $r['impressions'] ?? 0,
            'ctr'              => $r['ctr'] ?? null,
            'position'         => $r['position'] ?? null,
            'prev_clicks'      => $r['prev_clicks'] ?? null,
            'prev_impressions' => $r['prev_impressions'] ?? null,
            'prev_ctr'         => $r['prev_ctr'] ?? null,
            'prev_position'    => $r['prev_position'] ?? null,
            'created_at'       => $now,
        ], $data['rows']);

        DB::transaction(function () use ($site, $data, $rows) {
            SeoPageSnapshot::where('site_id', $site->id)
                ->where('period_start', $data['period_start'])
                ->where('period_end', $data['period_end'])
                ->delete();

            if ($rows) {
                SeoPageSnapshot::insert($rows);
            }
        });

        $this->log($site, $this->batchId($request), 'seo_pages', 'success', count($rows));

        return response()->json(['status' => 'ok', 'received' => count($rows)]);
    }

    /** POST connector/v1/sync/performance/runs — one PageSpeed/Lighthouse run + its audit issues. */
    public function performanceRuns(Request $request)
    {
        $site = $this->site($request);

        $data = $request->validate([
            'url'                    => ['required', 'string', 'max:1000'],
            'strategy'               => ['required', 'in:mobile,desktop'],
            'run_at'                 => ['required', 'date'],
            'lighthouse_version'     => ['nullable', 'string', 'max:32'],
            'fetch_time'             => ['nullable', 'date'],
            'performance_score'      => ['nullable', 'integer', 'min:0', 'max:100'],
            'accessibility_score'    => ['nullable', 'integer', 'min:0', 'max:100'],
            'best_practices_score'   => ['nullable', 'integer', 'min:0', 'max:100'],
            'seo_score'              => ['nullable', 'integer', 'min:0', 'max:100'],
            'ai_readiness_score'     => ['nullable', 'integer', 'min:0', 'max:100'],
            'lcp_ms'                 => ['nullable', 'integer', 'min:0'],
            'lcp_status'             => ['nullable', 'in:good,needs_improvement,poor'],
            'inp_ms'                 => ['nullable', 'integer', 'min:0'],
            'inp_status'             => ['nullable', 'in:good,needs_improvement,poor'],
            'cls'                    => ['nullable', 'numeric', 'min:0'],
            'cls_status'             => ['nullable', 'in:good,needs_improvement,poor'],
            'fcp_ms'                 => ['nullable', 'integer', 'min:0'],
            'tbt_ms'                 => ['nullable', 'integer', 'min:0'],
            'speed_index_ms'         => ['nullable', 'integer', 'min:0'],
            'ttfb_ms'                => ['nullable', 'integer', 'min:0'],
            'field_data_available'   => ['nullable', 'boolean'],
            'lab_data_available'     => ['nullable', 'boolean'],
            'status'                 => ['nullable', 'in:queued,running,complete,failed'],
            'error_message'          => ['nullable', 'string'],
            'issues'                 => ['nullable', 'array', 'max:100'],
            'issues.*.audit_key'     => ['required_with:issues', 'string', 'max:190'],
            'issues.*.category'      => ['required_with:issues', 'in:performance,accessibility,best-practices,seo'],
            'issues.*.title'         => ['required_with:issues', 'string', 'max:255'],
            'issues.*.severity'      => ['nullable', 'in:low,medium,high'],
            'issues.*.savings_ms'    => ['nullable', 'integer', 'min:0'],
            'issues.*.savings_bytes' => ['nullable', 'integer', 'min:0'],
            'issues.*.display_value' => ['nullable', 'string', 'max:190'],
            'issues.*.description'   => ['nullable', 'string'],
        ]);

        $hasScore = array_key_exists('performance_score', $data) && $data['performance_score'] !== null;
        $reportedStatus = $data['status'] ?? null;
        $runStatus = ! $hasScore && ($reportedStatus === null || $reportedStatus === 'complete')
            ? 'failed'
            : ($reportedStatus ?? 'complete');
        $runError = $data['error_message'] ?? (! $hasScore ? 'No performance score in connector payload.' : null);

        $run = DB::transaction(function () use ($site, $data, $runStatus, $runError) {
            $run = PerformanceRun::create([
                'site_id'              => $site->id,
                'url'                  => $data['url'],
                'strategy'             => $data['strategy'],
                'run_at'               => $data['run_at'],
                'lighthouse_version'   => $data['lighthouse_version'] ?? null,
                'fetch_time'           => $data['fetch_time'] ?? null,
                'performance_score'    => $data['performance_score'] ?? null,
                'accessibility_score'  => $data['accessibility_score'] ?? null,
                'best_practices_score' => $data['best_practices_score'] ?? null,
                'seo_score'            => $data['seo_score'] ?? null,
                'ai_readiness_score'   => $data['ai_readiness_score'] ?? null,
                'lcp_ms'               => $data['lcp_ms'] ?? null,
                'lcp_status'           => $data['lcp_status'] ?? null,
                'inp_ms'               => $data['inp_ms'] ?? null,
                'inp_status'           => $data['inp_status'] ?? null,
                'cls'                  => $data['cls'] ?? null,
                'cls_status'           => $data['cls_status'] ?? null,
                'fcp_ms'               => $data['fcp_ms'] ?? null,
                'tbt_ms'               => $data['tbt_ms'] ?? null,
                'speed_index_ms'       => $data['speed_index_ms'] ?? null,
                'ttfb_ms'              => $data['ttfb_ms'] ?? null,
                'field_data_available' => $data['field_data_available'] ?? false,
                'lab_data_available'   => $data['lab_data_available'] ?? true,
                // A scoreless push cannot remain complete even when the
                // connector explicitly sent status=complete.
                'status'               => $runStatus,
                'error_message'        => $runError,
            ]);

            $issues = $data['issues'] ?? [];
            if ($issues) {
                $now = now();
                $run->issues()->insert(array_map(fn ($i) => [
                    'performance_run_id' => $run->id,
                    'audit_key'          => $i['audit_key'],
                    'category'           => $i['category'],
                    'title'              => $i['title'],
                    'severity'           => $i['severity'] ?? 'medium',
                    'savings_ms'         => $i['savings_ms'] ?? null,
                    'savings_bytes'      => $i['savings_bytes'] ?? null,
                    'display_value'      => $i['display_value'] ?? null,
                    'description'        => $i['description'] ?? null,
                    'created_at'         => $now,
                ], $issues));
            }

            return $run;
        });

        $successful = $run->status === 'complete';
        $this->recordSource($site, 'pagespeed', $successful);
        $this->log(
            $site,
            $this->batchId($request),
            'performance_run',
            $successful ? 'success' : 'error',
            1 + count($data['issues'] ?? []),
            $successful ? null : $run->error_message,
        );

        return response()->json(['status' => 'ok', 'run_id' => $run->id]);
    }

    /** POST connector/v1/sync/engagement/daily — first-party tracked events, aggregated per day. */
    public function engagementDaily(Request $request)
    {
        $site = $this->site($request);

        $data = $request->validate([
            'rows'                  => ['required', 'array', 'max:400'],
            'rows.*.date'           => ['required', 'date'],
            'rows.*.event_name'     => ['required', 'string', 'max:190'],
            'rows.*.count'          => ['required', 'integer', 'min:0'],
        ]);

        $now = now();
        $rows = array_map(fn ($r) => [
            'site_id'    => $site->id,
            'date'       => $r['date'],
            'event_name' => $r['event_name'],
            'count'      => $r['count'],
            'created_at' => $now,
            'updated_at' => $now,
        ], $data['rows']);

        EngagementDaily::upsert($rows, ['site_id', 'date', 'event_name'], ['count', 'updated_at']);

        $this->recordSource($site, 'engagement', true);
        $this->log($site, $this->batchId($request), 'engagement_daily', 'success', count($rows));

        return response()->json(['status' => 'ok', 'received' => count($rows)]);
    }

    /**
     * POST connector/v1/sync/broken-links — the plugin's locally-tracked 404
     * log (capped client-side; see Insightistic_Engagement). Upserts on
     * (site_id, path); a path not resent for `stale_after_days` is left
     * alone here — resolution is a deliberate admin action (BrokenLinkSnapshot
     * already exposes a status column for that), not an ingestion side effect.
     */
    public function brokenLinks(Request $request)
    {
        $site = $this->site($request);

        $data = $request->validate([
            'links'                     => ['required', 'array', 'max:200'],
            'links.*.path'              => ['required', 'string', 'max:1000'],
            'links.*.first_seen_at'     => ['required', 'date'],
            'links.*.last_seen_at'      => ['required', 'date'],
            'links.*.hit_count'         => ['nullable', 'integer', 'min:1'],
            'links.*.referrer_hosts'    => ['nullable', 'array', 'max:20'],
            'links.*.referrer_hosts.*'  => ['string', 'max:255'],
        ]);

        $now = now();
        $rows = array_map(fn ($l) => [
            'site_id'        => $site->id,
            'path'           => $l['path'],
            'first_seen_at'  => $l['first_seen_at'],
            'last_seen_at'   => $l['last_seen_at'],
            'hit_count'      => $l['hit_count'] ?? 1,
            'referrer_hosts' => isset($l['referrer_hosts']) ? json_encode(array_values($l['referrer_hosts'])) : null,
            'status'         => 'open',
            'created_at'     => $now,
            'updated_at'     => $now,
        ], $data['links']);

        BrokenLinkSnapshot::upsert(
            $rows,
            ['site_id', 'path'],
            ['last_seen_at', 'hit_count', 'referrer_hosts', 'updated_at'],
        );

        $this->log($site, $this->batchId($request), 'broken_links', 'success', count($rows));

        return response()->json(['status' => 'ok', 'received' => count($rows)]);
    }

    /**
     * POST connector/v1/sync/cloudflare/daily — Cloudflare edge analytics the
     * plugin pulls with the Zone ID + API token from its own Settings →
     * Cloudflare fields (its BYO path), so the dashboard's edge page fills
     * with no app-side Cloudflare connect. Rows land in the exact shape the
     * nightly CloudflareAnalytics sync writes, and both paths converge on the
     * same (site, date) upsert key — a site with both configured gets one
     * converged row per day, never duplicates.
     *
     * Bot rows arrive as raw per-(date, user agent) groups; classification
     * stays platform-side (CloudflareAnalytics::classify) so the crawler
     * taxonomy exists in exactly one place, same as the nightly path.
     */
    public function cloudflareDaily(Request $request, CloudflareAnalytics $analytics)
    {
        $site = $this->site($request);

        $data = $request->validate([
            'days'                      => ['required', 'array', 'max:120'],
            'days.*.date'               => ['required', 'date'],
            'days.*.requests'           => ['nullable', 'integer', 'min:0'],
            'days.*.bytes'              => ['nullable', 'integer', 'min:0'],
            'days.*.cached_requests'    => ['nullable', 'integer', 'min:0'],
            'days.*.cached_bytes'       => ['nullable', 'integer', 'min:0'],
            'days.*.encrypted_requests' => ['nullable', 'integer', 'min:0'],
            'days.*.pageviews'          => ['nullable', 'integer', 'min:0'],
            'days.*.threats'            => ['nullable', 'integer', 'min:0'],
            'days.*.client_4xx'         => ['nullable', 'integer', 'min:0'],
            'days.*.client_5xx'         => ['nullable', 'integer', 'min:0'],
            'days.*.countries'          => ['nullable', 'array', 'max:60'],
            'days.*.countries.*'        => ['integer', 'min:0'],
            'days.*.status_breakdown'   => ['nullable', 'array', 'max:80'],
            'days.*.status_breakdown.*' => ['integer', 'min:0'],
            'days.*.firewall'           => ['nullable', 'array', 'max:40'],
            'days.*.firewall.*'         => ['integer', 'min:0'],
            'bots_available'            => ['nullable', 'boolean'],
            'bots'                      => ['nullable', 'array', 'max:400'],
            'bots.*.date'               => ['required_with:bots', 'date'],
            'bots.*.user_agent'         => ['required_with:bots', 'string', 'max:512'],
            'bots.*.requests'           => ['nullable', 'integer', 'min:0'],
        ]);

        $rows = array_map(fn ($d) => [
            'date'                  => $d['date'],
            'requests'              => $d['requests'] ?? 0,
            'bytes'                 => $d['bytes'] ?? 0,
            'cached_requests'       => $d['cached_requests'] ?? 0,
            'cached_bytes'          => $d['cached_bytes'] ?? 0,
            'encrypted_requests'    => $d['encrypted_requests'] ?? 0,
            'pageviews'             => $d['pageviews'] ?? 0,
            'threats'               => $d['threats'] ?? 0,
            'client_4xx'            => $d['client_4xx'] ?? 0,
            'client_5xx'            => $d['client_5xx'] ?? 0,
            'countries_json'        => $d['countries'] ?? null,
            'status_breakdown_json' => $d['status_breakdown'] ?? null,
            'firewall_json'         => $d['firewall'] ?? null,
        ], $data['days']);

        $bots = ['available' => (bool) ($data['bots_available'] ?? false), 'rows' => []];
        if ($bots['available']) {
            foreach ($data['bots'] ?? [] as $bot) {
                $classification = $analytics->classify($bot['user_agent']);
                if ($classification === null) {
                    continue; // unrecognized UA — not bot intelligence
                }
                $bots['rows'][] = [
                    'date'           => $bot['date'],
                    'bot_name'       => mb_substr($bot['user_agent'], 0, 64),
                    'classification' => $classification,
                    'requests'       => (int) ($bot['requests'] ?? 0),
                    'blocked'        => 0,
                ];
            }
        }

        $analytics->storeDaily($site, ['rows' => $rows, 'bots' => $bots]);

        $this->recordSource($site, 'cloudflare', true);
        $this->log($site, $this->batchId($request), 'cloudflare_daily', 'success', count($rows));

        return response()->json(['status' => 'ok', 'received' => count($rows), 'bots_stored' => count($bots['rows'])]);
    }

    // --- helpers -------------------------------------------------------------

    private function site(Request $request): Site
    {
        return $request->attributes->get('connector_site');
    }

    private function batchId(Request $request): ?string
    {
        return $request->header('X-INS-Sync-Batch') ?: $request->input('sync_batch_id');
    }

    private function log(Site $site, ?string $batchId, string $job, string $status, int $records, ?string $message = null): void
    {
        SyncLog::create([
            'site_id'       => $site->id,
            'sync_batch_id' => $batchId,
            'job'           => $job,
            'schema_version' => 'v2',
            'status'        => $status,
            'records'       => $records,
            'message'       => $message,
            'started_at'    => now(),
            'finished_at'   => now(),
        ]);
    }

    /**
     * On success, stamps last_successful_at/latest_data_at; on failure those
     * keys are simply omitted so recordAttempt()'s updateOrCreate() leaves
     * whatever was already there untouched (an Eloquent update only writes
     * the attributes it's given — there's no "set to current DB value" raw
     * expression needed).
     *
     * DUAL-PATH sources (gsc, ga4 — spec §12): the plugin path must never
     * masquerade as a direct OAuth connection. Plugin presence is recorded
     * in metadata_json.plugin_fed_at so the Integrations page can show BOTH
     * "connected via WordPress plugin" AND "direct Google: not connected /
     * [Connect directly]" at the same time. When a direct credential
     * exists, the row's connection_method stays 'oauth' and a plugin
     * failure cannot degrade the healthy direct source's state.
     */
    private function recordSource(Site $site, string $source, bool $success): void
    {
        $attrs = [
            'state'             => $success ? 'connected' : 'error',
            'last_attempted_at' => now(),
        ];
        if ($success) {
            $attrs['last_successful_at'] = now();
            $attrs['latest_data_at'] = now();
            $attrs['freshness'] = 'fresh';
        }

        $existing = DataSourceConnection::withoutGlobalScope('organization')
            ->where('site_id', $site->id)
            ->where('source', $source)
            ->first();

        if (in_array($source, ['gsc', 'ga4'], true)) {
            $hasDirect = \App\Models\DataSourceCredential::where('site_id', $site->id)
                ->where('source', $source)->exists();

            $meta = $existing?->metadata_json ?? [];
            if ($success) {
                $meta['plugin_fed_at'] = now()->toIso8601String();
            }
            $attrs['metadata_json'] = $meta;

            if (! $hasDirect) {
                $attrs['connection_method'] = 'wordpress_plugin';
                if ($success) {
                    $attrs['label'] = 'WordPress plugin';
                }
            } elseif (! $success) {
                // Plugin failure must not flip a healthy direct source.
                unset($attrs['state']);
            }
        } else {
            $attrs['connection_method'] = 'wordpress_plugin';
            if ($success) {
                $attrs['label'] = $existing->label ?? 'WordPress plugin';
            }
        }

        DataSourceConnection::recordAttempt($site->organization_id, $site->id, $source, $attrs);
    }

    /**
     * Deterministic classification, mirroring AlertDetector's threshold-rule
     * style rather than anything ML-based: high_potential (visible but
     * under-clicked), declining (clicks dropped hard vs. last period),
     * content_decay (impressions themselves dropped — the page is losing
     * visibility, not just clicks), stable otherwise.
     */
    private function classifyOpportunity(array $row): ?string
    {
        $clicks = $row['clicks'] ?? 0;
        $impressions = $row['impressions'] ?? 0;
        $ctr = $row['ctr'] ?? null;
        $prevClicks = $row['prev_clicks'] ?? null;
        $prevImpressions = $row['prev_impressions'] ?? null;

        if ($impressions >= 100 && $ctr !== null && $ctr < 0.02) {
            return 'high_potential';
        }
        if ($prevClicks && $prevClicks > 0 && $clicks < $prevClicks * 0.8) {
            return 'declining';
        }
        if ($prevImpressions && $prevImpressions > 0 && $impressions < $prevImpressions * 0.8) {
            return 'content_decay';
        }
        if ($prevClicks !== null || $prevImpressions !== null) {
            return 'stable';
        }

        return null;
    }
}
