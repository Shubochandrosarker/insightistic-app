<?php

use App\Jobs\SyncDataSource;
use App\Models\DataSourceConnection;
use App\Models\DataSourceCredential;
use App\Models\SeoDaily;
use App\Models\Site;
use App\Models\TrafficDaily;
use App\Models\TrafficDimensionSnapshot;
use App\Services\Integrations\GoogleOAuthService;
use App\Services\Integrations\IntegrationManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * Direct SaaS integrations (spec Phase 3.2): the connection workflow for
 * GA4 / Search Console / Cloudflare, the tenant boundary around it, and the
 * hard rule that no token ever reaches the browser.
 */

function makeSiteFor(\App\Models\Organization $org, string $domain = 'store.example.com'): Site
{
    return Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id,
        'name'            => 'Test Store',
        'domain'          => $domain,
    ]);
}

function makeGoogleCredential(Site $site, string $source, array $resource = ['id' => '123456', 'name' => 'Prop']): DataSourceCredential
{
    return DataSourceCredential::withoutGlobalScope('organization')->create([
        'organization_id'  => $site->organization_id,
        'site_id'          => $site->id,
        'source'           => $source,
        'provider'         => 'google',
        'access_token'     => 'at-' . Str::random(10),
        'refresh_token'    => 'rt-' . Str::random(10),
        'token_expires_at' => now()->addHour(),
        'resource_json'    => $resource,
    ]);
}

it('lists sources with connection metadata and no secrets', function () {
    [$user, $org] = makeOrgUser('int-index@example.com');
    $site = makeSiteFor($org);
    makeGoogleCredential($site, 'ga4');

    $response = $this->actingAs($user)->getJson("/api/sites/{$site->id}/integrations")->assertOk();

    $ga4 = collect($response->json('sources'))->firstWhere('source', 'ga4');

    expect($ga4['connectable'])->toBeTrue()
        ->and($ga4['auth_type'])->toBe('oauth')
        ->and($ga4['connected'])->toBeTrue()
        ->and($ga4['detail']['resource']['id'])->toBe('123456');

    // The whole payload must never contain token material.
    $body = $response->getContent();
    expect($body)->not->toContain('refresh_token')
        ->and($body)->not->toContain('access_token');

    // Plugin-fed sources are not connectable from this screen.
    $woo = collect($response->json('sources'))->firstWhere('source', 'woocommerce');
    expect($woo['connectable'])->toBeFalse()->and($woo['auth_type'])->toBe('plugin');
});

it('mints a google consent url bound to a single-use state', function () {
    config()->set('insightistic.integrations.google.client_id', 'cid');
    config()->set('insightistic.integrations.google.client_secret', 'csecret');

    [$user, $org] = makeOrgUser('int-auth@example.com');
    $site = makeSiteFor($org);

    $url = $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/integrations/ga4/authorize-url")
        ->assertOk()
        ->json('authorize_url');

    expect($url)->toContain('accounts.google.com')
        ->and($url)->toContain('code_challenge_method=S256')
        ->and($url)->toContain('access_type=offline');

    parse_str(parse_url($url, PHP_URL_QUERY), $query);

    $oauth = app(GoogleOAuthService::class);
    expect($oauth->consumeState($query['state']))->toMatchArray([
        'site_id' => $site->id,
        'source'  => 'ga4',
        'user_id' => $user->id,
    ]);

    // Single use: the second read must fail.
    expect($oauth->consumeState($query['state']))->toBeNull();
});

it('rejects a callback with an unknown state and stores nothing', function () {
    $this->get('/api/integrations/google/callback?state=bogus&code=x')
        ->assertRedirectContains('reason=invalid_state');

    expect(DataSourceCredential::withoutGlobalScope('organization')->count())->toBe(0);
});

