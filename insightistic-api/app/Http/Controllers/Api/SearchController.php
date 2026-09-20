<?php

namespace App\Http\Controllers\Api;

use App\Domain\Search\OpportunityEngine;
use App\Domain\Search\SearchExplorer;
use App\Http\Controllers\Controller;
use App\Jobs\BackfillGscHistory;
use App\Models\SeoBackfillState;
use App\Models\Site;
use App\Services\Integrations\IntegrationManager;
use App\Support\DateRange;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Search Console intelligence (Phase 5, doc 10-GOOGLE-SEARCH-CONSOLE.md).
 * Complements SeoController's overview/queries/pages: those read the small
 * connector snapshots, these read the full daily GSC fact tables
 * (seo_query_daily / seo_page_daily / seo_query_page_daily) with window
 * comparison, filtering, cursor pagination and the opportunity engine.
 *
 * Window contract: ?window=7|28|90 (default 28) — the window ends "today"
 * in the site's timezone and the comparison window is the immediately
 * preceding, same-length window. DateRange::fromRequest('custom', ...)
 * is reused so the half-open [start, endExclusive) semantics and the
 * site timezone resolution stay identical to every other analytics
 * surface; `window` deliberately replaces period/start/end here because
 * the opportunity algorithms require two equal-length aligned windows.
 *
 * COORDINATION (workstream S1): App\Jobs\BackfillGscHistory and
 * App\Models\SeoBackfillState are created by the parallel ingestion
 * workstream. The dispatch signature pinned here is
 * BackfillGscHistory(siteId: int, dimensions: string[], from: Y-m-d, to: Y-m-d)
 * — one queued job carrying all three dimensions ('queries', 'pages',
 * 'query_page'). SeoBackfillState rows are keyed per (site, dimension).
 */
class SearchController extends Controller
{
    /** GSC's UI exposes ~16 months of data; 480 days is the safe ceiling. */
    private const BACKFILL_MAX_DAYS = 480;

    private const OPPORTUNITY_TYPES = [
        'winners',
        'losers',
        'striking_distance',
        'ctr_opportunities',
        'content_decay',
        'cannibalization',
    ];

    public function __construct(
        private readonly SearchExplorer $explorer,
        private readonly OpportunityEngine $engine,
        private readonly IntegrationManager $integrations,
    ) {}

    /** GET /sites/{site}/search/queries */
    public function queries(Request $request): JsonResponse
    {
        [$site, $from, $to, $prevFrom, $prevTo, $filters, $perPage, $cursor] = $this->params($request);

        return response()->json(
            $this->explorer->exploreQueries($site, $from, $to, $prevFrom, $prevTo, $filters, $perPage, $cursor),
        );
    }

    /** GET /sites/{site}/search/pages */
    public function pages(Request $request): JsonResponse
    {
        [$site, $from, $to, $prevFrom, $prevTo, $filters, $perPage, $cursor] = $this->params($request);

        return response()->json(
            $this->explorer->explorePages($site, $from, $to, $prevFrom, $prevTo, $filters, $perPage, $cursor),
        );
    }

