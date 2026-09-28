<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\CrawlSiteJob;
use App\Models\CrawlChange;
use App\Models\CrawlIssue;
use App\Models\CrawlLink;
use App\Models\CrawlPage;
use App\Models\CrawlRun;
use App\Models\Organization;
use App\Models\Site;
use App\Services\Crawler\ChangeDetection;
use App\Services\Crawler\CrawlHealthScore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Site crawler API (Phase 7, doc 12). Runs are queued (CrawlSiteJob —
 * owned by the crawler workstream, which also owns the crawl_* models);
 * this controller is the gate in front of them: who may start a crawl,
 * how much of the plan's monthly page budget a run may consume, and the
 * read surfaces over runs/pages/links/issues once results exist.
 *
 * QUOTA DESIGN (crawl_pages_monthly): the budget is ORG-wide, not
 * per-site — the plan entitlement attaches to the organization, so a
 * Growth org with ten sites shares one 50k-page/month pool. There is no
 * usage_counters dimension for crawl pages, so "used" is derived from the
 * ledger that already exists: sum(max_pages) of every crawl_run the org
 * created this calendar month, any status (a failed run still burned
 * crawl capacity). requested + used > limit ⇒ 422 crawl_limit_reached.
 * max_pages is clamped, not rejected: a client asking for 1M pages gets
 * min(1000, plan limit), the same tolerant range handling as the
 * analytics surfaces' window params.
 *
 * COORDINATION (workstream E1): App\Models\{CrawlRun,CrawlPage,CrawlLink,
 * CrawlIssue} and App\Jobs\CrawlSiteJob are created by the parallel
 * crawler engine workstream. Manual runs are persisted as pending before
 * dispatch, so clients receive a stable run_id while the job waits in queue.
 */
class CrawlController extends Controller
{
    /** Hard ceiling on a single run, before the plan limit clamps further. */
    private const MAX_PAGES_PER_RUN = 1000;

    /** The pagination page size every listing here shares. */
    private const PER_PAGE_DEFAULT = 50;

    /** GET /sites/{site}/crawl/run — queue a manual crawl (owner/admin). */
    public function run(Request $request): JsonResponse
    {
        $site = $this->site($request);

        $validated = $request->validate([
            'max_pages' => ['sometimes', 'integer', 'min:1'],
        ]);

        // Lock the organization while reserving its org-wide page budget.
        // This also serializes same-site requests, so two rapid clicks cannot
        // both pass the pending/running check before either run is recorded.
        $result = DB::transaction(function () use ($site, $validated) {
            $org = Organization::query()
                ->whereKey($site->organization_id)
                ->lockForUpdate()
                ->firstOrFail();
            $org->load('plan');
            $site->setRelation('organization', $org);

            $inFlight = CrawlRun::where('site_id', $site->id)
                ->whereIn('status', ['pending', 'running'])
                ->orderBy('id')
                ->first();

            if ($inFlight) {
                return response()->json([
                    'code'    => 'crawl_already_running',
                    'message' => 'A crawl is already in progress for this site.',
                    'run_id'  => $inFlight->id,
                ], 409);
            }

            $limit = (int) ($org->plan?->limit('crawl_pages_monthly') ?? 0);
            $maxPages = min(
                (int) ($validated['max_pages'] ?? self::MAX_PAGES_PER_RUN),
                self::MAX_PAGES_PER_RUN,
                max($limit, 0),
            );

            if ($limit <= 0 || $maxPages < 1) {
                return response()->json([
                    'code'    => 'crawl_limit_reached',
                    'limit'   => $limit,
                    'used'    => $this->monthlyUsed($site),
                    'message' => 'Your plan includes no crawl pages. Upgrade to run crawls.',
                ], 422);
            }

            $used = $this->monthlyUsed($site);
            if ($used + $maxPages > $limit) {
                return response()->json([
                    'code'    => 'crawl_limit_reached',
                    'limit'   => $limit,
                    'used'    => $used,
                    'message' => 'Monthly crawl page budget exhausted. Upgrade or wait for the next cycle.',
                ], 422);
            }

            // Persist the queued run before publishing the job. Its ID and
            // pending status are immediately visible to every audit tab.
            return CrawlRun::create([
                'site_id'   => $site->id,
                'status'    => 'pending',
                'trigger'   => 'manual',
                'max_pages' => $maxPages,
            ]);
        });

        if ($result instanceof JsonResponse) {
            return $result;
        }

        $run = $result;
        try {
            CrawlSiteJob::dispatch(
                $site->id,
                (int) $run->max_pages,
                'manual',
                \App\Services\Crawler\CrawlRunExecutor::DEFAULT_DELAY_MS,
                (int) $run->id,
            );
        } catch (Throwable $e) {
            // The reservation did not reach a worker, so it must not burn the
            // monthly crawl quota. Keep a failed record for support visibility.
            $run->update([
                'status'         => 'failed',
                'max_pages'      => 0,
                'error_summary'  => 'The crawl could not be queued. Please try again.',
                'completed_at'   => now(),
            ]);

            Log::error('Crawl job dispatch failed.', [
                'run_id' => $run->id,
                'site_id' => $site->id,
                'exception' => get_class($e),
            ]);

            return response()->json([
                'code'    => 'crawl_dispatch_failed',
                'run_id'  => $run->id,
                'message' => 'The crawl could not be queued. Please try again.',
            ], 503);
        }

        return response()->json([
            'dispatched' => true,
            'run_id'     => $run->id,
            'max_pages'  => $run->max_pages,
        ]);
    }

