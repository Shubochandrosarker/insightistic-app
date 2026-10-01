<?php

use App\Models\CloudflareBotDaily;
use App\Models\CloudflareDaily;
use App\Models\DataSourceConnection;
use App\Models\Site;
use App\Models\SyncLog;
use Illuminate\Support\Str;

/**
 * Connector v2 Cloudflare ingestion (POST /api/connector/v1/sync/cloudflare/daily):
 * the plugin's BYO path (Zone ID + API token in its own Settings → Cloudflare)
 * pushes the same daily edge rows the nightly CloudflareAnalytics pull writes,
 * so the dashboard's edge page fills with no app-side Cloudflare connect.
 *
 * The fixture sites deliberately have NO cloudflare_zone_id/token on the Site
 * row — plugin-fed data must not require the app-side connection.
 */
function makeCfConnectorSite(\App\Models\Organization $org): Site
{
    $site = Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id,
        'name'            => 'Plugin Edge',
        'domain'          => 'plugin-edge.example.com',
    ]);
    $site->forceFill([
        'connector_key_id' => 'ik_'.Str::random(24),
        'connector_secret' => Str::random(32),
    ])->save();

    return $site;
}

function pushPluginCloudflareDaily(Site $site, array $payload)
{
    $body = json_encode($payload);
    $ts = (string) now()->timestamp;
    $nonce = Str::random(16);
    $path = '/api/connector/v1/sync/cloudflare/daily';
    $sig = hash_hmac('sha256', "POST\n{$path}\n{$ts}\n{$nonce}\n".hash('sha256', $body), $site->connector_secret);

    return test()->postJson($path, $payload, [
        'X-INS-Key-Id'  => $site->connector_key_id,
        'X-INS-Timestamp' => $ts,
        'X-INS-Nonce'   => $nonce,
        'X-INS-Signature' => $sig,
        'Content-Type'  => 'application/json',
    ]);
}

it('stores plugin-pushed edge rows unlinked, classifies bots, and serves them on the dashboard endpoint', function () {
    [$user, $org] = makeOrgUser('cf-connector-push@example.com');
    $site = makeCfConnectorSite($org);
    $day = now()->subDay()->toDateString();

    $response = pushPluginCloudflareDaily($site, [
        'days' => [[
            'date'               => $day,
            'requests'           => 1000,
            'bytes'              => 5000000,
            'cached_requests'    => 600,
            'cached_bytes'       => 3000000,
            'encrypted_requests' => 990,
            'pageviews'          => 210,
            'threats'            => 12,
            'client_4xx'         => 40,
            'client_5xx'         => 3,
            'countries'          => ['US' => 700, 'DE' => 300],
            'status_breakdown'   => ['200' => 950, '404' => 40, '503' => 3],
            'firewall'           => ['block' => 9, 'challenge' => 3],
        ]],
        'bots_available' => true,
        'bots' => [
            ['date' => $day, 'user_agent' => 'Mozilla/5.0 (compatible; GPTBot/1.0; +https://openai.com/gptbot)', 'requests' => 77],
            ['date' => $day, 'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/126.0', 'requests' => 5000],
        ],
    ]);

    $response->assertOk()->assertJson(['status' => 'ok', 'received' => 1, 'bots_stored' => 1]);

    $row = CloudflareDaily::where('site_id', $site->id)->where('date', $day)->first();
    expect($row)->not->toBeNull()
        ->and((int) $row->requests)->toBe(1000)
        ->and((int) $row->threats)->toBe(12)
        ->and((int) $row->client_4xx)->toBe(40)
        ->and($row->countries_json)->toBe(['US' => 700, 'DE' => 300])
        ->and($row->firewall_json)->toBe(['block' => 9, 'challenge' => 3]);

    // The human browser UA is dropped platform-side; only known crawlers store.
    $bots = CloudflareBotDaily::where('site_id', $site->id)->get();
    expect($bots)->toHaveCount(1);
    expect($bots[0]->classification)->toBe('ai_crawler');
    expect((int) $bots[0]->requests)->toBe(77);

    // Plugin-fed source state, without any app-side Cloudflare link.
    $connection = DataSourceConnection::withoutGlobalScope('organization')
        ->where('site_id', $site->id)->where('source', 'cloudflare')->first();
    expect($connection?->state)->toBe('connected')
        ->and($connection?->connection_method)->toBe('wordpress_plugin');

    expect(SyncLog::where('site_id', $site->id)->where('job', 'cloudflare_daily')->exists())->toBeTrue();

    // The dashboard edge page reads exactly these rows — no Cloudflare link
    // on the Site row, yet the page fills.
    $json = $this->actingAs($user)->getJson("/api/sites/{$site->id}/cloudflare?days=30")->assertOk()->json();
    expect($json['has_data'])->toBeTrue()
        ->and($json['overview']['requests'])->toBe(1000)
        ->and($json['overview']['cache_hit_ratio'])->toEqual(60)
        ->and($json['geo']['top_countries'][0])->toBe(['country' => 'US', 'requests' => 700])
        ->and($json['security']['actions'])->toBe(['block' => 9, 'challenge' => 3])
        ->and($json['bots']['rows'][0]['bot_name'])->toContain('GPTBot');

    // Idempotent replay: same (site, date) upsert key, no duplicates.
    pushPluginCloudflareDaily($site, [
        'days' => [['date' => $day, 'requests' => 1000]],
        'bots_available' => false,
    ])->assertOk();
    expect(CloudflareDaily::where('site_id', $site->id)->count())->toBe(1);
});

it('degrades bots to plan-unavailable when the plugin reports no bot dataset', function () {
    [$user, $org] = makeOrgUser('cf-connector-nobots@example.com');
    $site = makeCfConnectorSite($org);
    $day = now()->subDay()->toDateString();

    pushPluginCloudflareDaily($site, [
        'days' => [['date' => $day, 'requests' => 42, 'pageviews' => 9]],
        'bots_available' => false,
    ])->assertOk();

    expect(CloudflareBotDaily::where('site_id', $site->id)->exists())->toBeFalse();

    $json = $this->actingAs($user)
        ->getJson("/api/sites/{$site->id}/cloudflare?days=30&sections=bots")->assertOk()->json();
    expect($json['bots']['available'])->toBeFalse()
        ->and($json['bots']['reason'])->toBe('plan_does_not_expose_bot_analytics');
});
