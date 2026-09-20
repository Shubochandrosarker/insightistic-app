<?php

use App\Models\Organization;
use App\Models\RankKeyword;
use App\Models\Site;
use App\Services\Integrations\IntegrationFailure;
use App\Services\Rank\DataForSeoProvider;
use Illuminate\Support\Facades\Http;

/**
 * DataForSEO SERP provider (Phase 10, roadmap 13-RANK-TRACKER): config
 * gating, batch request shape (location/language naming, one task per
 * keyword), result mapping back to keyword ids, even cost distribution,
 * SERP feature collection, and the hard rule that credentials never leak
 * into an error message.
 */

const RANK_LOGIN = 'dfse-sample-login';
const RANK_PASSWORD = 'dfse-sample-password';

function rankOrgSite(Organization $org, string $domain = 'ranksite.example'): Site
{
    return Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id,
        'name'            => $domain,
        'domain'          => $domain,
    ]);
}

function rankKeyword(Site $site, string $keyword, array $overrides = []): RankKeyword
{
    $engine = $overrides['engine'] ?? RankKeyword::ENGINE_GOOGLE;
    $country = $overrides['country'] ?? null;
    $language = $overrides['language'] ?? 'en';
    $device = $overrides['device'] ?? RankKeyword::DEVICE_DESKTOP;
    $location = $overrides['location'] ?? null;

    return RankKeyword::create(array_merge([
        'site_id'     => $site->id,
        'keyword'     => $keyword,
        'engine'      => $engine,
        'country'     => $country,
        'language'    => $language,
        'device'      => $device,
        'location'    => $location,
        'active'      => true,
        'config_hash' => RankKeyword::makeConfigHash($keyword, $engine, $country, $language, $device, $location),
    ], array_diff_key($overrides, [
        'engine' => true, 'country' => true, 'language' => true,
        'device' => true, 'location' => true,
    ])));
}

function fakeDataForSeo(array $envelope, int $status = 200): void
{
    Http::fake([
        'api.dataforseo.com/v3/serp/google/organic/live/regular' => Http::response($envelope, $status),
    ]);
}

it('reports availability based on configured credentials', function () {
    config(['services.dataforseo.login' => null, 'services.dataforseo.password' => null]);
    expect(app(DataForSeoProvider::class)->available())->toBeFalse();

    config(['services.dataforseo.login' => RANK_LOGIN, 'services.dataforseo.password' => RANK_PASSWORD]);
    expect(app(DataForSeoProvider::class)->available())->toBeTrue();
});

it('maps SERP results back to keywords with even cost distribution and features', function () {
    [, $org] = makeOrgUser('rank-provider@example.com');
    $site = rankOrgSite($org);

    $found = rankKeyword($site, 'Running Shoes', ['country' => 'in']);
    $missing = rankKeyword($site, 'blue sneakers'); // no country → default market

    // One found (site's domain at rank 3), one beyond/without the site's
    // organic listing (site's only hit sits outside the top 100 → null).
    fakeDataForSeo([
        'status_code'    => 10000,
        'status_message' => 'Ok',
        'tasks'          => [
            [
                'id'          => 'task-1',
                'status_code' => 20000,
                'cost'        => 0.001,
                'data'        => ['keyword' => 'Running Shoes'],
                'result'      => [['items' => [
                    ['type' => 'paid', 'rank_absolute' => 1, 'url' => 'https://ads.example.com/x'],
                    ['type' => 'featured_snippet', 'rank_absolute' => 2, 'url' => 'https://wikipedia.example.org/shoes'],
                    ['type' => 'organic', 'domain' => 'competitor.example', 'rank_absolute' => 1, 'url' => 'https://competitor.example/a'],
                    ['type' => 'organic', 'domain' => 'www.ranksite.example', 'rank_absolute' => 3, 'url' => 'https://www.ranksite.example/shoes'],
                ]]],
            ],
            [
                'id'          => 'task-2',
                'status_code' => 20000,
                'cost'        => 0.003,
                'data'        => ['keyword' => 'blue sneakers'],
                'result'      => [['items' => [
                    ['type' => 'organic', 'domain' => 'competitor.example', 'rank_absolute' => 1, 'url' => 'https://competitor.example/b'],
                    ['type' => 'organic', 'domain' => 'ranksite.example', 'rank_absolute' => 150, 'url' => 'https://ranksite.example/deep'], // >100 → not found
                ]]],
            ],
        ],
    ]);

    $results = app(DataForSeoProvider::class)->checkBatch([$found, $missing]);

    expect($results)->toHaveCount(2);

    // Found: the site's own best organic listing, www. stripped on match.
    expect($results[$found->id]['position'])->toBe(3)
        ->and($results[$found->id]['ranking_url'])->toBe('https://www.ranksite.example/shoes')
        ->and($results[$found->id]['serp_features'])->toBe(['paid', 'featured_snippet'])
        ->and($results[$found->id]['provider_request_id'])->toBe('task-1');

    // Missing: real observation (null position), not an error.
    expect($results[$missing->id]['position'])->toBeNull()
        ->and($results[$missing->id]['ranking_url'])->toBeNull()
        ->and($results[$missing->id]['provider_request_id'])->toBe('task-2');

    // Even distribution of the batch's billable cost (0.001 + 0.003 = 0.004).
    expect((float) $results[$found->id]['cost_units'])->toBe(0.002)
        ->and((float) $results[$missing->id]['cost_units'])->toBe(0.002);
});

