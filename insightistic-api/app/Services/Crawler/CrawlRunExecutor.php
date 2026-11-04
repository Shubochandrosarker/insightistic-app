<?php

namespace App\Services\Crawler;

use App\Jobs\CrawlUrlJob;
use App\Models\CrawlFrontier;
use App\Models\CrawlLink;
use App\Models\CrawlPage;
use App\Models\CrawlRun;
use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

/**
 * Drives one crawl run end to end. CrawlSiteJob calls startRun() (run row +
 * frontier seeding + initial worker chain); each CrawlUrlJob calls
 * processNext(), which atomically claims the next frontier URL, fetches it
 * politely, records the page/links/issues, and either hands the baton to a
 * successor job or finalizes the run.
 *
 * Budget + concurrency mechanics:
 * - every processed URL increments pages_crawled; no URL is fetched once the
 *   budget is hit, and link discovery stops at max_pages * 2 frontier rows
 * - frontier rows are claimed with SELECT ... FOR UPDATE SKIP LOCKED, so any
 *   number of queue workers can share a run without double-fetching; each
 *   processed URL chains exactly one successor, so chains drain the frontier
 *   and the last chain standing finalizes the run
 * - per-URL failures are isolated: a throwing fetch marks the frontier row
 *   failed and the chain continues
 */
class CrawlRunExecutor
{
    public const USER_AGENT = 'InsightisticBot/1.0 (+https://insightistic.com/bot)';

    public const DEFAULT_MAX_DEPTH = 4;

    public const DEFAULT_DELAY_MS = 250;

    /** Robots-declared Crawl-delay is clamped to this window. */
    public const MIN_CRAWL_DELAY_MS = 250;

    public const MAX_CRAWL_DELAY_MS = 2000;

    /** Initial CrawlUrlJob batch — up to N chains work a run in parallel. */
    public const INITIAL_CHAINS = 3;

    /** Link discovery hard stop: frontier may grow to max_pages * 2 rows. */
    public const DISCOVERY_FACTOR = 2;

    public function __construct(
        private IssueEngine $issues,
        private HtmlInspector $inspector,
        private SitemapDiscovery $sitemaps,
    ) {
    }

    // ------------------------------------------------------------------
    // Run start
    // ------------------------------------------------------------------

    /**
     * @throws InvalidArgumentException when the site has no usable domain
     */
    public function startRun(
        Site $site,
        int $maxPages,
        string $trigger,
        int $delayMs = self::DEFAULT_DELAY_MS,
        ?int $runId = null,
    ): CrawlRun
    {
        $baseUrl = self::siteBaseUrl($site);

        $attributes = [
            'status' => 'running',
            'trigger' => in_array($trigger, ['manual', 'schedule'], true) ? $trigger : 'manual',
            'started_at' => Carbon::now(),
            'max_pages' => max(1, $maxPages),
            'config_json' => [
                'base_url' => $baseUrl,
                'max_depth' => self::DEFAULT_MAX_DEPTH,
                'delay_ms' => max(0, $delayMs),
                'respect_robots' => true,
                'user_agent' => self::USER_AGENT,
            ],
        ];

        if ($runId !== null) {
            $run = CrawlRun::query()
                ->where('site_id', $site->id)
                ->where('status', 'pending')
                ->findOrFail($runId);
            $run->update($attributes);
        } else {
            $run = CrawlRun::query()->create($attributes + ['site_id' => $site->id]);
        }

        try {
            $robots = RobotsTxt::forHost($baseUrl, self::USER_AGENT);
            $sitemapUrls = $this->sitemaps->discover($baseUrl, $robots->sitemapUrls(), self::USER_AGENT);

            $pending = $this->seedFrontier($run, [$baseUrl.'/', ...$sitemapUrls]);

            for ($i = 0; $i < min(self::INITIAL_CHAINS, $pending); $i++) {
                CrawlUrlJob::dispatch($run->id);
            }
        } catch (Throwable $e) {
            $run->update([
                'status' => 'failed',
                'error_summary' => $e->getMessage(),
                'completed_at' => Carbon::now(),
            ]);
        }

        return $run;
    }

