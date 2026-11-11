<?php

use App\Models\AppSumoLicense;
use App\Models\License;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * AppSumo buyer claim (the final step of the OAuth loop).
 *
 * The OAuth callback upserts the appsumo_licenses row and hands the buyer to
 * the SPA claim page; POST /api/appsumo/claim turns that key into a real
 * account. Three paths must hold: new email → user+org+link, existing email →
 * password IS the login, already-linked key → password re-verification. The
 * endpoint is public, so every failure mode here is also an abuse surface.
 */

const APPSUMO_CLAIM_TIERS = [
    1 => ['appsumo_solo', 3],
    2 => ['appsumo_studio', 10],
    3 => ['appsumo_agency', 25],
];

/** An active AppSumo license row + its seeded lifetime plan for tier N. */
function appsumoClaimLicense(int $tier = 1, string $status = 'active'): array
{
    [$slug, $sites] = APPSUMO_CLAIM_TIERS[$tier];

    $plan = Plan::create([
        'name' => ucfirst(str_replace('_', ' ', $slug)),
        'slug' => $slug,
        'site_limit' => $sites,
        'user_limit' => 5,
        'ai_insight_limit' => 100,
        'report_limit' => 100,
        'is_active' => true,
    ]);

    $row = AppSumoLicense::create([
        'license_key' => (string) Str::uuid(),
        'event' => 'activate',
        'license_status' => $status,
        'tier' => $tier,
        'plan_slug' => $slug,
        'event_timestamp' => 1318781876406,
        'is_test' => false,
    ]);

    return [$row, $plan];
}

/** The org-level `licenses` row applyEntitlements projects onto (signup flow issues one in prod). */
function appsumoClaimOrgLicense(Organization $org): License
{
    return License::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id,
        'plan_id' => $org->plan_id,
        'license_key_hash' => hash('sha256', Str::random(32)),
        'license_key_last4' => '4321',
        'status' => 'active',
        'activation_limit' => 5,
    ]);
}

it('claims an unclaimed license for a brand-new account and applies the tier plan', function () {
    [$row, $plan] = appsumoClaimLicense(tier: 2);

    $res = $this->postJson('/api/appsumo/claim', [
        'license_key' => $row->license_key,
        'email' => 'Sumo.Buyer@Example.com',
        'password' => 'super-secret-9',
        'name' => 'Sumo Buyer',
    ]);

    $res->assertCreated()
        ->assertJsonPath('plan_slug', 'appsumo_studio')
        ->assertJsonPath('sites_limit', 10)
        ->assertJsonPath('user.email', 'sumo.buyer@example.com')
        ->assertJsonStructure(['token', 'user', 'org', 'plan_slug', 'sites_limit']);

    $user = User::where('email', 'sumo.buyer@example.com')->firstOrFail();
    expect($user->status)->toBe('active')
        ->and($user->email_verified_at)->not->toBeNull();

    $row->refresh();
    $org = Organization::findOrFail($row->org_id);
    expect($row->user_id)->toBe($user->id)
        ->and($row->license_id)->not->toBeNull()
        ->and($org->plan_id)->toBe($plan->id)
        ->and($org->owner_user_id)->toBe($user->id);

    // The tier plan is projected onto the org-level licenses row (plugin
    // handshake reads this row), same as the webhook entitlement path.
    $license = License::withoutGlobalScope('organization')->findOrFail($row->license_id);
    expect($license->organization_id)->toBe($org->id)
        ->and($license->plan_id)->toBe($plan->id)
        ->and($license->status)->toBe('active');

    // The returned Sanctum token actually authenticates.
    $this->withToken($res->json('token'))->getJson('/api/auth/me')->assertOk();
});

it('lets an existing unlinked user claim by password sign-in and binds to their org', function () {
    [$row, $plan] = appsumoClaimLicense(tier: 1);
    [$user, $org] = makeOrgUser('existing-' . Str::random(6) . '@example.com');
    appsumoClaimOrgLicense($org);

    $res = $this->postJson('/api/appsumo/claim', [
        'license_key' => $row->license_key,
        'email' => $user->email,
        'password' => 'secret-password',
    ]);

    $res->assertOk()->assertJsonPath('plan_slug', 'appsumo_solo');

    $row->refresh();
    expect($row->user_id)->toBe($user->id)
        ->and($row->org_id)->toBe($org->id)
        ->and(Organization::where('owner_user_id', $user->id)->count())->toBe(1)
        ->and($org->refresh()->plan_id)->toBe($plan->id);

    $license = License::withoutGlobalScope('organization')->where('organization_id', $org->id)->first();
    expect($license->plan_id)->toBe($plan->id)
        ->and($license->status)->toBe('active');
});

