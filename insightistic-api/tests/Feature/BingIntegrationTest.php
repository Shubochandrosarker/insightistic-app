<?php

use App\Jobs\SyncBingSearchData;
use App\Jobs\SyncDataSource;
use App\Models\DataSourceConnection;
use App\Models\DataSourceCredential;
use App\Models\SeoDaily;
use App\Models\SeoQuerySnapshot;
use App\Models\Site;
use App\Services\Integrations\BingConnectionService;
use App\Services\Integrations\IntegrationFailure;
use App\Services\Integrations\IntegrationManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Bing Webmaster integration (Phase 6, roadmap 11-BING-WEBMASTER-INDEXNOW):
 * connect-by-API-key through the shared integration framework, sync into the
 * seo tables under source 'bing' with replay-safe upserts, and the hard rule
 * that the API key never escapes into an error message.
 */

// Low-entropy on purpose: gitleaks' generic-api-key rule flags hex fixtures.
const BING_KEY = 'bing-sample-key-for-tests';

function makeBingSite(\App\Models\Organization $org, string $domain = 'store.example.com'): Site
{
    return Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id,
        'name'            => 'Test Store',
        'domain'          => $domain,
    ]);
}

function makeBingCredential(Site $site, array $resource = []): DataSourceCredential
{
    return DataSourceCredential::withoutGlobalScope('organization')->create([
        'organization_id' => $site->organization_id,
        'site_id'         => $site->id,
        'source'          => 'bing',
        'provider'        => 'bing',
        'access_token'    => BING_KEY,
        'scopes_json'     => [],
        'resource_json'   => $resource ?: null,
    ]);
}

/** Bing's WCF timestamp format: "/Date(epochMillis)/". */
function bingDate(\Carbon\CarbonInterface $carbon): string
{
    return '/Date(' . ($carbon->getTimestamp() * 1000) . ')/';
}

/** Happy-path stubs for all three Bing endpoints used by the sync. */
function fakeBingHappyPath(): void
{
    // Dates relative to "now" so freshness stays deterministic: the latest
    // daily bucket ends yesterday-night, inside the 48h freshness window.
    $olderDay = now()->subDays(4)->startOfDay();
    $recentDay = now()->subDays(2)->startOfDay();
    $weekEnd = now()->subDays(3)->startOfDay();

    Http::fake([
        'ssl.bing.com/webmaster/api.svc/json/GetUserSites*' => Http::response([
            'd' => [['Url' => 'https://bingstore.example.com/', 'Title' => 'Bing Store', 'Username' => 'owner@example.com']],
        ]),
        'ssl.bing.com/webmaster/api.svc/json/GetRankAndTrafficStats*' => Http::response([
            'd' => [
                ['/Date(999)/', 'Clicks' => 8, 'Impressions' => 150], // malformed: no Date key, skipped
                ['Date' => bingDate($recentDay), 'Clicks' => 5, 'Impressions' => 100],
                ['Date' => bingDate($olderDay), 'Clicks' => 0, 'Impressions' => 0],
            ],
        ]),
        'ssl.bing.com/webmaster/api.svc/json/GetQueryStats*' => Http::response([
            'd' => [
                ['Query' => 'red widgets', 'Clicks' => 3, 'Impressions' => 60, 'AvgClickPosition' => 4.5, 'AvgImpressionPosition' => 9.5, 'Date' => bingDate($weekEnd)],
                ['Query' => 'blue widgets', 'Clicks' => 1, 'Impressions' => 90, 'AvgClickPosition' => 6.0, 'AvgImpressionPosition' => 11.0, 'Date' => bingDate($weekEnd)],
            ],
        ]),
    ]);
}

it('connects bing by verifying the api key, storing it encrypted and starting degraded', function () {
    [$user, $org] = makeOrgUser('bing-connect@example.com');
    $site = makeBingSite($org);

    Http::fake([
        'ssl.bing.com/webmaster/api.svc/json/GetUserSites*' => Http::response([
            'd' => [['Url' => 'https://bingstore.example.com/', 'Title' => 'Bing Store']],
        ]),
    ]);

    $credential = app(BingConnectionService::class)->connect($site, BING_KEY, $user->id);

    expect($credential->source)->toBe('bing')
        ->and($credential->provider)->toBe('bing')
        ->and($credential->access_token)->toBe(BING_KEY) // decryptable by the model
        ->and($credential->resource_json)->toBeNull();   // no site mapped yet

    // Stored encrypted: the raw column must not hold plaintext key material.
    $raw = DB::table('data_source_credentials')->value('access_token');
    expect($raw)->not->toBe(BING_KEY)->and($raw)->not->toContain('bing-test');

    // Connected but unusable until a Bing site is mapped (same semantics as
    // Google between grant and property selection).
    $state = DataSourceConnection::withoutGlobalScope('organization')->firstOrFail();
    expect($state->state)->toBe('degraded')->and($state->last_error_code)->toBe('no_property_selected');
});

