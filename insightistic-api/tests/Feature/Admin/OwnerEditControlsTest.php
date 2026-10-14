<?php

use App\Models\Site;
use App\Models\User;

use function Pest\Laravel\actingAs;

function ownerEditAdmin(string $email = 'owner-edit-admin@example.com'): User
{
    $admin = User::create([
        'name' => 'Owner Edit Admin',
        'email' => $email,
        'password' => 'secret-password',
        'status' => 'active',
    ]);
    $admin->forceFill(['is_super_admin' => true])->save();

    return $admin;
}

it('lets the owner edit organization ownership, plan, lifecycle, trial and notes', function () {
    $admin = ownerEditAdmin();
    [$owner, $org] = makeOrgUser('owner-edit-old@example.com');
    $newOwner = User::create([
        'name' => 'Replacement Owner',
        'email' => 'owner-edit-new@example.com',
        'password' => 'secret-password',
        'status' => 'active',
    ]);
    $plan = App\Models\Plan::create([
        'name' => 'Owner Edit Plan',
        'slug' => 'owner-edit-plan',
        'site_limit' => 10,
        'user_limit' => 10,
        'ai_insight_limit' => 100,
        'report_limit' => 100,
        'is_active' => true,
    ]);

    actingAs($admin)->patchJson("/api/admin/organizations/{$org->id}", [
        'name' => 'Renamed tenant',
        'owner_user_id' => $newOwner->id,
        'plan' => $plan->slug,
        'status' => 'trialing',
        'trial_ends_at' => '2030-01-15',
        'internal_notes' => 'Founder override',
    ])->assertOk();

    $org->refresh();
    expect($org->name)->toBe('Renamed tenant')
        ->and($org->owner_user_id)->toBe($newOwner->id)
        ->and($org->plan_id)->toBe($plan->id)
        ->and($org->status)->toBe('trialing')
        ->and($org->internal_notes)->toBe('Founder override')
        ->and($org->users()->where('users.id', $newOwner->id)->first()->pivot->role)->toBe('owner')
        ->and($org->users()->where('users.id', $owner->id)->first()->pivot->role)->toBe('member');
});

it('lets the owner edit a user profile, status and password without exposing it', function () {
    $admin = ownerEditAdmin('owner-edit-user-admin@example.com');
    [$user] = makeOrgUser('owner-edit-user@example.com');

    actingAs($admin)->patchJson("/api/admin/users/{$user->id}", [
        'name' => 'Edited user',
        'email' => 'edited-user@example.com',
        'password' => 'new-secret-password',
        'status' => 'active',
    ])->assertOk()->assertJsonMissing(['password' => 'new-secret-password']);

    expect($user->fresh()->name)->toBe('Edited user')
        ->and($user->fresh()->email)->toBe('edited-user@example.com')
        ->and($user->fresh()->status)->toBe('active')
        ->and(password_verify('new-secret-password', $user->fresh()->password))->toBeTrue();
});

it('lets the owner edit site metadata while preserving the connector identity', function () {
    $admin = ownerEditAdmin('owner-edit-site-admin@example.com');
    [, $org] = makeOrgUser('owner-edit-site@example.com');
    $site = Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id,
        'name' => 'Original site',
        'domain' => 'original.example.com',
        'normalized_domain' => 'original.example.com',
        'canonical_url' => 'https://original.example.com',
        'platform' => 'generic',
        'status' => 'active',
        'connection_status' => 'connected',
    ]);

    actingAs($admin)->patchJson("/api/admin/sites/{$site->id}", [
        'name' => 'Edited site',
        'platform' => 'woocommerce',
        'country' => 'US',
        'language' => 'en-US',
        'timezone' => 'America/Phoenix',
        'currency' => 'USD',
        'status' => 'suspended',
    ])->assertOk();

    $site->refresh();
    expect($site->name)->toBe('Edited site')
        ->and($site->domain)->toBe('original.example.com')
        ->and($site->platform)->toBe('woocommerce')
        ->and($site->country)->toBe('US')
        ->and($site->currency)->toBe('USD')
        ->and($site->status)->toBe('suspended');
});
