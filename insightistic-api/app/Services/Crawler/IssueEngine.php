<?php

namespace App\Services\Crawler;

use App\Models\CrawlIssue;
use App\Models\CrawlPage;
use App\Models\CrawlRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Issue taxonomy + cross-run lifecycle (docs/roadmap-2026/12-SITE-CRAWLER.md).
 *
 * Page-level issues are evaluated when the page is stored; link-level issues
 * and the open/resolved lifecycle pass run once per run in finalize().
 *
 * Lifecycle: one row per (site, fingerprint) with fingerprint = sha1(code|target).
 * Seeing it again updates last_seen_at / run_id / evidence (and reopens a
 * resolved row); not seeing an open issue in a finished run resolves it;
 * an ignored row is never touched again.
 */
class IssueEngine
{
    /** Pages below this word count are thin. */
    public const THIN_CONTENT_WORDS = 150;

    /** Evaluate one stored page (+ its inspection) and upsert its issues. */
    public function evaluate(CrawlRun $run, CrawlPage $page, ?array $inspection = null): void
    {
        $status = (int) ($page->status_code ?? 0);

        if ($status >= 300 && $status < 400) {
            $this->upsert($run, 'redirect', 'low', 'crawlability', $page->url, $page->id, [
                'status_code' => $status,
                'redirect_to' => $page->redirect_to,
            ]);

            return; // a redirect has no page content to judge
        }

        if ($status >= 400) {
            $this->upsert($run, 'http_error', $status >= 500 ? 'critical' : 'high', 'crawlability', $page->url, $page->id, [
                'status_code' => $status,
            ]);

            return;
        }

        if ($status < 200 || $status >= 300) {
            return; // no meaningful status — nothing to evaluate
        }

        // Only HTML documents have titles, meta descriptions, headings,
        // canonicals, body word counts, JSON-LD and image-alt semantics.
        // Keep non-HTML resources visible as crawled observations, but do not
        // manufacture HTML SEO defects for robots.txt, sitemap.xml, llms.txt,
        // PDFs, images, feeds or arbitrary downloads.
        if (! $this->isHtmlPage($page)) {
            return;
        }

        if ($page->robots_noindex) {
            $this->upsert($run, 'robots_noindex', 'low', 'crawlability', $page->url, $page->id, [
                'informational' => true, // intentional noindex, not a defect
            ]);
        }

        if (empty($page->title)) {
            $this->upsert($run, 'missing_title', 'high', 'on_page', $page->url, $page->id);
        }

        if (empty($page->meta_description)) {
            $this->upsert($run, 'missing_meta_description', 'medium', 'on_page', $page->url, $page->id);
        }

        if ($page->h1_count > 1) {
            $this->upsert($run, 'multiple_h1', 'low', 'on_page', $page->url, $page->id, [
                'h1_count' => $page->h1_count,
            ]);
        }

        if ($page->word_count < self::THIN_CONTENT_WORDS) {
            $this->upsert($run, 'thin_content', 'low', 'content', $page->url, $page->id, [
                'word_count' => $page->word_count,
                'threshold' => self::THIN_CONTENT_WORDS,
            ]);
        }

        // Only an indexable page is expected to declare a canonical.
        if (! $page->robots_noindex && empty($page->canonical_url)) {
            $this->upsert($run, 'canonical_missing', 'low', 'on_page', $page->url, $page->id);
        }

        if ($inspection !== null) {
            if (! empty($inspection['schema_error'])) {
                $this->upsert($run, 'schema_parse_error', 'low', 'structured_data', $page->url, $page->id, [
                    'message' => 'One or more JSON-LD blocks could not be parsed',
                ]);
            }

            $missingAlt = (int) ($inspection['image_missing_alt'] ?? 0);
            if ($missingAlt > 0) {
                $this->upsert($run, 'image_missing_alt', 'low', 'content', $page->url, $page->id, [
                    'missing' => $missingAlt,
                    'total' => (int) ($inspection['image_count'] ?? 0),
                ]);
            }
        }
    }

    /**
     * End-of-run pass: broken internal links, lifecycle
     * resolution, and the run's issues_found counter.
     */
    public function finalize(CrawlRun $run): int
    {
        $this->evaluateLinkIssues($run);
        $this->resolveUnseen($run);

        return CrawlIssue::query()->where('run_id', $run->id)->count();
    }

