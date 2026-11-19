<?php

use App\Models\CrawlIssue;
use App\Models\CrawlLink;
use App\Models\CrawlPage;
use App\Models\CrawlRun;
use App\Models\Organization;
use App\Models\Site;
use App\Services\Crawler\CrawlRunExecutor;
use App\Services\Crawler\HtmlInspector;
use App\Services\Crawler\IssueEngine;
use Illuminate\Support\Carbon;

/**
 * Issue taxonomy and cross-run lifecycle for the Phase 7 crawler
 * (docs/roadmap-2026/12-SITE-CRAWLER.md §Issue engine).
 */
function issuesSiteFor(Organization $org): Site
{
    return Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id,
        'name' => 'Issues Co',
        'domain' => 'issues.example.com',
    ]);
}

function issuesStartRun(Site $site): CrawlRun
{
    return CrawlRun::query()->create([
        'site_id' => $site->id,
        'status' => 'running',
        'trigger' => 'manual',
        'started_at' => Carbon::now(),
        'max_pages' => 100,
        'config_json' => ['base_url' => 'https://issues.example.com', 'max_depth' => 4],
    ]);
}

/** Minimal page row for direct IssueEngine evaluation. */
function issuesPage(CrawlRun $run, string $path, array $overrides = []): CrawlPage
{
    $url = 'https://issues.example.com'.$path;

    return CrawlPage::query()->create(array_merge([
        'run_id' => $run->id,
        'site_id' => $run->site_id,
        'url_hash' => sha1($url),
        'url' => $url,
        'status_code' => 200,
        'title' => 'Title of '.$path,
        'meta_description' => 'Description of '.$path,
        'h1_count' => 1,
        'word_count' => 300,
        'canonical_url' => $url,
        'created_at' => Carbon::now(),
    ], $overrides));
}

function issuesEngine(): IssueEngine
{
    return app(IssueEngine::class);
}

function issueFor(string $path, string $code): CrawlIssue
{
    return CrawlIssue::query()
        ->where('target_url', 'https://issues.example.com'.$path)
        ->where('issue_code', $code)
        ->sole();
}

it('fires each issue code with the correct severity and category', function () {
    [, $org] = makeOrgUser('issues-taxonomy@example.com');
    $site = issuesSiteFor($org);
    $run = issuesStartRun($site);
    $engine = issuesEngine();

    // Crawlability
    $engine->evaluate($run, issuesPage($run, '/not-found', ['status_code' => 404, 'title' => null, 'meta_description' => null, 'canonical_url' => null]));
    $engine->evaluate($run, issuesPage($run, '/boom', ['status_code' => 500, 'title' => null, 'meta_description' => null, 'canonical_url' => null]));
    $engine->evaluate($run, issuesPage($run, '/moved', ['status_code' => 301, 'redirect_to' => 'https://issues.example.com/']));
    $engine->evaluate($run, issuesPage($run, '/secret', ['robots_noindex' => true, 'indexable' => false, 'canonical_url' => null]));

    // On-page
    $engine->evaluate($run, issuesPage($run, '/no-title', ['title' => null]));
    $engine->evaluate($run, issuesPage($run, '/no-desc', ['meta_description' => null]));
    $engine->evaluate($run, issuesPage($run, '/two-h1', ['h1_count' => 2]));
    $engine->evaluate($run, issuesPage($run, '/no-canonical', ['canonical_url' => null]));

    // Content
    $engine->evaluate($run, issuesPage($run, '/thin', ['word_count' => 20]));
    $engine->evaluate($run, issuesPage($run, '/images', []), ['image_missing_alt' => 3, 'image_count' => 5]);

    // Structured data
    $engine->evaluate($run, issuesPage($run, '/schema', []), ['schema_error' => true]);

    // A healthy page must not fire anything.
    $engine->evaluate($run, issuesPage($run, '/healthy'));

    $cases = [
        ['/not-found', 'http_error', 'high', 'crawlability'],
        ['/boom', 'http_error', 'critical', 'crawlability'],
        ['/moved', 'redirect', 'low', 'crawlability'],
        ['/secret', 'robots_noindex', 'low', 'crawlability'],
        ['/no-title', 'missing_title', 'high', 'on_page'],
        ['/no-desc', 'missing_meta_description', 'medium', 'on_page'],
        ['/two-h1', 'multiple_h1', 'low', 'on_page'],
        ['/no-canonical', 'canonical_missing', 'low', 'on_page'],
        ['/thin', 'thin_content', 'low', 'content'],
        ['/images', 'image_missing_alt', 'low', 'content'],
        ['/schema', 'schema_parse_error', 'low', 'structured_data'],
    ];

    foreach ($cases as [$path, $code, $severity, $category]) {
        $issue = issueFor($path, $code);

        expect($issue->severity)->toBe($severity)
            ->and($issue->category)->toBe($category)
            ->and($issue->status)->toBe(CrawlIssue::STATUS_OPEN)
            ->and($issue->first_seen_at)->not->toBeNull()
            ->and($issue->last_seen_at)->not->toBeNull();
    }

    expect(issueFor('/images', 'image_missing_alt')->evidence_json['missing'])->toBe(3)
        ->and(issueFor('/thin', 'thin_content')->evidence_json['word_count'])->toBe(20)
        // the healthy page fired nothing beyond its own fingerprint scope
        ->and(CrawlIssue::query()->where('target_url', 'https://issues.example.com/healthy')->count())->toBe(0);
});