it('rejects a bad bing api key before storing anything', function () {
    [$user, $org] = makeOrgUser('bing-badkey@example.com');
    $site = makeBingSite($org);

    Http::fake([
        'ssl.bing.com/*' => Http::response(['ErrorCode' => 2, 'Message' => 'Invalid api key specified.'], 400),
    ]);

    expect(fn () => app(BingConnectionService::class)->connect($site, BING_KEY, $user->id))
        ->toThrow(function (IntegrationFailure $e) {
            expect($e->errorCode)->toBe('bing_sites_failed')
                ->and($e->getMessage())->toBe('Invalid api key specified.')
                ->and($e->getMessage())->not->toContain(BING_KEY);
        });

    expect(DataSourceCredential::withoutGlobalScope('organization')->count())->toBe(0);
});

it('lists bing resources and completes the framework connect flow', function () {
    Queue::fake();
    fakeBingHappyPath();

    [$user, $org] = makeOrgUser('bing-flow@example.com');
    $site = makeBingSite($org);
    makeBingCredential($site);

    $resources = $this->actingAs($user)
        ->getJson("/api/sites/{$site->id}/integrations/bing/resources")
        ->assertOk()
        ->json('resources');

    expect($resources)->toHaveCount(1)
        ->and($resources[0]['id'])->toBe('https://bingstore.example.com') // trailing slash normalised
        ->and($resources[0]['name'])->toBe('Bing Store');

    $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/integrations/bing/select", [
            'id'   => 'https://bingstore.example.com',
            'name' => 'Bing Store',
        ])->assertOk();

    $state = DataSourceConnection::withoutGlobalScope('organization')->firstOrFail();
    expect($state->state)->toBe('connected')->and($state->label)->toBe('Bing Store');

    // The framework queues its standard source-sync job for bing.
    Queue::assertPushed(SyncDataSource::class, fn ($job) => $job->siteId === $site->id && $job->source === 'bing');
});

it('syncs bing rows into the seo tables under the bing source, idempotently', function () {
    fakeBingHappyPath();

    [, $org] = makeOrgUser('bing-sync@example.com');
    $site = makeBingSite($org);
    makeBingCredential($site, ['id' => 'https://bingstore.example.com', 'name' => 'Bing Store']);

    $result = app(IntegrationManager::class)->sync($site, 'bing');

    $recentDate = now()->subDays(2)->startOfDay()->toDateString();
    $weekEndDate = now()->subDays(3)->startOfDay()->toDateString();

    expect($result['days'])->toBe(2)
        ->and($result['queries'])->toBe(2)
        ->and($result['latest_date'])->toBe($recentDate);

    $daily = SeoDaily::where('site_id', $site->id)->orderBy('date')->get();
    expect($daily)->toHaveCount(2)
        ->and($daily[0]['source'])->toBe('bing')
        ->and($daily[0]['clicks'])->toBe(0) // the older bucket
        ->and($daily[1]['date']->toDateString())->toBe($recentDate)
        ->and($daily[1]['clicks'])->toBe(5)
        ->and($daily[1]['impressions'])->toBe(100);

    $weekly = SeoQuerySnapshot::where('site_id', $site->id)->get();
    expect($weekly)->toHaveCount(2);

    $red = $weekly->firstWhere('query', 'red widgets');
    expect($red['source'])->toBe('bing')
        ->and($red['period_start']->toDateString())->toBe($weekEndDate) // weekly bucket: start == end
        ->and($red['period_end']->toDateString())->toBe($weekEndDate)
        ->and((float) $red['avg_click_position'])->toBe(4.5)
        ->and((float) $red['avg_impression_position'])->toBe(9.5)
        ->and($red['opportunity'])->toBeNull()
        ->and($red['prev_clicks'])->toBeNull();

    // Replay is the refresh path (Bing has no date-range filter): rows are
    // updated in place, never duplicated.
    app(IntegrationManager::class)->sync($site, 'bing');

    expect(SeoDaily::where('site_id', $site->id)->count())->toBe(2)
        ->and(SeoQuerySnapshot::where('site_id', $site->id)->count())->toBe(2);

    $state = DataSourceConnection::withoutGlobalScope('organization')->firstOrFail();
    expect($state->state)->toBe('connected')
        ->and($state->last_successful_at)->not->toBeNull()
        ->and($state->freshness)->toBe('fresh');
});

