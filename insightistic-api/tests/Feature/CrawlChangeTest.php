<?php

use App\Models\Alert;
use App\Models\CrawlChange;
use App\Models\CrawlPage;
use App\Models\CrawlRun;
use App\Models\Organization;
use App\Models\SeoPageDaily;
use App\Models\Site;
use App\Services\Crawler\ChangeDetection;
use App\Services\Crawler\CrawlRunExecutor;
use App\Support\SeoGrain;
use Illuminate\Support\Carbon;

/**
 * Phase 8 change monitoring (docs/roadmap-2026/12-SITE-CRAWLER.md
 * §Change monitoring): run-to-run diffs written by ChangeDetection at
 * finalize, GSC impression impact resolution, identical-transition dedupe,
 * the per-run critical alert, and the changes listing endpoint.
 */

function changeSiteFor(Organization $org, string $domain = 'changes.example.com'): Site
{
    return Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id,
        'name'            => $domain,
        'domain'          => $domain,
    ]);
}

function changeStartRun(Site $site, string $status = 'running'): CrawlRun
{
    return CrawlRun::query()->create([
        'site_id'   => $site->id,
        'status'    => $status,
        'trigger'   => 'manual',
        'started_at'   => Carbon::now()->subMinutes(5),
        'completed_at' => $status === 'completed' ? Carbon::now()->subMinutes(1) : null,
        'max_pages' => 100,
        'config_json' => ['base_url' => 'https://'.$site->domain, 'max_depth' => 4],
    ]);
}

/** Minimal crawl_pages row, matching what CrawlRunExecutor stores. */
function changePage(CrawlRun $run, string $path, array $overrides = []): CrawlPage
{
    $url = 'https://'.$run->site->domain.$path;

    return CrawlPage::query()->create(array_merge([
        'run_id'     => $run->id,
        'site_id'    => $run->site_id,
        'url_hash'   => sha1($url),
        'url'        => $url,
        'status_code' => 200,
        'indexable'  => true,
        'title'      => 'Title of '.$path,
        'meta_description' => 'Description of '.$path,
        'h1_count'   => 1,
        'word_count' => 300,
        'canonical_url' => $url,
        'content_hash' => sha1('content'.$path),
        'created_at' => Carbon::now(),
    ], $overrides));
}

it('logs run-to-run diffs with correct severities and GSC impressions at finalize', function () {
    [, $org] = makeOrgUser('change-detect@example.com');
    $site = changeSiteFor($org);

    $runOne = changeStartRun($site, 'completed');
    changePage($runOne, '/home', ['title' => 'Old Home']);
    changePage($runOne, '/pricing');
    changePage($runOne, '/about');

    $runTwo = changeStartRun($site);
    changePage($runTwo, '/home', ['title' => 'New Home']);                    // title change → medium
    changePage($runTwo, '/pricing', [                                         // 200 → 404 → critical
        'status_code' => 404,
        'indexable' => false,
        'title' => null,
        'meta_description' => null,
        'canonical_url' => null,
        'content_hash' => null,
    ]);
    changePage($runTwo, '/about');                                            // unchanged

    // GSC impact: /pricing is a traffic page, /home is unknown to Search Console.
    SeoPageDaily::query()->create([
        'site_id'   => $site->id,
        'date'      => Carbon::now()->subDays(3),
        'page_hash' => SeoGrain::hash('/pricing'),
        'page_path' => '/pricing',
        'clicks' => 40,
        'impressions' => 500,
        'ctr' => 0.08,
        'position' => 12.5,
        'created_at' => Carbon::now(),
    ]);

    app(CrawlRunExecutor::class)->finalizeRun($runTwo);

    expect(CrawlChange::query()->count())->toBe(2);

    $title = CrawlChange::query()->where('url', 'https://changes.example.com/home')->sole();
    expect($title->field)->toBe('title')
        ->and($title->severity)->toBe('medium')
        ->and($title->old_value)->toBe('Old Home')
        ->and($title->new_value)->toBe('New Home')
        ->and($title->impressions)->toBe(0)
        ->and($title->run_id)->toBe($runTwo->id)
        ->and($title->page_hash)->toBe(sha1('https://changes.example.com/home'));

    $status = CrawlChange::query()->where('url', 'https://changes.example.com/pricing')->sole();
    expect($status->field)->toBe('status_code')
        ->and($status->severity)->toBe('critical')
        ->and($status->old_value)->toBe('200')
        ->and($status->new_value)->toBe('404')
        ->and($status->impressions)->toBe(500);

    // The unchanged page logged nothing.
    expect(CrawlChange::query()->where('url', 'https://changes.example.com/about')->count())->toBe(0);

    // One critical alert for the run, quoting the changed URL.
    $alert = Alert::query()->where('type', 'critical_site_change')->sole();
    expect($alert->site_id)->toBe($site->id)
        ->and($alert->severity)->toBe('critical')
        ->and($alert->is_resolved)->toBeFalse()
        ->and($alert->message)->toContain('run #'.$runTwo->id)
        ->and($alert->message)->toContain('/pricing');

    expect($runTwo->fresh()->status)->toBe('completed');
});

