<?php

use App\Models\AppSumoLicense;
use App\Models\License;
use App\Models\Plan;
use App\Services\AppSumoLicenseService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * AppSumo Licensing API v2 intake (partner guide: webhooks + OAuth).
 *
 * The HMAC signature is the only thing standing between the public webhook
 * and entitlement writes, so missing/forged signatures must 401. The 200
 * {"event": …, "success": true} response shape and the test-event no-op are
 * contractual — AppSumo's Partner Portal URL validation depends on both.
 */

const APPSUMO_TEST_KEY = 'test-licensing-api-key';

/** Build a signed AppSumo webhook request the way AppSumo signs one. */
function appsumoWebhook(array $payload, string $secret = APPSUMO_TEST_KEY, ?int $timestamp = null): array
{
    $body = json_encode($payload);
    $ts = $timestamp ?? time();
    $signature = hash_hmac('sha256', $ts . $body, $secret);

    return [$body, [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X-APPSUMO-SIGNATURE' => $signature,
        'HTTP_X-APPSUMO-TIMESTAMP' => (string) $ts,
    ]];
}

/** Org + Insightistic license row, plus the AppSumo Agency plan for tier mapping. */
function appsumoOrgWithLicense(): array
{
    [, $org] = makeOrgUser('appsumo-' . Str::random(6) . '@example.com');

    $plan = Plan::create([
        'name' => 'AppSumo Agency', 'slug' => 'appsumo_agency',
        'site_limit' => 25, 'user_limit' => 10, 'ai_insight_limit' => 100,
        'report_limit' => 100, 'is_active' => true,
    ]);

    $license = License::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id, 'plan_id' => $org->plan_id,
        'license_key_hash' => hash('sha256', Str::random(32)), 'license_key_last4' => '4321',
        'status' => 'active', 'activation_limit' => 5,
    ]);

    return [$org, $plan, $license];
}

