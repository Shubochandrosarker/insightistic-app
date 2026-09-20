<?php

use App\Domain\Search\OpportunityEngine;
use App\Domain\Search\SearchExplorer;
use App\Models\SeoPageDaily;
use App\Models\SeoQueryDaily;
use App\Models\SeoQueryPageDaily;
use App\Models\Site;

/** Local site factory — each test file stays self-contained when run alone.
 *  Prefixed opp* because Pest loads every test file into one process and
 *  SafeWooQueryEngineTest already declares an engineSite(). */
function oppEngineSite($org): Site
{
    return Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id,
        'name'            => 'Engine Test Store',
        'domain'          => 'engine-' . uniqid() . '.example.com',
    ]);
}

/**
 * Opportunity algorithms (Phase 5, roadmap 10-GOOGLE-SEARCH-CONSOLE).
 *
 * Rows are seeded straight into the daily GSC fact tables (workstream S1's
 * schema: query_hash = sha1(query text), page_hash = sha1(path)). The engine
 * itself computes everything from clicks/impressions/position sums — the
 * stored per-day ctr column is ignored, so tests seed it consistently anyway
 * to keep fixtures honest.
 */

/** [from, to, prevFrom, prevTo]: June 1-28 vs May 4-31 (two aligned 28d windows). */
function engineWindows(): array
{
    $from = \Carbon\CarbonImmutable::parse('2026-06-01');
    $to = \Carbon\CarbonImmutable::parse('2026-06-28');

    return [$from, $to, $from->subDays(28), $to->subDays(28)];
}

function engineSeedQuery(Site $site, string $date, string $query, int $clicks, int $impressions, ?float $position = null): void
{
    SeoQueryDaily::create([
        'site_id'     => $site->id,
        'date'        => $date,
        'query_hash'  => sha1($query),
        'query'       => $query,
        'clicks'      => $clicks,
        'impressions' => $impressions,
        'ctr'         => $impressions > 0 ? round($clicks / $impressions, 4) : null,
        'position'    => $position,
    ]);
}

function engineSeedPage(Site $site, string $date, string $path, int $clicks, int $impressions, ?float $position = null): void
{
    SeoPageDaily::create([
        'site_id'     => $site->id,
        'date'        => $date,
        'page_hash'   => sha1($path),
        'page_path'   => $path,
        'clicks'      => $clicks,
        'impressions' => $impressions,
        'ctr'         => $impressions > 0 ? round($clicks / $impressions, 4) : null,
        'position'    => $position,
    ]);
}

function engineSeedPair(Site $site, string $date, string $query, string $path, int $clicks, int $impressions, ?float $position = null): void
{
    SeoQueryPageDaily::create([
        'site_id'     => $site->id,
        'date'        => $date,
        'query_hash'  => sha1($query),
        'page_hash'   => sha1($path),
        'clicks'      => $clicks,
        'impressions' => $impressions,
        'position'    => $position,
    ]);
}

function engineFor(Site $site): OpportunityEngine
{
    return new OpportunityEngine(new SearchExplorer);
}

it('flags winners with a human reason', function () {
    [$user, $org] = makeOrgUser('seo-win@example.com');
    $site = oppEngineSite($org);
    [$from, $to, $prevFrom, $prevTo] = engineWindows();

    engineSeedQuery($site, '2026-06-05', 'best running shoes', 30, 200, 3.0);
    engineSeedQuery($site, '2026-06-12', 'best running shoes', 30, 200, 3.0);
    engineSeedQuery($site, '2026-05-10', 'best running shoes', 20, 150, 4.0);

    $winners = engineFor($site)->winners($site, $from, $to, $prevFrom, $prevTo);

    expect($winners)->toHaveCount(1)
        ->and($winners[0]['query'])->toBe('best running shoes')
        ->and($winners[0]['clicks'])->toBe(60)
        ->and($winners[0]['prev_clicks'])->toBe(20)
        ->and($winners[0]['clicks_delta'])->toBe(40)
        ->and($winners[0]['reason'])->toBe('clicks up 200% (20 → 60)');
});

it('caps every list at fifty items', function () {
    [$user, $org] = makeOrgUser('seo-cap@example.com');
    $site = oppEngineSite($org);
    [$from, $to, $prevFrom, $prevTo] = engineWindows();

    for ($i = 0; $i < 60; $i++) {
        engineSeedQuery($site, '2026-06-05', "cap query {$i}", 50, 100, 5.0);
        engineSeedQuery($site, '2026-05-05', "cap query {$i}", 10, 60, 5.0);
    }

    $winners = engineFor($site)->winners($site, $from, $to, $prevFrom, $prevTo);

    expect(count($winners))->toBe(OpportunityEngine::CAP);
});

