<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\TrafficDaily;
use App\Models\TrafficDimensionSnapshot;
use App\Support\DateRange;
use Illuminate\Http\Request;

/**
 * Traffic Analytics (spec §9, §10.3). Reads traffic_daily/
 * traffic_dimension_snapshots — populated by the connector v2 sync
 * endpoints (Milestone 3, not yet built), so these return honest empty
 * series/[] until a site's GA4 data has actually synced. That's the
 * intended "clear no-data onboarding" state, not a bug.
 */
class TrafficController extends Controller
{
    private const DIMENSION_TYPES = ['channel', 'source_medium', 'country', 'device', 'landing_page', 'page', 'content'];

    /** GET /sites/{site}/analytics/traffic/overview */
    public function overview(Request $request)
    {
        $site  = $this->site($request);
        $range = $this->range($request, $site);
        $prev  = $range->previous();

        $current  = $this->aggregate($site->id, $range);
        $previous = $this->aggregate($site->id, $prev);

        $series = TrafficDaily::where('site_id', $site->id)
            ->whereBetween('date', [$range->dateFrom(), $range->dateTo()])
            ->orderBy('date')
            ->get(['date', 'sessions', 'users', 'page_views', 'conversions'])
            ->groupBy(fn ($r) => $r->date->toDateString())
            ->map(function ($rows, $date) {
                return [
                    'date'        => $date,
                    'sessions'    => (int) $rows->sum('sessions'),
                    'users'       => (int) $rows->sum('users'),
                    'page_views'  => (int) $rows->sum('page_views'),
                    'conversions' => (int) $rows->sum('conversions'),
                ];
            })
            ->values();

        return response()->json([
            'range'    => ['period' => $range->label, 'from' => $range->dateFrom(), 'to' => $range->dateTo(), 'timezone' => $range->tz],
            'metrics'  => $current,
            'previous' => $previous,
            'deltas'   => $this->deltas($current, $previous),
            'series'   => $series,
            'has_data' => $current['sessions'] > 0 || $previous['sessions'] > 0,
        ]);
    }

    /** GET /sites/{site}/analytics/traffic/dimensions/{type} */
    public function dimensions(Request $request, int $siteId, string $type)
    {
        // $siteId soaks the first route parameter: Laravel injects route
        // params positionally, so without this slot {site} lands on $type
        // and every dimension type fails the allowlist below (422
        // "Unknown dimension type." on all dashboard breakdown tabs).

        if (! in_array($type, self::DIMENSION_TYPES, true)) {
            return response()->json(['message' => 'Unknown dimension type.'], 422);
        }

        $site  = $this->site($request);
        $range = $this->range($request, $site);
        $limit = min(50, max(1, (int) $request->query('limit', 10)));

        $rows = TrafficDimensionSnapshot::where('site_id', $site->id)
            ->where('dimension_type', $type)
            ->where('period_start', '>=', $range->dateFrom())
            ->where('period_end', '<=', $range->dateTo())
            ->orderByDesc('sessions')
            ->limit($limit)
            ->get(['dimension_value', 'url', 'title', 'sessions', 'users', 'views', 'conversions', 'revenue', 'engagement_rate']);

        return response()->json(['type' => $type, 'rows' => $rows]);
    }

    private function aggregate(int $siteId, DateRange $range): array
    {
        $rows = TrafficDaily::where('site_id', $siteId)
            ->whereBetween('date', [$range->dateFrom(), $range->dateTo()])
            ->get(['users', 'sessions', 'engaged_sessions', 'page_views', 'conversions', 'transactions', 'revenue']);

        $sessions = (int) $rows->sum('sessions');

        return [
            'users'             => (int) $rows->sum('users'),
            'sessions'          => $sessions,
            'engaged_sessions'  => (int) $rows->sum('engaged_sessions'),
            'page_views'        => (int) $rows->sum('page_views'),
            'engagement_rate'   => $sessions > 0 ? round($rows->sum('engaged_sessions') / $sessions * 100, 1) : null,
            'conversions'       => (int) $rows->sum('conversions'),
            'transactions'      => (int) $rows->sum('transactions'),
            'revenue'           => round((float) $rows->sum('revenue'), 2),
        ];
    }

    private function deltas(array $current, array $previous): array
    {
        $out = [];
        foreach ($current as $key => $value) {
            $prevValue = $previous[$key] ?? null;
            $out[$key] = ($value !== null && $prevValue) ? round((($value - $prevValue) / $prevValue) * 100, 1) : null;
        }
        return $out;
    }

    private function site(Request $request): Site
    {
        return $request->attributes->get('site');
    }

    private function range(Request $request, Site $site): DateRange
    {
        return DateRange::fromRequest(
            $request->query('period'),
            $request->query('from'),
            $request->query('to'),
            $site->timezone ?: config('app.timezone', 'UTC'),
        );
    }
}
