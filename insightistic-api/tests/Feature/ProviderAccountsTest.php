<?php

use App\Models\DataSourceConnection;
use App\Models\DataSourceCredential;
use App\Models\ProviderAccount;
use App\Models\Site;
use App\Services\Integrations\GoogleOAuthService;
use App\Services\Integrations\ProviderAccountManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Org-level provider accounts (spec §5): one Google consent serves every
 * site, attach requires no second consent, refresh flows through the
 * account, disconnect revokes and detaches cleanly.
 */
function makeOrgGoogleAccount($org, string $email = 'owner@gmail.com', array $attrs = []): ProviderAccount
{
    return ProviderAccount::withoutGlobalScope('organization')->create(array_merge([
        'organization_id' => $org->id,
        'provider' => 'google',
        'external_account_id' => 'sub-'.md5($email),
        'email' => $email,
        'display_name' => $email,
        'access_token' => 'at-org',
        'refresh_token' => 'rt-org',
        'token_expires_at' => now()->addHour(),
        'scopes_json' => ['https://www.googleapis.com/auth/webmasters.readonly'],
        'status' => 'active',
    ], $attrs));
}

it('mints an org-level consent url with combined read-only scopes', function () {
    config()->set('insightistic.integrations.google.client_id', 'cid');
    config()->set('insightistic.integrations.google.client_secret', 'csecret');

    [$user, $org] = makeOrgUser('pa-auth@example.com');

    $url = $this->actingAs($user)
        ->postJson('/api/integrations/google/account/authorize-url')
        ->assertOk()
        ->json('authorize_url');

    expect($url)->toContain('accounts.google.com')
        ->and($url)->toContain(urlencode('https://www.googleapis.com/auth/webmasters.readonly'))
        ->and($url)->toContain(urlencode('https://www.googleapis.com/auth/analytics.readonly'))
        ->and($url)->toContain('code_challenge_method=S256')
        ->and($url)->toContain('access_type=offline');
});

it('reports the org google account status without leaking tokens', function () {
    [$user, $org] = makeOrgUser('pa-status@example.com');
    makeOrgGoogleAccount($org);

    $response = $this->actingAs($user)->getJson('/api/integrations/google/account')->assertOk();

    expect($response->json('connected'))->toBeTrue()
        ->and($response->json('account.account_email'))->toBe('owner@gmail.com')
        ->and($response->getContent())->not->toContain('rt-org')
        ->and($response->getContent())->not->toContain('access_token');
});

it('attaches gsc to a site from the org grant without a new consent', function () {
    [$user, $org] = makeOrgUser('pa-attach@example.com');
    $site = Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id, 'name' => 'A', 'domain' => 'a.example.com',
        'normalized_domain' => 'a.example.com', 'platform' => 'generic',
    ]);
    $account = makeOrgGoogleAccount($org);

    $response = $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/integrations/gsc/attach")
        ->assertOk();

    expect($response->json('needs_resource'))->toBeTrue();

    $credential = DataSourceCredential::withoutGlobalScope('organization')
        ->where('site_id', $site->id)->where('source', 'gsc')->first();

    expect($credential)->not->toBeNull()
        ->and($credential->provider_account_id)->toBe($account->id)
        ->and($credential->refresh_token)->toBeNull() // tokens live on the account
        ->and($credential->access_token)->toBe('at-org');

    // connection row reflects the oauth method
    $connection = DataSourceConnection::withoutGlobalScope('organization')
        ->where('site_id', $site->id)->where('source', 'gsc')->first();
    expect($connection->connection_method)->toBe('oauth')
        ->and($connection->state)->toBe('degraded');
});

it('refuses attach when the org has no google account', function () {
    [$user, $org] = makeOrgUser('pa-noacct@example.com');
    $site = Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id, 'name' => 'A', 'domain' => 'a.example.com', 'platform' => 'generic',
    ]);

    $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/integrations/gsc/attach")
        ->assertStatus(409)->assertJsonPath('code', 'org_google_not_connected');
});

it('refreshes a linked credential through the shared account, not its own token', function () {
    [$user, $org] = makeOrgUser('pa-refresh@example.com');
    $site = Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id, 'name' => 'A', 'domain' => 'a.example.com', 'platform' => 'generic',
    ]);
    $account = makeOrgGoogleAccount($org, 'r@gmail.com', [
        'access_token' => 'stale',
        'token_expires_at' => now()->subMinute(),
    ]);

    DataSourceCredential::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id,
        'provider_account_id' => $account->id,
        'site_id' => $site->id,
        'source' => 'gsc',
        'provider' => 'google',
        'access_token' => 'stale',
        'refresh_token' => null,
        'token_expires_at' => now()->subMinute(),
        'resource_json' => ['id' => 'https://a.example.com', 'name' => 'https://a.example.com'],
    ]);

    config()->set('insightistic.integrations.google.client_id', 'cid');
    config()->set('insightistic.integrations.google.client_secret', 'csecret');

    Http::fake(['*/token' => Http::response([
        'access_token' => 'fresh-account-token',
        'expires_in' => 3600,
    ])]);

    $credential = DataSourceCredential::withoutGlobalScope('organization')
        ->where('site_id', $site->id)->where('source', 'gsc')->first();

    $token = app(GoogleOAuthService::class)->freshAccessToken($credential);

    expect($token)->toBe('fresh-account-token')
        ->and($account->refresh()->access_token)->toBe('fresh-account-token')
        ->and($credential->refresh()->access_token)->toBe('fresh-account-token');
});