    /** GET /sites/{site}/search/opportunities (?type= single report, else all). */
    public function opportunities(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['sometimes', 'in:' . implode(',', self::OPPORTUNITY_TYPES)],
        ]);

        [$site, $from, $to, $prevFrom, $prevTo] = $this->window($request, $this->site($request));

        $reports = isset($validated['type'])
            ? [$validated['type'] => $this->engine->{$validated['type']}($site, $from, $to, $prevFrom, $prevTo)]
            : $this->engine->all($site, $from, $to, $prevFrom, $prevTo);

        return response()->json([
            'window'        => $this->windowMeta($from, $to, $prevFrom, $prevTo),
            'opportunities' => $reports,
        ]);
    }

    /**
     * POST /sites/{site}/search/backfill — queue a historical GSC pull for
     * all three dimensions (queries, pages, query_page), as far back as
     * GSC retains data (480d). Owner/admin (route middleware), matching
     * every other queued heavy action (performance runs, integrations
     * resync — none of which require reauth either).
     */
    public function backfill(Request $request): JsonResponse
    {
        $site = $this->site($request);

        if (! $this->integrations->isConnected($site, 'gsc')) {
            return response()->json([
                'message' => 'Connect Google Search Console before requesting a backfill.',
                'code'    => 'gsc_not_connected',
            ], 422);
        }

        $tz = $this->timezone($site);
        $to = CarbonImmutable::today($tz);
        $from = $to->subDays(self::BACKFILL_MAX_DAYS);
        $dimensions = ['queries', 'pages', 'query_page'];

        // One job per dimension — BackfillGscHistory owns a single
        // seo_backfill_state row per (site, dimension), so separate jobs keep
        // resumable coverage independent.
        foreach ($dimensions as $dimension) {
            BackfillGscHistory::dispatch($site, $dimension, $from->toDateString(), $to->toDateString());
        }

        return response()->json([
            'dispatched' => true,
            'dimensions' => $dimensions,
            'from'       => $from->toDateString(),
            'to'         => $to->toDateString(),
        ]);
    }

    /** GET /sites/{site}/search/backfill-status — per-dimension progress rows. */
    public function status(Request $request): JsonResponse
    {
        $site = $this->site($request);

        $states = SeoBackfillState::query()
            ->where('site_id', $site->id)
            ->orderBy('dimension')
            ->get(['dimension', 'status', 'covered_from', 'covered_to', 'attempts', 'last_error'])
            ->map(fn ($state) => [
                'dimension'    => $state->dimension,
                'status'       => $state->status,
                'covered_from' => $this->dateString($state->covered_from),
                'covered_to'   => $this->dateString($state->covered_to),
                'attempts'     => (int) $state->attempts,
                'last_error'   => $state->last_error,
            ])
            ->values()
            ->all();

        return response()->json(['states' => $states]);
    }

    /**
     * Validated request parameters shared by queries()/pages().
     *
     * @return array{0: Site, 1: CarbonImmutable, 2: CarbonImmutable, 3: CarbonImmutable, 4: CarbonImmutable, 5: array, 6: int, 7: ?string}
     */
    private function params(Request $request): array
    {
        $validated = $request->validate([
            'window'          => ['sometimes', 'integer', 'in:7,28,90'],
            'q'               => ['sometimes', 'nullable', 'string', 'max:200'],
            'min_impressions' => ['sometimes', 'integer', 'min:0'],
            'sort'            => ['sometimes', 'in:clicks,impressions,ctr,position,clicks_delta,position_delta'],
            'dir'             => ['sometimes', 'in:asc,desc'],
            'per_page'        => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor'          => ['sometimes', 'nullable', 'string'],
        ]);

        $site = $this->site($request);
        [$from, $to, $prevFrom, $prevTo] = $this->window($request, $site);

        $filters = array_intersect_key($validated, array_flip(['q', 'min_impressions', 'sort', 'dir']));

        return [
            $site,
            $from,
            $to,
            $prevFrom,
            $prevTo,
            $filters,
            (int) ($validated['per_page'] ?? 50),
            $validated['cursor'] ?? null,
        ];
    }

    /**
     * Resolve window=7|28|90 (default 28) into [from, to, prevFrom, prevTo]
     * as inclusive calendar dates in the site's timezone, via the shared
     * DateRange contract.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: CarbonImmutable, 3: CarbonImmutable}
     */
    private function window(Request $request, Site $site): array
    {
        $window = (int) $request->query('window', 28);
        if (! in_array($window, [7, 28, 90], true)) {
            $window = 28;
        }

        $tz = $this->timezone($site);
        $today = CarbonImmutable::today($tz);

        $range = DateRange::fromRequest(
            'custom',
            $today->subDays($window - 1)->toDateString(),
            $today->toDateString(),
            $tz,
        );
        $prev = $range->previous();

        return [
            $range->startLocal,                            // from (inclusive)
            $range->endExclusiveLocal->subDay(),           // to (inclusive)
            $prev->startLocal,
            $prev->endExclusiveLocal->subDay(),
        ];
    }

    private function windowMeta(CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $prevFrom, CarbonImmutable $prevTo): array
    {
        return [
            'from'      => $from->toDateString(),
            'to'        => $to->toDateString(),
            'prev_from' => $prevFrom->toDateString(),
            'prev_to'   => $prevTo->toDateString(),
        ];
    }

    private function dateString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof CarbonInterface
            ? $value->toDateString()
            : (string) $value;
    }

    private function timezone(Site $site): string
    {
        return $site->timezone ?: config('app.timezone', 'UTC');
    }

    private function site(Request $request): Site
    {
        return $request->attributes->get('site');
    }
}
