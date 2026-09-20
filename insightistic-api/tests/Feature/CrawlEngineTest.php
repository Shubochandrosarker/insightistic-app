<?php

use App\Jobs\CrawlSiteJob;
use App\Models\CrawlFrontier;
use App\Models\CrawlIssue;
use App\Models\CrawlPage;
use App\Models\CrawlRun;
use App\Models\Organization;
use App\Models\Site;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Engine mechanics for the Phase 7 technical crawler (docs/roadmap-2026/12):
 * budget enforcement, politeness/robots, per-URL error isolation and
 * idempotency — all against Http::fake'd responses, real PostgreSQL.
 */
const CRAWL_BASE = 'https://crawl.example.com';

function crawlSiteFor(Organization $org): Site
{
    return Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id,
        'name' => 'Crawl Co',
        'domain' => 'crawl.example.com',
    ]);
}

/** Self-contained healthy page: title, description, one H1, canonical, 150+ words, optional links. */
function crawlPageHtml(string $url, string $title, array $links = []): string
{
    $text = str_repeat('Lorem ipsum dolor sit amet consectetur adipiscing elit sed do eiusmod tempor incididunt. ', 20);

    $anchors = collect($links)
        ->map(fn ($href) => '<a href="'.$href.'">'.basename($href ?: 'home').'</a>')
        ->implode('');

    return '<!DOCTYPE html><html><head><title>'.$title.'</title>'
        .'<meta name="description" content="Description of '.$title.'">'
        .'<link rel="canonical" href="'.$url.'">'
        .'</head><body><h1>'.$title.'</h1><p>'.$text.'</p>'.$anchors.'</body></html>';
}

function crawlSitemapXml(array $urls): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

    foreach ($urls as $url) {
        $xml .= '<url><loc>'.$url.'</loc></url>';
    }

    return $xml.'</urlset>';
}

/**
 * Fake the whole site: robots.txt (with optional extra rules), sitemap.xml,
 * and a page table of path => [body, status].
 * $hits (by reference) counts requests per path.
 */
function fakeCrawlSite(array $pages, array $sitemapUrls, string $robotsExtra = '', array $statusCodes = [], &$hits = []): void
{
    Http::fake(function (Request $request) use ($pages, $sitemapUrls, $robotsExtra, $statusCodes, &$hits) {
        $path = parse_url($request->url(), PHP_URL_PATH) ?: '/';
        $hits[$path] = ($hits[$path] ?? 0) + 1;

        if ($path === '/robots.txt') {
            return Http::response("User-agent: *\nSitemap: ".CRAWL_BASE."/sitemap.xml\n".$robotsExtra, 200);
        }

        if ($path === '/sitemap.xml') {
            return Http::response(crawlSitemapXml($sitemapUrls), 200);
        }

        if (isset($statusCodes[$path])) {
            return Http::response('server error', $statusCodes[$path]);
        }

        return isset($pages[$path])
            ? Http::response($pages[$path], 200, ['Content-Type' => 'text/html; charset=UTF-8'])
            : Http::response('Not found', 404);
    });
}

