<?php

use App\Jobs\BackfillGscHistory;
use App\Jobs\CrawlSiteJob;
use App\Jobs\SyncDataSource;
use App\Models\DataSourceConnection;
use App\Models\ProviderAccount;
use App\Models\Site;
use App\Models\UptimeMonitor;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Org-level Search Console import (spec §7): list properties with matched
 * projects, multi-select → create missing sites → attach GSC → initial sync
 * + backfill + crawl/uptime/PageSpeed. All without the WordPress plugin.
 */
function fakeGscProperties(): void
{
    Http::fake(['*/webmasters/v3/sites' => Http::response([
        'siteEntry' => [
            ['siteUrl' => 'sc-domain:example.com', 'permissionLevel' => 'siteOwner'],
            ['siteUrl' => 'https://shop.example.com/', 'permissionLevel' => 'siteFullUser'],
            ['siteUrl' => 'https://blog.other.org/', 'permissionLevel' => 'siteRestrictedUser'],
            ['siteUrl' => 'https://unverified.example.com/', 'permissionLevel' => 'siteUnverifiedUser'],
        ],
    ])]);
}

function makeOrgWithGoogle($email): array
{
    [$user, $org] = makeOrgUser($email);
    ProviderAccount::withoutGlobalScope('organization')->create([
        'organization_id'     => $org->id,
        'provider'            => 'google',
        'external_account_id' => 'sub-import',
        'email'               => 'owner@gmail.com',
        'access_token'        => 'at-ok',
        'refresh_token'       => 'rt-ok',
        'token_expires_at'    => now()->addHour(),
        'scopes_json'         => ['webmasters.readonly'],
        'status'              => 'active',
    ]);

    return [$user, $org];
}

it('lists org GSC properties with matched projects and filters unverified', function () {
    fakeGscProperties();
    [$user, $org] = makeOrgWithGoogle('gi-list@example.com');
    Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id, 'name' => 'Existing', 'domain' => 'example.com',
        'normalized_domain' => 'example.com', 'platform' => 'generic',
    ]);

    $response = $this->actingAs($user)->getJson('/api/integrations/google/gsc/properties')->assertOk();

    $props = collect($response->json('properties'));
    expect($props->count())->toBe(3) // unverified filtered out
        ->and($props->firstWhere('property', 'sc-domain:example.com')['matched_site']['id'])->not->toBeNull()
        ->and($props->firstWhere('property', 'sc-domain:example.com')['property_type'])->toBe('domain')
        ->and($props->firstWhere('property', 'https://shop.example.com/')['matched_site'])->toBeNull()
        ->and($props->firstWhere('property', 'https://blog.other.org/')['permission_level'])->toBe('siteRestrictedUser');
});

it('imports selected properties: creates sites, attaches gsc, queues the full pipeline', function () {
    Queue::fake();
    fakeGscProperties();
    [$user, $org] = makeOrgWithGoogle('gi-import@example.com');

    $response = $this->actingAs($user)->postJson('/api/integrations/google/gsc/import', [
        'properties' => [
            'sc-domain:example.com',
            'https://shop.example.com/',
        ],
    ])->assertCreated();

    expect(count($response->json('created')))->toBe(2)
        ->and($response->json('skipped'))->toBe([]);

    $site = Site::withoutGlobalScope('organization')->where('normalized_domain', 'example.com')->first();
    expect($site->platform)->toBe('generic')
        ->and($site->canonical_url)->toBe('https://example.com')
        ->and($site->status)->toBe('active');

    $credential = \App\Models\DataSourceCredential::withoutGlobalScope('organization')
        ->where('site_id', $site->id)->where('source', 'gsc')->first();
    expect($credential->resource_json['id'])->toBe('sc-domain:example.com');

    $connection = DataSourceConnection::withoutGlobalScope('organization')
        ->where('site_id', $site->id)->where('source', 'gsc')->first();
    expect($connection->state)->toBe('connected')
        ->and($connection->connection_method)->toBe('oauth')
        ->and($connection->metadata_json['access_verified'])->toBeTrue();

    Queue::assertPushed(SyncDataSource::class, fn ($job) => $job->siteId === $site->id && $job->source === 'gsc');
    Queue::assertPushed(BackfillGscHistory::class, 4); // query + page per site
    Queue::assertPushed(CrawlSiteJob::class, 2);
    expect(UptimeMonitor::count())->toBe(2);
});

it('attaches GSC to an existing matched site instead of duplicating it', function () {
    Queue::fake();
    fakeGscProperties();
    [$user, $org] = makeOrgWithGoogle('gi-match@example.com');
    $existing = Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id, 'name' => 'Existing', 'domain' => 'example.com',
        'normalized_domain' => 'example.com', 'platform' => 'generic',
    ]);

    $this->actingAs($user)->postJson('/api/integrations/google/gsc/import', [
        'properties' => ['sc-domain:example.com'],
    ])->assertCreated();

    expect(Site::withoutGlobalScope('organization')->where('organization_id', $org->id)->count())->toBe(1)
        ->and($response ?? null)->toBeNull();

    $credential = \App\Models\DataSourceCredential::withoutGlobalScope('organization')
        ->where('site_id', $existing->id)->where('source', 'gsc')->first();
    expect($credential)->not->toBeNull();
});

it('skips unreadable properties and enforces the plan limit during import', function () {
    Queue::fake();
    fakeGscProperties();
    [$user, $org] = makeOrgWithGoogle('gi-limit@example.com');

    // unreadable property is skipped, not imported
    $response = $this->actingAs($user)->postJson('/api/integrations/google/gsc/import', [
        'properties' => ['https://not-in-gsc.example.com/'],
    ])->assertCreated();
    expect($response->json('skipped.0.reason'))->toBe('not_readable');

    // fill the plan (5 sites)
    foreach (['a.com', 'b.com', 'c.com', 'd.com', 'e.com'] as $i => $d) {
        Site::withoutGlobalScope('organization')->create([
            'organization_id' => $org->id, 'name' => $d, 'domain' => $d,
            'normalized_domain' => $d, 'platform' => 'generic',
        ]);
    }

    $response = $this->actingAs($user)->postJson('/api/integrations/google/gsc/import', [
        'properties' => ['sc-domain:example.com'],
    ])->assertCreated();

    expect($response->json('created'))->toBe([])
        ->and($response->json('skipped.0.reason'))->toBe('site_limit_reached');
});

it('refuses property listing/import when google is not connected at org level', function () {
    [$user, $org] = makeOrgUser('gi-noacct@example.com');

    $this->actingAs($user)->getJson('/api/integrations/google/gsc/properties')
        ->assertStatus(409)->assertJsonPath('code', 'org_google_not_connected');

    $this->actingAs($user)->postJson('/api/integrations/google/gsc/import', ['properties' => ['sc-domain:x.com']])
        ->assertStatus(409)->assertJsonPath('code', 'org_google_not_connected');
});

it('ga4 org-level property listing works from the shared grant', function () {
    [$user, $org] = makeOrgWithGoogle('gi-ga4@example.com');

    Http::fake(['*accountSummaries*' => Http::response([
        'accountSummaries' => [[
            'displayName'       => 'Main Account',
            'propertySummaries' => [
                ['property' => 'properties/123', 'displayName' => 'My Site'],
            ],
        ]],
    ])]);

    $response = $this->actingAs($user)->getJson('/api/integrations/google/ga4/properties')->assertOk();

    expect($response->json('properties.0.id'))->toBe('123')
        ->and($response->json('properties.0.name'))->toBe('My Site')
        ->and($response->json('properties.0.account'))->toBe('Main Account');
});