it('deduplicates broken internal links and keeps redirects as page findings', function () {
    [, $org] = makeOrgUser('issues-links@example.com');
    $site = issuesSiteFor($org);
    $run = issuesStartRun($site);
    $engine = issuesEngine();

    $source = issuesPage($run, '/source');
    $broken = issuesPage($run, '/broken', ['status_code' => 404, 'title' => null, 'meta_description' => null, 'canonical_url' => null]);
    $redirect = issuesPage($run, '/old', ['status_code' => 301, 'redirect_to' => 'https://issues.example.com/new']);

    // Links out of /source to a broken target, a redirecting target and a
    // healthy one. The healthy target has no crawled page row in this run,
    // so the join cannot classify it — no issue may fire for it.
    foreach (['/broken', '/old', '/ok'] as $path) {
        CrawlLink::query()->create([
            'run_id' => $run->id,
            'site_id' => $run->site_id,
            'source_page_id' => $source->id,
            'target_url_hash' => sha1('https://issues.example.com'.$path),
            'target_url' => 'https://issues.example.com'.$path,
            'is_internal' => true,
            'anchor' => 'go',
            'created_at' => Carbon::now(),
        ]);
    }

    $found = $engine->finalize($run);

    $brokenIssue = CrawlIssue::query()->where('issue_code', 'broken_internal_link')->sole();
    expect($brokenIssue->severity)->toBe('high')
        ->and($brokenIssue->category)->toBe('links')
        ->and($brokenIssue->target_url)->toBe('https://issues.example.com/broken')
        ->and($brokenIssue->evidence_json['status_code'])->toBe(404)
        ->and($brokenIssue->evidence_json['sources'])->toContain('https://issues.example.com/source')
        ->and(CrawlIssue::query()->where('issue_code', 'redirecting_internal_link')->count())->toBe(0)
        ->and(CrawlIssue::query()->where('issue_code', 'redirect')->count())->toBe(0)
        // healthy target fired nothing
        ->and(CrawlIssue::query()->where('target_url', 'https://issues.example.com/ok')->count())->toBe(0)
        // finalize counts exactly what this run saw
        ->and($found)->toBe(1);
});