it('rejects an AppSumo webhook with a missing or invalid signature', function () {
    config()->set('services.appsumo.key', APPSUMO_TEST_KEY);

    $payload = [
        'license_key' => '3794577c-3dbc-11ec-9bbc-0242ac130002',
        'event' => 'purchase', 'license_status' => 'inactive',
        'event_timestamp' => 1318781876406, 'tier' => 1,
    ];

    // No signature headers at all.
    [$body] = appsumoWebhook($payload);
    $this->call('POST', '/api/appsumo/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)
        ->assertStatus(401);

    // Signature computed with the wrong secret.
    [$body2, $headers2] = appsumoWebhook($payload, 'not-the-api-key');
    $this->call('POST', '/api/appsumo/webhook', [], [], [], $headers2, $body2)->assertStatus(401);

    // Missing timestamp header (part of the signed string).
    [, $headers3] = appsumoWebhook($payload);
    unset($headers3['HTTP_X-APPSUMO-TIMESTAMP']);
    $this->call('POST', '/api/appsumo/webhook', [], [], [], $headers3, $body)->assertStatus(401);

    expect(AppSumoLicense::count())->toBe(0);
});

it('acknowledges a test webhook without side effects', function () {
    config()->set('services.appsumo.key', APPSUMO_TEST_KEY);

    [$body, $headers] = appsumoWebhook([
        'license_key' => '00000000-aaaa-1111-bbbb-abcdef012345',
        'event' => 'purchase', 'license_status' => 'inactive',
        'event_timestamp' => 1318781876406, 'tier' => 1, 'test' => true,
        'extra' => ['reason' => 'Test event'],
    ]);

    $this->call('POST', '/api/appsumo/webhook', [], [], [], $headers, $body)
        ->assertOk()
        ->assertJson(['event' => 'purchase', 'success' => true]);

    expect(AppSumoLicense::count())->toBe(0);
});

it('upserts a purchase by license_key and keeps the raw payload', function () {
    config()->set('services.appsumo.key', APPSUMO_TEST_KEY);

    $key = '3794577c-3dbc-11ec-9bbc-0242ac130002';

    [$body, $headers] = appsumoWebhook([
        'license_key' => $key, 'event' => 'purchase', 'license_status' => 'inactive',
        'event_timestamp' => 1318781876406, 'created_at' => 1318738512,
        'tier' => 2, 'test' => false, 'extra' => ['reason' => 'Purchased'],
    ]);

    $this->call('POST', '/api/appsumo/webhook', [], [], [], $headers, $body)
        ->assertOk()
        ->assertJson(['event' => 'purchase', 'success' => true]);

    $row = AppSumoLicense::where('license_key', $key)->firstOrFail();
    expect($row->event)->toBe('purchase')
        ->and($row->license_status)->toBe('inactive')
        ->and($row->tier)->toBe(2)
        ->and($row->plan_slug)->toBe('appsumo_studio')
        ->and($row->event_timestamp)->toBe(1318781876406)
        ->and($row->is_test)->toBeFalse()
        ->and($row->raw['extra']['reason'])->toBe('Purchased');

    // AppSumo retries: upsert the same row, never duplicate.
    [$retryBody, $retryHeaders] = appsumoWebhook([
        'license_key' => $key, 'event' => 'activate', 'license_status' => 'inactive',
        'event_timestamp' => 1318781876999, 'tier' => 2, 'test' => false,
    ]);
    $this->call('POST', '/api/appsumo/webhook', [], [], [], $retryHeaders, $retryBody)->assertOk();

    expect(AppSumoLicense::where('license_key', $key)->count())->toBe(1)
        ->and(AppSumoLicense::where('license_key', $key)->first()->event)->toBe('activate')
        ->and(AppSumoLicense::where('license_key', $key)->first()->event_timestamp)->toBe(1318781876999);
});

it('marks the previous license deactivated and links the new key on upgrade', function () {
    config()->set('services.appsumo.key', APPSUMO_TEST_KEY);

    $oldKey = '3794577c-3dbc-11ec-9bbc-0242ac130002';
    $newKey = 'c86ad3d7-3942-4d11-8814-b0bd81971691';

    [$purchaseBody, $purchaseHeaders] = appsumoWebhook([
        'license_key' => $oldKey, 'event' => 'purchase', 'license_status' => 'inactive',
        'event_timestamp' => 1671586380000, 'tier' => 1,
    ]);
    $this->call('POST', '/api/appsumo/webhook', [], [], [], $purchaseHeaders, $purchaseBody)->assertOk();

    [$upgradeBody, $upgradeHeaders] = appsumoWebhook([
        'license_key' => $newKey, 'prev_license_key' => $oldKey, 'event' => 'upgrade',
        'license_status' => 'inactive', 'event_timestamp' => 1671586387628, 'tier' => 2,
        'extra' => ['reason' => 'Upgraded by the customer'],
    ]);
    $this->call('POST', '/api/appsumo/webhook', [], [], [], $upgradeHeaders, $upgradeBody)->assertOk();

    $row = AppSumoLicense::where('license_key', $newKey)->firstOrFail();
    expect($row->prev_license_key)->toBe($oldKey)
        ->and($row->tier)->toBe(2)
        ->and($row->plan_slug)->toBe('appsumo_studio');

    $prev = AppSumoLicense::where('license_key', $oldKey)->firstOrFail();
    expect($prev->license_status)->toBe('deactivated')
        ->and($prev->raw['deactivation_reason'])->toBe('Upgraded by the customer');
});

it('applies tier changes and deactivation to the linked Insightistic license', function () {
    config()->set('services.appsumo.key', APPSUMO_TEST_KEY);
    [$org, $plan, $license] = appsumoOrgWithLicense();

    $key = '9869ba65-cf39-405e-98db-6e2ca29f94fa';

    AppSumoLicense::create([
        'license_key' => $key, 'event' => 'purchase', 'license_status' => 'inactive',
        'tier' => 1, 'plan_slug' => 'appsumo_solo',
        'org_id' => $org->id, 'license_id' => $license->id,
        'raw' => [], 'event_timestamp' => 0,
    ]);

    // Buyer upgrades to tier 3 → linked license moves to the AppSumo Agency plan.
    [$upgradeBody, $upgradeHeaders] = appsumoWebhook([
        'license_key' => $key, 'event' => 'activate', 'license_status' => 'inactive',
        'event_timestamp' => 111, 'tier' => 3,
    ]);
    $this->call('POST', '/api/appsumo/webhook', [], [], [], $upgradeHeaders, $upgradeBody)->assertOk();

    $license->refresh();
    expect($license->plan_id)->toBe($plan->id)
        ->and($license->status)->toBe('active')
        ->and($org->fresh()->billing_provider)->toBe('license');

    // Refund → deactivate flips the linked license off.
    [$deactivateBody, $deactivateHeaders] = appsumoWebhook([
        'license_key' => $key, 'event' => 'deactivate', 'license_status' => 'active',
        'event_timestamp' => 222, 'tier' => 3, 'extra' => ['reason' => 'Refunded by the user'],
    ]);
    $this->call('POST', '/api/appsumo/webhook', [], [], [], $deactivateHeaders, $deactivateBody)->assertOk();

    expect($license->fresh()->status)->toBe('deactivated');
});

it('maps known AppSumo tiers and refuses unknown tiers rather than granting a wrong plan', function () {
    $service = app(AppSumoLicenseService::class);

    expect($service->tierToPlanSlug(1))->toBe('appsumo_solo')
        ->and($service->tierToPlanSlug(2))->toBe('appsumo_studio')
        ->and($service->tierToPlanSlug(3))->toBe('appsumo_agency');

    expect(fn () => $service->tierToPlanSlug(null))->toThrow(InvalidArgumentException::class);
    expect(fn () => $service->tierToPlanSlug(0))->toThrow(InvalidArgumentException::class);
    expect(fn () => $service->tierToPlanSlug(99))->toThrow(InvalidArgumentException::class);
});

it('answers the OAuth redirect validation ping with 200', function () {
    $this->get('/api/appsumo/oauth/callback')
        ->assertOk()
        ->assertJson(['ok' => true, 'service' => 'appsumo-oauth']);
});

it('exchanges the OAuth code and redirects to the claim page', function () {
    config()->set('services.appsumo.key', APPSUMO_TEST_KEY);
    config()->set('services.appsumo.oauth.client_id', 'cid_test');
    config()->set('services.appsumo.oauth.client_secret', 'csecret_test');

    $key = 'd8bfa201-d8c0-4bc8-a27c-b1c12efa4a5a';

    Http::fake([
        'appsumo.com/openid/token*' => Http::response(['access_token' => 'at_test', 'token_type' => 'bearer'], 200),
        'appsumo.com/openid/license_key*' => Http::response(['license_key' => $key, 'status' => 'active'], 200),
        'api.licensing.appsumo.com/*' => Http::response(['license_key' => $key, 'status' => 'active', 'tier' => 3], 200),
    ]);

    $state = urlencode(json_encode(['code_verifier' => 'verify-me']));

    $this->get("/api/appsumo/oauth/callback?code=abc123&state={$state}")
        ->assertRedirect("https://app.insightistic.com/appsumo/claim?license={$key}");

    expect(AppSumoLicense::where('license_key', $key)->firstOrFail()->plan_slug)->toBe('appsumo_agency');

    // The single-use code and the state-provided PKCE verifier reach the exchange.
    Http::assertSent(function ($request) {
        return str_starts_with($request->url(), 'https://appsumo.com/openid/token')
            && $request['code'] === 'abc123'
            && $request['code_verifier'] === 'verify-me'
            && $request['grant_type'] === 'authorization_code';
    });

    // The Licensing API fetch carries the partner key header.
    Http::assertSent(function ($request) use ($key) {
        return $request->url() === 'https://api.licensing.appsumo.com/v2/licenses/' . $key
            && $request->header('X-AppSumo-Licensing-Key') === [APPSUMO_TEST_KEY];
    });
});

it('redirects to pricing with an error flag when the OAuth exchange fails', function () {
    config()->set('services.appsumo.key', APPSUMO_TEST_KEY);
    config()->set('services.appsumo.oauth.client_id', 'cid_test');
    config()->set('services.appsumo.oauth.client_secret', 'csecret_test');

    Http::fake([
        'appsumo.com/openid/token*' => Http::response(['error' => 'server_error'], 500),
    ]);

    $this->get('/api/appsumo/oauth/callback?code=abc123')
        ->assertRedirect('https://app.insightistic.com/pricing?appsumo_error=1');

    expect(AppSumoLicense::count())->toBe(0);
});


it('preserves linked customer entitlement across upgrade and old-key deactivate callbacks', function () {
    config()->set('services.appsumo.key', APPSUMO_TEST_KEY);
    [$org, , $license] = appsumoOrgWithLicense();

    $studio = Plan::create([
        'name' => 'AppSumo Studio', 'slug' => 'appsumo_studio',
        'site_limit' => 10, 'user_limit' => 3, 'ai_insight_limit' => 600,
        'report_limit' => 25, 'is_active' => true,
    ]);

    $owner = $org->owner_user_id;
    $oldKey = (string) Str::uuid();
    $newKey = (string) Str::uuid();

    AppSumoLicense::create([
        'license_key' => $oldKey, 'event' => 'activate', 'license_status' => 'active',
        'tier' => 1, 'plan_slug' => 'appsumo_solo',
        'org_id' => $org->id, 'user_id' => $owner, 'license_id' => $license->id,
        'raw' => [], 'event_timestamp' => 100,
    ]);

    [$upgrade, $headers] = appsumoWebhook([
        'license_key' => $newKey, 'prev_license_key' => $oldKey,
        'event' => 'upgrade', 'license_status' => 'inactive',
        'event_timestamp' => 200, 'tier' => 2,
    ]);
    $this->call('POST', '/api/appsumo/webhook', [], [], [], $headers, $upgrade)->assertOk();

    $current = AppSumoLicense::where('license_key', $newKey)->firstOrFail();
    expect($current->user_id)->toBe($owner)
        ->and($current->org_id)->toBe($org->id)
        ->and($current->license_id)->toBe($license->id)
        ->and($license->fresh()->status)->toBe('active')
        ->and($license->fresh()->plan_id)->toBe($studio->id)
        ->and($org->fresh()->plan_id)->toBe($studio->id);

    // AppSumo sends this separately, sometimes after the new key was applied.
    [$oldDeactivate, $oldHeaders] = appsumoWebhook([
        'license_key' => $oldKey, 'event' => 'deactivate',
        'license_status' => 'deactivated', 'event_timestamp' => 300, 'tier' => 1,
    ]);
    $this->call('POST', '/api/appsumo/webhook', [], [], [], $oldHeaders, $oldDeactivate)->assertOk();
    expect($license->fresh()->status)->toBe('active')
        ->and($license->fresh()->plan_id)->toBe($studio->id);

    // But a refund of the CURRENT key genuinely revokes it.
    [$refunded, $refundHeaders] = appsumoWebhook([
        'license_key' => $newKey, 'event' => 'deactivate',
        'license_status' => 'active', 'event_timestamp' => 400, 'tier' => 2,
    ]);
    $this->call('POST', '/api/appsumo/webhook', [], [], [], $refundHeaders, $refunded)->assertOk();
    expect($license->fresh()->status)->toBe('deactivated');
});

it('returns a retryable failure instead of silently acknowledging an invalid paid tier', function () {
    config()->set('services.appsumo.key', APPSUMO_TEST_KEY);
    $key = (string) Str::uuid();

    [$body, $headers] = appsumoWebhook([
        'license_key' => $key, 'event' => 'activate',
        'license_status' => 'inactive', 'event_timestamp' => 100, 'tier' => 99,
    ]);

    $this->call('POST', '/api/appsumo/webhook', [], [], [], $headers, $body)
        ->assertStatus(503)->assertJson(['success' => false, 'error' => 'delivery_not_processed']);

    expect(AppSumoLicense::where('license_key', $key)->exists())->toBeFalse();
});
