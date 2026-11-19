<?php

use App\Jobs\CrawlSiteJob;
use App\Models\CrawlIssue;
use App\Models\CrawlLink;
use App\Models\CrawlPage;
use App\Models\CrawlRun;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * Site crawler API (Phase 7, doc 12): tenancy, role gates, the monthly
 * crawl-pages quota, single-run-per-site, listings and issue triage.
 *
 * The crawl_* models and CrawlSiteJob belong to the crawler engine
 * workstream; these tests create rows directly through the pinned model
 * contracts and fake the queue — nothing here performs a real crawl.
 */

function crawlSite(App\Models\Organization $org, string $domain = 'crawl.example.com'): Site
{
    return Site::withoutGlobalScope('organization')->create([
        'organization_id'   => $org->id,
        'name'              => $domain,
        'domain'            => $domain,
        'connection_status' => 'connected',
    ]);
}

function makeCrawlRun(Site $site, array $overrides = []): CrawlRun
{
    return CrawlRun::create(array_replace([
        'site_id'   => $site->id,
        'status'    => 'completed',
        'trigger'   => 'manual',
        'max_pages' => 500,
        'started_at'   => now()->subMinutes(5),
        'completed_at' => now()->subMinutes(1),
    ], $overrides));
}

function makeCrawlPage(Site $site, CrawlRun $run, string $path, array $overrides = []): CrawlPage
{
    $url = "https://{$site->domain}{$path}";

    return CrawlPage::create(array_replace([
        'run_id'    => $run->id,
        'site_id'   => $site->id,
        'url_hash'  => md5($url),
        'url'       => $url,
        'status_code' => 200,
        'indexable' => true,
        'title'       => "Page {$path}",
        'meta_description' => 'Meta for ' . $path,
        'word_count' => 300,
        'h1_count'   => 1,
        'response_ms' => 120,
    ], $overrides));
}

function makeCrawlLink(Site $site, CrawlRun $run, CrawlPage $source, string $targetUrl, array $overrides = []): CrawlLink
{
    return CrawlLink::create(array_replace([
        'run_id'           => $run->id,
        'site_id'          => $site->id,
        'source_page_id'   => $source->id,
        'target_url_hash'  => md5($targetUrl),
        'target_url'       => $targetUrl,
        'is_internal'      => str_contains($targetUrl, $site->domain),
        'status_code'      => null,
        'is_nofollow'      => false,
        'anchor'           => 'Link to ' . $targetUrl,
    ], $overrides));
}

function makeCrawlIssue(Site $site, CrawlRun $run, array $overrides = []): CrawlIssue
{
    $overrides = array_replace([
        'issue_code' => 'broken_internal_link',
        'severity'   => 'medium',
        'category'   => 'links',
        'target_url' => "https://{$site->domain}/broken",
        'status'     => 'open',
    ], $overrides);

    return CrawlIssue::create(array_replace([
        'site_id'       => $site->id,
        'run_id'        => $run->id,
        'issue_code'    => $overrides['issue_code'],
        'severity'      => $overrides['severity'],
        'category'      => $overrides['category'],
        'fingerprint'   => md5($overrides['issue_code'] . '|' . $overrides['target_url'] . '|' . Str::random(6)),
        'target_url'    => $overrides['target_url'],
        'status'        => $overrides['status'],
        'first_seen_at' => now()->subDay(),
        'last_seen_at'  => now(),
    ], $overrides));
}

/** Read a job's public constructor properties, tolerating either naming. */
function crawlJobProps(object $job): array
{
    return get_object_vars($job);
}

it('hides every crawl surface from another organization', function () {
    [$userA] = makeOrgUser('crawl-a@example.com');
    [, $orgB] = makeOrgUser('crawl-b@example.com');
    $siteB = crawlSite($orgB);

    makeCrawlRun($siteB);

    $this->actingAs($userA)
        ->getJson("/api/sites/{$siteB->id}/crawl/runs")
        ->assertNotFound();

    $this->actingAs($userA)
        ->getJson("/api/sites/{$siteB->id}/crawl/health")
        ->assertNotFound();

    $this->actingAs($userA)
        ->getJson("/api/sites/{$siteB->id}/crawl/pages")
        ->assertNotFound();
});