it('does not apply html checks to non-html resources', function () {
    [, $org] = makeOrgUser('issues-resource-types@example.com');
    $site = issuesSiteFor($org);
    $run = issuesStartRun($site);
    $engine = issuesEngine();

    $engine->evaluate($run, issuesPage($run, '/robots.txt', [
        'content_type' => 'text/plain; charset=utf-8',
        'title' => null,
        'meta_description' => null,
        'canonical_url' => null,
        'h1_count' => 0,
        'word_count' => 0,
    ]));
    $engine->evaluate($run, issuesPage($run, '/sitemap.xml', [
        'content_type' => 'application/xml',
        'title' => null,
        'meta_description' => null,
        'canonical_url' => null,
        'h1_count' => 0,
        'word_count' => 0,
    ]));

    expect(CrawlIssue::query()->count())->toBe(0);
});

it('keeps fingerprint identity stable across runs', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-20 10:00:00'));

    [, $org] = makeOrgUser('issues-fingerprint@example.com');
    $site = issuesSiteFor($org);

    $runOne = issuesStartRun($site);
    issuesEngine()->evaluate($runOne, issuesPage($runOne, '/x', ['title' => null]));

    $firstSeen = issueFor('/x', 'missing_title');

    expect($firstSeen->first_seen_at->equalTo(Carbon::parse('2026-09-20 10:00:00')))->toBeTrue();

    Carbon::setTestNow(Carbon::parse('2026-09-20 11:00:00'));

    // Same page, same issue, one hour later in a new run.
    $runTwo = issuesStartRun($site);
    issuesEngine()->evaluate($runTwo, issuesPage($runTwo, '/x', ['title' => null]));

    $all = CrawlIssue::query()->where('issue_code', 'missing_title')->get();

    expect($all)->toHaveCount(1);

    $row = $all->sole();

    expect($row->id)->toBe($firstSeen->id)
        ->and($row->first_seen_at->equalTo($firstSeen->first_seen_at))->toBeTrue()
        ->and($row->last_seen_at->equalTo(Carbon::parse('2026-09-20 11:00:00')))->toBeTrue()
        ->and($row->run_id)->toBe($runTwo->id)
        ->and($row->status)->toBe(CrawlIssue::STATUS_OPEN)
        ->and(CrawlIssue::fingerprint('missing_title', 'https://issues.example.com/x'))->toBe($row->fingerprint);

    Carbon::setTestNow();
});

it('resolves absent issues but never touches ignored ones', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-20 10:00:00'));

    [, $org] = makeOrgUser('issues-lifecycle@example.com');
    $site = issuesSiteFor($org);
    $executor = app(CrawlRunExecutor::class);

    $runOne = issuesStartRun($site);
    issuesEngine()->evaluate($runOne, issuesPage($runOne, '/gone', ['title' => null]));       // will disappear
    issuesEngine()->evaluate($runOne, issuesPage($runOne, '/stays', ['title' => null]));      // stays open
    issuesEngine()->evaluate($runOne, issuesPage($runOne, '/muted', ['title' => null]));      // will be ignored

    // /muted is a user decision: ignored forever.
    issueFor('/muted', 'missing_title')->update(['status' => CrawlIssue::STATUS_IGNORED]);

    Carbon::setTestNow(Carbon::parse('2026-09-20 12:00:00'));

    // Run two: /gone fixed, /stays still broken, /muted seen again but ignored.
    $runTwo = issuesStartRun($site);
    issuesEngine()->evaluate($runTwo, issuesPage($runTwo, '/stays', ['title' => null]));
    issuesEngine()->evaluate($runTwo, issuesPage($runTwo, '/muted', ['title' => null]));

    $executor->finalizeRun($runTwo);

    $gone = issueFor('/gone', 'missing_title');
    $stays = issueFor('/stays', 'missing_title');
    $muted = issueFor('/muted', 'missing_title');

    expect($gone->status)->toBe(CrawlIssue::STATUS_RESOLVED)
        ->and($gone->resolved_at->equalTo(Carbon::parse('2026-09-20 12:00:00')))->toBeTrue()
        ->and($stays->status)->toBe(CrawlIssue::STATUS_OPEN)
        ->and($stays->resolved_at)->toBeNull()
        ->and($stays->run_id)->toBe($runTwo->id)
        // ignored: untouched forever — even being seen again must not update it
        ->and($muted->status)->toBe(CrawlIssue::STATUS_IGNORED)
        ->and($muted->run_id)->toBe($runOne->id)
        ->and($muted->last_seen_at->equalTo(Carbon::parse('2026-09-20 10:00:00')))->toBeTrue()
        // issues_found counts what this run saw: /stays only — the ignored
        // row keeps its old run_id, the resolved one is not part of run two.
        ->and($runTwo->fresh()->issues_found)->toBe(1);

    // A resolved issue that reappears reopens and preserves first_seen.
    Carbon::setTestNow(Carbon::parse('2026-09-20 13:00:00'));

    $runThree = issuesStartRun($site);
    issuesEngine()->evaluate($runThree, issuesPage($runThree, '/gone', ['title' => null]));

    $reopened = issueFor('/gone', 'missing_title');

    expect($reopened->status)->toBe(CrawlIssue::STATUS_OPEN)
        ->and($reopened->resolved_at)->toBeNull()
        ->and($reopened->first_seen_at->equalTo(Carbon::parse('2026-09-20 10:00:00')))->toBeTrue()
        ->and($reopened->last_seen_at->equalTo(Carbon::parse('2026-09-20 13:00:00')))->toBeTrue();

    Carbon::setTestNow();
});

