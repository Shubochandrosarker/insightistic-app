<?php

use App\Http\Controllers\Api\Connector\SyncV2Controller;
use App\Models\DataSourceConnection;
use App\Models\ProviderAccount;
use App\Models\Site;
use App\Services\Integrations\ProviderAccountManager;
use Illuminate\Support\Str;

/**
 * Connection-method clarity (spec §12) — the advisor's launch blocker:
 * when the WordPress plugin feeds GSC/GA4 data, the Integrations page must
 * say "connected via WordPress plugin / last data X ago" AND "direct
 * Google connection: not connected [Connect directly]" — never a flat
 * "Not connected" while data is flowing.
 */
function pushPluginGscDaily(Site $site, array $days): void
{
    $payload = ['days' => $days];
    $body = json_encode($payload);
    $ts = (string) now()->timestamp;
    $nonce = Str::random(16);
    $path = '/api/connector/v1/sync/seo/daily';
    $sig = hash_hmac('sha256', "POST\n{$path}\n{$ts}\n{$nonce}\n".hash('sha256', $body), $site->connector_secret);

    test()->postJson('/api/connector/v1/sync/seo/daily', $payload, [
        'X-INS-Key-Id' => $site->connector_key_id,
        'X-INS-Timestamp' => $ts,
        'X-INS-Nonce' => $nonce,
        'X-INS-Signature' => $sig,
        'Content-Type' => 'application/json',
    ])->assertOk();
}

it('plugin GSC push without direct connection shows plugin-fed, direct stays connectable', function () {
    [$user, $org] = makeOrgUser('cm-plugin-only@example.com');
    $site = Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id, 'name' => 'P', 'domain' => 'p.example.com',
        'normalized_domain' => 'p.example.com', 'platform' => 'wordpress',
        'detected_platform' => 'wordpress',
    ]);
    $site->issueConnectorToken();

    pushPluginGscDaily($site, [['date' => now()->subDay()->toDateString(), 'clicks' => 5, 'impressions' => 100]]);

    $response = $this->actingAs($user)->getJson("/api/sites/{$site->id}/integrations")->assertOk();
    $gsc = collect($response->json('sources'))->firstWhere('source', 'gsc');

    expect($gsc['state'])->toBe('connected')
        ->and($gsc['connection_method'])->toBe('wordpress_plugin')
        ->and($gsc['plugin_fed']['active'])->toBeTrue()
        ->and($gsc['plugin_fed']['last_data_at'])->not->toBeNull()
        // direct card must still offer a clean connect path
        ->and($gsc['connected'])->toBeFalse()
        ->and($gsc['connectable'])->toBeTrue();
});

it('plugin push does not masquerade as oauth when a direct credential exists', function () {
    [$user, $org] = makeOrgUser('cm-both@example.com');
    $site = Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id, 'name' => 'B', 'domain' => 'b.example.com',
        'normalized_domain' => 'b.example.com', 'platform' => 'wordpress',
    ]);
    $site->issueConnectorToken();

    // direct OAuth credential attached (org account path)
    $account = ProviderAccount::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id, 'provider' => 'google',
        'external_account_id' => 'sub-x', 'email' => 'x@gmail.com',
        'access_token' => 'at', 'refresh_token' => 'rt',
        'token_expires_at' => now()->addHour(), 'status' => 'active',
    ]);
    app(ProviderAccountManager::class)
        ->attachGoogleSource($site, 'gsc', $account, $user->id);

    pushPluginGscDaily($site, [['date' => now()->subDay()->toDateString(), 'clicks' => 7]]);

    $row = DataSourceConnection::withoutGlobalScope('organization')
        ->where('site_id', $site->id)->where('source', 'gsc')->first();

    // Direct OAuth remains the recorded method; plugin presence is metadata.
    expect($row->connection_method)->toBe('oauth')
        ->and($row->metadata_json['plugin_fed_at'])->not->toBeNull();
});