it('role-gates run-now and issue triage to owner/admin', function () {
    Queue::fake();

    [$owner, $org] = makeOrgUser('crawl-owner@example.com', [
        'crawl_pages_monthly'  => 5000,
        'crawl_frequency_days' => 7,
    ]);
    $site = crawlSite($org);

    $analyst = User::create([
        'name' => 'Analyst', 'email' => 'crawl-analyst@example.com',
        'password' => 'secret-password', 'status' => 'active',
    ]);
    $org->users()->attach($analyst->id, ['role' => 'analyst']);

    $run = makeCrawlRun($site);
    $issue = makeCrawlIssue($site, $run);

    $this->actingAs($analyst)
        ->postJson("/api/sites/{$site->id}/crawl/run")
        ->assertForbidden();

    $this->actingAs($analyst)
        ->postJson("/api/sites/{$site->id}/crawl/issues/{$issue->id}/status", ['status' => 'ignored'])
        ->assertForbidden();

    Queue::assertNothingPushed();

    $this->actingAs($owner)
        ->postJson("/api/sites/{$site->id}/crawl/run")
        ->assertOk();

    $this->actingAs($owner)
        ->postJson("/api/sites/{$site->id}/crawl/issues/{$issue->id}/status", ['status' => 'ignored'])
        ->assertOk();

    expect($issue->fresh()->status)->toBe('ignored');
});

it('dispatches a manual crawl with the plan-capped page budget', function () {
    Queue::fake();

    [$user, $org] = makeOrgUser('crawl-dispatch@example.com', [
        'crawl_pages_monthly'  => 2500,
        'crawl_frequency_days' => 7,
    ]);
    $site = crawlSite($org);

    $response = $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/crawl/run")
        ->assertOk()
        ->assertJsonPath('dispatched', true)
        // Default per-run allowance: min(1000, plan 2500).
        ->assertJsonPath('max_pages', 1000);

    $runId = $response->json('run_id');
    expect($runId)->not->toBeNull()
        ->and(CrawlRun::where('site_id', $site->id)->find($runId)?->status)->toBe('pending');

    Queue::assertPushed(CrawlSiteJob::class, function (CrawlSiteJob $job) use ($site) {
        $props = crawlJobProps($job);
        $siteProp = $props['site'] ?? null;
        $siteId = $siteProp instanceof Site ? $siteProp->id : ($props['siteId'] ?? $props['site_id'] ?? null);
        $maxPages = $props['maxPages'] ?? $props['max_pages'] ?? null;
        $runId = $props['runId'] ?? $props['run_id'] ?? null;

        return $maxPages === 1000
            && ($props['trigger'] ?? null) === 'manual'
            && is_numeric($runId)
            && $siteId === $site->id;
    });
});

it('clamps a requested max_pages down to the plan limit', function () {
    Queue::fake();

    [$user, $org] = makeOrgUser('crawl-clamp@example.com', [
        'crawl_pages_monthly'  => 500,
        'crawl_frequency_days' => 7,
    ]);
    $site = crawlSite($org);

    $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/crawl/run", ['max_pages' => 5000])
        ->assertOk()
        ->assertJsonPath('max_pages', 500);

    Queue::assertPushed(CrawlSiteJob::class, function (CrawlSiteJob $job) {
        $props = crawlJobProps($job);

        return ($props['maxPages'] ?? $props['max_pages'] ?? null) === 500;
    });
});

