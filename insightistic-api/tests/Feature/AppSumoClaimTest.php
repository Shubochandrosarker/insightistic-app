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
    1 => ['appsumo_tier_1', 1],
    2 => ['appsumo_tier_2', 3],
    3 => ['appsumo_tier_3', 10],
    4 => ['appsumo_tier_4', 25],
    5 => ['appsumo_tier_5', 50],
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
        ->assertJsonPath('plan_slug', 'appsumo_tier_2')
        ->assertJsonPath('sites_limit', 3)
        ->assertJsonPath('user.email', 'sumo.buyer@example.com')
        ->assertJsonStructure(['token', 'user', 'org', 'plan_slug', 'sites_limit']);

    $user = User::where('email', 'sumo.buyer@example.com')->firstOrFail();
    expect($user->status)->toBe('active')
        ->and($user->email_verified_at)->toBeNull();

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

    $res->assertOk()->assertJsonPath('plan_slug', 'appsumo_tier_1');

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
            'plan_slug' => 'appsumo_tier_3',
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


it('allows claim after AppSumo activate while provider status is still inactive', function () {
    [$row,] = appsumoClaimLicense(tier: 1, status: 'inactive');

    $this->getJson("/api/appsumo/claim/{$row->license_key}")
        ->assertOk()->assertJsonPath('status', 'active');

    $this->postJson('/api/appsumo/claim', [
        'license_key' => $row->license_key,
        'email' => 'pending-buyer-' . Str::random(6) . '@example.com',
        'password' => 'long-secret-123',
    ])->assertCreated()->assertJsonPath('plan_slug', 'appsumo_tier_1');
});

it('does not allow password-only first claims to bypass multi-factor authentication', function () {
    [$row,] = appsumoClaimLicense(tier: 1);
    [$user, $org] = makeOrgUser('mfa-sumo-' . Str::random(6) . '@example.com');
    appsumoClaimOrgLicense($org);

    $user->forceFill([
        'mfa_secret' => 'test-totp-secret',
        'mfa_confirmed_at' => now(),
    ])->save();

    $this->postJson('/api/appsumo/claim', [
        'license_key' => $row->license_key,
        'email' => $user->email,
        'password' => 'secret-password',
    ])->assertStatus(403);

    expect($row->fresh()->user_id)->toBeNull()
        ->and($row->fresh()->org_id)->toBeNull();
});


it('lets an MFA customer claim through an authenticated session without a password-only bypass', function () {
    [$row, $plan] = appsumoClaimLicense(tier: 2);
    [$user, $org] = makeOrgUser('mfa-claim-' . Str::random(6) . '@example.com');
    $license = appsumoClaimOrgLicense($org);

    $user->forceFill([
        'mfa_secret' => 'test-mfa-secret',
        'mfa_confirmed_at' => now(),
    ])->save();

    $this->withToken($user->createToken('spa')->plainTextToken)
        ->postJson('/api/appsumo/claim/authenticated', [
            'license_key' => $row->license_key,
        ])
        ->assertOk()
        ->assertJsonPath('plan_slug', 'appsumo_tier_2')
        ->assertJsonPath('sites_limit', 3)
        ->assertJsonPath('linked', true);

    expect($row->fresh()->user_id)->toBe($user->id)
        ->and($row->fresh()->org_id)->toBe($org->id)
        ->and($license->fresh()->plan_id)->toBe($plan->id);
});

it('requires a logged-in account for the authenticated claim path', function () {
    [$row,] = appsumoClaimLicense(tier: 1);

    $this->postJson('/api/appsumo/claim/authenticated', [
        'license_key' => $row->license_key,
    ])->assertUnauthorized();

    expect($row->fresh()->user_id)->toBeNull();
});

it('does not transfer a claimed AppSumo key between authenticated accounts', function () {
    [$row,] = appsumoClaimLicense(tier: 1);
    [$owner, $org] = makeOrgUser('first-' . Str::random(6) . '@example.com');
    [$other, $otherOrg] = makeOrgUser('second-' . Str::random(6) . '@example.com');

    $license = appsumoClaimOrgLicense($org);
    appsumoClaimOrgLicense($otherOrg);
    $row->forceFill([
        'user_id' => $owner->id,
        'org_id' => $org->id,
        'license_id' => $license->id,
    ])->save();

    $this->withToken($other->createToken('spa')->plainTextToken)
        ->postJson('/api/appsumo/claim/authenticated', [
            'license_key' => $row->license_key,
        ])->assertStatus(409);

    expect($row->fresh()->user_id)->toBe($owner->id)
        ->and($row->fresh()->org_id)->toBe($org->id);
});


it('blocks license transfers through elevated support impersonation sessions', function () {
    [$row,] = appsumoClaimLicense(tier: 1);
    [$user, $org] = makeOrgUser('impersonation-' . Str::random(6) . '@example.com');
    appsumoClaimOrgLicense($org);

    $impersonationToken = $user->createToken('impersonation', [
        'impersonated', 'impersonated-elevated',
    ])->plainTextToken;

    $this->withToken($impersonationToken)
        ->postJson('/api/appsumo/claim/authenticated', [
            'license_key' => $row->license_key,
        ])->assertStatus(403);

    expect($row->fresh()->user_id)->toBeNull();
});