    /** GET /sites/{site}/crawl/runs — the 20 most recent runs. */
    public function runs(Request $request): JsonResponse
    {
        $site = $this->site($request);

        $runs = CrawlRun::where('site_id', $site->id)
            ->orderByDesc('id')
            ->limit(20)
            ->get([
                'id', 'status', 'trigger', 'started_at', 'completed_at',
                'max_pages', 'pages_discovered', 'pages_crawled',
                'issues_found', 'error_summary',
            ]);

        return response()->json(['runs' => $runs]);
    }

    /**
     * GET /sites/{site}/crawl/runs/{run} — one run + open issue summary.
     *
     * {site} must be declared before {run}: route parameters are injected
     * positionally (see AlertController), so a bare (Request, int $run)
     * would bind the *site* id into $run.
     */
    public function runShow(Request $request, int $siteId, int $run): JsonResponse
    {
        $site = $this->site($request);

        $row = CrawlRun::where('site_id', $site->id)->find($run);

        if (! $row) {
            return response()->json(['message' => 'Crawl run not found.'], 404);
        }

        $severityCounts = CrawlIssue::where('run_id', $row->id)
            ->where('status', 'open')
            ->selectRaw('severity, count(*) as n')
            ->groupBy('severity')
            ->pluck('n', 'severity');

        $topIssues = CrawlIssue::where('run_id', $row->id)
            ->where('status', 'open')
            ->orderByRaw("array_position(ARRAY['critical','high','medium','low']::text[], severity)")
            ->orderByDesc('last_seen_at')
            ->limit(10)
            ->get(['id', 'issue_code', 'severity', 'category', 'target_url']);

        return response()->json([
            'run'              => $row,
            'severity_counts'  => $severityCounts,
            'top_issues'       => $topIssues,
        ]);
    }

    /** GET /sites/{site}/crawl/pages?run_id=&status=error|ok&search=&per_page=&cursor= */
    public function pages(Request $request): JsonResponse
    {
        $site  = $this->site($request);
        $runId = $this->runId($request, $site);

        $query = CrawlPage::where('site_id', $site->id)
            ->when($runId !== null, fn ($q) => $q->where('run_id', $runId))
            ->when($request->query('status') === 'error', fn ($q) => $q->where('status_code', '>=', 400))
            ->when($request->query('status') === 'ok', fn ($q) => $q->where(function ($q) {
                $q->whereNull('status_code')->orWhere('status_code', '<', 400);
            }))
            ->when($request->filled('search'), fn ($q) => $q->where('url', 'like', '%' . $request->query('search') . '%'))
            ->orderBy('id');

        [$page, $meta] = $this->paginate($query, $request, [
            'id', 'run_id', 'url', 'status_code', 'indexable', 'title',
            'meta_description', 'word_count', 'h1_count', 'canonical_url', 'response_ms',
        ]);

        return response()->json(['data' => $page] + $meta);
    }

    /**
     * GET /sites/{site}/crawl/links?run_id=&type=broken|redirect|internal|external
     *
     * broken/redirect resolve the link's status through the TARGET page row
     * of the same run (joined on url hash); external links never have one,
     * so their status_code is null by construction.
     */
    public function links(Request $request): JsonResponse
    {
        $site  = $this->site($request);
        $runId = $this->runId($request, $site);

        $type = $request->query('type', 'broken');

        if (! in_array($type, ['broken', 'redirect', 'internal', 'external'], true)) {
            return response()->json(['message' => 'Unknown link type.'], 422);
        }

        $targetPages = 'crawl_pages as target';
        $sourcePages = 'crawl_pages as source';

        $query = CrawlLink::query()
            ->from('crawl_links')
            ->where('crawl_links.site_id', $site->id)
            ->when($runId !== null, fn ($q) => $q->where('crawl_links.run_id', $runId))
            // source_url via the source page row; target page supplies the
            // HTTP status (null for off-site targets that were never fetched).
            ->join("$sourcePages", 'source.id', '=', 'crawl_links.source_page_id')
            ->leftJoin("$targetPages", function ($join) {
                $join->on('target.url_hash', '=', 'crawl_links.target_url_hash')
                    ->on('target.run_id', '=', 'crawl_links.run_id');
            })
            ->where('crawl_links.is_internal', $type === 'external' ? false : true)
            ->when($type === 'broken', fn ($q) => $q->where('target.status_code', '>=', 400))
            ->when($type === 'redirect', fn ($q) => $q->whereBetween('target.status_code', [300, 399]))
            ->orderBy('crawl_links.id');

        [$page, $meta] = $this->paginate($query, $request, [
            'crawl_links.id', 'source.url as source_url', 'crawl_links.target_url',
            'target.status_code', 'crawl_links.anchor', 'crawl_links.is_nofollow',
        ]);

        return response()->json(['data' => $page] + $meta);
    }

