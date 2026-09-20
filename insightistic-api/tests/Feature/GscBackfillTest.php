<?php

use App\Jobs\BackfillGscHistory;
use App\Models\DataSourceCredential;
use App\Models\SeoBackfillState;
use App\Models\SeoPageDaily;
use App\Models\SeoQueryDaily;
use App\Models\SeoQueryPageDaily;
use App\Models\Site;
use App\Services\Integrations\GscClient;
use App\Services\Integrations\IntegrationFailure;
use App\Support\SeoGrain;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Phase 5 search intelligence storage (workstream S1): the daily-grain
 * tables written by GSC pulls, their pagination, and the resumable
 * historical backfill job behind them.
 */

function gscGrainSite(\App\Models\Organization $org): Site
{
    return Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id,
        'name'            => 'Grain Store',
        'domain'          => 'grain.example.com',
    ]);
}

function gscGrainCredential(Site $site): DataSourceCredential
{
    return DataSourceCredential::withoutGlobalScope('organization')->create([
        'organization_id'  => $site->organization_id,
        'site_id'          => $site->id,
        'source'           => 'gsc',
        'provider'         => 'google',
        'access_token'     => 'at-grain',
        'refresh_token'    => 'rt-grain',
        'token_expires_at' => now()->addHour(),
        'resource_json'    => ['id' => 'https://grain.example.com/'],
    ]);
}

/** Route each GSC call to a payload shaped for the dimension it requested. */
function gscGrainFake(\Closure $onQueryDaily): \Closure
{
    return function (Request $request) use ($onQueryDaily) {
        $dims = $request['dimensions'] ?? [];

        if ($dims === ['date', 'query']) {
            return Http::response(['rows' => $onQueryDaily()]);
        }

        if ($dims === ['date', 'page']) {
            return Http::response(['rows' => [
                // Query string must be stripped when normalizing to page_path.
                ['keys' => ['2026-07-01', 'https://grain.example.com/landing?utm=x'], 'clicks' => 5, 'impressions' => 200, 'ctr' => 0.025, 'position' => 8.1],
            ]]);
        }

        if ($dims === ['date', 'query', 'page']) {
            return Http::response(['rows' => [
                ['keys' => ['2026-07-01', 'blue widgets', 'https://grain.example.com/landing'], 'clicks' => 4, 'impressions' => 150, 'ctr' => 0.0267, 'position' => 9.5],
            ]]);
        }

        // Legacy daily + snapshot calls.
        return Http::response(['rows' => [
            ['keys' => ['2026-07-01'], 'clicks' => 7, 'impressions' => 300, 'ctr' => 0.0233, 'position' => 12.5],
        ]]);
    };
}

it('writes daily-grain rows on sync and replays them idempotently', function () {
    [$user, $org] = makeOrgUser('seo-grain-sync@example.com');
    $site = gscGrainSite($org);
    $credential = gscGrainCredential($site);

    $queryFetches = 0;
    Http::fake(gscGrainFake(function () use (&$queryFetches) {
        $queryFetches++;

        // Provider numbers move between the two syncs; rows must update
        // in place, not duplicate.
        $clicks = $queryFetches === 1 ? 7 : 11;

        return [
            ['keys' => ['2026-07-01', 'blue widgets'], 'clicks' => $clicks, 'impressions' => 300, 'ctr' => 0.0233, 'position' => 14.2],
            ['keys' => ['2026-07-02', 'blue widgets'], 'clicks' => $clicks, 'impressions' => 120, 'ctr' => 0.025, 'position' => 13.0],
        ];
    }));

    $client = app(GscClient::class);

    $first = $client->sync($site, $credential, 7);
    $second = $client->sync($site, $credential, 7);

    expect($first['daily_query_rows'])->toBe(2)
        ->and($first['daily_page_rows'])->toBe(1)
        ->and($first['daily_query_page_rows'])->toBe(1)
        ->and($second['daily_query_rows'])->toBe(2)
        ->and($second['daily_page_rows'])->toBe(1)
        ->and($second['daily_query_page_rows'])->toBe(1)
        ->and(SeoQueryDaily::where('site_id', $site->id)->count())->toBe(2)
        ->and(SeoPageDaily::where('site_id', $site->id)->count())->toBe(1)
        ->and(SeoQueryPageDaily::where('site_id', $site->id)->count())->toBe(1);

    $query = SeoQueryDaily::where('site_id', $site->id)->where('date', '2026-07-01')->firstOrFail();
    expect($query->query)->toBe('blue widgets')
        ->and($query->query_hash)->toBe(SeoGrain::hash('blue widgets'))
        ->and($query->clicks)->toBe(11)
        ->and($query->position)->toBe(14.2);

    $page = SeoPageDaily::where('site_id', $site->id)->firstOrFail();
    expect($page->page_path)->toBe('/landing')
        ->and($page->page_hash)->toBe(SeoGrain::hash('/landing'))
        ->and($page->clicks)->toBe(5);

    $pair = SeoQueryPageDaily::where('site_id', $site->id)->firstOrFail();
    expect($pair->query_hash)->toBe(SeoGrain::hash('blue widgets'))
        ->and($pair->page_hash)->toBe(SeoGrain::hash('/landing'))
        ->and($pair->clicks)->toBe(4);
});