it('flags losers but floors tiny prior impressions', function () {
    [$user, $org] = makeOrgUser('seo-lose@example.com');
    $site = oppEngineSite($org);
    [$from, $to, $prevFrom, $prevTo] = engineWindows();

    // Real loser: 300 prior impressions, 80 → 10 clicks.
    engineSeedQuery($site, '2026-06-08', 'great pizza oven', 10, 40, 8.0);
    engineSeedQuery($site, '2026-05-15', 'great pizza oven', 80, 300, 5.0);

    // Noise: bigger absolute drop (30 clicks) but only 10 prior impressions —
    // below MIN_IMPRESSIONS, so it must never be flagged.
    engineSeedQuery($site, '2026-06-08', 'tiny niche query', 0, 5, 30.0);
    engineSeedQuery($site, '2026-05-15', 'tiny niche query', 30, 10, 30.0);

    $losers = engineFor($site)->losers($site, $from, $to, $prevFrom, $prevTo);

    expect($losers)->toHaveCount(1)
        ->and($losers[0]['query'])->toBe('great pizza oven')
        ->and($losers[0]['clicks_delta'])->toBe(-70)
        ->and($losers[0]['reason'])->toBe('clicks down 87.5% (80 → 10)');
});

it('flags striking distance queries with the expected ctr in the reason', function () {
    [$user, $org] = makeOrgUser('seo-strike@example.com');
    $site = oppEngineSite($org);
    [$from, $to, $prevFrom, $prevTo] = engineWindows();

    // Position 6.5, 1000 impressions, 4% CTR — inside the 4-20 band, above
    // the floor, under the default curve's ~4.5% expected at rank 6-8.
    engineSeedQuery($site, '2026-06-05', 'striking distance query', 20, 500, 6.5);
    engineSeedQuery($site, '2026-06-12', 'striking distance query', 20, 500, 6.5);

    // Already ranking top — not an opportunity.
    engineSeedQuery($site, '2026-06-05', 'top ranking query', 30, 500, 1.5);

    // In the band but under the impression floor.
    engineSeedQuery($site, '2026-06-05', 'low volume query', 0, 20, 10.0);

    $items = engineFor($site)->strikingDistance($site, $from, $to, $prevFrom, $prevTo);

    expect($items)->toHaveCount(1)
        ->and($items[0]['query'])->toBe('striking distance query')
        ->and($items[0]['position'])->toBe(6.5)
        ->and($items[0]['score'])->toBe(5.0) // 1000 * (0.045 - 0.04)
        ->and($items[0]['reason'])->toContain('avg position 6.5')
        ->and($items[0]['reason'])->toContain('CTR 4% vs ~4.5% expected at this position');
});

it('flags ctr opportunities against the sites own band curve with a fallback', function () {
    [$user, $org] = makeOrgUser('seo-ctr@example.com');
    $site = oppEngineSite($org);
    [$from, $to, $prevFrom, $prevTo] = engineWindows();

    // Band 6-8 gets 1400 site impressions (>= 500): the site's own curve
    // applies. Band ctr = 44/1400 ≈ 3.14%; the weak query (1%) underperforms
    // it, the strong one (4%) does not.
    engineSeedQuery($site, '2026-06-05', 'strong band query', 20, 500, 6.5);
    engineSeedQuery($site, '2026-06-12', 'strong band query', 20, 500, 6.5);
    engineSeedQuery($site, '2026-06-05', 'weak ctr query', 2, 200, 7.0);
    engineSeedQuery($site, '2026-06-12', 'weak ctr query', 2, 200, 7.0);

    // Band 21-30 only has 100 site impressions (< 500): the universal
    // fallback curve (~1.2%) decides, and 0% CTR is far below 60% of it.
    engineSeedQuery($site, '2026-06-05', 'deep rank query', 0, 100, 25.0);

    $items = engineFor($site)->ctrOpportunities($site, $from, $to, $prevFrom, $prevTo);

    $byQuery = collect($items)->keyBy('query');

    expect($items)->toHaveCount(2)
        ->and($byQuery->has('weak ctr query'))->toBeTrue()
        ->and($byQuery->has('deep rank query'))->toBeTrue()
        ->and($byQuery['weak ctr query']['band'])->toBe('6-8')
        ->and($byQuery['weak ctr query']['reason'])->toContain("site's own curve")
        ->and($byQuery['weak ctr query']['reason'])->toContain('~3.1%')
        ->and($byQuery['deep rank query']['reason'])->toContain('universal fallback curve');
});