it('exchanges the code server-side and never puts a token in the redirect', function () {
    config()->set('insightistic.integrations.google.client_id', 'cid');
    config()->set('insightistic.integrations.google.client_secret', 'csecret');

    [$user, $org] = makeOrgUser('int-callback@example.com');
    $site = makeSiteFor($org);

    Http::fake([
        'oauth2.googleapis.com/token' => Http::response([
            'access_token'  => 'ya29-access',
            'refresh_token' => '1//refresh-secret',
            'expires_in'    => 3600,
            'scope'         => 'https://www.googleapis.com/auth/analytics.readonly',
        ]),
        'www.googleapis.com/oauth2/v3/userinfo' => Http::response(['email' => 'owner@example.com']),
    ]);

    $url = $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/integrations/gsc/authorize-url")
        ->json('authorize_url');
    parse_str(parse_url($url, PHP_URL_QUERY), $query);

    $redirect = $this->get('/api/integrations/google/callback?state=' . $query['state'] . '&code=auth-code');
    $location = $redirect->headers->get('Location');

    expect($location)->toContain('integration=connected')
        ->and($location)->not->toContain('refresh-secret')
        ->and($location)->not->toContain('ya29-access');

    $credential = DataSourceCredential::withoutGlobalScope('organization')->firstOrFail();
    $account = \App\Models\ProviderAccount::withoutGlobalScope('organization')->firstOrFail();

    expect($credential->source)->toBe('gsc')
        // Refresh tokens now live on the shared org provider account (spec §5)
        ->and($credential->provider_account_id)->toBe($account->id)
        ->and($credential->refresh_token)->toBeNull()
        ->and($credential->external_account_id)->toBe('owner@example.com')
        ->and($account->refresh_token)->toBe('1//refresh-secret')
        ->and($account->email)->toBe('owner@example.com');

    // Stored encrypted: the raw column must not be readable plaintext.
    $raw = \Illuminate\Support\Facades\DB::table('provider_accounts')->value('refresh_token');
    expect($raw)->not->toContain('1//refresh-secret');

    // Connected but unusable until a property is chosen.
    $state = DataSourceConnection::withoutGlobalScope('organization')->firstOrFail();
    expect($state->state)->toBe('degraded')->and($state->last_error_code)->toBe('no_property_selected');
});

it('refuses a callback when the user lost access while consenting', function () {
    config()->set('insightistic.integrations.google.client_id', 'cid');
    config()->set('insightistic.integrations.google.client_secret', 'csecret');

    [$user, $org] = makeOrgUser('int-removed@example.com');
    $site = makeSiteFor($org);

    $url = $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/integrations/ga4/authorize-url")
        ->json('authorize_url');
    parse_str(parse_url($url, PHP_URL_QUERY), $query);

    $org->users()->detach($user->id); // removed from the org mid-flow

    $this->get('/api/integrations/google/callback?state=' . $query['state'] . '&code=auth-code')
        ->assertRedirectContains('reason=forbidden');

    expect(DataSourceCredential::withoutGlobalScope('organization')->count())->toBe(0);
});

it('selects a property, marks the source connected and queues a first sync', function () {
    Queue::fake();

    [$user, $org] = makeOrgUser('int-select@example.com');
    $site = makeSiteFor($org);
    makeGoogleCredential($site, 'ga4', []);

    $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/integrations/ga4/select", ['id' => '987', 'name' => 'Main property'])
        ->assertOk();

    $state = DataSourceConnection::withoutGlobalScope('organization')->firstOrFail();
    expect($state->state)->toBe('connected')->and($state->label)->toBe('Main property');

    Queue::assertPushed(SyncDataSource::class, fn ($job) => $job->siteId === $site->id && $job->source === 'ga4');
});

