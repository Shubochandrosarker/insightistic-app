<?php

use App\Models\DataSourceConnection;
use App\Models\DataSourceCredential;
use App\Models\EmailCampaign;
use App\Models\Site;
use App\Services\Email\EmailAnalyticsService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Email analytics (spec Phase 3.4): provider connection, API pull,
 * connector push, aggregation, and the honest-empty-state rule that a
 * missing counter is never rendered as a real zero.
 */

function makeEmailSite(\App\Models\Organization $org, string $domain = 'shop.example.com'): Site
{
    return Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id,
        'name'            => 'Shop',
        'domain'          => $domain,
    ]);
}

function makeCampaign(Site $site, array $attrs = []): EmailCampaign
{
    return EmailCampaign::withoutGlobalScope('organization')->create(array_merge([
        'organization_id' => $site->organization_id,
        'site_id'         => $site->id,
        'provider'        => 'mailchimp',
        'external_id'     => Str::random(10),
        'name'            => 'Newsletter',
        'type'            => 'campaign',
        'status'          => 'sent',
        'sent_at'         => now()->subDays(3),
    ], $attrs));
}

it('reports an honest disconnected state instead of zeroes', function () {
    [$user, $org] = makeOrgUser('em-empty@example.com');
    $site = makeEmailSite($org);

    $body = $this->actingAs($user)->getJson("/api/sites/{$site->id}/emails/overview")->assertOk()->json();

    expect($body['connection']['connected'])->toBeFalse()
        ->and($body['connection']['provider'])->toBeNull()
        ->and($body['overview']['campaigns'])->toBe(0);

    // The critical rule: nothing reported means null, never 0.
    expect($body['overview']['totals']['sent'])->toBeNull()
        ->and($body['overview']['totals']['revenue'])->toBeNull()
        ->and($body['overview']['rates']['open'])->toBeNull();

    // Both connection routes are advertised so the UI can offer either path.
    expect(collect($body['connection']['available_providers'])->pluck('key')->all())
        ->toEqualCanonicalizing(['mailchimp', 'klaviyo']);
    expect($body['connection']['connector_providers'])
        ->toEqualCanonicalizing(['fluentcrm', 'automatewoo', 'woocommerce']);
});

it('verifies a mailchimp key, stores it encrypted and never returns it', function () {
    [$user, $org] = makeOrgUser('em-connect@example.com');
    $site = makeEmailSite($org);

    Http::preventStrayRequests();
    Http::fake(['*mailchimp.com/3.0*' => Http::response(['account_name' => 'Acme Store'])]);

    $response = $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/emails/connect", ['provider' => 'mailchimp', 'api_key' => 'abc123def456-us14'])
        ->assertOk();

    expect($response->json('account'))->toBe('Acme Store')
        ->and($response->getContent())->not->toContain('abc123def456');

    $credential = DataSourceCredential::withoutGlobalScope('organization')->firstOrFail();
    expect($credential->source)->toBe('email')
        ->and($credential->provider)->toBe('mailchimp')
        ->and($credential->access_token)->toBe('abc123def456-us14');

    // Encrypted at rest.
    $raw = \Illuminate\Support\Facades\DB::table('data_source_credentials')->value('access_token');
    expect($raw)->not->toContain('abc123def456');

    // The overview must expose the account label but never the key.
    $overview = $this->actingAs($user)->getJson("/api/sites/{$site->id}/emails/overview")->assertOk();
    expect($overview->json('connection.account'))->toBe('Acme Store')
        ->and($overview->getContent())->not->toContain('abc123def456');
});

it('rejects a mailchimp key with no data centre suffix before calling out', function () {
    [$user, $org] = makeOrgUser('em-baddc@example.com');
    $site = makeEmailSite($org);

    Http::fake(); // any outbound call would return 200 and hide the bug

    $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/emails/connect", ['provider' => 'mailchimp', 'api_key' => 'keywithoutsuffix'])
        ->assertStatus(409)
        ->assertJson(['code' => 'invalid_credential']);

    Http::assertNothingSent();
    expect(DataSourceCredential::withoutGlobalScope('organization')->count())->toBe(0);
});