it('rejects a run when the monthly crawl budget is spent', function () {
    Queue::fake();

    [$user, $org] = makeOrgUser('crawl-quota@example.com', [
        'crawl_pages_monthly'  => 500,
        'crawl_frequency_days' => 7,
    ]);
    $site = crawlSite($org);

    // Failed runs count the URLs they processed before failing.
    makeCrawlRun($site, ['status' => 'completed', 'max_pages' => 500, 'pages_crawled' => 300]);
    makeCrawlRun($site, ['status' => 'failed', 'max_pages' => 500, 'pages_crawled' => 250]);

    $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/crawl/run", ['max_pages' => 100])
        ->assertStatus(422)
        ->assertJsonPath('code', 'crawl_limit_reached')
        ->assertJsonPath('limit', 500)
        ->assertJsonPath('used', 550);

    Queue::assertNothingPushed();
});

it('counts the whole organization against the monthly budget, not just the site', function () {
    Queue::fake();

    [$user, $org] = makeOrgUser('crawl-orgquota@example.com', [
        'crawl_pages_monthly'  => 500,
        'crawl_frequency_days' => 1,
    ]);
    $site = crawlSite($org, 'site-a.crawl.example.com');
    $sibling = crawlSite($org, 'site-b.crawl.example.com');

    makeCrawlRun($sibling, ['status' => 'completed', 'max_pages' => 500, 'pages_crawled' => 500]);

    $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/crawl/run")
        ->assertStatus(422)
        ->assertJsonPath('code', 'crawl_limit_reached')
        ->assertJsonPath('used', 500);

    Queue::assertNothingPushed();
});

it('shows actual pages, reserves in-flight capacity and enforces a saved project limit', function () {
    Queue::fake();

    [$owner, $org] = makeOrgUser('crawl-project-limit@example.com', ['crawl_pages_monthly' => 10000]);
    $site = crawlSite($org, 'project-a.crawl.example.com');
    $sibling = crawlSite($org, 'project-b.crawl.example.com');

    makeCrawlRun($site, ['max_pages' => 5000, 'pages_crawled' => 486]);
    makeCrawlRun($sibling, ['status' => 'running', 'max_pages' => 1000, 'pages_crawled' => 100]);

    $this->actingAs($owner)
        ->patchJson("/api/sites/{$site->id}/crawl/settings", ['monthly_page_limit' => 500])
        ->assertOk()
        ->assertJsonPath('budget.project.used', 486)
        ->assertJsonPath('budget.project.remaining', 14)
        ->assertJsonPath('budget.workspace.used', 586)
        ->assertJsonPath('budget.workspace.reserved', 900)
        ->assertJsonPath('budget.workspace.remaining', 8514);

    $this->postJson("/api/sites/{$site->id}/crawl/run", ['max_pages' => 1000])
        ->assertOk()
        ->assertJsonPath('max_pages', 14);
});

it('409s while a crawl is pending or running and names the run', function () {
    Queue::fake();

    [$user, $org] = makeOrgUser('crawl-conflict@example.com', [
        'crawl_pages_monthly'  => 10000,
        'crawl_frequency_days' => 1,
    ]);
    $site = crawlSite($org);

    $running = makeCrawlRun($site, ['status' => 'running', 'completed_at' => null]);

    $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/crawl/run")
        ->assertStatus(409)
        ->assertJsonPath('code', 'crawl_already_running')
        ->assertJsonPath('run_id', $running->id);

    // pending counts as in-flight too — the queued job owns the
    // pending -> running transition.
    $running->update(['status' => 'completed', 'completed_at' => now()]);
    $pending = makeCrawlRun($site, ['status' => 'pending', 'started_at' => null, 'completed_at' => null]);

    $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/crawl/run")
        ->assertStatus(409)
        ->assertJsonPath('run_id', $pending->id);

    Queue::assertNothingPushed();
});

