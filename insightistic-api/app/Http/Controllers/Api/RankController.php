<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RankCheckJob;
use App\Models\RankKeyword;
use App\Models\RankObservation;
use App\Models\SeoQueryDaily;
use App\Models\Site;
use App\Services\Rank\RankKpis;
use App\Services\Rank\RankSuggestions;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Rank tracker API (Phase 10, doc 13). Keywords are queued for checking
 * (RankCheckJob — owned by the rank engine workstream, which also owns the
 * rank_* models and the DataForSEO provider); this controller is the gate
 * in front of them: who may track keywords, the tracked_keywords plan
 * quota, the check guards, and the read surfaces over observations once
 * checks have run.
 *
 * GSC-vs-tracker LABELING (doc 13): impressions_28d below comes from
 * seo_query_daily — aggregated positions from actual Google impressions.
 * latest/previous/best come from rank_observations — controlled
 * point-in-time SERP observations for the keyword's locale/device. They
 * are different products and stay separate fields; nothing here merges
 * them into one unnamed "position".
 *
 * QUOTA DESIGN (tracked_keywords): the entitlement is ORG-wide (the plan
 * attaches to the organization), but the natural unit of tracking is the
 * site, so "used" counts ACTIVE keywords on THIS site — the same
 * per-site UX as every other site-scoped surface; orgs that want the
 * whole-org picture can sum across sites. requested + used > limit ⇒ 422
 * tracked_keyword_limit_reached, in UsageService::limitError's envelope
 * shape ({code, limit: {key, max, used}, message}) so the SPA's upgrade
 * prompt treats it like every other limit denial.
 *
 * KPI SCOPE: the overview KPIs are computed over ALL active keywords'
 * latest+previous observations (site-wide), while the keyword list is
 * paginated — a keyword that has never been checked contributes to the
 * list but not to the KPI maps (there is no position to count it under;
 * not_found means "checked and absent from the SERP", never "not checked
 * yet").
 *
 * COORDINATION (rank engine workstream): App\Models\{RankKeyword,
 * RankObservation}, App\Services\Rank\RankChecker and
 * App\Jobs\RankCheckJob are created by the parallel engine workstream.
 * The pinned contracts used here: RankKeyword::makeConfigHash(keyword,
 * engine, ?country, ?language, device, ?location) for the (site,
 * config_hash) uniqueness key, and RankCheckJob(siteId: int, ?array
 * $keywordIds = null).
 */
class RankController extends Controller
{
    /** The pagination page size for the keyword table. */
    private const PER_PAGE_DEFAULT = 50;

    /** How many history points the keyword history endpoint returns. */
    private const HISTORY_LIMIT = 90;

    /** GET /sites/{site}/rank — overview: KPIs + paginated keyword table. */
    public function index(Request $request): JsonResponse
    {
        $site = $this->site($request);

        $keywordsQuery = RankKeyword::where('site_id', $site->id)
            ->where('active', true)
            ->orderBy('id');

        [$page, $meta] = $this->paginate($keywordsQuery, $request, [
            'id', 'keyword', 'engine', 'country', 'language', 'device',
            'location', 'last_checked_at', 'config_hash',
        ]);

        $allIds = RankKeyword::where('site_id', $site->id)
            ->where('active', true)
            ->pluck('id');

        // Latest + one-before per keyword, site-wide, in one windowed pass:
        // rn=1 is the newest observation, rn=2 the comparison point.
        $latestPerKeyword = [];
        $previousPerKeyword = [];
        $rowsByKeyword = [];

        if ($allIds->isNotEmpty()) {
            $observed = DB::table('rank_observations')
                ->select('keyword_id', 'position', 'checked_at', 'ranking_url', 'rn')
                ->fromSub(function ($query) use ($allIds) {
                    $query->from('rank_observations')
                        ->selectRaw('keyword_id, position, checked_at, ranking_url,
                            row_number() over (partition by keyword_id order by checked_at desc, id desc) as rn')
                        ->whereIn('keyword_id', $allIds);
                }, 'ranked')
                ->where('rn', '<=', 2)
                ->get();

            foreach ($observed as $row) {
                if ((int) $row->rn === 1) {
                    $latestPerKeyword[$row->keyword_id] = [
                        'position'   => $row->position === null ? null : (int) $row->position,
                        'checked_at' => $row->checked_at,
                    ];
                    $rowsByKeyword[$row->keyword_id] = $row;
                } else {
                    $previousPerKeyword[$row->keyword_id] = [
                        'position'   => $row->position === null ? null : (int) $row->position,
                        'checked_at' => $row->checked_at,
                    ];
                }
            }
        }

        $pageIds = $page->pluck('id');

        // Best-ever position and this month's provider cost, for the page.
        $best = RankObservation::whereIn('keyword_id', $pageIds)
            ->whereNotNull('position')
            ->groupBy('keyword_id')
            ->selectRaw('keyword_id, min(position) as best')
            ->pluck('best', 'keyword_id');

        $cost = RankObservation::whereIn('keyword_id', $pageIds)
            ->where('checked_at', '>=', now()->subDays(30))
            ->groupBy('keyword_id')
            ->selectRaw('keyword_id, sum(cost_units) as total')
            ->pluck('total', 'keyword_id');

        $impressions = $this->impressions28d($site);

        $keywords = $page->map(function (RankKeyword $keyword) use ($rowsByKeyword, $previousPerKeyword, $best, $cost, $impressions) {
            $latest = $rowsByKeyword[$keyword->id] ?? null;

            return [
                'id'                => $keyword->id,
                'keyword'           => $keyword->keyword,
                'engine'            => $keyword->engine,
                'country'           => $keyword->country,
                'device'            => $keyword->device,
                'location'          => $keyword->location,
                'last_checked_at'   => $keyword->last_checked_at,
                'latest'            => [
                    'position'    => $latest === null ? null : ($latest->position === null ? null : (int) $latest->position),
                    'ranking_url' => $latest->ranking_url ?? null,
                    'checked_at'  => $latest->checked_at ?? null,
                ],
                'previous_position' => isset($previousPerKeyword[$keyword->id])
                    ? $previousPerKeyword[$keyword->id]['position']
                    : null,
                'best_position'     => isset($best[$keyword->id]) ? (int) $best[$keyword->id] : null,
                // GSC aggregated impressions (28d) for a matching query —
                // labeled separately from the SERP observations above.
                'impressions_28d'   => $impressions[mb_strtolower(trim($keyword->keyword))] ?? null,
                'cost_30d'          => isset($cost[$keyword->id]) ? round((float) $cost[$keyword->id], 2) : 0.0,
            ];
        })->values();

        return response()->json([
            'kpis'                => RankKpis::kpis($latestPerKeyword, $previousPerKeyword),
            'provider_configured' => $this->providerConfigured(),
            'keywords'            => $keywords,
        ] + $meta);
    }

    /**
     * POST /sites/{site}/rank/keywords — track keywords (owner/admin).
     * Duplicates (same site + config hash, or the same config twice in one
     * request) are skipped silently; the tracked_keywords plan quota is
     * enforced against the keywords that would actually be created.
     */
    public function storeKeywords(Request $request): JsonResponse
    {
        $site = $this->site($request);

        // Field bounds mirror the rank_* columns and the engine's own
        // vocabularies (RankKeyword::ENGINES / DEVICES), so validation can
        // never drift from what the provider actually accepts.
        $validated = $request->validate([
            'keywords'            => ['required', 'array', 'min:1', 'max:100'],
            'keywords.*.keyword'  => ['required', 'string', 'max:200'],
            'keywords.*.country'  => ['sometimes', 'nullable', 'string', 'max:8'],
            'keywords.*.language' => ['sometimes', 'nullable', 'string', 'max:8'],
            'keywords.*.device'   => ['sometimes', 'nullable', 'in:' . implode(',', RankKeyword::DEVICES)],
            'keywords.*.location' => ['sometimes', 'nullable', 'string', 'max:64'],
            'keywords.*.engine'   => ['sometimes', 'nullable', 'in:' . implode(',', RankKeyword::ENGINES)],
        ]);

        // Normalize every item into its create payload + uniqueness hash
        // BEFORE the quota math, so duplicates never consume budget.
        $items = [];
        $seenHashes = [];

        foreach ($validated['keywords'] as $item) {
            $keyword = trim($item['keyword']);
            $engine = $item['engine'] ?? 'google';
            $country = $item['country'] ?? null;
            $language = $item['language'] ?? null;
            $device = $item['device'] ?? 'desktop';
            $location = $item['location'] ?? null;

            if ($keyword === '') {
                continue;
            }

            $hash = RankKeyword::makeConfigHash($keyword, $engine, $country, $language, $device, $location);

            if (isset($seenHashes[$hash])) {
                continue; // same config twice in one request
            }

            $seenHashes[$hash] = true;

            $items[] = [
                'hash'   => $hash,
                'attributes' => [
                    'site_id'     => $site->id,
                    'keyword'     => $keyword,
                    'engine'      => $engine,
                    'country'     => $country,
                    'language'    => $language,
                    'device'      => $device,
                    'location'    => $location,
                    'active'      => true,
                    'config_hash' => $hash,
                ],
            ];
        }

        $existingRows = RankKeyword::where('site_id', $site->id)
            ->whereIn('config_hash', array_column($items, 'hash'))
            ->get(['id', 'config_hash', 'active']);

        $existingHashes = $existingRows->pluck('config_hash')->all();

        // Re-POSTing a deactivated keyword re-tracks it (soft-deleted rows
        // keep their observation history, so reactivate > duplicate-skip).
        RankKeyword::where('site_id', $site->id)
            ->whereIn('config_hash', $existingRows->where('active', false)->pluck('config_hash'))
            ->update(['active' => true]);
        $reactivated = $existingRows->where('active', false)->count();

        $fresh = array_values(array_filter(
            $items,
            fn ($item) => ! in_array($item['hash'], $existingHashes, true),
        ));

        $org = $site->organization;
        $limit = (int) ($org->plan?->limit('tracked_keywords') ?? 0);
        $used = RankKeyword::where('site_id', $site->id)->where('active', true)->count();

        if ($used + count($fresh) > $limit) {
            // UsageService::limitError's envelope, with the tracker's own
            // code, so the SPA's existing limit handling lights up.
            return response()->json([
                'code'    => 'tracked_keyword_limit_reached',
                'limit'   => ['key' => 'tracked_keywords', 'max' => $limit, 'used' => $used],
                'message' => 'Your plan limit for tracked keywords has been reached. Upgrade to continue.',
            ], 422);
        }

        foreach ($fresh as $item) {
            // config_hash is pinned by direct assignment so the (site,
            // config_hash) uniqueness never depends on the engine model's
            // $fillable drift.
            $row = new RankKeyword($item['attributes']);
            $row->config_hash = $item['hash'];
            $row->save();
        }

        return response()->json([
            'created' => count($fresh),
            'skipped' => count($validated['keywords']) - count($fresh),
        ]);
    }

    /**
     * DELETE /sites/{site}/rank/keywords/{keyword} — stop tracking
     * (owner/admin). Soft: active=false, observations kept so history and
     * best-position survive a re-track.
     */
    public function destroyKeyword(Request $request, int $siteId, int $keyword): JsonResponse
    {
        $site = $this->site($request);

        $row = RankKeyword::where('site_id', $site->id)->find($keyword);

        if (! $row) {
            return response()->json(['message' => 'Tracked keyword not found.'], 404);
        }

        $row->update(['active' => false]);

        return response()->json(['ok' => true]);
    }

    /**
     * POST /sites/{site}/rank/keywords/{keyword}/check — queue one keyword
     * (owner/admin). Guarded: an unconfigured provider would make the job
     * die in the queue, and a same-day recheck would only burn provider
     * cost to re-observe a SERP the tracker already saw today.
     */
    public function checkKeyword(Request $request, int $siteId, int $keyword): JsonResponse
    {
        $site = $this->site($request);

        if (! $this->providerConfigured()) {
            return response()->json([
                'code'    => 'rank_provider_not_configured',
                'message' => 'No SERP provider is configured for rank tracking.',
            ], 422);
        }

        $row = RankKeyword::where('site_id', $site->id)->find($keyword);

        if (! $row) {
            return response()->json(['message' => 'Tracked keyword not found.'], 404);
        }

        $tz = $this->timezone($site);

        if ($row->last_checked_at !== null
            && $row->last_checked_at->copy()->timezone($tz)->isSameDay(CarbonImmutable::today($tz))) {
            return response()->json([
                'code'    => 'already_checked_today',
                'message' => 'This keyword was already checked today.',
            ], 422);
        }

        RankCheckJob::dispatch($site->id, [$row->id]);

        return response()->json(['dispatched' => true]);
    }

    /**
     * POST /sites/{site}/rank/check — queue every active keyword
     * (owner/admin). No same-day guard here: the engine job decides per
     * keyword what a check is worth.
     */
    public function checkSite(Request $request): JsonResponse
    {
        $site = $this->site($request);

        if (! $this->providerConfigured()) {
            return response()->json([
                'code'    => 'rank_provider_not_configured',
                'message' => 'No SERP provider is configured for rank tracking.',
            ], 422);
        }

        $activeCount = RankKeyword::where('site_id', $site->id)->where('active', true)->count();

        RankCheckJob::dispatch($site->id, null);

        return response()->json(['dispatched' => true, 'keywords' => $activeCount]);
    }

    /**
     * GET /sites/{site}/rank/keywords/{keyword}/history — the keyword plus
     * its last 90 observations for the trend chart and the check table.
     */
    public function history(Request $request, int $siteId, int $keyword): JsonResponse
    {
        $site = $this->site($request);

        $row = RankKeyword::where('site_id', $site->id)->find($keyword);

        if (! $row) {
            return response()->json(['message' => 'Tracked keyword not found.'], 404);
        }

        $observations = RankObservation::where('keyword_id', $row->id)
            ->orderByDesc('checked_at')
            ->orderByDesc('id')
            ->limit(self::HISTORY_LIMIT)
            ->get(['checked_at', 'position', 'ranking_url', 'cost_units']);

        return response()->json([
            'keyword' => [
                'id'              => $row->id,
                'keyword'         => $row->keyword,
                'engine'          => $row->engine,
                'country'         => $row->country,
                'device'          => $row->device,
                'location'        => $row->location,
                'last_checked_at' => $row->last_checked_at,
            ],
            'history' => collect($observations)->map(fn ($observation) => [
                'checked_at'   => $observation->checked_at,
                'position'     => $observation->position === null ? null : (int) $observation->position,
                'ranking_url'  => $observation->ranking_url,
                'cost_units'   => $observation->cost_units === null ? null : (float) $observation->cost_units,
            ])->values(),
        ]);
    }

    /** GET /sites/{site}/rank/suggestions — untracked GSC queries to track. */
    public function suggestions(Request $request): JsonResponse
    {
        $site = $this->site($request);

        $limit = min(100, max(1, (int) $request->query('limit', 20)));

        return response()->json([
            'suggestions' => app(RankSuggestions::class)->suggest($site, $limit),
        ]);
    }

    /**
     * POST /sites/{site}/rank/suggestions/confirm — turn confirmed
     * suggestions into tracked keywords (owner/admin). Fills the
     * tracked_keywords quota and stops there; over-quota queries are left
     * untracked rather than failing the whole confirmation.
     */
    public function confirmSuggestions(Request $request): JsonResponse
    {
        $site = $this->site($request);

        $validated = $request->validate([
            'queries'       => ['required', 'array', 'min:1', 'max:100'],
            'queries.*'     => ['required', 'string', 'max:200'],
        ]);

        $created = app(RankSuggestions::class)->confirm($site, $validated['queries']);

        return response()->json(['created' => $created]);
    }

    /**
     * Distinct GSC queries of the last 28 days with summed impressions,
     * keyed by lowercased query — the impressions_28d lookup table for
     * the keyword rows.
     *
     * @return array<string, int>
     */
    private function impressions28d(Site $site): array
    {
        return SeoQueryDaily::query()
            ->where('site_id', $site->id)
            ->where('date', '>=', now()->subDays(27)->toDateString())
            ->groupBy('query')
            ->selectRaw('query, sum(impressions) as impressions')
            ->get()
            ->mapWithKeys(fn ($row) => [mb_strtolower(trim($row->query)) => (int) $row->impressions])
            ->all();
    }

    /** The SERP provider is usable when DataForSEO credentials exist. */
    private function providerConfigured(): bool
    {
        return filled(config('services.dataforseo.login'))
            && filled(config('services.dataforseo.password'));
    }

    private function timezone(Site $site): string
    {
        return $site->timezone ?: config('app.timezone', 'UTC');
    }

    /**
     * Offset cursor pagination over a query builder, in the
     * SearchExplorer shape: base64(JSON {"o": offset}) — a corrupted
     * cursor restarts pagination rather than 500ing.
     *
     * @return array{0: \Illuminate\Support\Collection, 1: array{meta: array}}
     */
    private function paginate($query, Request $request, array $columns): array
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', self::PER_PAGE_DEFAULT)));

        $cursor = $request->query('cursor');
        $offset = 0;
        if ($cursor !== null && $cursor !== '') {
            $decoded = json_decode((string) base64_decode($cursor, true), true);
            $offset = (is_array($decoded) && isset($decoded['o']) && (int) $decoded['o'] > 0)
                ? (int) $decoded['o']
                : 0;
        }

        $total = (clone $query)->toBase()->count();
        $rows = $query->offset($offset)->limit($perPage)->get($columns);
        $hasMore = $offset + $perPage < $total;

        return [$rows, ['meta' => [
            'next_cursor' => $hasMore ? base64_encode(json_encode(['o' => $offset + $perPage])) : null,
            'has_more'    => $hasMore,
            'total'       => $total,
        ]]];
    }

    private function site(Request $request): Site
    {
        return $request->attributes->get('site');
    }
}