it('re-logs an owner whose license is already linked without duplicating accounts', function () {
    [$row,] = appsumoClaimLicense(tier: 1);
    [$user, $org] = makeOrgUser('claimed-' . Str::random(6) . '@example.com');
    $license = appsumoClaimOrgLicense($org);

    $row->forceFill(['user_id' => $user->id, 'org_id' => $org->id, 'license_id' => $license->id])->save();

    $res = $this->postJson('/api/appsumo/claim', [
        'license_key' => $row->license_key,
        'email' => $user->email,
        'password' => 'secret-password',
    ]);

    $res->assertOk()->assertJsonPath('user.email', $user->email);
    expect(User::where('email', $user->email)->count())->toBe(1)
        ->and(Organization::where('owner_user_id', $user->id)->count())->toBe(1);

    $row->refresh();
    expect($row->user_id)->toBe($user->id)
        ->and($row->org_id)->toBe($org->id);

    $this->withToken($res->json('token'))->getJson('/api/auth/me')->assertOk();
});

it('rejects a claimed license presented with a different or unknown email', function () {
    [$row,] = appsumoClaimLicense(tier: 1);
    [$owner, $org] = makeOrgUser('owner-' . Str::random(6) . '@example.com');
    [$other,] = makeOrgUser('other-' . Str::random(6) . '@example.com');
    $license = appsumoClaimOrgLicense($org);
    $row->forceFill(['user_id' => $owner->id, 'org_id' => $org->id, 'license_id' => $license->id])->save();

    // Existing account that is not the license owner.
    $this->postJson('/api/appsumo/claim', [
        'license_key' => $row->license_key,
        'email' => $other->email,
        'password' => 'secret-password',
    ])->assertStatus(422);

    // Brand-new email: must NOT create an account or rebind the license.
    $this->postJson('/api/appsumo/claim', [
        'license_key' => $row->license_key,
        'email' => 'fresh-' . Str::random(6) . '@example.com',
        'password' => 'super-secret-9',
        'name' => 'Sneaky Newcomer',
    ])->assertStatus(422);

    expect(User::where('email', 'like', 'fresh-%')->exists())->toBeFalse()
        ->and($row->refresh()->user_id)->toBe($owner->id)
        ->and($row->org_id)->toBe($org->id);
});

it('rejects an unknown license key with 404', function () {
    $this->postJson('/api/appsumo/claim', [
        'license_key' => (string) Str::uuid(),
        'email' => 'nobody-' . Str::random(6) . '@example.com',
        'password' => 'super-secret-9',
    ])->assertStatus(404);
});

it('rejects a license that is not active with 422', function () {
    [$row,] = appsumoClaimLicense(tier: 1, status: 'deactivated');

    $this->postJson('/api/appsumo/claim', [
        'license_key' => $row->license_key,
        'email' => 'nobody-' . Str::random(6) . '@example.com',
        'password' => 'super-secret-9',
    ])->assertStatus(422);

    expect(AppSumoLicense::where('license_key', $row->license_key)->first()->user_id)->toBeNull();
});

it('rejects an existing email whose password does not match', function () {
    [$row,] = appsumoClaimLicense(tier: 1);
    [$user, $org] = makeOrgUser('wrongpw-' . Str::random(6) . '@example.com');
    appsumoClaimOrgLicense($org);

    $this->postJson('/api/appsumo/claim', [
        'license_key' => $row->license_key,
        'email' => $user->email,
        'password' => 'not-the-right-one',
    ])->assertStatus(422);

    // Nothing linked, nothing provisioned, no token minted.
    expect($row->refresh()->user_id)->toBeNull()
        ->and(User::where('email', $user->email)->count())->toBe(1);
});

it('rejects weak passwords before touching the license', function () {
    [$row,] = appsumoClaimLicense(tier: 1);

    $this->postJson('/api/appsumo/claim', [
        'license_key' => $row->license_key,
        'email' => 'weak-' . Str::random(6) . '@example.com',
        'password' => 'short',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['password']);

    expect(User::where('email', 'like', 'weak-%')->exists())->toBeFalse()
        ->and($row->refresh()->user_id)->toBeNull();
});

it('exposes license state for the SPA pre-check and 404s unknown keys', function () {
    [$row,] = appsumoClaimLicense(tier: 3);

    $this->getJson("/api/appsumo/claim/{$row->license_key}")
        ->assertOk()
        ->assertJson([
            'status' => 'active',
            'tier' => 3,
            'plan_slug' => 'appsumo_agency',
            'linked' => false,
        ]);

    $this->getJson('/api/appsumo/claim/does-not-exist')->assertStatus(404);

    [$user, $org] = makeOrgUser('precheck-' . Str::random(6) . '@example.com');
    $license = appsumoClaimOrgLicense($org);
    $row->forceFill(['user_id' => $user->id, 'org_id' => $org->id, 'license_id' => $license->id])->save();

    $this->getJson("/api/appsumo/claim/{$row->license_key}")
        ->assertOk()
        ->assertJsonPath('linked', true);
});