it('plugin failure never degrades a healthy direct source', function () {
    [$user, $org] = makeOrgUser('cm-fail@example.com');
    $site = Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id, 'name' => 'F', 'domain' => 'f.example.com',
        'normalized_domain' => 'f.example.com', 'platform' => 'wordpress',
    ]);
    $site->issueConnectorToken();

    $account = ProviderAccount::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id, 'provider' => 'google',
        'external_account_id' => 'sub-y', 'email' => 'y@gmail.com',
        'access_token' => 'at', 'refresh_token' => 'rt',
        'token_expires_at' => now()->addHour(), 'status' => 'active',
    ]);
    app(ProviderAccountManager::class)
        ->attachGoogleSource($site, 'ga4', $account, $user->id);

    // simulate a plugin push failure for ga4 (invalid payload → 422, then
    // record the failed state the way a handled failure would)
    $reflection = new ReflectionMethod(SyncV2Controller::class, 'recordSource');
    $reflection->setAccessible(true);
    $controller = app(SyncV2Controller::class);
    $reflection->invoke($controller, $site, 'ga4', false);

    $row = DataSourceConnection::withoutGlobalScope('organization')
        ->where('site_id', $site->id)->where('source', 'ga4')->first();

    expect($row->state)->toBe('degraded') // direct state untouched by plugin failure
        ->and($row->last_error_code)->toBe('no_property_selected');
});

it('email plugin pushes record connection_method wordpress_plugin', function () {
    [$user, $org] = makeOrgUser('cm-email@example.com');
    $site = Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id, 'name' => 'E', 'domain' => 'e.example.com',
        'normalized_domain' => 'e.example.com', 'platform' => 'wordpress',
    ]);
    $site->issueConnectorToken();

    $payload = ['provider' => 'fluentcrm', 'campaigns' => [[
        'external_id' => 'c1', 'name' => 'News', 'status' => 'sent',
        'sent_at' => now()->subDay()->toIso8601String(),
        'recipients' => 10, 'opened' => 5,
    ]]];
    $body = json_encode($payload);
    $ts = (string) now()->timestamp;
    $nonce = Str::random(16);
    $path = '/api/connector/v1/sync/email/campaigns';
    $sig = hash_hmac('sha256', "POST\n{$path}\n{$ts}\n{$nonce}\n".hash('sha256', $body), $site->connector_secret);

    $this->postJson('/api/connector/v1/sync/email/campaigns', $payload, [
        'X-INS-Key-Id' => $site->connector_key_id,
        'X-INS-Timestamp' => $ts,
        'X-INS-Nonce' => $nonce,
        'X-INS-Signature' => $sig,
        'Content-Type' => 'application/json',
    ])->assertOk();

    $row = DataSourceConnection::withoutGlobalScope('organization')
        ->where('site_id', $site->id)->where('source', 'email')->first();

    expect($row->connection_method)->toBe('wordpress_plugin')
        ->and($row->state)->toBe('connected');
});

it('org google account surfaces on the site integrations payload', function () {
    [$user, $org] = makeOrgUser('cm-org@example.com');
    $site = Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id, 'name' => 'O', 'domain' => 'o.example.com',
        'normalized_domain' => 'o.example.com', 'platform' => 'generic',
    ]);
    ProviderAccount::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id, 'provider' => 'google',
        'external_account_id' => 'sub-z', 'email' => 'z@gmail.com',
        'access_token' => 'at', 'refresh_token' => 'rt',
        'token_expires_at' => now()->addHour(), 'status' => 'active',
    ]);

    $response = $this->actingAs($user)->getJson("/api/sites/{$site->id}/integrations")->assertOk();

    expect($response->json('google_account.account_email'))->toBe('z@gmail.com');

    $gsc = collect($response->json('sources'))->firstWhere('source', 'gsc');
    expect($gsc['attachable_from_org'])->toBeTrue();
});