it('paginates pages with the offset cursor and filters by status and search', function () {
    [$user, $org] = makeOrgUser('crawl-pages@example.com', ['crawl_pages_monthly' => 10000]);
    $site = crawlSite($org);
    $run = makeCrawlRun($site);

    foreach (range(1, 30) as $i) {
        makeCrawlPage($site, $run, "/post-{$i}");
    }
    foreach (range(1, 25) as $i) {
        makeCrawlPage($site, $run, "/missing-{$i}", ['status_code' => 404, 'indexable' => false]);
    }
    makeCrawlPage($site, $run, '/blog/writing');

    $first = $this->actingAs($user)
        ->getJson("/api/sites/{$site->id}/crawl/pages?run_id={$run->id}&per_page=50")
        ->assertOk();

    $first->assertJsonPath('meta.total', 56)
        ->assertJsonPath('meta.has_more', true);
    expect(count($first->json('data')))->toBe(50);

    $cursor = $first->json('meta.next_cursor');
    expect($cursor)->not->toBeNull();

    $second = $this->actingAs($user)
        ->getJson("/api/sites/{$site->id}/crawl/pages?run_id={$run->id}&per_page=50&cursor={$cursor}")
        ->assertOk();

    expect(count($second->json('data')))->toBe(6)
        ->and($second->json('meta.has_more'))->toBeFalse()
        ->and($second->json('meta.next_cursor'))->toBeNull();

    // status=error means HTTP >= 400.
    $errors = $this->actingAs($user)
        ->getJson("/api/sites/{$site->id}/crawl/pages?run_id={$run->id}&status=error")
        ->assertOk();

    expect($errors->json('meta.total'))->toBe(25)
        ->and(collect($errors->json('data'))->every(fn ($p) => $p['status_code'] >= 400))->toBeTrue();

    // search is a url LIKE.
    $search = $this->actingAs($user)
        ->getJson("/api/sites/{$site->id}/crawl/pages?run_id={$run->id}&search=/blog/")
        ->assertOk();

    expect($search->json('meta.total'))->toBe(1)
        ->and($search->json('data.0.url'))->toContain('/blog/writing');
});

it('resolves broken and redirect links through the target page of the run', function () {
    [$user, $org] = makeOrgUser('crawl-links@example.com', ['crawl_pages_monthly' => 10000]);
    $site = crawlSite($org);
    $run = makeCrawlRun($site);

    $source = makeCrawlPage($site, $run, '/');
    $dead   = makeCrawlPage($site, $run, '/gone', ['status_code' => 404]);
    $moved  = makeCrawlPage($site, $run, '/old', ['status_code' => 301]);
    $fine   = makeCrawlPage($site, $run, '/live');

    makeCrawlLink($site, $run, $source, $dead->url);
    makeCrawlLink($site, $run, $source, $moved->url);
    makeCrawlLink($site, $run, $source, $fine->url);
    makeCrawlLink($site, $run, $source, 'https://elsewhere.example.com/out', ['anchor' => 'Out']);

    $broken = $this->actingAs($user)
        ->getJson("/api/sites/{$site->id}/crawl/links?run_id={$run->id}&type=broken")
        ->assertOk();

    expect($broken->json('meta.total'))->toBe(1)
        ->and($broken->json('data.0.target_url'))->toBe($dead->url)
        ->and($broken->json('data.0.source_url'))->toBe($source->url)
        ->and($broken->json('data.0.status_code'))->toBe(404)
        ->and($broken->json('data.0.anchor'))->toBe('Link to ' . $dead->url);

    $redirects = $this->actingAs($user)
        ->getJson("/api/sites/{$site->id}/crawl/links?run_id={$run->id}&type=redirect")
        ->assertOk();

    expect($redirects->json('meta.total'))->toBe(1)
        ->and($redirects->json('data.0.target_url'))->toBe($moved->url);

    $external = $this->actingAs($user)
        ->getJson("/api/sites/{$site->id}/crawl/links?run_id={$run->id}&type=external")
        ->assertOk();

    expect($external->json('meta.total'))->toBe(1)
        ->and($external->json('data.0.status_code'))->toBeNull();

    $internal = $this->actingAs($user)
        ->getJson("/api/sites/{$site->id}/crawl/links?run_id={$run->id}&type=internal")
        ->assertOk();

    expect($internal->json('meta.total'))->toBe(3);
});

