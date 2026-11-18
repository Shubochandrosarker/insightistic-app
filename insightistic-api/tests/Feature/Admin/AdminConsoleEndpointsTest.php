<?php

use App\Models\AdminAuditLog;
use App\Models\Organization;
use App\Models\Site;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\actingAs;

/**
 * Admin console fixes (2026-10-13 stuck-Loading incident).
 *
 * Pins the three founder-visible bugs at the API layer:
 *  1. GET /admin/system-health returned a JsonResponse from a method typed
 *     `: array` → TypeError → 500 → the SPA's skeleton never resolved.
 *  2. GET /admin/settings did not exist while the settings page rendered
 *     platform facts (it was pointed at system-health as a stopgap).
 *  3. Sites were read-only at admin level (no PATCH, no key rotation).
 */
function adminSuperAdmin(string $email = 'owner-admin@example.com'): App\Models\User
{
    $user = App\Models\User::create([
        'name'     => 'Super Admin',
        'email'    => $email,
        'password' => 'secret-password',
        'status'   => 'active',
    ]);
    $user->forceFill(['is_super_admin' => true])->save();

    return $user;
}

function adminSiteWithOrg(): array
{
    [$user, $org] = makeOrgUser('site-owner@example.com');

    $site = Site::withoutGlobalScope('organization')->create([
        'organization_id'   => $org->id,
        'name'              => 'Throwaway Site',
        'domain'            => 'throwaway.example.com',
        'normalized_domain' => 'throwaway.example.com',
        'platform'          => 'generic',
        'status'            => 'active',
        'connection_status' => 'connected',
    ]);

    return [$site, $org];
}

it('serves system-health as a JSON object (no TypeError 500)', function () {
    actingAs(adminSuperAdmin())
        ->getJson('/api/admin/system-health')
        ->assertOk()
        ->assertJsonPath('database.status', 'ok')
        ->assertJsonPath('stripe.mode', fn ($v) => in_array($v, ['test', 'live'], true));
});

it('hides system-health from non-super-admins', function () {
    [$user] = makeOrgUser('plain-user@example.com');

    actingAs($user)
        ->getJson('/api/admin/system-health')
        ->assertStatus(403)
        ->assertJsonPath('code', 'not_super_admin');
});

it('serves platform settings without exposing secrets', function () {
    $res = actingAs(adminSuperAdmin('settings-admin@example.com'))
        ->getJson('/api/admin/settings')
        ->assertOk();

    $body = $res->json();

    expect($body['counts'])->toHaveKeys(['organizations', 'users', 'sites', 'plans', 'super_admins']);
    expect($body['stripe'])->toHaveKeys(['configured', 'mode']);
    expect($body['app'])->toHaveKeys(['env', 'url', 'debug']);
    expect($body['release'])->toHaveKey('sha');

    $flat = json_encode($body);
    // Settings carries status/mode booleans only — never key material.
    expect($flat)->not->toContain('api_key');
    expect($flat)->not->toContain('secret');
});

it('rejects settings for non-super-admins', function () {
    [$user] = makeOrgUser('settings-peasant@example.com');

    actingAs($user)->getJson('/api/admin/settings')->assertStatus(403);
});

it('renames and suspends a site via PATCH', function () {
    [$admin, $site] = (function () {
        $a = adminSuperAdmin('site-patch-admin@example.com');
        [$s, $o] = adminSiteWithOrg();

        return [$a, $s];
    })();

    actingAs($admin)
        ->patchJson("/api/admin/sites/{$site->id}", ['name' => 'Renamed Site', 'status' => 'suspended'])
        ->assertOk()
        ->assertJsonPath('site.name', 'Renamed Site')
        ->assertJsonPath('site.status', 'suspended');

    expect($site->fresh()->name)->toBe('Renamed Site');
    expect($site->fresh()->status)->toBe('suspended');
    expect(AdminAuditLog::where('action', 'site.update')->where('target_id', $site->id)->count())->toBe(1);
});

it('rejects invalid site PATCH payloads', function () {
    $admin = adminSuperAdmin('site-patch-bad@example.com');
    [$site] = adminSiteWithOrg();

    actingAs($admin)
        ->patchJson("/api/admin/sites/{$site->id}", ['status' => 'exploded'])
        ->assertStatus(422);
});

it('forbids site PATCH for non-super-admins', function () {
    [$user] = makeOrgUser('site-peasant@example.com');
    [$site] = adminSiteWithOrg();

    actingAs($user)
        ->patchJson("/api/admin/sites/{$site->id}", ['name' => 'Hacked'])
        ->assertStatus(403);
});

it('regenerates a site connector key once and invalidates the old pair', function () {
    $admin = adminSuperAdmin('site-key-admin@example.com');
    [$site] = adminSiteWithOrg();

    $first = actingAs($admin)
        ->postJson("/api/admin/sites/{$site->id}/regenerate-key")
        ->assertOk()
        ->assertJsonPath('site', null)
        ->json('connector_token');

    expect($first)->toContain('.')
        ->and($first)->toStartWith('ik_');

    [$keyId, $secret] = explode('.', $first);
    $site->refresh();

    expect($site->connector_key_id)->toBe($keyId);
    // Secret round-trips through the encrypted cast.
    expect($site->connector_secret)->toBe($secret);
    expect($site->connection_status)->toBe('not_installed');

    // Key id is unique — a second rotation produces a different pair.
    $second = actingAs($admin)
        ->postJson("/api/admin/sites/{$site->id}/regenerate-key")
        ->assertOk()
        ->json('connector_token');

    expect($second)->not->toBe($first);
    expect(AdminAuditLog::where('action', 'site.connector_key_regenerated')->where('target_id', $site->id)->count())->toBe(2);
});

it('forbids key regeneration for non-super-admins', function () {
    [$user] = makeOrgUser('key-peasant@example.com');
    [$site] = adminSiteWithOrg();

    actingAs($user)
        ->postJson("/api/admin/sites/{$site->id}/regenerate-key")
        ->assertStatus(403);
});
