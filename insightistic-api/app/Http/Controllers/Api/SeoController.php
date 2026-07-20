<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SeoDaily;
use App\Models\SeoPageSnapshot;
use App\Models\SeoQuerySnapshot;
use App\Models\Site;
use App\Support\DateRange;
use Illuminate\Http\Request;

/**
 * SEO Analytics (spec §9, §10.4). Reads seo_daily/seo_query_snapshots/
 * seo_page_snapshots — populated by the connector v2 sync endpoints
 * (Milestone 3, not yet built). See TrafficController's note on the same
 * situation; it applies here identically.
 */
class SeoController extends Controller
{
    /** GET /sites/{site}/analytics/seo/overview */
    public function overview(Request $request)
    {
        $site  = $this->site($request);
        $range = $this->range($request, $site);
        $prev  = $range->previous();

        $current  = $this->aggregate($site->id, $range);
        $previous = $this->aggregate($site->id, $prev);

        $series = SeoDaily::where('site_id', $site->id)
            ->whereBetween('date', [$range->dateFrom(), $range->dateTo()])
            ->orderBy('date')
            ->get(['date', 'clicks', 'impressions', 'ctr', 'avg_position'])
            ->map(fn ($r) => [
                'date'         => $r->date->toDateString(),
                'clicks'       => (int) $r->clicks,
                'impressions'  => (int) $r->impressions,
                'ctr'          => $r->ctr !== null ? (float) $r->ctr : null,
                'avg_position' => $r->avg_position !== null ? (float) $r->avg_position : null,
            ]);

        return response()->json([
            'range'    => ['period' => $range->label, 'from' => $range->dateFrom(), 'to' => $range->dateTo(), 'timezone' => $range->tz],
            'metrics'  => $current,
            'previous' => $previous,
            'deltas'   => $this->deltas($current, $previous),
            'series'   => $series,
            'has_data' => $current['clicks'] > 0 || $current['impressions'] > 0 || $previous['clicks'] > 0,
        ]);
    }

    /** GET /sites/{site}/analytics/seo/queries — top queries + opportunity flags for the range. */
    public function queries(Request $request)
    {
        $site  = $this->site($request);
        $range = $this->range($request, $site);
        $limit = min(100, max(1, (int) $request->query('limit', 25)));

        $rows = SeoQuerySnapshot::where('site_id', $site->id)
            ->where('period_start', '>=', $range->dateFrom())
            ->where('period_end', '<=', $range->dateTo())
            ->orderByDesc('impressions')
            ->limit($limit)
            ->get([
                'query', 'clicks', 'impressions', 'ctr', 'position',
                'prev_clicks', 'prev_impressions', 'prev_ctr', 'prev_position', 'opportunity',
            ]);

        return response()->json(['queries' => $rows]);
    }

    /** GET /sites/{site}/analytics/seo/pages — top pages for the range. */
    public function pages(Request $request)
    {
        $site  = $this->site($request);
        $range = $this->range($request, $site);
        $limit = min(100, max(1, (int) $request->query('limit', 25)));

        $rows = SeoPageSnapshot::where('site_id', $site->id)
            ->where('period_start', '>=', $range->dateFrom())
            ->where('period_end', '<=', $range->dateTo())
            ->orderByDesc('impressions')
            ->limit($limit)
            ->get([
                'page_path', 'clicks', 'impressions', 'ctr', 'position',
                'prev_clicks', 'prev_impressions', 'prev_ctr', 'prev_position',
            ]);

        return response()->json(['pages' => $rows]);
    }

    private function aggregate(int $siteId, DateRange $range): array
    {
        $rows = SeoDaily::where('site_id', $siteId)
            ->whereBetween('date', [$range->dateFrom(), $range->dateTo()])
            ->get(['clicks', 'impressions', 'ctr', 'avg_position']);

        $clicks = (int) $rows->sum('clicks');
        $impressions = (int) $rows->sum('impressions');

        return [
            'clicks'       => $clicks,
            'impressions'  => $impressions,
            'ctr'          => $impressions > 0 ? round($clicks / $impressions * 100, 2) : null,
            'avg_position' => $rows->whereNotNull('avg_position')->avg('avg_position'),
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
