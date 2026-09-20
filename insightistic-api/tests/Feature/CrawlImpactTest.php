<?php

use App\Models\CrawlIssue;
use App\Models\CrawlRun;
use App\Models\Organization;
use App\Models\SeoPageDaily;
use App\Models\Site;
use App\Services\Crawler\CrawlHealthScore;
use App\Support\SeoGrain;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Phase 8 GSC-impact weighting (doc 12: "a missing title on a page with
 * 100k GSC impressions should rank above the same issue on an unvisited
 * archive page"): CrawlHealthScore::calculateWeighted math, the weighted
 * forSite() mode, and the health endpoint's ?weighted=1 opt-in.
 *
 * The uniform calculate() contract is pinned by CrawlHealthScoreTest and
 * must stay untouched — this file only covers the weighted additions.
 */

function impactIssue(Site $site, string $path, string $severity = 'high', string $category = 'on_page'): CrawlIssue
{
    $run = CrawlRun::query()->create([
        'site_id' => $site->id,
        'status' => 'completed',
        'trigger' => 'manual',
        'started_at' => Carbon::now()->subHour(),
        'completed_at' => Carbon::now(),
        'max_pages' => 10,
    ]);

    return CrawlIssue::query()->create([
        'site_id' => $site->id,
        'run_id' => $run->id,
        'issue_code' => 'missing_title',
        'severity' => $severity,
        'category' => $category,
        'fingerprint' => sha1('missing_title|'.$path.'|'.Str::random(6)),
        'target_url' => 'https://'.$site->domain.$path,
        'status' => 'open',
        'first_seen_at' => Carbon::now()->subDay(),
        'last_seen_at' => Carbon::now(),
    ]);
}

it('doubles the penalty of issues on pages with 1000+ impressions', function () {
    $issues = [
        ['category' => 'on_page', 'severity' => 'high', 'target_url' => 'https://x.example.com/hot'],
        ['category' => 'on_page', 'severity' => 'high', 'target_url' => '/cold'],
    ];
    $impressions = ['/hot' => 5000, '/cold' => 0];

    $weighted = CrawlHealthScore::calculateWeighted($issues, $impressions);
    $uniform = CrawlHealthScore::calculate($issues);

    // 6 points x2 for /hot, x1 for /cold = 18 — under the 20-point cap.
    expect($weighted['breakdown']['on_page']['penalty'])->toBe(18)
        ->and($weighted['score'])->toBe(82)
        // same issues without impact data: 6 + 6 = 12
        ->and($uniform['breakdown']['on_page']['penalty'])->toBe(12)
        ->and($uniform['score'])->toBe(88);

    // Issue counts and weights are untouched by the mode.
    expect($weighted['breakdown']['on_page']['open_issues'])->toBe(2)
        ->and($weighted['breakdown']['on_page']['weight'])->toBe(20);
});

it('scales mid-traffic pages 1.5x and leaves zero-impression pages at baseline', function () {
    expect(CrawlHealthScore::impactFactor(99))->toBe(1.0)
        ->and(CrawlHealthScore::impactFactor(100))->toBe(1.5)
        ->and(CrawlHealthScore::impactFactor(999))->toBe(1.5)
        ->and(CrawlHealthScore::impactFactor(1000))->toBe(2.0)
        ->and(CrawlHealthScore::impactFactor(0))->toBe(1.0);
});

it('still caps weighted categories at their own weight', function () {
    // Four criticals on 100k-impression pages: 12 x2 x4 = 96 raw, capped at 20.
    $issues = array_map(
        fn (int $i) => ['category' => 'on_page', 'severity' => 'critical', 'target_url' => '/p'.$i],
        range(1, 4),
    );

    $result = CrawlHealthScore::calculateWeighted($issues, array_fill_keys(['/p1', '/p2', '/p3', '/p4'], 100000));

    expect($result['breakdown']['on_page']['penalty'])->toBe(20)
        ->and($result['breakdown']['on_page']['open_issues'])->toBe(4)
        ->and($result['score'])->toBe(80);
});

it('matches impressions by path regardless of host in target_url', function () {
    $result = CrawlHealthScore::calculateWeighted(
        [['category' => 'crawlability', 'severity' => 'high', 'target_url' => 'https://www.x.example.com/deep/page?q=1']],
        ['/deep/page' => 2000],
    );

    // Host, scheme and query stripped; 6 x2 = 12 of the 30 cap.
    expect($result['breakdown']['crawlability']['penalty'])->toBe(12)
        ->and($result['score'])->toBe(88);
});

it('scores weighted and uniform modes through forSite', function () {
    [, $org] = makeOrgUser('impact-service@example.com');
    $site = Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id,
        'name' => 'impact.example.com',
        'domain' => 'impact.example.com',
    ]);

    impactIssue($site, '/hot');
    impactIssue($site, '/quiet');

    SeoPageDaily::query()->create([
        'site_id' => $site->id,
        'date' => Carbon::now()->subDays(2),
        'page_hash' => SeoGrain::hash('/hot'),
        'page_path' => '/hot',
        'clicks' => 90,
        'impressions' => 1200,
        'ctr' => 0.075,
        'position' => 8.1,
        'created_at' => Carbon::now(),
    ]);

    $score = app(CrawlHealthScore::class);

    $weighted = $score->forSite($site, true);
    expect($weighted['mode'])->toBe('gsc_weighted')
        ->and($weighted['breakdown']['on_page']['penalty'])->toBe(18) // 6x2 + 6x1
        ->and($weighted['score'])->toBe(82)
        ->and($weighted['top_issues'])->toHaveCount(2);

    $uniform = $score->forSite($site);
    expect($uniform['mode'])->toBe('uniform')
        ->and($uniform['breakdown']['on_page']['penalty'])->toBe(12)
        ->and($uniform['score'])->toBe(88);
});

it('lets the health endpoint opt into the weighted mode without changing the default response', function () {
    [$user, $org] = makeOrgUser('impact-api@example.com');
    $site = Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id,
        'name' => 'impactapi.example.com',
        'domain' => 'impactapi.example.com',
    ]);

    impactIssue($site, '/hot');

    SeoPageDaily::query()->create([
        'site_id' => $site->id,
        'date' => Carbon::now()->subDay(),
        'page_hash' => SeoGrain::hash('/hot'),
        'page_path' => '/hot',
        'clicks' => 10,
        'impressions' => 3000,
        'ctr' => 0.01,
        'position' => 5.0,
        'created_at' => Carbon::now(),
    ]);

    $weighted = $this->actingAs($user)
        ->getJson("/api/sites/{$site->id}/crawl/health?weighted=1")
        ->assertOk()
        ->assertJsonPath('mode', 'gsc_weighted')
        ->assertJsonPath('score', 88); // 6 x2 = 12

    expect($weighted->json('breakdown.on_page.penalty'))->toBe(12);

    // Default response unchanged: no mode flag, uniform math.
    $uniform = $this->actingAs($user)
        ->getJson("/api/sites/{$site->id}/crawl/health")
        ->assertOk()
        ->assertJsonPath('score', 94);

    expect($uniform->json('mode'))->toBeNull();
});
