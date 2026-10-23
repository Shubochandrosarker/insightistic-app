<?php

use App\Mail\InviteMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password as PasswordBroker;

use function Pest\Laravel\actingAs;

/**
 * SECURITY REGRESSION SUITE — the password-reset and login paths must honor
 * the admin account-state controls. A reset may activate an INVITED account
 * (that IS invite acceptance) but must never resurrect a disabled, suspended
 * or archived one, and resets must revoke every live session token.
 */

function hardeningResetToken(User $user): string
{
    return PasswordBroker::broker()->createToken($user);
}

it('blocks password login for archived accounts even while status stays active', function () {
    $user = User::create([
        'name'     => 'Archived Login',
        'email'    => 'archived-login@example.com',
        'password' => 'secret-password',
        'status'   => 'active',
    ]);
    $user->forceFill(['archived_at' => now()])->save();

    $this->postJson('/api/auth/login', [
        'email'    => $user->email,
        'password' => 'secret-password',
    ])->assertForbidden();

    expect($user->tokens()->count())->toBe(0);
});

it('does not resurrect a disabled account through the password reset flow', function () {
    $user = User::create([
        'name'     => 'Disabled Reset',
        'email'    => 'disabled-reset@example.com',
        'password' => 'secret-password',
        'status'   => 'disabled',
    ]);

    $this->postJson('/api/auth/reset-password', [
        'token'                => hardeningResetToken($user),
        'email'                => $user->email,
        'password'             => 'brand-new-password',
        'password_confirmation' => 'brand-new-password',
    ])->assertOk();

    // The password rotated, but the admin's disable decision survives —
    // login with the NEW password is still refused.
    expect($user->fresh()->status)->toBe('disabled');
    $this->postJson('/api/auth/login', [
        'email'    => $user->email,
        'password' => 'brand-new-password',
    ])->assertForbidden();
});

it('does not resurrect a suspended account through the password reset flow', function () {
    $user = User::create([
        'name'     => 'Suspended Reset',
        'email'    => 'suspended-reset@example.com',
        'password' => 'secret-password',
        'status'   => 'suspended',
    ]);

    $this->postJson('/api/auth/reset-password', [
        'token'                 => hardeningResetToken($user),
        'email'                 => $user->email,
        'password'              => 'brand-new-password',
        'password_confirmation' => 'brand-new-password',
    ])->assertOk();

    expect($user->fresh()->status)->toBe('suspended');
});

it('still activates an invited account when the set-password link is used', function () {
    $user = User::create([
        'name'     => 'Invitee',
        'email'    => 'invite-accept@example.com',
        'password' => 'secret-password',
        'status'   => 'invited',
    ]);

    $this->postJson('/api/auth/reset-password', [
        'token'                 => hardeningResetToken($user),
        'email'                 => $user->email,
        'password'              => 'chosen-new-password',
        'password_confirmation' => 'chosen-new-password',
    ])->assertOk();

    expect($user->fresh()->status)->toBe('active');
    $this->postJson('/api/auth/login', [
        'email'    => $user->email,
        'password' => 'chosen-new-password',
    ])->assertOk();
});

it('revokes every session token when a password resets', function () {
    $user = User::create([
        'name'     => 'Compromised',
        'email'    => 'token-revoke@example.com',
        'password' => 'secret-password',
        'status'   => 'active',
    ]);
    // Simulate tokens an attacker holds alongside the owner's session.
    $user->createToken('spa');
    $user->createToken('spa');
    expect($user->tokens()->count())->toBe(2);

    $this->postJson('/api/auth/reset-password', [
        'token'                 => hardeningResetToken($user),
        'email'                 => $user->email,
        'password'              => 'rotated-password',
        'password_confirmation' => 'rotated-password',
    ])->assertOk();

    expect($user->fresh()->tokens()->count())->toBe(0);
});

it('re-sends a fresh invite email when re-inviting a pending invited user', function () {
    Mail::fake();
    [$owner, $org] = makeOrgUser('resend-owner@example.com');

    $pending = User::create([
        'name'     => 'Pending Invitee',
        'email'    => 'pending-invitee@example.com',
        'password' => 'secret-password',
        'status'   => 'invited',
    ]);

    actingAs($owner)->postJson('/api/organizations/users/invite', [
        'email' => $pending->email,
        'role'  => 'analyst',
    ])->assertCreated()->assertJsonPath('note', 'Invite email sent with a set-password link.');

    // The invite token expires after an hour — the owner's only resend path
    // is re-inviting, and that must mail a fresh link.
    Mail::assertSent(InviteMail::class, 1);
});

it('refuses to invite disabled or archived accounts into an organization', function (string $status, bool $archived) {
    [$owner, $org] = makeOrgUser('invite-guard-owner@example.com');

    $user = User::create([
        'name'     => 'Unavailable',
        'email'    => "unavailable-{$status}@example.com",
        'password' => 'secret-password',
        'status'   => $status,
    ]);
    if ($archived) {
        $user->forceFill(['archived_at' => now()])->save();
    }

    actingAs($owner)->postJson('/api/organizations/users/invite', [
        'email' => $user->email,
        'role'  => 'analyst',
    ])->assertStatus(422);

    expect($org->users()->where('users.id', $user->id)->exists())->toBeFalse();
})->with([
    'disabled'  => ['disabled', false],
    'suspended' => ['suspended', false],
    'archived'  => ['active', true],
]);
