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

        // Snapshots are stored per sync window (GA4: [today-N, yesterday];
        // connector: whatever window it posts), which almost never matches the
        // dashboard range exactly — e.g. last_30_days is [today-29, today]
        // while a 30-day GA4 sync is [today-30, today-1]. Requiring the
        // snapshot to sit fully inside the range hid every row, so pick the
        // most recent snapshot window that overlaps the range instead, and
        // read only that window so rows are never double counted.
        $window = TrafficDimensionSnapshot::where('site_id', $site->id)
            ->where('dimension_type', $type)
            ->where('period_start', '<=', $range->dateTo())
            ->where('period_end', '>=', $range->dateFrom())
            ->orderByDesc('period_end')
            ->orderBy('period_start')
            ->first(['period_start', 'period_end', 'source']);

        if (! $window) {
            return response()->json(['type' => $type, 'rows' => [], 'window' => null]);
        }

        $start = $this->dateString($window->period_start);
        $end   = $this->dateString($window->period_end);

        $rows = TrafficDimensionSnapshot::where('site_id', $site->id)
            ->where('dimension_type', $type)
            ->where('source', $window->source)
            ->where('period_start', $start)
            ->where('period_end', $end)
            ->orderByDesc('sessions')
            ->limit($limit)
            ->get(['dimension_value', 'url', 'title', 'sessions', 'users', 'views', 'conversions', 'revenue', 'engagement_rate']);

        return response()->json([
            'type' => $type,
            'rows' => $rows,
            'window' => ['from' => $start, 'to' => $end, 'source' => $window->source],
        ]);
    }

    private function dateString(mixed $value): string
    {
        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : substr((string) $value, 0, 10);

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