it('skips re-logging an identical transition (detection is idempotent per run)', function () {
    [, $org] = makeOrgUser('change-dedupe@example.com');
    $site = changeSiteFor($org, 'dedupe.example.com');

    $runOne = changeStartRun($site, 'completed');
    changePage($runOne, '/x', ['title' => 'Before']);

    $runTwo = changeStartRun($site);
    changePage($runTwo, '/x', ['title' => 'After']);

    $detection = app(ChangeDetection::class);
    $first = $detection->detect($runTwo, $site);

    expect($first)->toHaveCount(1);

    // Re-running detection for the same run must be a no-op: the latest
    // logged row already records the identical old→new pair.
    $second = $detection->detect($runTwo, $site);

    expect($second)->toHaveCount(0)
        ->and(CrawlChange::query()->count())->toBe(1)
        // and still exactly one critical-change alert (none here — medium only)
        ->and(Alert::query()->where('type', 'critical_site_change')->count())->toBe(0);
});

it('creates exactly one alert per run even with several critical changes', function () {
    [, $org] = makeOrgUser('change-alert@example.com');
    $site = changeSiteFor($org, 'alerts.example.com');

    $runOne = changeStartRun($site, 'completed');
    changePage($runOne, '/gone');
    changePage($runOne, '/also-gone');
    changePage($runOne, '/hidden', ['robots_noindex' => false]);

    $runTwo = changeStartRun($site);
    changePage($runTwo, '/gone', ['status_code' => 404, 'indexable' => false, 'title' => null, 'meta_description' => null, 'canonical_url' => null, 'content_hash' => null]);
    changePage($runTwo, '/also-gone', ['status_code' => 500, 'indexable' => false, 'title' => null, 'meta_description' => null, 'canonical_url' => null, 'content_hash' => null]);
    changePage($runTwo, '/hidden', ['robots_noindex' => true, 'indexable' => false]); // noindex loss → critical

    app(CrawlRunExecutor::class)->finalizeRun($runTwo);

    expect(CrawlChange::query()->where('severity', 'critical')->count())->toBe(3)
        ->and(Alert::query()->where('type', 'critical_site_change')->count())->toBe(1);

    $message = Alert::query()->where('type', 'critical_site_change')->sole()->message;
    expect($message)->toContain('/gone')
        ->and($message)->toContain('/also-gone')
        ->and($message)->toContain('/hidden');
});

it('diffs nothing on the first completed run of a site', function () {
    [, $org] = makeOrgUser('change-first@example.com');
    $site = changeSiteFor($org, 'first.example.com');

    $run = changeStartRun($site);
    changePage($run, '/only');

    expect(app(ChangeDetection::class)->detect($run, $site))->toBe([])
        ->and(CrawlChange::query()->count())->toBe(0);
});

it('lists changes with cursor pagination, severity and run filters', function () {
    [$user, $org] = makeOrgUser('change-api@example.com');
    $site = changeSiteFor($org, 'apichanges.example.com');

    $runOne = changeStartRun($site, 'completed');
    changePage($runOne, '/a');
    changePage($runOne, '/b');

    $runTwo = changeStartRun($site);
    changePage($runTwo, '/a', ['title' => 'Renamed']);
    changePage($runTwo, '/b', [                                              // 200 → 404 → critical
        'status_code' => 404,
        'indexable' => false,
        'title' => null,
        'meta_description' => null,
        'canonical_url' => null,
        'content_hash' => null,
    ]);

    app(CrawlRunExecutor::class)->finalizeRun($runTwo);

    $this->actingAs($user)
        ->getJson("/api/sites/{$site->id}/crawl/changes")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonStructure([
            'data' => [['url', 'field', 'severity', 'old_value', 'new_value', 'impressions', 'created_at']],
            'meta' => ['next_cursor', 'has_more', 'total'],
        ])
        ->assertJsonPath('meta.total', 2);

    $this->actingAs($user)
        ->getJson("/api/sites/{$site->id}/crawl/changes?severity=critical")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.field', 'status_code')
        ->assertJsonPath('data.0.severity', 'critical');

    $this->actingAs($user)
        ->getJson("/api/sites/{$site->id}/crawl/changes?run_id={$runOne->id}")
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.total', 0);

    $this->actingAs($user)
        ->getJson("/api/sites/{$site->id}/crawl/changes?per_page=1")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.has_more', true)
        ->assertJsonPath('meta.next_cursor', fn ($cursor) => $cursor !== null);

    // Tenant isolation: another org's site is invisible.
    [, $orgB] = makeOrgUser('change-api-b@example.com');
    $siteB = changeSiteFor($orgB, 'other.example.com');

    $this->actingAs($user)
        ->getJson("/api/sites/{$siteB->id}/crawl/changes")
        ->assertNotFound();
});
