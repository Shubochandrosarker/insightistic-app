<?php

use App\Domain\Search\SearchExplorer;
use App\Models\SeoQueryDaily;
use App\Models\Site;

/** Local site factory — each test file stays self-contained when run alone. */
function explorerSite($org): Site
{
    return \App\Models\Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id,
        'name'            => 'Explorer Test Store',
        'domain'          => 'explorer-' . uniqid() . '.example.com',
    ]);
}

/**
 * SearchExplorer (Phase 5, roadmap 10-GOOGLE-SEARCH-CONSOLE): window
 * aggregation math, filters, sorting and cursor pagination over
 * seo_query_daily.
 */

/** [from, to, prevFrom, prevTo]: June 1-28 vs May 4-31 (two aligned 28d windows). */
function explorerWindows(): array
{
    $from = \Carbon\CarbonImmutable::parse('2026-06-01');
    $to = \Carbon\CarbonImmutable::parse('2026-06-28');

    return [$from, $to, $from->subDays(28), $to->subDays(28)];
}

function explorerSeedQuery($site, string $date, string $query, int $clicks, int $impressions, ?float $position = null): void
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

function explorerWalkAll(SearchExplorer $explorer, $site, array $windows, int $perPage): array
{
    [$from, $to, $prevFrom, $prevTo] = $windows;

    $labels = collect();
    $cursor = null;
    $pages = 0;

    do {
        $result = $explorer->exploreQueries($site, $from, $to, $prevFrom, $prevTo, [], $perPage, $cursor);
        $labels = $labels->merge(collect($result['data'])->pluck('query'));
        $cursor = $result['meta']['next_cursor'];
        $pages++;
    } while ($cursor !== null && $pages < 100);

    return ['labels' => $labels, 'pages' => $pages];
}

it('aggregates clicks, impressions, ctr and position with impression weighting', function () {
    [$user, $org] = makeOrgUser('explorer-math@example.com');
    $site = explorerSite($org);
    [$from, $to, $prevFrom, $prevTo] = explorerWindows();

    explorerSeedQuery($site, '2026-06-05', 'weighted query', 10, 200, 5.0);
    explorerSeedQuery($site, '2026-06-12', 'weighted query', 30, 100, 10.0);

    $result = (new SearchExplorer)->exploreQueries($site, $from, $to);

    expect($result['data'])->toHaveCount(1);
    $row = $result['data'][0];

    expect($row['query'])->toBe('weighted query')
        ->and($row['clicks'])->toBe(40)
        ->and($row['impressions'])->toBe(300)
        ->and($row['ctr'])->toBe(0.133333)                     // 40 / 300 — weighted, not averaged
        ->and($row['position'])->toBe(6.67)                    // (5*200 + 10*100) / 300
        ->and($row['prev_clicks'])->toBe(0)
        ->and($row['prev_position'])->toBeNull()
        ->and($row['clicks_delta'])->toBe(0)
        ->and($row['position_delta'])->toBeNull()
        ->and($result['meta']['total_queries'])->toBe(1)
        ->and($result['meta']['has_more'])->toBeFalse()
        ->and($result['meta']['next_cursor'])->toBeNull()
        ->and($result['meta']['window']['from'])->toBe('2026-06-01')
        ->and($result['meta']['window']['to'])->toBe('2026-06-28')
        ->and($result['meta']['window']['prev_from'])->toBeNull();
});

it('merges the previous window and computes deltas', function () {
    [$user, $org] = makeOrgUser('explorer-prev@example.com');
    $site = explorerSite($org);
    [$from, $to, $prevFrom, $prevTo] = explorerWindows();

    explorerSeedQuery($site, '2026-06-05', 'delta query', 10, 200, 5.0);
    explorerSeedQuery($site, '2026-06-12', 'delta query', 30, 100, 10.0);
    explorerSeedQuery($site, '2026-05-10', 'delta query', 20, 100, 8.0);

    $row = (new SearchExplorer)->exploreQueries($site, $from, $to, $prevFrom, $prevTo)['data'][0];

    expect($row['prev_clicks'])->toBe(20)
        ->and($row['prev_impressions'])->toBe(100)
        ->and($row['prev_position'])->toBe(8.0)
        ->and($row['clicks_delta'])->toBe(20)
        ->and($row['impressions_delta'])->toBe(200)
        ->and($row['position_delta'])->toBe(-1.33); // 6.67 - 8.0, negative = improved
});