it('flags decayed content but ignores low-volume noise', function () {
    [$user, $org] = makeOrgUser('seo-decay@example.com');
    $site = oppEngineSite($org);
    [$from, $to, $prevFrom, $prevTo] = engineWindows();

    // Real decay: 40 prior clicks → 5.
    engineSeedQuery($site, '2026-06-05', 'evergreen guide', 5, 100, 9.0);
    engineSeedQuery($site, '2026-05-05', 'evergreen guide', 20, 300, 9.0);
    engineSeedQuery($site, '2026-05-12', 'evergreen guide', 20, 300, 9.0);

    // Noise: 8 prior clicks is below the MIN_PREV_CLICKS floor.
    engineSeedQuery($site, '2026-06-05', 'noisy small query', 0, 10, 12.0);
    engineSeedQuery($site, '2026-05-05', 'noisy small query', 8, 20, 12.0);

    // Halved exactly: 20 → 10 is NOT < 0.5 * prev, so not decay.
    engineSeedQuery($site, '2026-06-05', 'boundary query', 10, 100, 8.0);
    engineSeedQuery($site, '2026-05-05', 'boundary query', 20, 200, 8.0);

    $decay = engineFor($site)->contentDecay($site, $from, $to, $prevFrom, $prevTo);

    expect($decay)->toHaveCount(1)
        ->and($decay[0]['dimension'])->toBe('query')
        ->and($decay[0]['query'])->toBe('evergreen guide')
        ->and($decay[0]['reason'])->toBe('decayed from 40 to 5 clicks');
});

it('flags cannibalization candidates with competing pages', function () {
    [$user, $org] = makeOrgUser('seo-cann@example.com');
    $site = oppEngineSite($org);
    [$from, $to, $prevFrom, $prevTo] = engineWindows();

    engineSeedPair($site, '2026-06-05', 'best crm for agencies', '/guides/crm-a', 10, 50, 4.0);
    engineSeedPair($site, '2026-06-12', 'best crm for agencies', '/guides/crm-a', 10, 50, 4.0);
    engineSeedPair($site, '2026-06-05', 'best crm for agencies', '/guides/crm-b', 5, 60, 6.0);
    engineSeedPair($site, '2026-06-12', 'best crm for agencies', '/guides/crm-b', 5, 60, 6.0);
    engineSeedPage($site, '2026-06-05', '/guides/crm-a', 20, 100, 4.0);
    engineSeedPage($site, '2026-06-05', '/guides/crm-b', 10, 120, 6.0);

    // Single landing page above the floor — not a candidate.
    engineSeedPair($site, '2026-06-05', 'single page query', '/only/page', 10, 50, 3.0);
    engineSeedPage($site, '2026-06-05', '/only/page', 10, 50, 3.0);

    $candidates = engineFor($site)->cannibalization($site, $from, $to, $prevFrom, $prevTo);

    expect($candidates)->toHaveCount(1)
        ->and($candidates[0]['query'])->toBe('best crm for agencies')
        ->and($candidates[0]['is_candidate'])->toBeTrue()
        ->and($candidates[0]['pages'])->toHaveCount(2)
        ->and($candidates[0]['pages'][0]['page_path'])->toBe('/guides/crm-a') // sorted by clicks desc
        ->and($candidates[0]['pages'][0]['clicks'])->toBe(20)
        ->and($candidates[0]['total_clicks'])->toBe(30)
        ->and($candidates[0]['total_impressions'])->toBe(220)
        ->and($candidates[0]['reason'])->toBe('2 pages compete · top two split 20/10 clicks');
});

it('returns every report from all() with non-empty reasons', function () {
    [$user, $org] = makeOrgUser('seo-all@example.com');
    $site = oppEngineSite($org);
    [$from, $to, $prevFrom, $prevTo] = engineWindows();

    engineSeedQuery($site, '2026-06-05', 'all winner query', 50, 300, 3.0);
    engineSeedQuery($site, '2026-05-05', 'all winner query', 10, 100, 4.0);
    engineSeedQuery($site, '2026-06-05', 'all striking query', 5, 600, 8.5);

    $all = engineFor($site)->all($site, $from, $to, $prevFrom, $prevTo);

    expect($all)->toHaveKeys([
        'winners', 'losers', 'striking_distance', 'ctr_opportunities',
        'content_decay', 'cannibalization',
    ]);

    foreach ($all as $report => $items) {
        foreach ($items as $item) {
            expect($item['reason'])->toBeString()
                ->and(strlen($item['reason']))->toBeGreaterThan(0, "report [{$report}] had an empty reason");
        }
    }

    expect($all['winners'])->not->toBeEmpty()
        ->and($all['losers'])->toBeEmpty()
        ->and($all['striking_distance'])->not->toBeEmpty();
});