    /** GET /sites/{site}/crawl/issues?status=&severity=&category=&per_page=&cursor= */
    public function issues(Request $request): JsonResponse
    {
        $site = $this->site($request);

        $status = $request->query('status', 'open');

        if (! in_array($status, ['open', 'resolved', 'ignored'], true)) {
            return response()->json(['message' => 'Unknown issue status.'], 422);
        }

        $query = CrawlIssue::where('site_id', $site->id)
            ->where('status', $status)
            ->when(in_array($request->query('severity'), CrawlHealthScore::SEVERITY_ORDER, true),
                fn ($q) => $q->where('severity', $request->query('severity')))
            ->when(array_key_exists($request->query('category') ?? '', CrawlHealthScore::CATEGORY_WEIGHTS),
                fn ($q) => $q->where('category', $request->query('category')))
            // Worst first, same ordering the health score uses.
            ->orderByRaw("array_position(ARRAY['critical','high','medium','low']::text[], severity)")
            ->orderByDesc('last_seen_at');

        [$page, $meta] = $this->paginate($query, $request, [
            'id', 'issue_code', 'severity', 'category', 'target_url',
            'evidence_json', 'first_seen_at', 'last_seen_at',
        ]);

        return response()->json(['data' => $page] + $meta);
    }

    /**
     * POST /sites/{site}/crawl/issues/{issue}/status — triage (owner/admin).
     * Positional injection again: {site} first, then {issue}.
     */
    public function issueStatus(Request $request, int $siteId, int $issue): JsonResponse
    {
        $site = $this->site($request);

        $validated = $request->validate([
            'status' => ['required', 'in:open,ignored'],
        ]);

        $row = CrawlIssue::where('site_id', $site->id)->find($issue);

        if (! $row) {
            return response()->json(['message' => 'Crawl issue not found.'], 404);
        }

        $row->update(['status' => $validated['status']]);

        return response()->json(['issue' => $row]);
    }

    /** GET /sites/{site}/crawl/health — weighted score + worst open issues. */
    public function health(Request $request): JsonResponse
    {
        $site = $this->site($request);

        // ?weighted=1 opts into the GSC-impact mode (Phase 8): penalties
        // scaled by each page's 28-day impressions. Default response is
        // untouched — the mode flag only surfaces for weighted requests.
        $weighted = $request->boolean('weighted');
        $result = app(CrawlHealthScore::class)->forSite($site, $weighted);

        $response = [
            'score'       => $result['score'],
            'breakdown'   => $result['breakdown'],
            'top_issues'  => collect($result['top_issues'])->map->only([
                'id', 'issue_code', 'severity', 'category', 'target_url',
                'first_seen_at', 'last_seen_at',
            ])->values(),
        ];

        if ($weighted) {
            $response['mode'] = $result['mode'];
        }

        return response()->json($response);
    }

    /**
     * GET /sites/{site}/crawl/changes?run_id=&severity=&per_page=&cursor=
     * (Phase 8 change monitoring) — the (page, field) diffs ChangeDetection
     * logged at run finalize. Defaults to the latest COMPLETED run, since
     * changes only exist for finished runs.
     */
    public function changes(Request $request): JsonResponse
    {
        $site = $this->site($request);

        $runId = $request->filled('run_id')
            ? (int) $request->query('run_id')
            : CrawlRun::where('site_id', $site->id)
                ->where('status', 'completed')
                ->orderByDesc('id')
                ->value('id');

        $query = app(ChangeDetection::class)
            ->historyFor($site, $runId)
            ->when(in_array($request->query('severity'), CrawlChange::SEVERITIES, true),
                fn ($q) => $q->where('severity', $request->query('severity')));

        [$page, $meta] = $this->paginate($query, $request, [
            'url', 'field', 'severity', 'old_value', 'new_value',
            'impressions', 'created_at',
        ]);

        return response()->json(['data' => $page] + $meta);
    }

    /**
     * Org-wide crawl budget consumed this calendar month: every run's
     * max_pages, regardless of outcome — a run that failed on page 3 still
     * reserved (and mostly burned) its allowance.
     */
    private function monthlyUsed(Site $site): int
    {
        $siteIds = $site->organization->sites()->pluck('id');

        return (int) CrawlRun::whereIn('site_id', $siteIds)
            ->whereBetween('created_at', [now()->startOfMonth(), now()])
            ->sum('max_pages');
    }

    /** ?run_id= or, by default, the site's most recent run. */
    private function runId(Request $request, Site $site): ?int
    {
        if ($request->filled('run_id')) {
            return (int) $request->query('run_id');
        }

        return CrawlRun::where('site_id', $site->id)->orderByDesc('id')->value('id');
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