it('lists issues filtered by status, severity and category, worst first', function () {
    [$user, $org] = makeOrgUser('crawl-issues@example.com', ['crawl_pages_monthly' => 10000]);
    $site = crawlSite($org);
    $run = makeCrawlRun($site);

    $critical = makeCrawlIssue($site, $run, [
        'issue_code' => 'robots_blocked', 'severity' => 'critical',
        'category' => 'crawlability', 'target_url' => "https://{$site->domain}/blocked",
    ]);
    $mediumLink = makeCrawlIssue($site, $run);
    $resolved = makeCrawlIssue($site, $run, [
        'issue_code' => 'missing_title', 'severity' => 'high',
        'category' => 'on_page', 'target_url' => "https://{$site->domain}/fixed", 'status' => 'resolved',
    ]);

    $open = $this->actingAs($user)
        ->getJson("/api/sites/{$site->id}/crawl/issues")
        ->assertOk();

    expect($open->json('meta.total'))->toBe(2)
        ->and($open->json('data.0.id'))->toBe($critical->id); // critical sorts before medium

    $bySeverity = $this->actingAs($user)
        ->getJson("/api/sites/{$site->id}/crawl/issues?severity=medium")
        ->assertOk();

    expect($bySeverity->json('meta.total'))->toBe(1)
        ->and($bySeverity->json('data.0.id'))->toBe($mediumLink->id);

    $resolvedList = $this->actingAs($user)
        ->getJson("/api/sites/{$site->id}/crawl/issues?status=resolved")
        ->assertOk();

    expect($resolvedList->json('meta.total'))->toBe(1)
        ->and($resolvedList->json('data.0.id'))->toBe($resolved->id);
});

it('shows a run with severity counts and top issues', function () {
    [$user, $org] = makeOrgUser('crawl-runshow@example.com', ['crawl_pages_monthly' => 10000]);
    $site = crawlSite($org);
    $run = makeCrawlRun($site, [
        'pages_crawled' => 42, 'pages_discovered' => 60, 'issues_found' => 2,
    ]);

    makeCrawlIssue($site, $run, [
        'severity' => 'critical', 'category' => 'crawlability',
        'issue_code' => 'robots_blocked', 'target_url' => "https://{$site->domain}/blocked",
    ]);
    makeCrawlIssue($site, $run, [
        'severity' => 'low', 'category' => 'content', 'issue_code' => 'thin_content',
        'target_url' => "https://{$site->domain}/thin",
    ]);

    $this->actingAs($user)
        ->getJson("/api/sites/{$site->id}/crawl/runs/{$run->id}")
        ->assertOk()
        ->assertJsonPath('run.id', $run->id)
        ->assertJsonPath('run.pages_crawled', 42)
        ->assertJsonPath('severity_counts.critical', 1)
        ->assertJsonPath('severity_counts.low', 1)
        ->assertJsonCount(2, 'top_issues')
        ->assertJsonPath('top_issues.0.severity', 'critical');

    // Another org's run id behind this site's route: 404, not leaked.
    [$otherUser] = makeOrgUser('crawl-runshow-other@example.com');
    $this->actingAs($otherUser)
        ->getJson("/api/sites/{$site->id}/crawl/runs/{$run->id}")
        ->assertNotFound();
});