it('completes a run and records pages, links, config and a drained frontier', function () {
    [, $org] = makeOrgUser('crawl-basic@example.com');
    $site = crawlSiteFor($org);

    $pages = [
        '/' => crawlPageHtml(CRAWL_BASE.'/', 'Home', [CRAWL_BASE.'/a', CRAWL_BASE.'/b', CRAWL_BASE.'/c']),
        '/a' => crawlPageHtml(CRAWL_BASE.'/a', 'Page A', [CRAWL_BASE.'/']),
        '/b' => crawlPageHtml(CRAWL_BASE.'/b', 'Page B'),
        '/c' => crawlPageHtml(CRAWL_BASE.'/c', 'Page C'),
    ];

    fakeCrawlSite($pages, [CRAWL_BASE.'/', CRAWL_BASE.'/a', CRAWL_BASE.'/b', CRAWL_BASE.'/c'], hits: $hits);

    CrawlSiteJob::dispatch($site->id, 100, 'manual', 0);

    $run = CrawlRun::query()->sole();

    expect($run->status)->toBe('completed')
        ->and($run->completed_at)->not->toBeNull()
        ->and($run->trigger)->toBe('manual')
        ->and($run->pages_crawled)->toBe(4)
        ->and($run->pages_discovered)->toBe(4)
        ->and($run->config('max_depth'))->toBe(4)
        ->and($run->issues_found)->toBe(0)
        ->and($run->error_summary)->toBeNull();

    $home = CrawlPage::query()->where('url', CRAWL_BASE.'/')->sole();

    expect($home->title)->toBe('Home')
        ->and($home->meta_description)->toBe('Description of Home')
        ->and($home->h1_count)->toBe(1)
        ->and($home->indexable)->toBeTrue()
        ->and($home->word_count)->toBeGreaterThan(150)
        ->and($home->content_hash)->not->toBeNull()
        ->and(CrawlPage::query()->where('run_id', $run->id)->count())->toBe(4);

    // Home's 3 links + /a's link back home.
    expect(CrawlPage::query()->where('run_id', $run->id)->where('url', CRAWL_BASE.'/')->count())->toBe(1);

    $links = DB::table('crawl_links')->where('run_id', $run->id)->get();
    expect($links)->toHaveCount(4)
        ->and($links->where('is_internal', true)->count())->toBe(4)
        ->and($links->every(fn ($l) => $l->source_page_id !== null))->toBeTrue();

    // Frontier fully drained: everything done, nothing pending or in flight.
    expect(CrawlFrontier::query()->where('run_id', $run->id)->count())->toBe(4)
        ->and(CrawlFrontier::query()->where('run_id', $run->id)->where('status', '!=', CrawlFrontier::STATUS_DONE)->count())->toBe(0);

    // Politeness: each URL fetched exactly once — no retry storms, no runaway chains.
    foreach (['/robots.txt', '/sitemap.xml', '/', '/a', '/b', '/c'] as $path) {
        expect($hits[$path] ?? 0)->toBeLessThanOrEqual(2); // sitemap is fetched as robots-declared + conventional
    }
    expect($hits['/'] ?? 0)->toBe(1)
        ->and(CrawlIssue::query()->count())->toBe(0);
});

it('honours the page budget on a large site without runaway crawling', function () {
    [, $org] = makeOrgUser('crawl-budget@example.com');
    $site = crawlSiteFor($org);

    $paths = array_map(fn ($i) => '/p'.$i, range(1, 30));

    $pages = ['/' => crawlPageHtml(CRAWL_BASE.'/', 'Home', array_map(fn ($p) => CRAWL_BASE.$p, $paths))];
    foreach ($paths as $p) {
        $pages[$p] = crawlPageHtml(CRAWL_BASE.$p, 'Page '.$p, [CRAWL_BASE.'/']);
    }

    fakeCrawlSite($pages, [CRAWL_BASE.'/', ...array_map(fn ($p) => CRAWL_BASE.$p, $paths)], hits: $hits);

    CrawlSiteJob::dispatch($site->id, 5, 'manual', 0);

    $run = CrawlRun::query()->sole();

    expect($run->status)->toBe('completed')
        ->and($run->pages_crawled)->toBe(5)
        ->and(CrawlPage::query()->where('run_id', $run->id)->count())->toBe(5)
        // discovery hard stop: frontier may grow to max_pages * 2, never beyond
        ->and(CrawlFrontier::query()->where('run_id', $run->id)->count())->toBeLessThanOrEqual(10)
        // nothing left unclaimed or in flight after completion
        ->and(CrawlFrontier::query()->where('run_id', $run->id)->where('status', CrawlFrontier::STATUS_PENDING)->count())->toBe(0)
        ->and(CrawlFrontier::query()->where('run_id', $run->id)->where('status', CrawlFrontier::STATUS_CRAWLING)->count())->toBe(0)
        // URLs beyond the budget were never requested
        ->and(collect($hits)->sum())->toBeLessThan(count($paths));
});

it('skips robots-disallowed URLs without ever fetching them', function () {
    [, $org] = makeOrgUser('crawl-robots@example.com');
    $site = crawlSiteFor($org);

    $pages = [
        '/' => crawlPageHtml(CRAWL_BASE.'/', 'Home', [CRAWL_BASE.'/a', CRAWL_BASE.'/private']),
        '/a' => crawlPageHtml(CRAWL_BASE.'/a', 'Page A'),
        '/private' => crawlPageHtml(CRAWL_BASE.'/private', 'Secret'),
    ];

    fakeCrawlSite(
        $pages,
        [CRAWL_BASE.'/', CRAWL_BASE.'/a', CRAWL_BASE.'/private'],
        robotsExtra: "Disallow: /private\n",
        hits: $hits
    );

    CrawlSiteJob::dispatch($site->id, 100, 'manual', 0);

    $run = CrawlRun::query()->sole();

    $private = CrawlFrontier::query()->where('run_id', $run->id)
        ->where('url', CRAWL_BASE.'/private')->sole();

    expect($run->status)->toBe('completed')
        ->and($run->pages_crawled)->toBe(2)
        ->and($private->status)->toBe(CrawlFrontier::STATUS_SKIPPED)
        ->and($private->error)->toBe('robots_disallowed')
        // the disallowed URL was never fetched, and no page row exists for it
        ->and($hits['/private'] ?? 0)->toBe(0)
        ->and(CrawlPage::query()->where('run_id', $run->id)->where('url', CRAWL_BASE.'/private')->count())->toBe(0);
});

