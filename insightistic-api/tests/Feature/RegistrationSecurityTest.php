<?php

use App\Models\User;

/**
 * SECURITY REGRESSION SUITE — public registration must never mint a
 * platform super admin, under any configuration.
 */

it('registers a user and bootstraps an organization without super admin', function () {
    $res = $this->postJson('/api/auth/register', [
        'name'                  => 'Owner One',
        'email'                 => 'owner@example.com',
        'password'              => 'secret-password',
        'password_confirmation' => 'secret-password',
        'organization_name'     => 'Acme Store',
    ]);

    $res->assertCreated()->assertJsonPath('user.is_super_admin', false);

    expect(User::where('email', 'owner@example.com')->firstOrFail()->is_super_admin)->toBeFalse();
});

it('never grants super admin even when the legacy SUPER_ADMIN_EMAIL config is set', function () {
    // Simulate an operator who still has the removed env/config in place —
    // nothing in the register path may read it anymore.
    config()->set('insightistic.super_admin_email', 'boss@example.com');
    putenv('SUPER_ADMIN_EMAIL=boss@example.com');

    $res = $this->postJson('/api/auth/register', [
        'name'                  => 'Impostor',
        'email'                 => 'boss@example.com',
        'password'              => 'secret-password',
        'password_confirmation' => 'secret-password',
        'organization_name'     => 'Evil Co',
    ]);

    putenv('SUPER_ADMIN_EMAIL');

    $res->assertCreated()->assertJsonPath('user.is_super_admin', false);
    expect(User::where('email', 'boss@example.com')->firstOrFail()->is_super_admin)->toBeFalse();
});

it('ignores an is_super_admin field smuggled into the registration payload', function () {
    $res = $this->postJson('/api/auth/register', [
        'name'                  => 'Smuggler',
        'email'                 => 'smuggler@example.com',
        'password'              => 'secret-password',
        'password_confirmation' => 'secret-password',
        'organization_name'     => 'Smuggle Inc',
        'is_super_admin'        => true,
    ]);

    $res->assertCreated();
    expect(User::where('email', 'smuggler@example.com')->firstOrFail()->is_super_admin)->toBeFalse();
});

it('is_super_admin is not mass assignable on the User model', function () {
    $user = User::create([
        'name'           => 'Mass Assign',
        'email'          => 'mass@example.com',
        'password'       => 'secret-password',
        'status'         => 'active',
        'is_super_admin' => true, // must be silently discarded
    ]);

    expect($user->fresh()->is_super_admin)->toBeFalse();
});

it('blocks login for disabled and invited users', function (string $status, string $needle) {
    $user = User::create([
        'name'     => 'Blocked',
        'email'    => "blocked-{$status}@example.com",
        'password' => 'secret-password',
        'status'   => $status,
    ]);

    $res = $this->postJson('/api/auth/login', [
        'email'    => $user->email,
        'password' => 'secret-password',
    ]);

    $res->assertForbidden();
    expect($user->tokens()->count())->toBe(0);
})->with([
    ['invited', 'invite'],
    ['disabled', 'disabled'],
    ['suspended', 'disabled'],
]);
