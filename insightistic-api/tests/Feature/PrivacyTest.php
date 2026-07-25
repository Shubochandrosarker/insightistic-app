<?php

use App\Models\Organization;
use App\Models\OrganizationExport;
use App\Models\Site;
use App\Models\TrafficDaily;
use App\Models\User;
use App\Services\Privacy\OrganizationExporter;
use App\Services\Privacy\OrganizationPurger;
use App\Services\Privacy\RetentionPurger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * Privacy and compliance (spec Phase 10): data export, the two-stage
 * organization deletion workflow, and retention enforcement.
 *
 * These use a real Sanctum token because the mutating endpoints sit behind
 * the reauth guard, which binds to a token id.
 */

/** @return array{0:User,1:Organization,2:string} user, org, bearer token */
function privacyOwner(string $email, ?array $limits = null): array
{
    [$user, $org] = makeOrgUser($email, $limits);
    $token = $user->createToken('test')->plainTextToken;

    return [$user, $org, $token];
}

/**
 * Confirm the password for this token, then clear the resolved guard.
 *
 * Laravel keeps one container per test, so the auth guard caches the first
 * resolved user and access token; a real request always gets a fresh one.
 * Uses app('auth') rather than $test->app — `$app` is protected on TestCase
 * and unreachable from a global helper.
 */
function confirmReauth($test, string $token): void
{
    app('auth')->forgetGuards();
    $test->withToken($token)->postJson('/api/auth/reauthenticate', ['password' => 'secret-password'])->assertOk();
    app('auth')->forgetGuards();
}

function privacySite(Organization $org): Site
{
    return Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id,
        'name'            => 'Store',
        'domain'          => 'store-' . $org->id . '.example.com',
    ]);
}

it('requires reauthentication before exporting or deleting', function () {
    [, , $token] = privacyOwner('priv-gate@example.com');

    $this->withToken($token)->postJson('/api/privacy/exports')->assertStatus(428);
    app('auth')->forgetGuards();
    $this->withToken($token)->postJson('/api/privacy/deletion', ['confirm_name' => 'x'])->assertStatus(428);

    expect(OrganizationExport::withoutGlobalScope('organization')->count())->toBe(0);
});

it('queues an export and refuses a concurrent second one', function () {
    Queue::fake();

    [, $org, $token] = privacyOwner('priv-export@example.com');
    confirmReauth($this, $token);

    $this->withToken($token)->postJson('/api/privacy/exports')->assertStatus(202);

    app('auth')->forgetGuards();
    $this->withToken($token)
        ->postJson('/api/privacy/exports')
        ->assertStatus(409)
        ->assertJson(['code' => 'export_in_progress']);

    expect(OrganizationExport::withoutGlobalScope('organization')->count())->toBe(1);
    Queue::assertPushed(\App\Jobs\BuildOrganizationExport::class, 1);
});

it('builds an archive containing the org data but never its secrets', function () {
    Storage::fake('local');
    config()->set('insightistic.privacy.export_disk', 'local');

    [$user, $org] = privacyOwner('priv-archive@example.com');
    $site = privacySite($org);
    $site->forceFill(['connector_secret' => 'sk_super_secret_value', 'cloudflare_api_token' => 'cf_token_secret'])->save();

    TrafficDaily::create(['site_id' => $site->id, 'source' => 'ga4', 'date' => now()->toDateString(), 'sessions' => 42]);

    $export = OrganizationExport::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id, 'requested_by_user_id' => $user->id, 'status' => 'queued',
    ]);

    app(OrganizationExporter::class)->build($export);

    $fresh = $export->fresh();
    expect($fresh->status)->toBe('ready')->and($fresh->size_bytes)->toBeGreaterThan(0);

    $zip = new ZipArchive();
    $zip->open(Storage::disk('local')->path($fresh->storage_path));

    $manifest = json_decode($zip->getFromName('manifest.json'), true);
    expect($manifest['organization_id'])->toBe($org->id);

    // Real data is present...
    expect($zip->getFromName('traffic_daily.ndjson'))->toContain('"sessions":42');
    expect($zip->getFromName('sites.ndjson'))->toContain($site->domain);

    // ...but credentials are withheld.
    $sites = $zip->getFromName('sites.ndjson');
    expect($sites)->not->toContain('sk_super_secret_value')
        ->and($sites)->not->toContain('cf_token_secret')
        ->and($sites)->not->toContain('connector_secret');

    $zip->close();
});