it('filters by query text and minimum impressions', function () {
    [$user, $org] = makeOrgUser('explorer-filter@example.com');
    $site = explorerSite($org);
    [$from, $to, $prevFrom, $prevTo] = explorerWindows();

    explorerSeedQuery($site, '2026-06-05', 'Alpha Shoes', 10, 300, 5.0);
    explorerSeedQuery($site, '2026-06-05', 'beta gadget', 5, 500, 9.0);
    explorerSeedQuery($site, '2026-06-05', 'alphabet soup', 1, 50, 15.0);

    $explorer = new SearchExplorer;

    // Case-insensitive contains.
    $byText = $explorer->exploreQueries($site, $from, $to, $prevFrom, $prevTo, ['q' => 'ALP']);
    expect(collect($byText['data'])->pluck('query')->sort()->values()->all())
        ->toBe(['Alpha Shoes', 'alphabet soup'])
        ->and($byText['meta']['total_queries'])->toBe(2);

    // min_impressions applies to the current window's impressions.
    $byFloor = $explorer->exploreQueries($site, $from, $to, $prevFrom, $prevTo, ['min_impressions' => 400]);
    expect(collect($byFloor['data'])->pluck('query')->all())->toBe(['beta gadget']);
});

it('sorts position best-first by default and honours explicit direction', function () {
    [$user, $org] = makeOrgUser('explorer-sort@example.com');
    $site = explorerSite($org);
    [$from, $to, $prevFrom, $prevTo] = explorerWindows();

    explorerSeedQuery($site, '2026-06-05', 'rank mid', 1, 100, 7.5);
    explorerSeedQuery($site, '2026-06-05', 'rank worst', 1, 100, 12.0);
    explorerSeedQuery($site, '2026-06-05', 'rank best', 1, 100, 3.0);

    $explorer = new SearchExplorer;

    $asc = $explorer->exploreQueries($site, $from, $to, $prevFrom, $prevTo, ['sort' => 'position']);
    expect(collect($asc['data'])->pluck('position')->all())->toBe([3.0, 7.5, 12.0]);

    $desc = $explorer->exploreQueries($site, $from, $to, $prevFrom, $prevTo, ['sort' => 'position', 'dir' => 'desc']);
    expect(collect($desc['data'])->pluck('position')->all())->toBe([12.0, 7.5, 3.0]);
});

it('paginates with cursors without losing or duplicating rows', function () {
    [$user, $org] = makeOrgUser('explorer-cursor@example.com');
    $site = explorerSite($org);
    [$from, $to, $prevFrom, $prevTo] = explorerWindows();

    for ($i = 0; $i < 25; $i++) {
        explorerSeedQuery($site, '2026-06-05', sprintf('cursor query %02d', $i), 100 + $i, 500, 5.0);
    }

    $walk = explorerWalkAll(new SearchExplorer, $site, [$from, $to, $prevFrom, $prevTo], 10);

    expect($walk['pages'])->toBe(3)
        ->and($walk['labels'])->toHaveCount(25)
        ->and($walk['labels']->unique())->toHaveCount(25)
        ->and($walk['labels']->sort()->values()->all())
            ->toBe(collect(range(0, 24))->map(fn ($i) => sprintf('cursor query %02d', $i))->sort()->values()->all());
});

it('explores pages with the same contract as queries', function () {
    [$user, $org] = makeOrgUser('explorer-pages@example.com');
    $site = explorerSite($org);
    [$from, $to, $prevFrom, $prevTo] = explorerWindows();

    \App\Models\SeoPageDaily::create([
        'site_id' => $site->id, 'date' => '2026-06-05', 'page_hash' => sha1('/blog/a'),
        'page_path' => '/blog/a', 'clicks' => 12, 'impressions' => 240, 'ctr' => 0.05, 'position' => 6.0,
    ]);
    \App\Models\SeoPageDaily::create([
        'site_id' => $site->id, 'date' => '2026-05-05', 'page_hash' => sha1('/blog/a'),
        'page_path' => '/blog/a', 'clicks' => 20, 'impressions' => 240, 'ctr' => 0.0833, 'position' => 5.0,
    ]);

    $result = (new SearchExplorer)->explorePages($site, $from, $to, $prevFrom, $prevTo);

    expect($result['data'])->toHaveCount(1)
        ->and($result['data'][0]['page_path'])->toBe('/blog/a')
        ->and($result['data'][0]['clicks'])->toBe(12)
        ->and($result['data'][0]['prev_clicks'])->toBe(20)
        ->and($result['data'][0]['clicks_delta'])->toBe(-8)
        ->and($result['meta']['total_queries'])->toBe(1); // same meta key, document-wide contract
});