it('paginates a daily window when the first page returns exactly the row limit', function () {
    [$user, $org] = makeOrgUser('seo-grain-page@example.com');
    $site = gscGrainSite($org);
    $credential = gscGrainCredential($site);

    config()->set('insightistic.integrations.gsc_row_limit', 2);

    $pageOne = [
        ['keys' => ['2026-07-01', 'alpha'], 'clicks' => 1, 'impressions' => 10, 'ctr' => 0.1, 'position' => 5.0],
        ['keys' => ['2026-07-01', 'beta'], 'clicks' => 2, 'impressions' => 20, 'ctr' => 0.1, 'position' => 6.0],
    ];
    $pageTwo = [
        ['keys' => ['2026-07-02', 'alpha'], 'clicks' => 3, 'impressions' => 30, 'ctr' => 0.1, 'position' => 7.0],
    ];

    Http::fake([
        'www.googleapis.com/webmasters/*' => Http::sequence()
            ->push(['rows' => $pageOne])
            ->push(['rows' => $pageTwo]),
    ]);

    $written = app(GscClient::class)->fetchDailyWindow($site, $credential, '2026-07-01', '2026-07-02', 'queries');

    expect($written)->toBe(3)
        ->and(SeoQueryDaily::where('site_id', $site->id)->count())->toBe(3);

    // A full page must be followed by one more call resuming at startRow.
    Http::assertSentCount(2);
    Http::assertSent(function (Request $request) {
        return $request['startRow'] === 2 && $request['rowLimit'] === 2;
    });
});

it('backfills in 30-day chunks, tracks coverage progressively and ends done', function () {
    [$user, $org] = makeOrgUser('seo-grain-backfill@example.com');
    $site = gscGrainSite($org);
    gscGrainCredential($site);

    $windows = [];
    Http::fake(function (Request $request) use (&$windows) {
        $windows[] = [$request['startDate'], $request['endDate']];

        return Http::response(['rows' => [
            ['keys' => ['2026-06-15', 'chunk row'], 'clicks' => 1, 'impressions' => 10, 'ctr' => 0.1, 'position' => 5.0],
        ]]);
    });

    (new BackfillGscHistory($site, 'queries', '2026-06-01', '2026-07-15'))->handle(app(GscClient::class));

    // 45 days split into two chunk windows, each ending where coverage moved.
    expect($windows)->toBe([
        ['2026-06-01', '2026-06-30'],
        ['2026-07-01', '2026-07-15'],
    ]);

    $state = SeoBackfillState::where('site_id', $site->id)->where('dimension', 'queries')->firstOrFail();
    expect($state->status)->toBe('done')
        ->and($state->covered_from->toDateString())->toBe('2026-06-01')
        ->and($state->covered_to->toDateString())->toBe('2026-07-15')
        ->and($state->attempts)->toBe(1)
        ->and($state->last_error)->toBeNull()
        ->and(SeoQueryDaily::where('site_id', $site->id)->count())->toBe(2);
});

it('short-circuits a finished backfill unless forced', function () {
    [$user, $org] = makeOrgUser('seo-grain-done@example.com');
    $site = gscGrainSite($org);
    gscGrainCredential($site);

    SeoBackfillState::create([
        'site_id'      => $site->id,
        'dimension'    => 'pages',
        'covered_from' => '2026-06-01',
        'covered_to'   => '2026-06-30',
        'status'       => 'done',
        'attempts'     => 1,
    ]);

    Http::fake(['www.googleapis.com/webmasters/*' => Http::response(['rows' => []])]);

    // Same range, already done: no fetches, no attempt bump, no rewrites.
    (new BackfillGscHistory($site, 'pages', '2026-06-01', '2026-06-30'))->handle(app(GscClient::class));

    Http::assertNothingSent();

    $state = SeoBackfillState::where('site_id', $site->id)->where('dimension', 'pages')->firstOrFail();
    expect($state->status)->toBe('done')->and($state->attempts)->toBe(1);

    // Forced refresh is allowed to walk the range again.
    Http::fake(['www.googleapis.com/webmasters/*' => Http::response(['rows' => [
        ['keys' => ['2026-06-01', '/landing'], 'clicks' => 2, 'impressions' => 50, 'ctr' => 0.04, 'position' => 7.0],
    ]])]);

    (new BackfillGscHistory($site, 'pages', '2026-06-01', '2026-06-30', force: true))->handle(app(GscClient::class));

    $state = $state->fresh();
    expect($state->status)->toBe('done')
        ->and($state->attempts)->toBe(2)
        ->and($state->covered_from->toDateString())->toBe('2026-06-01')
        ->and($state->covered_to->toDateString())->toBe('2026-06-30')
        ->and(SeoPageDaily::where('site_id', $site->id)->count())->toBe(1);
});

it('keeps covered dates and records the failure when a chunk errors', function () {
    [$user, $org] = makeOrgUser('seo-grain-fail@example.com');
    $site = gscGrainSite($org);
    gscGrainCredential($site);

    Http::fake([
        'www.googleapis.com/webmasters/*' => Http::sequence()
            ->push(['rows' => [
                ['keys' => ['2026-06-10', 'kept row'], 'clicks' => 1, 'impressions' => 10, 'ctr' => 0.1, 'position' => 5.0],
            ]])
            ->push(['error' => ['message' => 'Backend Error']], 500),
    ]);

    $job = new BackfillGscHistory($site, 'queries', '2026-06-01', '2026-07-15');

    expect(fn () => $job->handle(app(GscClient::class)))->toThrow(IntegrationFailure::class);

    $state = SeoBackfillState::where('site_id', $site->id)->where('dimension', 'queries')->firstOrFail();
    expect($state->status)->toBe('failed')
        ->and($state->covered_from->toDateString())->toBe('2026-06-01')
        // Chunk one landed before chunk two failed: coverage stays intact,
        // so a retry resumes at 2026-07-01 instead of re-pulling June.
        ->and($state->covered_to->toDateString())->toBe('2026-06-30')
        ->and($state->attempts)->toBe(1)
        ->and($state->last_error)->toBe('Backend Error')
        ->and(SeoQueryDaily::where('site_id', $site->id)->count())->toBe(1);
});