    // ------------------------------------------------------------------
    // One URL
    // ------------------------------------------------------------------

    /**
     * Claim and process the next frontier URL, then decide the chain step.
     *
     * @return string 'continue' (dispatch a successor) | 'stop'
     */
    public function processNext(CrawlRun $run): string
    {
        $run->refresh();

        if ($run->status !== 'running') {
            return 'stop';
        }

        $row = $this->claimNext($run);

        if ($row === null) {
            // Nothing claimable: either other chains are still in flight, or
            // this is the last one and the run can be finalized.
            if ($this->frontierCount($run, CrawlFrontier::STATUS_CRAWLING) === 0) {
                $this->finalizeRun($run);
            }

            return 'stop';
        }

        $baseUrl = (string) $run->config('base_url');
        $robots = RobotsTxt::forHost($baseUrl, self::USER_AGENT);

        $path = (string) (parse_url($row->url, PHP_URL_PATH) ?: '/');
        $query = parse_url($row->url, PHP_URL_QUERY);

        if ($run->config('respect_robots', true) && ! $robots->isAllowed($path.($query !== null ? '?'.$query : ''))) {
            $this->finishFrontierRow($row->id, CrawlFrontier::STATUS_SKIPPED, 'robots_disallowed');

            return $this->nextStep($run);
        }

        $delayMs = $this->effectiveDelayMs($run, $robots);
        if ($delayMs > 0) {
            usleep($delayMs * 1000);
        }

        try {
            $startedAt = hrtime(true);

            $response = Http::withHeaders([
                'User-Agent' => self::USER_AGENT,
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            ])
                ->timeout(10)
                // Observe redirects instead of following them: the redirect
                // itself is recorded data (crawl_pages.redirect_to).
                ->withOptions(['allow_redirects' => false])
                ->get($row->url);

            $responseMs = (int) ((hrtime(true) - $startedAt) / 1e6);

            $this->storeResponse($run, $row, $response, $responseMs, $robots);
        } catch (Throwable $e) {
            // A failed URL is data, not a retry: isolate and keep crawling.
            $this->finishFrontierRow($row->id, CrawlFrontier::STATUS_FAILED, mb_substr($e->getMessage(), 0, 500));
        }

        CrawlRun::query()->where('id', $run->id)->increment('pages_crawled');

        return $this->nextStep($run);
    }

    // ------------------------------------------------------------------
    // Run finalization
    // ------------------------------------------------------------------

    public function finalizeRun(CrawlRun $run): void
    {
        DB::transaction(function () use ($run) {
            // Serialize concurrent finalizers; the loser sees a non-running row.
            $fresh = CrawlRun::query()->where('id', $run->id)->lockForUpdate()->first();

            if ($fresh === null || $fresh->status !== 'running') {
                return;
            }

            // Chains that died mid-flight (worker crash): not coming back.
            DB::table('crawl_frontier')
                ->where('run_id', $fresh->id)
                ->where('status', CrawlFrontier::STATUS_CRAWLING)
                ->update(['status' => CrawlFrontier::STATUS_FAILED, 'error' => 'interrupted']);

            $issuesFound = $this->issues->finalize($fresh);

            // Phase 8 change monitoring (doc 12 §Change monitoring): diff
            // this run against the previous completed run now that the issue
            // lifecycle has settled. Separate service — must never be able
            // to fail an otherwise complete crawl.
            // Nested transaction = SAVEPOINT. On PostgreSQL a failed
            // statement aborts the whole transaction (25P02), so catching the
            // PHP exception alone is not enough: roll back to the savepoint.
            try {
                DB::transaction(fn () => app(ChangeDetection::class)->detect($fresh, $fresh->site));
            } catch (Throwable $e) {
                logger()->warning('ChangeDetection failed for run '.$fresh->id.': '.$e->getMessage());
            }

            $fresh->forceFill([
                'status' => 'completed',
                'completed_at' => Carbon::now(),
                'pages_discovered' => DB::table('crawl_frontier')->where('run_id', $fresh->id)->count(),
                'issues_found' => $issuesFound,
            ])->save();

            // Funnel event (spec §33) — the run truly finished.
            try {
                DB::transaction(fn () => app(\App\Services\Analytics\AttributionService::class)->event(
                    'crawler_completed',
                    $fresh->site?->organization,
                    null,
                    [
                        'domain'     => $fresh->site?->normalized_domain,
                        'trigger'    => $fresh->trigger,
                        'page_count' => $fresh->pages_crawled,
                    ],
                ));
            } catch (Throwable) {
            }
        });
    }