it('syncs GA4 rows into traffic_daily under the ga4 source', function () {
    [$user, $org] = makeOrgUser('int-ga4sync@example.com');
    $site = makeSiteFor($org);
    $credential = makeGoogleCredential($site, 'ga4');

    Http::fake([
        'analyticsdata.googleapis.com/*' => Http::sequence()
            // Daily totals arrive in two batches (GA4 caps reports at 10 metrics).
            ->push(['rows' => [[
                'dimensionValues' => [['value' => '20260701']],
                'metricValues'    => [
                    ['value' => '10'], ['value' => '4'], ['value' => '12'], ['value' => '9'],
                    ['value' => '40'], ['value' => '0.75'],
                ],
            ]]])
            ->push(['rows' => [[
                'dimensionValues' => [['value' => '20260701']],
                'metricValues'    => [
                    ['value' => '0.25'], ['value' => '65.4'], ['value' => '2'], ['value' => '1'], ['value' => '99.5'],
                ],
            ]]])
            ->push(['rows' => [[
                'dimensionValues' => [['value' => 'Organic Search']],
                'metricValues'    => [['value' => '5'], ['value' => '4'], ['value' => '6'], ['value' => '1'], ['value' => '20']],
            ]]])
            ->push(['rows' => [[
                'dimensionValues' => [['value' => 'google / organic']],
                'metricValues'    => [['value' => '5'], ['value' => '4'], ['value' => '6'], ['value' => '1'], ['value' => '20']],
            ]]])
            ->push(['rows' => [[
                'dimensionValues' => [['value' => 'Bangladesh']],
                'metricValues'    => [['value' => '5'], ['value' => '4'], ['value' => '6'], ['value' => '1'], ['value' => '20']],
            ]]])
            ->push(['rows' => [[
                'dimensionValues' => [['value' => 'desktop']],
                'metricValues'    => [['value' => '5'], ['value' => '4'], ['value' => '6'], ['value' => '1'], ['value' => '20']],
            ]]])
            ->push(['rows' => [[
                'dimensionValues' => [['value' => '/landing']],
                'metricValues'    => [['value' => '5'], ['value' => '4'], ['value' => '6'], ['value' => '1'], ['value' => '20']],
            ]]]),
    ]);

    $result = app(IntegrationManager::class)->sync($site, 'ga4', 7);

    expect($result['days'])->toBe(1)->and($result['dimension_rows'])->toBe(5);

    // Every Traffic page breakdown tab must get its own snapshot rows.
    $byType = TrafficDimensionSnapshot::where('site_id', $site->id)->where('source', 'ga4')
        ->pluck('dimension_value', 'dimension_type')->all();
    expect($byType)->toEqualCanonicalizing([
        'channel'       => 'Organic Search',
        'source_medium' => 'google / organic',
        'country'       => 'Bangladesh',
        'device'        => 'desktop',
        'landing_page'  => '/landing',
    ]);
    expect(TrafficDimensionSnapshot::where('dimension_type', 'channel')->value('url'))->toBeNull();

    $row = TrafficDaily::where('site_id', $site->id)->where('source', 'ga4')->firstOrFail();
    expect($row->sessions)->toBe(12)
        ->and($row->users)->toBe(10)
        // GA4 sends ratios; the column stores percent.
        ->and((float) $row->engagement_rate)->toBe(75.0)
        ->and((float) $row->revenue)->toBe(99.5)
        ->and((float) $row->bounce_rate)->toBe(25.0)
        ->and($row->conversions)->toBe(2);

    // No daily request may exceed GA4's 10-metric limit.
    $dailyRequests = collect(Http::recorded())
        ->map(fn ($pair) => $pair[0]->data())
        ->filter(fn ($body) => ($body['dimensions'][0]['name'] ?? null) === 'date');
    expect($dailyRequests)->toHaveCount(2);
    $dailyRequests->each(fn ($body) => expect(count($body['metrics']))->toBeLessThanOrEqual(10));
    expect($dailyRequests->flatMap(fn ($b) => array_column($b['metrics'], 'name'))->values()->all())
        ->toBe(\App\Services\Integrations\Ga4Client::DAILY_METRICS);

    $state = DataSourceConnection::withoutGlobalScope('organization')->firstOrFail();
    expect($state->state)->toBe('connected')->and($state->last_successful_at)->not->toBeNull();
});

it('syncs Search Console rows into seo_daily', function () {
    [$user, $org] = makeOrgUser('int-gscsync@example.com');
    $site = makeSiteFor($org);
    makeGoogleCredential($site, 'gsc', ['id' => 'https://store.example.com/']);

    Http::fake([
        'www.googleapis.com/webmasters/*' => Http::response([
            'rows' => [['keys' => ['2026-07-01'], 'clicks' => 7, 'impressions' => 300, 'ctr' => 0.0233, 'position' => 14.2]],
        ]),
    ]);

    $result = app(IntegrationManager::class)->sync($site, 'gsc', 7);

    expect($result['days'])->toBe(1);

    $row = SeoDaily::where('site_id', $site->id)->firstOrFail();
    expect($row->clicks)->toBe(7)->and($row->impressions)->toBe(300);
});

it('marks the source as error and demands reauth when google revokes the grant', function () {
    [$user, $org] = makeOrgUser('int-revoked@example.com');
    $site = makeSiteFor($org);
    makeGoogleCredential($site, 'ga4');

    Http::fake([
        'analyticsdata.googleapis.com/*' => Http::response(['error' => ['message' => 'Request had invalid authentication credentials.']], 401),
    ]);

    $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/integrations/ga4/test")
        ->assertStatus(409)
        ->assertJson(['code' => 'reauth_required']);

    $state = DataSourceConnection::withoutGlobalScope('organization')->firstOrFail();
    expect($state->state)->toBe('error')->and($state->last_error_code)->toBe('reauth_required');
});

it('verifies a cloudflare token before storing it and rejects a bad one', function () {
    [$user, $org] = makeOrgUser('int-cf@example.com');
    $site = makeSiteFor($org);

    // One fake, two responses: a later Http::fake() would not override an
    // existing stub for the same URL, so the reject-then-accept story has to
    // be expressed as a sequence.
    Http::fake([
        'api.cloudflare.com/client/v4/user/tokens/verify' => Http::sequence()
            ->push(['success' => false], 401)
            ->push(['success' => true]),
    ]);

    $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/integrations/cloudflare/connect", ['api_token' => str_repeat('t', 40)])
        ->assertStatus(502)
        ->assertJson(['code' => 'invalid_token']);

    expect($site->fresh()->cloudflare_api_token)->toBeNull();

    $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/integrations/cloudflare/connect", ['api_token' => str_repeat('t', 40)])
        ->assertOk();

    expect($site->fresh()->cloudflare_api_token)->toBe(str_repeat('t', 40));
});