it('inspects HTML through DomCrawler and degrades gracefully on malformed input', function () {
    $inspector = new HtmlInspector;

    $html = <<<'HTML'
    <!DOCTYPE html>
    <html><head>
        <title>  Inspected   Page </title>
        <meta name="Description" content="Hello world">
        <meta name="robots" content="noindex, follow">
        <link rel="canonical" href="/preferred">
    </head>
    <body>
        <h1>First</h1><h1>Second</h1>
        <p>Some meaningful words here</p>
        <a href="/relative">Rel</a>
        <a href="https://other.example.org/out">Out</a>
        <a href="mailto:x@y.z">Mail</a>
        <a href="#anchor">Frag</a>
        <a href="/dup">Dup</a><a href="/dup">Dup</a>
        <img src="a.png" alt="ok"><img src="b.png"><img src="c.png" alt="  ">
        <script type="application/ld+json">{"@type":"Product"}</script>
        <script type="application/ld+json">{not json}</script>
        <script>var x = 1;</script>
    </body></html>
    HTML;

    $result = $inspector->inspect('https://x.example.com/dir/page', $html, 'max-snippet:-1');

    expect($result['title'])->toBe('Inspected Page')
        ->and($result['meta_description'])->toBe('Hello world')
        ->and($result['robots_noindex'])->toBeTrue()
        ->and($result['canonical_url'])->toBe('https://x.example.com/preferred')
        ->and($result['h1_count'])->toBe(2)
        ->and($result['word_count'])->toBeGreaterThan(0)
        ->and($result['content_hash'])->not->toBeNull()
        // mailto/fragment dropped, duplicate hrefs collapsed, relative resolved
        ->and(collect($result['links'])->pluck('href')->all())->toEqualCanonicalizing([
            'https://x.example.com/relative',
            'https://other.example.org/out',
            'https://x.example.com/dup',
        ])
        ->and(collect($result['links'])->firstWhere('href', 'https://x.example.com/relative')['anchor'])->toBe('Rel')
        ->and($result['image_count'])->toBe(3)
        ->and($result['image_missing_alt'])->toBe(2)
        ->and($result['schema_types'])->toBe(['Product'])
        ->and($result['schema_error'])->toBeTrue()
        ->and($result['parse_error'])->toBeFalse();

    // X-Robots-Tag alone triggers noindex; garbage HTML never throws.
    $headerOnly = $inspector->inspect('https://x.example.com/a', '<p>plain</p>', 'NOINDEX');
    expect($headerOnly['robots_noindex'])->toBeTrue();

    $garbage = $inspector->inspect('https://x.example.com/b', '<<<not html at all>>>', null);
    expect($garbage['title'])->toBeNull();

    $empty = $inspector->inspect('https://x.example.com/c', '', null);
    expect($empty['parse_error'])->toBeTrue();
});