it('pulls mailchimp reports into campaigns with a derived delivered count', function () {
    [$user, $org] = makeOrgUser('em-sync@example.com');
    $site = makeEmailSite($org);

    DataSourceCredential::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id, 'site_id' => $site->id,
        'source' => 'email', 'provider' => 'mailchimp',
        'access_token' => 'key-us14', 'external_account_id' => 'Acme',
    ]);

    Http::fake([
        '*mailchimp.com/3.0/reports*' => Http::response(['reports' => [[
            'id'             => 'camp-1',
            'campaign_title' => 'July newsletter',
            'subject_line'   => 'Summer deals',
            'send_time'      => '2026-07-01T10:00:00+00:00',
            'emails_sent'    => 1000,
            'bounces'        => ['hard_bounces' => 5, 'soft_bounces' => 3, 'syntax_errors' => 2],
            'opens'          => ['opens_total' => 700, 'unique_opens' => 400],
            'clicks'         => ['clicks_total' => 120, 'unique_subscriber_clicks' => 90],
            'unsubscribed'   => 4,
            'abuse_reports'  => 1,
            'ecommerce'      => ['total_orders' => 12, 'total_revenue' => 845.50, 'currency_code' => 'USD'],
        ]]]),
    ]);

    $result = $this->actingAs($user)->postJson("/api/sites/{$site->id}/emails/sync")->assertOk()->json('result');

    expect($result['campaigns'])->toBe(1)->and($result['provider'])->toBe('mailchimp');

    $campaign = EmailCampaign::withoutGlobalScope('organization')->firstOrFail();
    expect($campaign->sent)->toBe(1000)
        // 1000 sent minus 10 bounces of all three kinds.
        ->and($campaign->delivered)->toBe(990)
        ->and($campaign->bounced)->toBe(10)
        ->and($campaign->unique_opens)->toBe(400)
        ->and((float) $campaign->revenue)->toBe(845.50)
        ->and($campaign->currency)->toBe('USD');

    // Open rate is unique opens over delivered.
    expect($campaign->openRate())->toBe(40.4);

    $state = DataSourceConnection::withoutGlobalScope('organization')->firstOrFail();
    expect($state->state)->toBe('connected')->and($state->last_successful_at)->not->toBeNull();
});

it('re-syncing updates campaigns instead of duplicating them', function () {
    [$user, $org] = makeOrgUser('em-idempotent@example.com');
    $site = makeEmailSite($org);

    DataSourceCredential::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id, 'site_id' => $site->id,
        'source' => 'email', 'provider' => 'mailchimp', 'access_token' => 'key-us14',
    ]);

    Http::fake([
        '*mailchimp.com/3.0/reports*' => Http::sequence()
            ->push(['reports' => [['id' => 'camp-1', 'campaign_title' => 'Draft name', 'emails_sent' => 100]]])
            ->push(['reports' => [['id' => 'camp-1', 'campaign_title' => 'Final name', 'emails_sent' => 150]]]),
    ]);

    $service = app(EmailAnalyticsService::class);
    $service->sync($site);
    $service->sync($site);

    expect(EmailCampaign::withoutGlobalScope('organization')->count())->toBe(1);

    $campaign = EmailCampaign::withoutGlobalScope('organization')->firstOrFail();
    expect($campaign->name)->toBe('Final name')->and($campaign->sent)->toBe(150);
});