it('disconnecting deletes the credential and resets the state row', function () {
    [$user, $org] = makeOrgUser('int-disconnect@example.com');
    $site = makeSiteFor($org);
    makeGoogleCredential($site, 'ga4');
    DataSourceConnection::recordAttempt($org->id, $site->id, 'ga4', ['state' => 'connected', 'label' => 'Prop']);

    Http::fake(['oauth2.googleapis.com/revoke' => Http::response([])]);

    $this->actingAs($user)->deleteJson("/api/sites/{$site->id}/integrations/ga4")->assertOk();

    expect(DataSourceCredential::withoutGlobalScope('organization')->count())->toBe(0);

    $state = DataSourceConnection::withoutGlobalScope('organization')->firstOrFail();
    expect($state->state)->toBe('not_configured')->and($state->label)->toBeNull();
});

it('hides another organization\'s site from every integration endpoint', function () {
    [$userA] = makeOrgUser('int-tenant-a@example.com');
    [, $orgB] = makeOrgUser('int-tenant-b@example.com');
    $siteB = makeSiteFor($orgB, 'other.example.com');
    makeGoogleCredential($siteB, 'ga4');

    // A site in another org must not exist for this user — 404, not 403.
    $this->actingAs($userA)->getJson("/api/sites/{$siteB->id}/integrations")->assertNotFound();
    $this->actingAs($userA)->postJson("/api/sites/{$siteB->id}/integrations/ga4/test")->assertNotFound();
    $this->actingAs($userA)->deleteJson("/api/sites/{$siteB->id}/integrations/ga4")->assertNotFound();

    expect(DataSourceCredential::withoutGlobalScope('organization')->count())->toBe(1);
});

it('blocks connect and disconnect for non-admin roles', function () {
    [$owner, $org] = makeOrgUser('int-role-owner@example.com');
    $site = makeSiteFor($org);
    makeGoogleCredential($site, 'ga4');

    $analyst = \App\Models\User::create([
        'name' => 'Analyst', 'email' => 'int-analyst@example.com',
        'password' => 'secret-password', 'status' => 'active',
    ]);
    $org->users()->attach($analyst->id, ['role' => 'analyst']);

    // Reading status is fine for an analyst...
    $this->actingAs($analyst)->getJson("/api/sites/{$site->id}/integrations")->assertOk();

    // ...but mutating the connection is not.
    $this->actingAs($analyst)->deleteJson("/api/sites/{$site->id}/integrations/ga4")->assertForbidden();
    $this->actingAs($analyst)->postJson("/api/sites/{$site->id}/integrations/ga4/select", ['id' => '1'])->assertForbidden();

    expect(DataSourceCredential::withoutGlobalScope('organization')->count())->toBe(1);
});

it('refuses to resync a source that is not connected', function () {
    Queue::fake();

    [$user, $org] = makeOrgUser('int-resync@example.com');
    $site = makeSiteFor($org);

    $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/integrations/ga4/resync")
        ->assertStatus(422)
        ->assertJson(['code' => 'not_connected']);

    Queue::assertNothingPushed();
});

it('refreshes an expired access token without losing the refresh token', function () {
    config()->set('insightistic.integrations.google.client_id', 'cid');
    config()->set('insightistic.integrations.google.client_secret', 'csecret');

    [, $org] = makeOrgUser('int-refresh@example.com');
    $site = makeSiteFor($org);
    $credential = makeGoogleCredential($site, 'ga4');
    $credential->forceFill(['token_expires_at' => now()->subMinute()])->save();

    // Google omits refresh_token on a plain refresh; the old one must survive.
    Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'new-access', 'expires_in' => 3600])]);

    $token = app(GoogleOAuthService::class)->freshAccessToken($credential->fresh());

    expect($token)->toBe('new-access');

    $stored = $credential->fresh();
    expect($stored->refresh_token)->toStartWith('rt-')
        ->and($stored->token_expires_at->isFuture())->toBeTrue();
});

it('unknown sources are not routable', function () {
    [$user, $org] = makeOrgUser('int-unknown@example.com');
    $site = makeSiteFor($org);

    $this->actingAs($user)->postJson("/api/sites/{$site->id}/integrations/woocommerce/test")->assertNotFound();
    $this->actingAs($user)->postJson("/api/sites/{$site->id}/integrations/nonsense/test")->assertNotFound();
});