it('sends one task per keyword with location and language names', function () {
    [, $org] = makeOrgUser('rank-provider-request@example.com');
    $site = rankOrgSite($org, 'request.example');

    $india = rankKeyword($site, 'budget phones', ['country' => 'in']);
    $city = rankKeyword($site, 'pizza delivery', ['location' => 'Mumbai', 'country' => 'in']);
    $default = rankKeyword($site, 'generic thing', ['country' => null, 'language' => null]);

    fakeDataForSeo(['status_code' => 10000, 'tasks' => [
        ['id' => 't1', 'status_code' => 20000, 'cost' => 0, 'data' => ['keyword' => 'budget phones'], 'result' => [['items' => []]]],
        ['id' => 't2', 'status_code' => 20000, 'cost' => 0, 'data' => ['keyword' => 'pizza delivery'], 'result' => [['items' => []]]],
        ['id' => 't3', 'status_code' => 20000, 'cost' => 0, 'data' => ['keyword' => 'generic thing'], 'result' => [['items' => []]]],
    ]]);

    app(DataForSeoProvider::class)->checkBatch([$india, $city, $default]);

    Http::assertSent(function ($request) {
        if (str_starts_with((string) $request->header('Authorization')[0], 'Basic ') === false) {
            return false;
        }

        $tasks = $request->data();

        return count($tasks) === 3
            // ISO-2 'in' resolves through the extensible country map.
            && ($tasks[0]['location_name'] ?? null) === 'India'
            && ($tasks[0]['language_name'] ?? null) === 'English'
            // Free-text location column wins over the country map.
            && ($tasks[1]['location_name'] ?? null) === 'Mumbai'
            // Unknown/no country omits location entirely (provider default).
            && ! array_key_exists('location_name', $tasks[2])
            && ($tasks[2]['language_name'] ?? null) === 'English';
    });
});

it('throws IntegrationFailure when the envelope rejects the batch', function () {
    [, $org] = makeOrgUser('rank-provider-envfail@example.com');
    $site = rankOrgSite($org, 'envfail.example');

    $keyword = rankKeyword($site, 'doomed keyword');

    fakeDataForSeo(['status_code' => 40100, 'status_message' => 'Not enough funds'], 200);

    try {
        app(DataForSeoProvider::class)->checkBatch([$keyword]);
        $this->fail('Expected IntegrationFailure');
    } catch (IntegrationFailure $e) {
        expect($e->errorCode)->toBe('rank_provider_failed')
            ->and($e->retryable)->toBeFalse()
            ->and($e->getMessage())->not->toContain(RANK_LOGIN)
            ->and($e->getMessage())->not->toContain(RANK_PASSWORD);
    }
});

it('throws a retryable IntegrationFailure on HTTP errors without leaking credentials', function () {
    config(['services.dataforseo.login' => RANK_LOGIN, 'services.dataforseo.password' => RANK_PASSWORD]);

    [, $org] = makeOrgUser('rank-provider-httpfail@example.com');
    $site = rankOrgSite($org, 'httpfail.example');

    $keyword = rankKeyword($site, 'doomed keyword');

    fakeDataForSeo(['error' => 'boom'], 500);

    try {
        app(DataForSeoProvider::class)->checkBatch([$keyword]);
        $this->fail('Expected IntegrationFailure');
    } catch (IntegrationFailure $e) {
        expect($e->errorCode)->toBe('rank_provider_failed')
            ->and($e->retryable)->toBeTrue()
            ->and($e->getMessage())->not->toContain(RANK_LOGIN)
            ->and($e->getMessage())->not->toContain(RANK_PASSWORD);
    }
});

it('never turns a hard task error into a fake not-found result', function () {
    [, $org] = makeOrgUser('rank-provider-taskfail@example.com');
    $site = rankOrgSite($org, 'taskfail.example');

    $keyword = rankKeyword($site, 'doomed keyword');

    fakeDataForSeo(['status_code' => 10000, 'tasks' => [
        ['id' => 't1', 'status_code' => 40700, 'status_message' => 'Invalid location',
         'cost' => 0, 'data' => ['keyword' => 'doomed keyword'], 'result' => []],
    ]]);

    expect(fn () => app(DataForSeoProvider::class)->checkBatch([$keyword]))
        ->toThrow(IntegrationFailure::class);
});

it('splits batches larger than the per-call task cap into multiple posts', function () {
    [, $org] = makeOrgUser('rank-provider-chunk@example.com');
    $site = rankOrgSite($org, 'chunk.example');

    $keywords = [];
    foreach (range(1, 101) as $i) {
        $keywords[] = rankKeyword($site, 'keyword '.$i);
    }

    $tasks = [];
    foreach ($keywords as $keyword) {
        $tasks[] = ['id' => 't-'.$keyword->id, 'status_code' => 20000, 'cost' => 0,
            'data' => ['keyword' => $keyword->keyword], 'result' => [['items' => []]]];
    }
    fakeDataForSeo(['status_code' => 10000, 'tasks' => $tasks]);

    $results = app(DataForSeoProvider::class)->checkBatch($keywords);

    expect(count($results))->toBe(101);

    Http::assertSentCount(2); // 100 + 1
});