it('marks the connection as errored when the provider rejects the key', function () {
    [$user, $org] = makeOrgUser('em-revoked@example.com');
    $site = makeEmailSite($org);

    DataSourceCredential::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id, 'site_id' => $site->id,
        'source' => 'email', 'provider' => 'mailchimp', 'access_token' => 'key-us14',
    ]);

    Http::fake(['*mailchimp.com/3.0/reports*' => Http::response(['detail' => 'API key revoked'], 401)]);

    $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/emails/sync")
        ->assertStatus(409)
        ->assertJson(['code' => 'invalid_credential']);

    $state = DataSourceConnection::withoutGlobalScope('organization')->firstOrFail();
    expect($state->state)->toBe('error')->and($state->last_error_message)->toContain('revoked');
});

it('accepts connector-pushed campaigns from WordPress-side platforms', function () {
    [, $org] = makeOrgUser('em-connector@example.com');
    $site = makeEmailSite($org);

    $rows = [[
        'provider' => 'fluentcrm', 'external_id' => 'fc-9', 'name' => 'Welcome sequence',
        'type' => 'automation', 'sent_at' => now()->subDay()->toDateTimeString(),
        'sent' => 200, 'delivered' => 198, 'opened' => 90, 'clicked' => 20, 'revenue' => 310.25,
    ]];

    app(EmailAnalyticsService::class)->store($site, $rows);

    $campaign = EmailCampaign::withoutGlobalScope('organization')->firstOrFail();
    expect($campaign->provider)->toBe('fluentcrm')
        ->and($campaign->type)->toBe('automation')
        ->and($campaign->delivered)->toBe(198);

    // No API credential is involved for connector-backed providers.
    expect(DataSourceCredential::withoutGlobalScope('organization')->count())->toBe(0);
});

it('aggregates across providers and leaves unreported counters null', function () {
    [$user, $org] = makeOrgUser('em-overview@example.com');
    $site = makeEmailSite($org);

    makeCampaign($site, ['provider' => 'mailchimp', 'sent' => 1000, 'delivered' => 990, 'unique_opens' => 400, 'unique_clicks' => 90, 'revenue' => 500]);
    makeCampaign($site, ['provider' => 'fluentcrm', 'sent' => 500, 'delivered' => 495, 'unique_opens' => 200, 'unique_clicks' => 40, 'revenue' => 250]);

    $body = $this->actingAs($user)->getJson("/api/sites/{$site->id}/emails/overview")->assertOk()->json('overview');

    expect($body['campaigns'])->toBe(2)
        ->and($body['providers'])->toEqualCanonicalizing(['mailchimp', 'fluentcrm'])
        ->and($body['totals']['sent'])->toBe(1500)
        ->and($body['totals']['opened'])->toBe(600)
        ->and((float) $body['totals']['revenue'])->toBe(750.0)
        // 600 unique opens over 1485 delivered.
        ->and($body['rates']['open'])->toBe(40.4);

    // Neither campaign reported bounces or unsubscribes — must stay null.
    expect($body['totals']['bounced'])->toBeNull()
        ->and($body['totals']['unsubscribed'])->toBeNull()
        ->and($body['rates']['bounce'])->toBeNull();
});

it('excludes campaigns outside the requested window', function () {
    [$user, $org] = makeOrgUser('em-window@example.com');
    $site = makeEmailSite($org);

    makeCampaign($site, ['sent' => 100, 'sent_at' => now()->subDays(5)]);
    makeCampaign($site, ['sent' => 900, 'sent_at' => now()->subDays(200)]);

    $recent = $this->actingAs($user)->getJson("/api/sites/{$site->id}/emails/overview?days=30")->json('overview');
    expect($recent['campaigns'])->toBe(1)->and($recent['totals']['sent'])->toBe(100);

    $all = $this->actingAs($user)->getJson("/api/sites/{$site->id}/emails/overview?days=365")->json('overview');
    expect($all['campaigns'])->toBe(2)->and($all['totals']['sent'])->toBe(1000);
});