    /**
     * Restart a chain whose hop died (worker killed, attempts exhausted).
     * Rows left 'crawling' by the dead hop are returned to the frontier.
     */
    public function recoverChain(CrawlRun $run): void
    {
        DB::table('crawl_frontier')
            ->where('run_id', $run->id)
            ->where('status', CrawlFrontier::STATUS_CRAWLING)
            ->update(['status' => CrawlFrontier::STATUS_PENDING]);

        if ($this->frontierCount($run, CrawlFrontier::STATUS_PENDING) > 0 && $run->pages_crawled < $run->max_pages) {
            CrawlUrlJob::dispatch($run->id);

            return;
        }

        $this->nextStep($run);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /** Atomic claim of the next pending frontier row (SELECT FOR UPDATE SKIP LOCKED). */
    private function claimNext(CrawlRun $run): ?object
    {
        return DB::transaction(function () use ($run) {
            $row = DB::table('crawl_frontier')
                ->where('run_id', $run->id)
                ->where('status', CrawlFrontier::STATUS_PENDING)
                ->orderBy('id')
                ->lock('FOR UPDATE SKIP LOCKED')
                ->first();

            if ($row !== null) {
                DB::table('crawl_frontier')
                    ->where('id', $row->id)
                    ->update(['status' => CrawlFrontier::STATUS_CRAWLING]);
            }

            return $row;
        });
    }

    /**
     * Chain decision after a URL was processed (or skipped by robots).
     * 'continue' dispatches one successor job; the last chain standing
     * finalizes the run either by draining the frontier or by exhausting
     * the page budget (leftover pending rows are skipped).
     */
    private function nextStep(CrawlRun $run): string
    {
        $run->refresh();

        if ($run->status !== 'running') {
            return 'stop';
        }

        $pending = $this->frontierCount($run, CrawlFrontier::STATUS_PENDING);
        $crawling = $this->frontierCount($run, CrawlFrontier::STATUS_CRAWLING);

        if ($run->pages_crawled >= $run->max_pages) {
            if ($crawling === 0) {
                DB::table('crawl_frontier')
                    ->where('run_id', $run->id)
                    ->where('status', CrawlFrontier::STATUS_PENDING)
                    ->update(['status' => CrawlFrontier::STATUS_SKIPPED, 'error' => 'budget_exhausted']);

                $this->finalizeRun($run);
            }

            return 'stop';
        }

        if ($pending > 0) {
            return 'continue';
        }

        if ($crawling === 0) {
            $this->finalizeRun($run);
        }

        return 'stop';
    }

    private function storeResponse(CrawlRun $run, object $row, $response, int $responseMs, RobotsTxt $robots): void
    {
        $status = $response->status();
        $body = (string) $response->body();
        $contentType = (string) $response->header('Content-Type');
        $location = $response->header('Location');

        $isHtml = $status >= 200 && $status < 300 && str_contains(strtolower($contentType), 'text/html');

        $page = CrawlPage::query()->firstOrCreate(
            ['run_id' => $run->id, 'url_hash' => $row->url_hash],
            [
                'site_id' => $run->site_id,
                'url' => $row->url,
                'status_code' => $status,
                'redirect_to' => $this->absoluteRedirectTarget($row->url, $status, $location),
                'response_ms' => $responseMs,
                'size_bytes' => strlen($body),
                'created_at' => Carbon::now(),
            ]
        );

        // A redirect at the seed (non-www → www, http → https) must not dead-end
        // the run: the target joins the frontier at the same depth, budget-capped
        // like discovery.
        if (in_array($status, [301, 302, 307, 308], true) && $page->redirect_to) {
            $siteHost = strtolower((string) parse_url((string) $run->config('base_url'), PHP_URL_HOST));

            if (self::isSameHost($page->redirect_to, $siteHost) && $this->frontierTotal($run) < $run->max_pages * self::DISCOVERY_FACTOR) {
                CrawlFrontier::query()->insertOrIgnore([
                    'run_id'     => $run->id,
                    'site_id'    => $run->site_id,
                    'url_hash'   => sha1($page->redirect_to),
                    'url'        => mb_substr($page->redirect_to, 0, 1000),
                    'depth'      => $row->depth,
                    'status'     => CrawlFrontier::STATUS_PENDING,
                    'created_at' => Carbon::now(),
                ]);
            }
        }

        if ($isHtml) {
            $inspection = $this->inspector->inspect(
                $row->url,
                $body,
                $response->header('X-Robots-Tag')
            );

            $page->fill([
                'indexable' => ! $inspection['robots_noindex'],
                'robots_noindex' => $inspection['robots_noindex'],
                'canonical_url' => $inspection['canonical_url'],
                'title' => $inspection['title'],
                'meta_description' => $inspection['meta_description'],
                'h1_count' => $inspection['h1_count'],
                'word_count' => $inspection['word_count'],
                'content_hash' => $inspection['content_hash'],
                'schema_types_json' => empty($inspection['schema_types']) ? null : array_values($inspection['schema_types']),
            ]);
            $page->save();

            $this->storeLinksAndDiscover($run, $row, $page, $inspection);
        }

        $this->issues->evaluate($run, $page, $inspection ?? null);

        // 2xx/3xx fetched fine (3xx recorded as data); 4xx/5xx are failures.
        $frontierStatus = $status >= 400 ? CrawlFrontier::STATUS_FAILED : CrawlFrontier::STATUS_DONE;
        $this->finishFrontierRow(
            $row->id,
            $frontierStatus,
            $status >= 400 ? 'HTTP '.$status : null
        );
    }

    /** Persist the page's links and enqueue new same-host URLs. */
    private function storeLinksAndDiscover(CrawlRun $run, object $row, CrawlPage $page, array $inspection): void
    {
        $siteHost = strtolower((string) parse_url((string) $run->config('base_url'), PHP_URL_HOST));
        $maxDepth = (int) $run->config('max_depth', self::DEFAULT_MAX_DEPTH);
        $now = Carbon::now();

        $links = [];
        $discovered = [];

        foreach ($inspection['links'] ?? [] as $link) {
            $target = self::normalizeUrl($link['href']);

            if ($target === null) {
                continue;
            }

            $targetHash = sha1($target);
            $internal = self::isSameHost($target, $siteHost);

            $links[] = [
                'run_id' => $run->id,
                'site_id' => $run->site_id,
                'source_page_id' => $page->id,
                'target_url_hash' => $targetHash,
                'target_url' => mb_substr($target, 0, 1000),
                'is_internal' => $internal,
                'is_nofollow' => (bool) $link['nofollow'],
                'anchor' => $link['anchor'],
                'created_at' => $now,
            ];

            // Discovery: same-host only, depth budget, frontier size cap
            // (committed rows AND rows queued in this batch), and never a
            // URL this run already knows about.
            if (
                $internal
                && $row->depth < $maxDepth
                && ! isset($discovered[$targetHash])
                && ($this->frontierTotal($run) + count($discovered)) < $run->max_pages * self::DISCOVERY_FACTOR
            ) {
                $discovered[$targetHash] = [
                    'run_id' => $run->id,
                    'site_id' => $run->site_id,
                    'url_hash' => $targetHash,
                    'url' => mb_substr($target, 0, 1000),
                    'depth' => $row->depth + 1,
                    'status' => CrawlFrontier::STATUS_PENDING,
                    'created_at' => $now,
                ];
            }
        }

        foreach (array_chunk($links, 500) as $chunk) {
            DB::table('crawl_links')->insertOrIgnore($chunk);
        }

        foreach (array_chunk(array_values($discovered), 500) as $chunk) {
            DB::table('crawl_frontier')->insertOrIgnore($chunk); // unique(run, url_hash) guards races
        }
    }

    /** Homepage + sitemap URLs, deduplicated, capped at the page budget. */
    private function seedFrontier(CrawlRun $run, array $urls): int
    {
        $siteHost = strtolower((string) parse_url((string) $run->config('base_url'), PHP_URL_HOST));
        $rows = [];
        $now = Carbon::now();

        foreach ($urls as $url) {
            if (count($rows) >= $run->max_pages) {
                break;
            }

            $normalized = self::normalizeUrl($url);

            if ($normalized === null || ! self::isSameHost($normalized, $siteHost)) {
                continue;
            }

            $hash = sha1($normalized);
            $rows[$hash] ??= [
                'run_id' => $run->id,
                'site_id' => $run->site_id,
                'url_hash' => $hash,
                'url' => $normalized,
                'depth' => 0,
                'status' => CrawlFrontier::STATUS_PENDING,
                'created_at' => $now,
            ];
        }

        foreach (array_chunk(array_values($rows), 500) as $chunk) {
            DB::table('crawl_frontier')->insertOrIgnore($chunk);
        }

        return $this->frontierCount($run, CrawlFrontier::STATUS_PENDING);
    }

    private function finishFrontierRow(int $rowId, string $status, ?string $error = null): void
    {
        DB::table('crawl_frontier')->where('id', $rowId)->update([
            'status' => $status,
            'error' => $error,
        ]);
    }

    private function frontierCount(CrawlRun $run, string $status): int
    {
        return DB::table('crawl_frontier')->where('run_id', $run->id)->where('status', $status)->count();
    }

    private function frontierTotal(CrawlRun $run): int
    {
        return DB::table('crawl_frontier')->where('run_id', $run->id)->count();
    }

    /** Config delay, raised to the robots Crawl-delay clamped into [250, 2000]ms. */
    private function effectiveDelayMs(CrawlRun $run, RobotsTxt $robots): int
    {
        $configured = (int) $run->config('delay_ms', self::DEFAULT_DELAY_MS);
        $declared = $robots->crawlDelayMs();

        if ($declared === null) {
            return $configured;
        }

        $declared = max(self::MIN_CRAWL_DELAY_MS, min(self::MAX_CRAWL_DELAY_MS, $declared));

        return max($configured, $declared);
    }

    private function absoluteRedirectTarget(string $url, int $status, ?string $location): ?string
    {
        if ($status < 300 || $status >= 400 || $location === null || $location === '') {
            return null;
        }

        return (new HtmlInspector)->absolute($url, trim($location)) ?? mb_substr(trim($location), 0, 1000);
    }

    // ------------------------------------------------------------------
    // URL normalization helpers
    // ------------------------------------------------------------------

    /**
     * Canonical crawl key: lowercase scheme+host, default ports removed,
     * fragments dropped, redundant trailing slashes collapsed. Query strings
     * are preserved verbatim — different query, different page (we do not
     * want to merge meaningful URLs), discovery caps stop parameter blowups.
     */
    public static function normalizeUrl(string $url): ?string
    {
        $parts = parse_url(trim($url));

        if ($parts === false || ! isset($parts['host'])) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower($parts['host']);
        $port = $parts['port'] ?? null;

        if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
            $port = null;
        }

        $path = $parts['path'] ?? '/';
        $path = $path === '' ? '/' : preg_replace('/\/+$/', '/', $path);

        return $scheme.'://'.$host.($port !== null ? ':'.$port : '').$path.(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    /** Same host, tolerating the www. prefix in either direction. */
    public static function isSameHost(string $url, string $siteHost): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host === '' || $siteHost === '') {
            return false;
        }

        $bare = fn (string $h): string => preg_replace('/^www\./', '', $h) ?? $h;

        return $host === $siteHost || $bare($host) === $bare($siteHost);
    }

    /** Site base URL from its domain field (https assumed when bare). */
    public static function siteBaseUrl(Site $site): string
    {
        $domain = trim((string) $site->domain);

        if ($domain === '') {
            throw new InvalidArgumentException('Site ['.$site->id.'] has no domain to crawl.');
        }

        if (! preg_match('#^https?://#i', $domain)) {
            $domain = 'https://'.$domain;
        }

        return rtrim($domain, '/');
    }
}
