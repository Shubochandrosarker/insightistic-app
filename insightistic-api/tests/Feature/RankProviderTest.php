<?php

use App\Models\Organization;
use App\Models\RankKeyword;
use App\Models\Site;
use App\Services\Integrations\IntegrationFailure;
use App\Services\Rank\DataForSeoProvider;
use App\Services\Rank\RankProvider;
use App\Services\Rank\RankProviderFactory;
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

/**
 * Fake the live endpoint. Live calls carry ONE task each, so the fake answers
 * per request: $byKeyword maps keyword text => task fields (status_code,
 * cost, items, ...) and echoes the request's tag back like DataForSEO does.
 * An `http` key on a task forces that HTTP status; `envelope` overrides the
 * envelope status/message.
 */
function fakeDataForSeoLive(array $byKeyword): void
{
    Http::fake([
        'api.dataforseo.com/v3/serp/google/organic/live/regular' => function ($request) use ($byKeyword) {
            $task = $request->data()[0] ?? [];
            $spec = $byKeyword[$task['keyword'] ?? ''] ?? ['items' => []];

            if (isset($spec['http'])) {
                return Http::response(['error' => 'boom'], $spec['http']);
            }

            if (isset($spec['envelope'])) {
                return Http::response($spec['envelope'], 200);
            }

            return Http::response([
                'status_code'    => 20000,
                'status_message' => 'Ok.',
                'cost'           => $spec['cost'] ?? 0.02,
                'tasks'          => [[
                    'id'             => $spec['id'] ?? ('task-'.$task['tag']),
                    'status_code'    => $spec['status_code'] ?? 20000,
                    'status_message' => $spec['status_message'] ?? 'Ok.',
                    'cost'           => $spec['cost'] ?? 0.02,
                    'data'           => $task,
                    'result'         => ($spec['status_code'] ?? 20000) === 20000 ? [['items' => $spec['items'] ?? []]] : null,
                ]],
            ], 200);
        },
    ]);
}

it('reports availability based on configured credentials', function () {
    config(['services.dataforseo.login' => null, 'services.dataforseo.password' => null]);
    expect(app(DataForSeoProvider::class)->available())->toBeFalse();

    config(['services.dataforseo.login' => RANK_LOGIN, 'services.dataforseo.password' => RANK_PASSWORD]);
    expect(app(DataForSeoProvider::class)->available())->toBeTrue();
});

it('maps SERP results back to keywords with the measured per-task cost and features', function () {
    [, $org] = makeOrgUser('rank-provider@example.com');
    $site = rankOrgSite($org);

    $found = rankKeyword($site, 'Running Shoes', ['country' => 'in']);
    $missing = rankKeyword($site, 'blue sneakers');

    fakeDataForSeoLive([
        'Running Shoes' => ['id' => 'task-1', 'cost' => 0.02, 'items' => [
            ['type' => 'paid', 'rank_absolute' => 1, 'url' => 'https://ads.example.com/x'],
            ['type' => 'featured_snippet', 'rank_absolute' => 2, 'url' => 'https://wikipedia.example.org/shoes'],
            ['type' => 'organic', 'domain' => 'competitor.example', 'rank_absolute' => 1, 'url' => 'https://competitor.example/a'],
            ['type' => 'organic', 'domain' => 'www.ranksite.example', 'rank_absolute' => 3, 'url' => 'https://www.ranksite.example/shoes'],
        ]],
        'blue sneakers' => ['id' => 'task-2', 'cost' => 0.014, 'items' => [
            ['type' => 'organic', 'domain' => 'competitor.example', 'rank_absolute' => 1, 'url' => 'https://competitor.example/b'],
            ['type' => 'organic', 'domain' => 'ranksite.example', 'rank_absolute' => 150, 'url' => 'https://ranksite.example/deep'], // beyond depth 100
        ]],
    ]);

    $results = app(DataForSeoProvider::class)->checkBatch([$found, $missing]);

    expect($results)->toHaveCount(2);
    expect($results[$found->id]['position'])->toBe(3)
        ->and($results[$found->id]['ranking_url'])->toBe('https://www.ranksite.example/shoes')
        ->and($results[$found->id]['serp_features'])->toBe(['paid', 'featured_snippet'])
        ->and($results[$found->id]['provider_request_id'])->toBe('task-1')
        ->and((float) $results[$found->id]['cost_units'])->toBe(0.02);

    expect($results[$missing->id]['position'])->toBeNull()
        ->and($results[$missing->id]['ranking_url'])->toBeNull()
        ->and($results[$missing->id]['provider_request_id'])->toBe('task-2')
        ->and((float) $results[$missing->id]['cost_units'])->toBe(0.014);
});