    /**
     * Internal links whose target page (crawled in this same run) is broken.
     * Redirecting targets already have one canonical page-level `redirect`
     * issue, so they are deliberately excluded here to avoid double-counting
     * the same finding under a second issue code.
     */
    private function evaluateLinkIssues(CrawlRun $run): void
    {
        $rows = DB::table('crawl_links as l')
            ->join('crawl_pages as tp', function ($join) {
                $join->on('tp.run_id', '=', 'l.run_id')
                    ->on('tp.url_hash', '=', 'l.target_url_hash');
            })
            ->where('l.run_id', $run->id)
            ->where('l.is_internal', true)
            ->where('tp.status_code', '>=', 400)
            ->get(['l.target_url_hash', 'l.target_url', 'l.source_page_id', 'tp.status_code']);

        $grouped = [];

        foreach ($rows as $row) {
            $kind = 'broken_internal_link';
            $grouped[$row->target_url_hash.'|'.$kind] = [
                'kind' => $kind,
                'target_url' => $row->target_url,
                'target_url_hash' => $row->target_url_hash,
                'page_id' => $row->source_page_id,
                'status_code' => (int) $row->status_code,
            ];
        }

        foreach ($grouped as $group) {
            $sources = DB::table('crawl_links')
                ->join('crawl_pages as sp', 'sp.id', '=', 'crawl_links.source_page_id')
                ->where('crawl_links.run_id', $run->id)
                ->where('crawl_links.target_url_hash', $group['target_url_hash'])
                ->distinct()
                ->limit(50)
                ->pluck('sp.url');

            $this->upsert(
                $run,
                $group['kind'],
                $group['kind'] === 'broken_internal_link' ? 'high' : 'low',
                'links',
                $group['target_url'],
                $group['page_id'],
                ['status_code' => $group['status_code'], 'sources' => $sources->all()]
            );
        }
    }

    private function isHtmlPage(CrawlPage $page): bool
    {
        $contentType = trim(strtolower((string) $page->content_type));

        // Null is the legacy value for rows created before content_type was
        // introduced. Treat those as HTML for backward-compatible issue
        // lifecycle behavior; every newly crawled response is classified.
        return $contentType === ''
            || preg_match('/(?:^|;)\s*(?:text\/html|application\/xhtml\+xml)\b/i', $contentType) === 1;
    }

    /** Open issues of this site not seen in this run become resolved. Ignored rows stay ignored. */
    private function resolveUnseen(CrawlRun $run): void
    {
        CrawlIssue::query()
            ->where('site_id', $run->site_id)
            ->where('status', CrawlIssue::STATUS_OPEN)
            ->whereNotIn('fingerprint', function ($query) use ($run) {
                $query->select('fingerprint')
                    ->from('crawl_issues')
                    ->where('run_id', $run->id);
            })
            ->update([
                'status' => CrawlIssue::STATUS_RESOLVED,
                'resolved_at' => Carbon::now(),
            ]);
    }

    /**
     * Fingerprint-keyed upsert. first_seen_at survives updates; ignored rows
     * are returned untouched; a resolved row that reappears reopens.
     */
    private function upsert(
        CrawlRun $run,
        string $code,
        string $severity,
        string $category,
        string $targetUrl,
        ?int $pageId,
        ?array $evidence = null,
    ): void {
        $fingerprint = CrawlIssue::fingerprint($code, $targetUrl);

        $issue = CrawlIssue::query()
            ->where('site_id', $run->site_id)
            ->where('fingerprint', $fingerprint)
            ->first();

        if ($issue !== null && $issue->status === CrawlIssue::STATUS_IGNORED) {
            return; // user decision wins, forever
        }

        $now = Carbon::now();

        if ($issue === null) {
            CrawlIssue::query()->create([
                'site_id' => $run->site_id,
                'run_id' => $run->id,
                'issue_code' => $code,
                'severity' => $severity,
                'category' => $category,
                'fingerprint' => $fingerprint,
                'target_url' => $targetUrl,
                'page_id' => $pageId,
                'evidence_json' => $evidence,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'status' => CrawlIssue::STATUS_OPEN,
            ]);

            return;
        }

        $issue->fill([
            'run_id' => $run->id,
            'issue_code' => $code,
            'severity' => $severity,
            'category' => $category,
            'target_url' => $targetUrl,
            'page_id' => $issue->page_id ?? $pageId,
            'evidence_json' => $evidence,
            'status' => CrawlIssue::STATUS_OPEN,
            'resolved_at' => null,
            'last_seen_at' => $now,
        ]);
        $issue->save();
    }
}
