<?php

use App\Models\License;
use App\Models\LicenseActivation;
use App\Models\Site;
use App\Models\Subscription;
use App\Services\LicenseService;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;

/**
 * License activation and the payment/trial grace windows.
 *
 * This is the contract every installed plugin depends on, and the grace
 * logic is the part most likely to be got wrong in a way nobody notices
 * until a paying customer is locked out — or a lapsed one keeps full access.
 */
function licensedOrg(string $email, array $orgAttrs = []): array
{
    [, $org] = makeOrgUser($email);

    if ($orgAttrs !== []) {
        $org->forceFill($orgAttrs)->save();
    }

    $rawKey = app(LicenseService::class)->issue($org->fresh());

    return [$org->fresh(), $rawKey, License::withoutGlobalScope('organization')->where('organization_id', $org->id)->firstOrFail()];
}

function activate(string $key, string $siteUrl = 'https://shop.example.com')
{
    return test()->postJson('/api/plugin/activate', [
        'license_key' => $key,
        'site_url' => $siteUrl,
        'site_name' => 'Shop',
    ]);
}

it('activates a valid key and issues connector credentials', function () {
    [$org, $key] = licensedOrg('lic-ok@example.com');

    $body = activate($key)->assertOk()->json();

    expect($body['status'] ?? null)->not->toBe('invalid_key');

    $site = Site::withoutGlobalScope('organization')->firstOrFail();
    expect($site->organization_id)->toBe($org->id)
        ->and($site->connector_key_id)->toStartWith('ik_');

    expect(LicenseActivation::withoutGlobalScope('organization')->count())->toBe(1);
});

it('rejects an unknown key without creating anything', function () {
    activate('insightistic_totally-made-up-key')
        ->assertStatus(403)
        ->assertJson(['code' => 'invalid_key']);

    expect(Site::withoutGlobalScope('organization')->count())->toBe(0)
        ->and(LicenseActivation::withoutGlobalScope('organization')->count())->toBe(0);
});

it('re-activating the same site is idempotent and rotates the secret', function () {
    [, $key] = licensedOrg('lic-reactivate@example.com');

    activate($key)->assertOk();
    $first = Site::withoutGlobalScope('organization')->firstOrFail();
    $firstSecret = $first->connector_secret;

    activate($key)->assertOk();

    // One site, one activation — a reinstall must not multiply either.
    expect(Site::withoutGlobalScope('organization')->count())->toBe(1)
        ->and(LicenseActivation::withoutGlobalScope('organization')->count())->toBe(1);

    // Credentials are re-issued: a reinstall replaces the only plugin
    // instance that held the previous secret.
    expect($first->fresh()->connector_secret)->not->toBe($firstSecret);
});

it('recovers activation when the stored connector secret uses an old encryption key', function () {
    [, $key] = licensedOrg('lic-stale-connector-secret@example.com');

    activate($key)->assertOk();
    $site = Site::withoutGlobalScope('organization')->firstOrFail();

    // Simulate ciphertext created before the production APP_KEY changed.
    $previousKey = new Encrypter(str_repeat('x', 32), 'AES-256-CBC');
    DB::table('sites')->where('id', $site->id)->update([
        'connector_secret' => $previousKey->encryptString('stale-secret'),
    ]);

    $response = activate($key)->assertOk();
    $rotatedSecret = $response->json('connector.secret');

    expect($rotatedSecret)->toStartWith('sk_')
        ->and($site->fresh()->connector_secret)->toBe($rotatedSecret)
        ->and($site->fresh()->connection_status)->toBe('connected')
        ->and(LicenseActivation::withoutGlobalScope('organization')->count())->toBe(1);
});

it('refuses a revoked key', function () {
    [, $key, $license] = licensedOrg('lic-revoked@example.com');
    $license->forceFill(['revoked_at' => now()])->save();

    activate($key)->assertStatus(403)->assertJson(['code' => 'key_revoked']);
});