it('scores health from open issues and returns the worst five', function () {
    [$user, $org] = makeOrgUser('crawl-health@example.com', ['crawl_pages_monthly' => 10000]);
    $site = crawlSite($org);
    $run = makeCrawlRun($site);

    // crawlability critical (12) + 2 high on_page (12) = 24 -> score 76.
    // The resolved critical must not count; the ignored link neither.
    makeCrawlIssue($site, $run, [
        'issue_code' => 'robots_blocked', 'severity' => 'critical',
        'category' => 'crawlability', 'target_url' => "https://{$site->domain}/blocked",
    ]);
    makeCrawlIssue($site, $run, [
        'issue_code' => 'missing_title', 'severity' => 'high', 'category' => 'on_page',
        'target_url' => "https://{$site->domain}/a",
    ]);
    makeCrawlIssue($site, $run, [
        'issue_code' => 'duplicate_title', 'severity' => 'high', 'category' => 'on_page',
        'target_url' => "https://{$site->domain}/b",
    ]);
    makeCrawlIssue($site, $run, [
        'issue_code' => 'noindex_root', 'severity' => 'critical', 'category' => 'crawlability',
        'target_url' => "https://{$site->domain}/fixed", 'status' => 'resolved',
    ]);
    makeCrawlIssue($site, $run, [
        'issue_code' => 'broken_internal_link', 'severity' => 'high', 'category' => 'links',
        'target_url' => "https://{$site->domain}/ignored-link", 'status' => 'ignored',
    ]);

    $response = $this->actingAs($user)
        ->getJson("/api/sites/{$site->id}/crawl/health")
        ->assertOk();

    $response->assertJsonPath('score', 76)
        ->assertJsonPath('breakdown.crawlability.penalty', 12)
        ->assertJsonPath('breakdown.crawlability.open_issues', 1)
        ->assertJsonPath('breakdown.on_page.penalty', 12)
        ->assertJsonPath('breakdown.links.penalty', 0)
        ->assertJsonCount(3, 'top_issues')
        ->assertJsonPath('top_issues.0.severity', 'critical');
});

it('schedules crawls only for due sites on plans with a crawl entitlement', function () {
    Queue::fake();

    [, $org] = makeOrgUser('crawl-schedule@example.com', [
        'crawl_pages_monthly'  => 10000,
        'crawl_frequency_days' => 7,
    ]);
    $dueSite = crawlSite($org, 'due.crawl.example.com');
    $freshSite = crawlSite($org, 'fresh.crawl.example.com');
    $busySite = crawlSite($org, 'busy.crawl.example.com');

    // Last finished run 8 days ago on a weekly plan -> due again.
    // created_at is guarded, so age is set via forceFill after create.
    makeCrawlRun($dueSite, ['status' => 'completed'])
        ->forceFill(['created_at' => now()->subDays(8)])->save();
    // Crawled yesterday -> not due.
    makeCrawlRun($freshSite, ['status' => 'completed'])
        ->forceFill(['created_at' => now()->subDay()])->save();
    // In flight -> never stacked, even though the last run is ancient.
    makeCrawlRun($busySite, ['status' => 'running', 'completed_at' => null])
        ->forceFill(['created_at' => now()->subDays(30)])->save();

    // A failed run still counts as "the site was recently attempted".
    $failedSite = crawlSite($org, 'failed.crawl.example.com');
    makeCrawlRun($failedSite, ['status' => 'failed', 'completed_at' => null])
        ->forceFill(['created_at' => now()->subHour()])->save();

    // A plan without a crawl entitlement is never crawled.
    [, $freeOrg] = makeOrgUser('crawl-schedule-free@example.com');
    crawlSite($freeOrg, 'free.crawl.example.com');

    $this->artisan('insightistic:crawl-scheduled')->assertSuccessful();

    Queue::assertPushed(CrawlSiteJob::class, 1);
    Queue::assertPushed(CrawlSiteJob::class, function (CrawlSiteJob $job) use ($dueSite) {
        $props = crawlJobProps($job);
        $site = $props['site'] ?? null;
        $siteId = $site instanceof Site ? $site->id : ($props['siteId'] ?? $props['site_id'] ?? null);

        return $siteId === $dueSite->id
            && ($props['maxPages'] ?? $props['max_pages'] ?? null) === 1000
            && ($props['trigger'] ?? null) === 'schedule';
    });
});
