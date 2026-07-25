<?php

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Laravel\Socialite\Facades\Socialite;

/**
 * SECURITY REGRESSION SUITE — the OAuth callback must enforce the exact same
 * account-state rules as password login, validate the anti-CSRF state nonce,
 * never auto-reactivate accounts, never grant super admin, and only link by
 * email when the provider asserts a verified address.
 */

function enableGoogleOAuth(): void
{
    config()->set('services.google.client_id', 'test-client');
    config()->set('services.google.client_secret', 'test-secret');
    config()->set('insightistic.app_url', 'https://app.insightistic.test');
}

function primeOAuthState(string $state = 'test-state', string $provider = 'google'): void
{
    Cache::put('oauth_state:' . hash('sha256', $state), $provider, 600);
}

function fakeGoogleUser(string $email, bool $verified = true, string $id = '12345'): void
{
    $socialiteUser = (new \Laravel\Socialite\Two\User())->map([
        'id'       => $id,
        'nickname' => null,
        'name'     => 'OAuth Person',
        'email'    => $email,
        'avatar'   => 'https://example.com/a.png',
    ]);
    $socialiteUser->user = ['email_verified' => $verified];

    $driver = Mockery::mock();
    $driver->shouldReceive('stateless')->andReturnSelf();
    $driver->shouldReceive('user')->andReturn($socialiteUser);

    Socialite::shouldReceive('driver')->with('google')->andReturn($driver);
}

it('rejects a callback with a missing or unknown state nonce', function () {
    enableGoogleOAuth();

    $res = $this->get('/api/auth/oauth/google/callback?code=abc');

    $res->assertRedirect();
    expect($res->headers->get('Location'))->toContain('#error=invalid_state');
});

it('burns the state nonce after one use', function () {
    enableGoogleOAuth();
    primeOAuthState();
    fakeGoogleUser('fresh@example.com');

    $first = $this->get('/api/auth/oauth/google/callback?code=abc&state=test-state');
    expect($first->headers->get('Location'))->toContain('#token=');

    // Replay with the same state: must fail even though the code is "valid".
    $replay = $this->get('/api/auth/oauth/google/callback?code=abc&state=test-state');
    expect($replay->headers->get('Location'))->toContain('#error=invalid_state');
});

it('issues no token to a disabled user via OAuth', function (string $status) {
    enableGoogleOAuth();
    primeOAuthState();

    $user = User::create([
        'name'     => 'Disabled',
        'email'    => 'disabled@example.com',
        'password' => 'x',
        'status'   => $status,
    ]);
    $user->forceFill(['provider' => 'google', 'provider_id' => '12345'])->save();

    fakeGoogleUser('disabled@example.com');

    $res = $this->get('/api/auth/oauth/google/callback?code=abc&state=test-state');

    expect($res->headers->get('Location'))->toContain('#error=account_not_active');
    expect($user->tokens()->count())->toBe(0);
    expect($user->fresh()->status)->toBe($status); // never silently reactivated
})->with(['disabled', 'suspended']);

it('issues no token to an archived user via OAuth', function () {
    enableGoogleOAuth();
    primeOAuthState();

    $user = User::create([
        'name'     => 'Archived',
        'email'    => 'archived@example.com',
        'password' => 'x',
        'status'   => 'active',
    ]);
    $user->forceFill(['provider' => 'google', 'provider_id' => '12345', 'archived_at' => now()])->save();

    fakeGoogleUser('archived@example.com');

    $res = $this->get('/api/auth/oauth/google/callback?code=abc&state=test-state');

    expect($res->headers->get('Location'))->toContain('#error=account_not_active');
    expect($user->tokens()->count())->toBe(0);
});

it('does not auto-activate an invited user via OAuth', function () {
    enableGoogleOAuth();
    primeOAuthState();

    $user = User::create([
        'name'     => 'Invited',
        'email'    => 'invited@example.com',
        'password' => 'x',
        'status'   => 'invited',
    ]);
    $user->forceFill(['provider' => 'google', 'provider_id' => '12345'])->save();

    fakeGoogleUser('invited@example.com');

    $res = $this->get('/api/auth/oauth/google/callback?code=abc&state=test-state');

    expect($res->headers->get('Location'))->toContain('#error=invite_pending');
    expect($user->fresh()->status)->toBe('invited');
    expect($user->tokens()->count())->toBe(0);
});

it('lets an active user in via OAuth and issues a token', function () {
    enableGoogleOAuth();
    primeOAuthState();

    $user = User::create([
        'name'     => 'Active',
        'email'    => 'active@example.com',
        'password' => 'x',
        'status'   => 'active',
    ]);
    $user->forceFill(['provider' => 'google', 'provider_id' => '12345'])->save();

    fakeGoogleUser('active@example.com');

    $res = $this->get('/api/auth/oauth/google/callback?code=abc&state=test-state');

    expect($res->headers->get('Location'))->toContain('#token=');
    expect($user->tokens()->count())->toBe(1);
});

it('never grants super admin on OAuth signup, even with the legacy env set', function () {
    enableGoogleOAuth();
    primeOAuthState();
    config()->set('insightistic.super_admin_email', 'boss@example.com');

    fakeGoogleUser('boss@example.com');

    $res = $this->get('/api/auth/oauth/google/callback?code=abc&state=test-state');

    expect($res->headers->get('Location'))->toContain('#token=');
    expect(User::where('email', 'boss@example.com')->firstOrFail()->is_super_admin)->toBeFalse();
});

it('refuses to link a social identity onto an existing account when the provider email is unverified', function () {
    enableGoogleOAuth();
    primeOAuthState();

    $existing = User::create([
        'name'     => 'Existing',
        'email'    => 'victim@example.com',
        'password' => 'x',
        'status'   => 'active',
    ]);

    fakeGoogleUser('victim@example.com', verified: false);

    $res = $this->get('/api/auth/oauth/google/callback?code=abc&state=test-state');

    expect($res->headers->get('Location'))->toContain('#error=email_unverified');
    expect($existing->tokens()->count())->toBe(0);
    expect($existing->fresh()->provider)->toBeNull();
});

it('refuses to link when the account is already bound to a different provider', function () {
    enableGoogleOAuth();
    primeOAuthState();

    $existing = User::create([
        'name'     => 'GitHub Person',
        'email'    => 'gh@example.com',
        'password' => 'x',
        'status'   => 'active',
    ]);
    $existing->forceFill(['provider' => 'github', 'provider_id' => '999'])->save();

    fakeGoogleUser('gh@example.com', verified: true, id: '777');

    $res = $this->get('/api/auth/oauth/google/callback?code=abc&state=test-state');

    expect($res->headers->get('Location'))->toContain('#error=account_exists');
    expect($existing->tokens()->count())->toBe(0);
});