it('will not hand another organization an export', function () {
    Storage::fake('local');
    config()->set('insightistic.privacy.export_disk', 'local');

    [, $orgB] = privacyOwner('priv-b@example.com');
    $exportB = OrganizationExport::withoutGlobalScope('organization')->create([
        'organization_id' => $orgB->id, 'status' => 'ready',
        'disk' => 'local', 'storage_path' => 'exports/b.zip', 'expires_at' => now()->addDay(),
    ]);

    [, , $tokenA] = privacyOwner('priv-a@example.com');
    confirmReauth($this, $tokenA);

    $this->withToken($tokenA)->getJson("/api/privacy/exports/{$exportB->id}/download")->assertNotFound();
});

it('schedules deletion only when the typed name matches, and can cancel', function () {
    [, $org, $token] = privacyOwner('priv-delete@example.com');
    confirmReauth($this, $token);

    $this->withToken($token)
        ->postJson('/api/privacy/deletion', ['confirm_name' => 'Not The Name'])
        ->assertStatus(422)
        ->assertJson(['code' => 'name_mismatch']);

    expect($org->fresh()->isPendingDeletion())->toBeFalse();

    app('auth')->forgetGuards();
    $this->withToken($token)
        ->postJson('/api/privacy/deletion', ['confirm_name' => $org->name])
        ->assertOk()
        ->assertJson(['status' => 'scheduled']);

    $scheduled = $org->fresh();
    expect($scheduled->isPendingDeletion())->toBeTrue()
        // Grace window, not immediate.
        ->and($scheduled->deletion_scheduled_for->isFuture())->toBeTrue();

    app('auth')->forgetGuards();
    $this->withToken($token)->deleteJson('/api/privacy/deletion')->assertOk();

    expect($org->fresh()->isPendingDeletion())->toBeFalse();
});

it('does not delete an organization before its grace window elapses', function () {
    [, $org] = privacyOwner('priv-grace@example.com');
    $org->forceFill(['deletion_scheduled_for' => now()->addDays(3)])->save();

    $this->artisan('insightistic:privacy-maintenance')->assertSuccessful();

    expect(Organization::withoutGlobalScope('organization')->find($org->id))->not->toBeNull();
});

it('purges the organization once the window has elapsed', function () {
    [$user, $org] = privacyOwner('priv-purge@example.com');
    $site = privacySite($org);
    TrafficDaily::create(['site_id' => $site->id, 'source' => 'ga4', 'date' => now()->toDateString(), 'sessions' => 5]);

    $org->forceFill(['deletion_scheduled_for' => now()->subMinute()])->save();

    $this->artisan('insightistic:privacy-maintenance')->assertSuccessful();

    expect(Organization::withoutGlobalScope('organization')->find($org->id))->toBeNull()
        // Cascades reach the site and its analytics.
        ->and(Site::withoutGlobalScope('organization')->find($site->id))->toBeNull()
        ->and(TrafficDaily::where('site_id', $site->id)->count())->toBe(0)
        // The sole member is removed with the tenant.
        ->and(User::find($user->id))->toBeNull();
});

it('keeps a user who still belongs to another organization', function () {
    [$user, $orgA] = privacyOwner('priv-shared@example.com');

    [, $orgB] = makeOrgUser('priv-other-owner@example.com');
    $orgB->users()->attach($user->id, ['role' => 'analyst']);

    $orgA->forceFill(['deletion_scheduled_for' => now()->subMinute()])->save();

    app(OrganizationPurger::class)->purge($orgA->fresh());

    expect(User::find($user->id))->not->toBeNull();
});