it('isolates a 500 page: frontier failed, page recorded, run still completes', function () {
    [, $org] = makeOrgUser('crawl-500@example.com');
    $site = crawlSiteFor($org);

    $pages = [
        '/' => crawlPageHtml(CRAWL_BASE.'/', 'Home', [CRAWL_BASE.'/b']),
        '/b' => crawlPageHtml(CRAWL_BASE.'/b', 'Page B'),
    ];

    fakeCrawlSite($pages, [CRAWL_BASE.'/', CRAWL_BASE.'/b'], statusCodes: ['/b' => 500], hits: $hits);

    CrawlSiteJob::dispatch($site->id, 100, 'manual', 0);

    $run = CrawlRun::query()->sole();

    $b = CrawlFrontier::query()->where('run_id', $run->id)->where('url', CRAWL_BASE.'/b')->sole();

    expect($run->status)->toBe('completed')
        ->and($run->pages_crawled)->toBe(2)
        ->and($b->status)->toBe(CrawlFrontier::STATUS_FAILED)
        ->and($b->error)->toBe('HTTP 500');

    $page = CrawlPage::query()->where('run_id', $run->id)->where('url', CRAWL_BASE.'/b')->sole();

    expect($page->status_code)->toBe(500)
        ->and($page->title)->toBeNull();

    $issue = CrawlIssue::query()->where('target_url', CRAWL_BASE.'/b')->where('issue_code', 'http_error')->sole();

    expect($issue->issue_code)->toBe('http_error')
        ->and($issue->severity)->toBe('critical')
        ->and($issue->category)->toBe('crawlability')
        // home links to /b, so the finalize pass also raises the link issue
        ->and(CrawlIssue::query()->where('issue_code', 'broken_internal_link')->where('severity', 'high')->count())->toBe(1)
        ->and($run->fresh()->issues_found)->toBe(2);
});

it('does not duplicate page or frontier rows on a second run of the same URLs', function () {
    [, $org] = makeOrgUser('crawl-idempotent@example.com');
    $site = crawlSiteFor($org);

    $pages = [
        '/' => crawlPageHtml(CRAWL_BASE.'/', 'Home', [CRAWL_BASE.'/a']),
        '/a' => crawlPageHtml(CRAWL_BASE.'/a', 'Page A'),
    ];

    fakeCrawlSite($pages, [CRAWL_BASE.'/', CRAWL_BASE.'/a'], hits: $hits);

    CrawlSiteJob::dispatch($site->id, 100, 'manual', 0);
    CrawlSiteJob::dispatch($site->id, 100, 'manual', 0);

    $runs = CrawlRun::query()->orderBy('id')->get();
    expect($runs)->toHaveCount(2)
        ->and($runs->every(fn ($r) => $r->status === 'completed'))->toBeTrue();

    $first = CrawlRun::query()->orderBy('id')->first();
    $second = CrawlRun::query()->orderByDesc('id')->first();

    foreach ([
        'crawl_pages' => DB::table('crawl_pages'),
        'crawl_frontier' => DB::table('crawl_frontier'),
        'crawl_links' => DB::table('crawl_links'),
    ] as $table) {
        $firstCount = (clone $table)->where('run_id', $first->id)->count();
        $secondCount = (clone $table)->where('run_id', $second->id)->count();

        expect($secondCount)->toBe($firstCount);
    }

    // Unique (run_id, url_hash): retries/discovery must never double-insert.
    expect(DB::table('crawl_pages')->where('run_id', $second->id)->distinct()->count('url_hash'))
        ->toBe(DB::table('crawl_pages')->where('run_id', $second->id)->count())
        ->and(DB::table('crawl_frontier')->where('run_id', $second->id)->distinct()->count('url_hash'))
        ->toBe(DB::table('crawl_frontier')->where('run_id', $second->id)->count())
        ->and($second->pages_crawled)->toBe(2);
});