it('never leaks the api key when a bing sync fails', function () {
    [, $org] = makeOrgUser('bing-leak@example.com');
    $site = makeBingSite($org);
    makeBingCredential($site, ['id' => 'https://bingstore.example.com']);

    Http::fake([
        'ssl.bing.com/webmaster/api.svc/json/GetRankAndTrafficStats*' => Http::response(['ErrorCode' => 3, 'Message' => 'Quota exceeded for this account.'], 500),
        'ssl.bing.com/webmaster/api.svc/json/GetQueryStats*' => Http::response(['d' => []]),
    ]);

    try {
        app(IntegrationManager::class)->sync($site, 'bing');
        $this->fail('Expected an IntegrationFailure.');
    } catch (IntegrationFailure $e) {
        expect($e->errorCode)->toBe('bing_daily_failed')
            ->and($e->getMessage())->toContain('Quota exceeded')
            ->and($e->getMessage())->not->toContain(BING_KEY)
            ->and($e->retryable)->toBeTrue();
    }

    // The failure recorded for the Integrations page must be key-free too.
    $state = DataSourceConnection::withoutGlobalScope('organization')->firstOrFail();
    expect($state->state)->toBe('degraded')
        ->and($state->last_error_code)->toBe('bing_daily_failed')
        ->and($state->last_error_message)->not->toContain(BING_KEY);
});

it('sweeps every mapped bing credential and can be scoped to one site', function () {
    fakeBingHappyPath();

    [, $orgA] = makeOrgUser('bing-job-a@example.com');
    [, $orgB] = makeOrgUser('bing-job-b@example.com');
    $siteA = makeBingSite($orgA, 'a.example.com');
    $siteB = makeBingSite($orgB, 'b.example.com');
    makeBingCredential($siteA, ['id' => 'https://bingstore.example.com']);
    makeBingCredential($siteB, ['id' => 'https://bingstore.example.com']);

    (new SyncBingSearchData)->handle(app(IntegrationManager::class));

    expect(SeoDaily::where('source', 'bing')->distinct()->get(['site_id']))->toHaveCount(2);

    // A site-scoped run touches only its own site (B and A stay at 2 rows each).
    (new SyncBingSearchData($siteB->id))->handle(app(IntegrationManager::class));

    expect(SeoDaily::where('site_id', $siteB->id)->count())->toBe(2)
        ->and(SeoDaily::where('site_id', $siteA->id)->count())->toBe(2);
});

it('skips bing credentials that have no mapped site yet', function () {
    fakeBingHappyPath();

    [, $org] = makeOrgUser('bing-skip@example.com');
    $site = makeBingSite($org);
    makeBingCredential($site); // connected, degraded, no resource chosen

    (new SyncBingSearchData)->handle(app(IntegrationManager::class));

    expect(SeoDaily::count())->toBe(0)
        ->and(SeoQuerySnapshot::count())->toBe(0);
});

it('disconnects bing by deleting the credential without any upstream revoke', function () {
    Http::fake([
        'ssl.bing.com/*'  => Http::response(['d' => []]),
        '*'               => Http::response([], 500), // any unexpected call (e.g. a Google revoke) would fail loudly
    ]);

    [$user, $org] = makeOrgUser('bing-disconnect@example.com');
    $site = makeBingSite($org);
    makeBingCredential($site, ['id' => 'https://bingstore.example.com']);
    DataSourceConnection::recordAttempt($org->id, $site->id, 'bing', ['state' => 'connected', 'label' => 'Bing Store']);

    $this->actingAs($user)
        ->deleteJson("/api/sites/{$site->id}/integrations/bing")
        ->assertOk();

    expect(DataSourceCredential::withoutGlobalScope('organization')->count())->toBe(0);

    $state = DataSourceConnection::withoutGlobalScope('organization')->firstOrFail();
    expect($state->state)->toBe('not_configured')->and($state->label)->toBeNull();
});

it('shows bing on the integrations page as a key-authenticated direct source', function () {
    [$user, $org] = makeOrgUser('bing-index@example.com');
    $site = makeBingSite($org);

    $response = $this->actingAs($user)->getJson("/api/sites/{$site->id}/integrations")->assertOk();

    $bing = collect($response->json('sources'))->firstWhere('source', 'bing');

    expect($bing['connectable'])->toBeTrue()
        ->and($bing['auth_type'])->toBe('api_key')
        ->and($bing['configured'])->toBeTrue() // no server-level credential needed
        ->and($bing['connected'])->toBeFalse();
});