it('never removes a super admin as a side effect of a tenant deletion', function () {
    [$user, $org] = privacyOwner('priv-superadmin@example.com');
    $user->forceFill(['is_super_admin' => true])->save();

    $org->forceFill(['deletion_scheduled_for' => now()->subMinute()])->save();
    app(OrganizationPurger::class)->purge($org->fresh());

    expect(User::find($user->id))->not->toBeNull();
});

it('enforces the plan retention window on analytics only', function () {
    [, $org] = privacyOwner('priv-retention@example.com', ['data_retention_days' => 30]);
    $site = privacySite($org);

    TrafficDaily::create(['site_id' => $site->id, 'source' => 'ga4', 'date' => now()->subDays(10)->toDateString(), 'sessions' => 1]);
    TrafficDaily::create(['site_id' => $site->id, 'source' => 'ga4', 'date' => now()->subDays(200)->toDateString(), 'sessions' => 2]);

    // A commercial record well outside the window must survive.
    DB::table('wc_orders')->insert([
        'site_id' => $site->id, 'external_order_id' => 991, 'status' => 'completed',
        'total' => 10, 'currency' => 'USD', 'created_at_store' => now()->subDays(400),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $removed = app(RetentionPurger::class)->purgeOrganization($org->fresh());

    expect($removed['traffic_daily'] ?? 0)->toBe(1)
        ->and(TrafficDaily::where('site_id', $site->id)->count())->toBe(1)
        // Orders are explicitly out of scope for retention.
        ->and(DB::table('wc_orders')->where('site_id', $site->id)->count())->toBe(1);
});

it('treats a missing or zero retention limit as unlimited', function () {
    [, $unlimited] = privacyOwner('priv-unlimited@example.com', ['data_retention_days' => 0]);
    $site = privacySite($unlimited);
    TrafficDaily::create(['site_id' => $site->id, 'source' => 'ga4', 'date' => now()->subDays(900)->toDateString(), 'sessions' => 3]);

    expect(app(RetentionPurger::class)->purgeOrganization($unlimited->fresh()))->toBe([]);
    expect(TrafficDaily::where('site_id', $site->id)->count())->toBe(1);
});

it('expires an export and removes the archive from disk', function () {
    Storage::fake('local');

    [, $org] = privacyOwner('priv-expire@example.com');
    Storage::disk('local')->put('exports/old.zip', 'archive-bytes');

    OrganizationExport::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id, 'status' => 'ready', 'disk' => 'local',
        'storage_path' => 'exports/old.zip', 'expires_at' => now()->subDay(),
    ]);

    $this->artisan('insightistic:privacy-maintenance')->assertSuccessful();

    $row = OrganizationExport::withoutGlobalScope('organization')->firstOrFail();
    expect($row->status)->toBe('expired')->and($row->storage_path)->toBeNull();
    // The file is the sensitive part — it must actually be gone.
    Storage::disk('local')->assertMissing('exports/old.zip');
});

it('lets the owner turn external AI processing off', function () {
    [, $org, $token] = privacyOwner('priv-ai@example.com');
    confirmReauth($this, $token);

    $this->withToken($token)
        ->patchJson('/api/privacy/ai-processing', ['allow_external_ai' => false])
        ->assertOk()
        ->assertJson(['allow_external_ai' => false]);

    expect((bool) $org->fresh()->allow_external_ai)->toBeFalse();
});

it('blocks privacy actions for non-owner roles', function () {
    [, $org] = privacyOwner('priv-role@example.com');

    $admin = User::create([
        'name' => 'Admin', 'email' => 'priv-admin@example.com',
        'password' => 'secret-password', 'status' => 'active',
    ]);
    $org->users()->attach($admin->id, ['role' => 'admin']);
    $adminToken = $admin->createToken('t')->plainTextToken;

    // An admin may look...
    $this->withToken($adminToken)->getJson('/api/privacy/overview')->assertOk();

    // ...but only the owner may export or delete. Reauth runs first, so a
    // confirmed admin still has to be refused on role.
    app('auth')->forgetGuards();
    $this->withToken($adminToken)->postJson('/api/auth/reauthenticate', ['password' => 'secret-password'])->assertOk();
    app('auth')->forgetGuards();
    $this->withToken($adminToken)->postJson('/api/privacy/exports')->assertForbidden();
});