it('sends ONE task per live request with depth, device, tag, location and language', function () {
    [, $org] = makeOrgUser('rank-provider-request@example.com');
    $site = rankOrgSite($org, 'request.example');

    $india = rankKeyword($site, 'budget phones', ['country' => 'in']);
    $city = rankKeyword($site, 'pizza delivery', ['location' => 'Mumbai', 'country' => 'in', 'device' => RankKeyword::DEVICE_MOBILE]);
    $default = rankKeyword($site, 'generic thing', ['country' => null, 'language' => null]);

    fakeDataForSeoLive([]);

    app(DataForSeoProvider::class)->checkBatch([$india, $city, $default]);

    Http::assertSentCount(3);

    $sent = collect(Http::recorded())->map(fn ($pair) => $pair[0]);
    expect($sent->every(fn ($r) => str_starts_with((string) $r->header('Authorization')[0], 'Basic ') && count($r->data()) === 1))->toBeTrue();

    $byKeyword = $sent->mapWithKeys(fn ($r) => [$r->data()[0]['keyword'] => $r->data()[0]]);

    expect($byKeyword['budget phones'])->toMatchArray(['location_name' => 'India', 'language_name' => 'English', 'depth' => 100, 'device' => 'desktop', 'tag' => (string) $india->id])
        ->and($byKeyword['pizza delivery'])->toMatchArray(['location_name' => 'Mumbai', 'device' => 'mobile', 'tag' => (string) $city->id])
        ->and($byKeyword['generic thing'])->not->toHaveKey('location_name')
        ->and($byKeyword['generic thing']['language_name'])->toBe('English');
});

it('honours DATAFORSEO_DEPTH within 10..200 and estimates cost per 10-result SERP', function () {
    config(['services.dataforseo.depth' => 50]);
    $p = app(DataForSeoProvider::class);
    expect($p->depth())->toBe(50)->and($p->serpsPerCheck())->toBe(5)->and($p->estimatedCostPerCheckUsd())->toBe(0.01);

    config(['services.dataforseo.depth' => 1000]);
    expect(app(DataForSeoProvider::class)->depth())->toBe(200);

    config(['services.dataforseo.depth' => 3]);
    expect(app(DataForSeoProvider::class)->depth())->toBe(10);
});

it('throws IntegrationFailure when the envelope rejects the only request', function () {
    [, $org] = makeOrgUser('rank-provider-envfail@example.com');
    $site = rankOrgSite($org, 'envfail.example');
    $keyword = rankKeyword($site, 'doomed keyword');

    fakeDataForSeoLive(['doomed keyword' => ['envelope' => ['status_code' => 40200, 'status_message' => 'Payment Required.']]]);

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

    fakeDataForSeoLive(['doomed keyword' => ['http' => 500]]);

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

    fakeDataForSeoLive(['doomed keyword' => ['status_code' => 40501, 'status_message' => 'Invalid Field: location_name.']]);

    expect(fn () => app(DataForSeoProvider::class)->checkBatch([$keyword]))
        ->toThrow(IntegrationFailure::class);
});

it('drops only the failed keywords from a mixed batch (they stay due) and keeps the rest', function () {
    [, $org] = makeOrgUser('rank-provider-partial@example.com');
    $site = rankOrgSite($org, 'partial.example');
    $good = rankKeyword($site, 'good keyword');
    $bad = rankKeyword($site, 'bad keyword');

    fakeDataForSeoLive(['bad keyword' => ['status_code' => 40501, 'status_message' => 'Invalid Field.']]);

    $results = app(DataForSeoProvider::class)->checkBatch([$good, $bad]);

    expect($results)->toHaveKey($good->id)->not->toHaveKey($bad->id);
});

it('treats the real Ok code 20000 as success (regression: the old adapter expected 10000)', function () {
    [, $org] = makeOrgUser('rank-provider-ok@example.com');
    $site = rankOrgSite($org, 'ok.example');
    $kw = rankKeyword($site, 'ok keyword');

    fakeDataForSeoLive(['ok keyword' => ['items' => [['type' => 'organic', 'domain' => 'ok.example', 'rank_absolute' => 7, 'url' => 'https://ok.example/']]]]);

    expect(app(DataForSeoProvider::class)->checkBatch([$kw])[$kw->id]['position'])->toBe(7);
});

it('resolves the provider from RANK_PROVIDER with DataForSEO as the default', function () {
    config(['insightistic.rank.provider' => null]);
    expect(app(RankProvider::class))->toBeInstanceOf(DataForSeoProvider::class)
        ->and(app(RankProvider::class)->name())->toBe('dataforseo');

    config(['insightistic.rank.provider' => 'nope']);
    expect(fn () => RankProviderFactory::make())->toThrow(InvalidArgumentException::class);
});

it('rank-provider-check reports not configured without calling the provider', function () {
    config(['services.dataforseo.login' => null, 'services.dataforseo.password' => null]);
    Http::fake();

    $this->artisan('insightistic:rank-provider-check')
        ->expectsOutputToContain('provider: dataforseo')
        ->expectsOutputToContain('configured: no')
        ->assertExitCode(0);

    Http::assertNothingSent();
});

it('rank-provider-check verifies credentials and balance via the free user_data endpoint', function () {
    config(['services.dataforseo.login' => RANK_LOGIN, 'services.dataforseo.password' => RANK_PASSWORD]);
    Http::fake([
        'api.dataforseo.com/v3/appendix/user_data' => Http::response(['status_code' => 20000, 'tasks' => [['result' => [['money' => ['balance' => 12.5]]]]]]),
    ]);

    $this->artisan('insightistic:rank-provider-check')
        ->expectsOutputToContain('credentials: ok (balance $12.50)')
        ->assertExitCode(0);
});
