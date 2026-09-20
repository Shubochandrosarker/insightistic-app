<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CloudflareBotDaily;
use App\Models\CloudflareDaily;
use App\Models\Site;
use App\Models\TrafficDaily;
use Illuminate\Http\Request;

/**
 * Cloudflare Edge Intelligence (roadmap 2026/15). One payload for the
 * dashboard's edge page: overview KPIs + trend, geo, security, bot
 * intelligence, the edge-vs-GA4 traffic gap, and data freshness.
 *
 * Everything is computed from the stored cloudflare_daily /
 * cloudflare_bot_daily rows written by the NIGHTLY IntegrationManager
 * cloudflare sync (CloudflareAnalytics). This endpoint never calls
 * Cloudflare live — a site with no synced rows yet is an expected,
 * calm "has_data: false" state with a connect hint, not an error.
 *
 * Bots availability is derived rather than stored: the nightly sync only
 * writes bot rows when the zone's plan exposes the httpRequestsAdaptiveGroups
 * dataset, so "no bot rows exist for the site at all" effectively always
 * means the plan does not expose bot analytics (when the dataset exists,
 * Googlebot/Bingbot traffic shows up on any live site). The reason string
 * matches roadmap 15's degradation language.
 */
class CloudflareController extends Controller
{
    private const SECTIONS = ['overview', 'geo', 'security', 'bots', 'traffic_gap', 'freshness'];

    private const REASON_NO_BOT_DATASET = 'plan_does_not_expose_bot_analytics';

    /** GET /sites/{site}/cloudflare?days=30[&sections=overview,bots] */
    public function overview(Request $request)
    {
        $site = $request->attributes->get('site');

        $days = min(90, max(1, (int) $request->query('days', 30)));
        $to   = now()->toDateString();
        $from = now()->subDays($days - 1)->toDateString();

        $rows = CloudflareDaily::where('site_id', $site->id)
            ->whereBetween('date', [$from, $to])
            ->orderBy('date')
            ->get();

        if ($rows->isEmpty()) {
            return response()->json([
                'has_data' => false,
                'hint'     => $site->hasCloudflareLinked()
                    ? 'connected_awaiting_sync'
                    : 'connect_cloudflare',
                'message'  => $site->hasCloudflareLinked()
                    ? 'Cloudflare is connected; edge analytics appear after the nightly sync fills them.'
                    : 'Connect Cloudflare (Zone Analytics: Read token + zone) to start collecting edge intelligence.',
            ]);
        }

        $requested = collect(explode(',', (string) $request->query('sections', '')))
            ->map(fn ($s) => trim($s))
            ->filter()
            ->intersect(self::SECTIONS)
            ->values();

        $want = fn (string $section): bool => $requested->isEmpty() || $requested->contains($section);

        $payload = [
            'range'    => ['days' => $days, 'from' => $from, 'to' => $to],
            'has_data' => true,
        ];

        if ($want('overview')) {
            $payload['overview'] = $this->overviewSection($rows);
        }

        if ($want('geo')) {
            $payload['geo'] = ['top_countries' => $this->topCountries($rows)];
        }

        if ($want('security')) {
            $payload['security'] = $this->securitySection($rows);
        }

        if ($want('bots')) {
            $payload['bots'] = $this->botsSection($site, $from, $to);
        }

        if ($want('traffic_gap')) {
            $payload['traffic_gap'] = $this->trafficGapSection($site, $from, $to, (int) $rows->sum('requests'));
        }

        if ($want('freshness')) {
            // Global last data date (not range-clipped): freshness is about
            // the sync, not the selected window.
            $lastDate = CloudflareDaily::where('site_id', $site->id)->max('date');

            $payload['freshness'] = [
                'last_data_date' => $lastDate ? (string) $lastDate : null,
                'hours_old'      => $lastDate ? (int) now()->diffInHours(\Illuminate\Support\Carbon::parse($lastDate)->endOfDay()) : null,
            ];
        }

        return response()->json($payload);
    }

    /** Traffic & Edge Overview KPIs (roadmap 15) + per-day trend. */
    private function overviewSection($rows): array
    {
        $requests       = (int) $rows->sum('requests');
        $cachedRequests = (int) $rows->sum('cached_requests');
        $bytes          = (int) $rows->sum('bytes');
        $cachedBytes    = (int) $rows->sum('cached_bytes');
        $encrypted      = (int) $rows->sum('encrypted_requests');

        return [
            'requests'            => $requests,
            'bandwidth_gb'        => round($bytes / 1e9, 2),
            'cached_requests'     => $cachedRequests,
            'cache_hit_ratio'     => $requests > 0 ? round($cachedRequests / $requests * 100, 1) : null,
            'bandwidth_saved_pct' => $bytes > 0 ? round($cachedBytes / $bytes * 100, 1) : null,
            'encrypted_pct'       => $requests > 0 ? round($encrypted / $requests * 100, 1) : null,
            'pageviews'           => (int) $rows->sum('pageviews'),
            'threats'             => (int) $rows->sum('threats'),
            'client_4xx'          => (int) $rows->sum('client_4xx'),
            'client_5xx'          => (int) $rows->sum('client_5xx'),
            'trend'               => $rows->map(fn (CloudflareDaily $r) => [
                'date'              => $r->date->toDateString(),
                'requests'          => (int) $r->requests,
                'bytes'             => (int) $r->bytes,
                'cached_requests'   => (int) $r->cached_requests,
                'pageviews'         => (int) $r->pageviews,
                'threats'           => (int) $r->threats,
                'client_4xx'        => (int) $r->client_4xx,
                'client_5xx'        => (int) $r->client_5xx,
            ])->values()->all(),
        ];
    }