it('disconnecting the org google account revokes, detaches and resets connection rows', function () {
    Http::fake(['oauth2.googleapis.com/revoke' => Http::response()]);
    [$user, $org] = makeOrgUser('pa-disc@example.com');
    $site = Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id, 'name' => 'A', 'domain' => 'a.example.com', 'platform' => 'generic',
    ]);
    $account = makeOrgGoogleAccount($org);

    app(ProviderAccountManager::class)->attachGoogleSource($site, 'gsc', $account, $user->id);

    $this->actingAs($user)->deleteJson('/api/integrations/google/account')->assertOk();

    expect(ProviderAccount::withoutGlobalScope('organization')->count())->toBe(0)
        ->and(DataSourceCredential::withoutGlobalScope('organization')->count())->toBe(0);

    $connection = DataSourceConnection::withoutGlobalScope('organization')
        ->where('site_id', $site->id)->where('source', 'gsc')->first();
    expect($connection->state)->toBe('not_configured');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'revoke'));
});

it('org callback exchange creates exactly one account per google identity', function () {
    config()->set('insightistic.integrations.google.client_id', 'cid');
    config()->set('insightistic.integrations.google.client_secret', 'csecret');

    [$user, $org] = makeOrgUser('pa-callback@example.com');

    // mint an org state like the service does
    $url = app(GoogleOAuthService::class)->orgAuthorizeUrl($org->id, (int) $user->id);
    parse_str(parse_url($url, PHP_URL_QUERY), $query);
    $state = $query['state'];
    // peek at the verifier without consuming the state (the callback pulls it)
    $payload = Cache::get('integration_oauth_state:'.hash('sha256', $state));

    Http::fake([
        '*/token' => Http::response([
            'access_token' => 'at-1',
            'refresh_token' => 'rt-1',
            'expires_in' => 3600,
            'scope' => 'webmasters.readonly analytics.readonly',
        ]),
        '*userinfo*' => Http::response(['email' => 'ag@gmail.com', 'sub' => 'sub-999']),
    ]);

    $this->get('/api/integrations/google/callback?state='.$state.'&code=xyz')
        ->assertRedirect();

    expect(ProviderAccount::withoutGlobalScope('organization')->count())->toBe(1)
        ->and(ProviderAccount::withoutGlobalScope('organization')->first()->external_account_id)->toBe('sub-999');

    // second consent for the same identity → still one account
    Cache::put('integration_oauth_state:'.hash('sha256', $state), $payload, 600);
    $this->get('/api/integrations/google/callback?state='.$state.'&code=xyz2')
        ->assertRedirect();

    expect(ProviderAccount::withoutGlobalScope('organization')->count())->toBe(1);
});

it('site-level consent now also seeds the org account and links the credential', function () {
    config()->set('insightistic.integrations.google.client_id', 'cid');
    config()->set('insightistic.integrations.google.client_secret', 'csecret');

    [$user, $org] = makeOrgUser('pa-siteflow@example.com');
    $site = Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id, 'name' => 'A', 'domain' => 'a.example.com', 'platform' => 'generic',
    ]);

    $authorizeUrl = app(GoogleOAuthService::class)->authorizeUrl($site->id, 'gsc', (int) $user->id);
    parse_str(parse_url($authorizeUrl, PHP_URL_QUERY), $query);
    $state = $query['state'];

    Http::fake([
        '*/token' => Http::response([
            'access_token' => 'at-site',
            'refresh_token' => 'rt-site',
            'expires_in' => 3600,
            'scope' => 'webmasters.readonly',
        ]),
        '*userinfo*' => Http::response(['email' => 'site@gmail.com', 'sub' => 'sub-site']),
    ]);

    $this->get('/api/integrations/google/callback?state='.$state.'&code=xyz')
        ->assertRedirect();

    $account = ProviderAccount::withoutGlobalScope('organization')->first();
    $credential = DataSourceCredential::withoutGlobalScope('organization')
        ->where('site_id', $site->id)->where('source', 'gsc')->first();

    expect($account)->not->toBeNull()
        ->and($account->external_account_id)->toBe('sub-site')
        ->and($credential->provider_account_id)->toBe($account->id);
});

it('keeps org accounts inside their tenant', function () {
    [$userA, $orgA] = makeOrgUser('pa-tenant-a@example.com');
    [$userB, $orgB] = makeOrgUser('pa-tenant-b@example.com');
    makeOrgGoogleAccount($orgA, 'a@gmail.com');

    $this->actingAs($userB)->getJson('/api/integrations/google/account')->assertOk()
        ->assertJsonPath('connected', false);
});