it('lists campaigns newest first and filters by provider', function () {
    [$user, $org] = makeOrgUser('em-list@example.com');
    $site = makeEmailSite($org);

    makeCampaign($site, ['provider' => 'mailchimp', 'name' => 'Older', 'sent_at' => now()->subDays(10)]);
    makeCampaign($site, ['provider' => 'klaviyo', 'name' => 'Newer', 'sent_at' => now()->subDay()]);

    $all = $this->actingAs($user)->getJson("/api/sites/{$site->id}/emails/campaigns")->assertOk()->json('campaigns');
    expect($all)->toHaveCount(2)->and($all[0]['name'])->toBe('Newer');

    $filtered = $this->actingAs($user)->getJson("/api/sites/{$site->id}/emails/campaigns?provider=mailchimp")->json('campaigns');
    expect($filtered)->toHaveCount(1)->and($filtered[0]['name'])->toBe('Older');
});

it('keeps campaigns and connections inside their organization', function () {
    [$userA] = makeOrgUser('em-tenant-a@example.com');
    [, $orgB] = makeOrgUser('em-tenant-b@example.com');
    $siteB = makeEmailSite($orgB, 'other.example.com');
    makeCampaign($siteB, ['name' => 'Private campaign']);

    $this->actingAs($userA)->getJson("/api/sites/{$siteB->id}/emails/overview")->assertNotFound();
    $this->actingAs($userA)->getJson("/api/sites/{$siteB->id}/emails/campaigns")->assertNotFound();
    $this->actingAs($userA)->deleteJson("/api/sites/{$siteB->id}/emails/connection")->assertNotFound();

    expect(EmailCampaign::withoutGlobalScope('organization')->count())->toBe(1);
});

it('blocks connecting and disconnecting for non-admin roles', function () {
    [, $org] = makeOrgUser('em-role@example.com');
    $site = makeEmailSite($org);

    $analyst = \App\Models\User::create([
        'name' => 'Analyst', 'email' => 'em-analyst@example.com',
        'password' => 'secret-password', 'status' => 'active',
    ]);
    $org->users()->attach($analyst->id, ['role' => 'analyst']);

    $this->actingAs($analyst)->getJson("/api/sites/{$site->id}/emails/overview")->assertOk();

    $this->actingAs($analyst)
        ->postJson("/api/sites/{$site->id}/emails/connect", ['provider' => 'mailchimp', 'api_key' => 'abc123def456-us14'])
        ->assertForbidden();
    $this->actingAs($analyst)->deleteJson("/api/sites/{$site->id}/emails/connection")->assertForbidden();
});

it('disconnecting removes the credential but keeps historical campaigns', function () {
    [$user, $org] = makeOrgUser('em-disconnect@example.com');
    $site = makeEmailSite($org);
    makeCampaign($site, ['sent' => 100]);

    DataSourceCredential::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id, 'site_id' => $site->id,
        'source' => 'email', 'provider' => 'mailchimp', 'access_token' => 'key-us14',
    ]);

    $this->actingAs($user)->deleteJson("/api/sites/{$site->id}/emails/connection")->assertOk();

    expect(DataSourceCredential::withoutGlobalScope('organization')->count())->toBe(0)
        // Disconnecting a provider must not destroy reporting history.
        ->and(EmailCampaign::withoutGlobalScope('organization')->count())->toBe(1);

    $state = DataSourceConnection::withoutGlobalScope('organization')->firstOrFail();
    expect($state->state)->toBe('not_configured');
});

it('rejects an unknown provider on connect', function () {
    [$user, $org] = makeOrgUser('em-badprovider@example.com');
    $site = makeEmailSite($org);

    $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/emails/connect", ['provider' => 'sendgrid', 'api_key' => 'x-us14-longenough'])
        ->assertStatus(422);

    // fluentcrm is connector-only — it must not be connectable with a key.
    $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/emails/connect", ['provider' => 'fluentcrm', 'api_key' => 'x-us14-longenough'])
        ->assertStatus(422);
});