    /**
     * Geographic traffic: country maps are stored per day (top 10 each), so
     * the range view re-aggregates across days and re-caps to the top 10.
     *
     * @return array<int,array{country:string,requests:int}>
     */
    private function topCountries($rows): array
    {
        $countries = [];

        foreach ($rows as $row) {
            foreach ((array) ($row->countries_json ?? []) as $country => $requests) {
                $countries[$country] = ($countries[$country] ?? 0) + (int) $requests;
            }
        }

        arsort($countries);

        return collect($countries)
            ->take(10)
            ->map(fn (int $requests, string $country) => ['country' => $country, 'requests' => $requests])
            ->values()
            ->all();
    }

    /** Security: threat trend + firewall action breakdown (roadmap 15). */
    private function securitySection($rows): array
    {
        $actions = [];

        foreach ($rows as $row) {
            foreach ((array) ($row->firewall_json ?? []) as $action => $count) {
                $actions[$action] = ($actions[$action] ?? 0) + (int) $count;
            }
        }

        arsort($actions);

        return [
            'threats_total' => (int) $rows->sum('threats'),
            'client_4xx'    => (int) $rows->sum('client_4xx'),
            'client_5xx'    => (int) $rows->sum('client_5xx'),
            'actions'       => $actions,
            'trend'         => $rows->map(fn (CloudflareDaily $r) => [
                'date'    => $r->date->toDateString(),
                'threats' => (int) $r->threats,
            ])->values()->all(),
        ];
    }

    /**
     * Crawler & bot intelligence. Rows are stored per (site, date, bot);
     * the payload aggregates per bot over the range. When the zone's plan
     * never exposed the bot dataset there are no rows at all → degrade to
     * available:false with the roadmap's plan reason, never an error.
     */
    private function botsSection(Site $site, string $from, string $to): array
    {
        $rows = CloudflareBotDaily::where('site_id', $site->id)
            ->whereBetween('date', [$from, $to])
            ->get(['bot_name', 'classification', 'requests', 'blocked']);

        if ($rows->isEmpty() && ! CloudflareBotDaily::where('site_id', $site->id)->exists()) {
            return [
                'available' => false,
                'rows'      => [],
                'reason'    => self::REASON_NO_BOT_DATASET,
            ];
        }

        $bots = [];

        foreach ($rows as $row) {
            $name = $row->bot_name;

            $bots[$name] ??= [
                'bot_name'       => $name,
                'classification' => $row->classification,
                'requests'       => 0,
                'blocked'        => 0,
            ];

            $bots[$name]['requests'] += (int) $row->requests;
            $bots[$name]['blocked']  += (int) $row->blocked;
        }

        $aggregated = array_values($bots);
        usort($aggregated, fn ($a, $b) => $b['requests'] <=> $a['requests']);

        return ['available' => true, 'rows' => $aggregated];
    }

    /**
     * Traffic Gap (roadmap 15): edge requests vs GA4 sessions, with the
     * explanation strings AS the feature. These never equate the two units —
     * they explain why the numbers differ and must not be compared directly.
     */
    private function trafficGapSection(Site $site, string $from, string $to, int $edgeRequests): array
    {
        // GA4 rows only — the edge source also writes traffic_daily and
        // comparing Cloudflare with Cloudflare is not a gap.
        $ga4Sessions = (int) TrafficDaily::where('site_id', $site->id)
            ->where('source', 'ga4')
            ->whereBetween('date', [$from, $to])
            ->sum('sessions');

        return [
            'edge_requests' => $edgeRequests,
            'ga4_sessions'  => $ga4Sessions,
            'gap_ratio_pct' => $edgeRequests > 0 ? round($ga4Sessions / $edgeRequests * 100, 1) : null,
            'explanations'  => [
                'requests_are_not_sessions' => 'An edge request is any asset served through Cloudflare (HTML, images, APIs, cached objects); a GA4 session is a visit. The two measure different units and must never be equated.',
                'cached_hits_have_no_pageview' => 'Requests served from Cloudflare cache may never reach your origin or fire a pageview, so cached/edge traffic is not equivalent to pageviews.',
                'bots_never_hit_ga4' => 'Bots and crawlers do not execute JavaScript, so they generate edge requests but never appear in GA4.',
                'consent_and_blockers' => 'Ad blockers, disabled JavaScript and consent tools can stop the GA4 tag from firing even for human visitors.',
                'misconfiguration' => 'GA4 misconfiguration (wrong measurement ID, filtered views, missing tag) can also widen the gap.',
            ],
        ];
    }
}