it('keeps a trialing organization entitled until the trial actually lapses', function () {
    [$org, $key] = licensedOrg('lic-trial@example.com', [
        'status' => 'trialing',
        'trial_ends_at' => now()->addDays(3),
    ]);

    activate($key)->assertOk();

    expect(app(LicenseService::class)->computeStatus($org->fresh()))->toBe('trialing');
});

it('locks out an expired trial', function () {
    [$org, $key] = licensedOrg('lic-trial-over@example.com', [
        'status' => 'trialing',
        'trial_ends_at' => now()->subDay(),
    ]);

    activate($key)
        ->assertStatus(402)
        ->assertJson(['code' => 'license_expired']);

    expect(app(LicenseService::class)->computeStatus($org->fresh()))->toBe('expired');
});

it('keeps a failed payment usable inside the grace window and suspends it after', function () {
    config()->set('insightistic.license.payment_grace_days', 7);

    [$org, $key] = licensedOrg('lic-grace@example.com', ['status' => 'past_due']);

    $subscription = Subscription::create([
        'organization_id' => $org->id,
        'stripe_customer_id' => 'cus_grace',
        'status' => 'past_due',
    ]);

    // Day 2 of a 7-day grace window: still working, flagged as payment_failed.
    $subscription->forceFill(['updated_at' => now()->subDays(2)])->save();
    expect(app(LicenseService::class)->computeStatus($org->fresh()))->toBe('payment_failed');
    activate($key)->assertOk();

    // Day 9: past the window, access is suspended.
    $subscription->forceFill(['updated_at' => now()->subDays(9)])->save();
    expect(app(LicenseService::class)->computeStatus($org->fresh()))->toBe('suspended');

    activate($key, 'https://another.example.com')
        ->assertStatus(402)
        ->assertJson(['code' => 'payment_required']);
});

it('keeps a cancelled organization running until the paid period ends', function () {
    [$org, $key] = licensedOrg('lic-cancelled@example.com', ['status' => 'canceled']);

    $subscription = Subscription::create([
        'organization_id' => $org->id,
        'stripe_customer_id' => 'cus_cancel',
        'status' => 'canceled',
        'current_period_end' => now()->addDays(10),
    ]);

    // Paid through: still entitled.
    expect(app(LicenseService::class)->computeStatus($org->fresh()))->toBe('active');
    activate($key)->assertOk();

    // Period elapsed: access ends.
    $subscription->forceFill(['current_period_end' => now()->subDay()])->save();
    expect(app(LicenseService::class)->computeStatus($org->fresh()))->toBe('cancelled');
});

it('a super admin entitlement override beats billing state but not a revoke', function () {
    [$org, $key, $license] = licensedOrg('lic-override@example.com', ['status' => 'past_due']);

    $subscription = Subscription::create([
        'organization_id' => $org->id,
        'stripe_customer_id' => 'cus_override',
        'status' => 'past_due',
    ]);
    $subscription->forceFill(['updated_at' => now()->subDays(30), 'internal_override_active' => true])->save();

    expect(app(LicenseService::class)->computeStatus($org->fresh()))->toBe('active');

    // Revoke is sticky and must still win over the override.
    $license->forceFill(['revoked_at' => now()])->save();
    expect(app(LicenseService::class)->computeStatus($org->fresh(), $license->fresh()))->toBe('revoked');
});

it('enforces the activation limit on production sites only', function () {
    [$org, $key, $license] = licensedOrg('lic-limit@example.com');
    $license->forceFill(['activation_limit' => 1])->save();

    activate($key, 'https://one.example.com')->assertOk();

    // A staging host must not consume a paid slot.
    activate($key, 'https://staging.two.example.com')->assertOk();

    // A second production host does.
    activate($key, 'https://three.example.com')
        ->assertStatus(402)
        ->assertJson(['code' => 'activation_limit_reached']);
});
