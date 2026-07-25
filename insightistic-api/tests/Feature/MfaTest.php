<?php

use App\Models\EmailLog;
use App\Models\User;
use App\Services\Auth\MfaService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use PragmaRX\Google2FA\Google2FA;

/**
 * Two-factor auth, reauthentication and login notifications (spec Phase 1.4).
 *
 * These tests authenticate with a REAL Sanctum token rather than actingAs(),
 * because the reauth mark is deliberately bound to a token id — a transient
 * test token has none, and the guard is written to fail closed in that case.
 */

/** @return array{0:User, 1:string} user + bearer token */
function userWithToken(string $email, array $attrs = []): array
{
    $user = User::create(array_merge([
        'name'     => 'MFA User',
        'email'    => $email,
        'password' => 'secret-password',
        'status'   => 'active',
    ], $attrs));

    return [$user, $user->createToken('test')->plainTextToken];
}

function currentCode(User $user): string
{
    return app(Google2FA::class)->getCurrentOtp((string) $user->fresh()->mfa_secret);
}

/** Complete enrolment out-of-band so a test can start from "MFA is on". */
function enrolMfa(User $user): array
{
    $mfa = app(MfaService::class);
    $mfa->beginEnrolment($user);

    return $mfa->confirmEnrolment($user->fresh(), currentCode($user));
}

it('requires a recent password confirmation before starting enrolment', function () {
    [$user, $token] = userWithToken('mfa-gate@example.com');

    $this->withToken($token)
        ->postJson('/api/auth/mfa/setup')
        ->assertStatus(428)
        ->assertJson(['code' => 'reauth_required']);

    expect($user->fresh()->mfa_secret)->toBeNull();
});

it('reauthenticates with a password and then allows enrolment', function () {
    [$user, $token] = userWithToken('mfa-reauth@example.com');

    $this->withToken($token)
        ->postJson('/api/auth/reauthenticate', ['password' => 'wrong-password'])
        ->assertStatus(422)
        ->assertJson(['code' => 'invalid_password']);

    $this->withToken($token)
        ->postJson('/api/auth/reauthenticate', ['password' => 'secret-password'])
        ->assertOk()
        ->assertJson(['status' => 'confirmed']);

    $body = $this->withToken($token)->postJson('/api/auth/mfa/setup')->assertOk()->json();

    expect($body['secret'])->toBeString()
        ->and($body['otpauth_uri'])->toContain('otpauth://totp/')
        ->and($body['qr_svg'])->toContain('<svg');

    // A secret alone must not switch MFA on.
    expect($user->fresh()->hasMfa())->toBeFalse();
});

it('confirms enrolment only with a working code and returns recovery codes once', function () {
    [$user, $token] = userWithToken('mfa-confirm@example.com');

    $this->withToken($token)->postJson('/api/auth/reauthenticate', ['password' => 'secret-password'])->assertOk();
    $this->withToken($token)->postJson('/api/auth/mfa/setup')->assertOk();

    $this->withToken($token)
        ->postJson('/api/auth/mfa/confirm', ['code' => '000000'])
        ->assertStatus(422)
        ->assertJson(['code' => 'invalid_code']);

    expect($user->fresh()->hasMfa())->toBeFalse();

    $codes = $this->withToken($token)
        ->postJson('/api/auth/mfa/confirm', ['code' => currentCode($user)])
        ->assertOk()
        ->json('recovery_codes');

    expect($codes)->toHaveCount(8)->and($user->fresh()->hasMfa())->toBeTrue();

    // Recovery codes are stored hashed, never in plaintext.
    foreach ($user->fresh()->mfa_recovery_codes as $stored) {
        expect($stored)->not->toBeIn($codes);
    }
    expect(Hash::check($codes[0], $user->fresh()->mfa_recovery_codes[0]))->toBeTrue();
});

it('stores the totp secret encrypted at rest', function () {
    [$user] = userWithToken('mfa-encrypted@example.com');
    enrolMfa($user);

    $secret = (string) $user->fresh()->mfa_secret;
    $raw = \Illuminate\Support\Facades\DB::table('users')->where('id', $user->id)->value('mfa_secret');

    expect($secret)->not->toBe('')->and($raw)->not->toContain($secret);
});

it('login stops at a challenge instead of issuing a token when mfa is on', function () {
    [$user] = userWithToken('mfa-login@example.com');
    enrolMfa($user);

    $body = $this->postJson('/api/auth/login', [
        'email' => 'mfa-login@example.com', 'password' => 'secret-password',
    ])->assertOk()->json();

    expect($body['mfa_required'])->toBeTrue()
        ->and($body)->not->toHaveKey('token')
        ->and($body['challenge'])->toBeString();

    // The challenge alone is useless without a code.
    $this->postJson('/api/auth/mfa/challenge', ['challenge' => $body['challenge'], 'code' => '000000'])
        ->assertStatus(422)
        ->assertJson(['code' => 'invalid_code']);

    // ...and it is burned by that attempt, so it cannot be brute-forced.
    $this->postJson('/api/auth/mfa/challenge', ['challenge' => $body['challenge'], 'code' => currentCode($user)])
        ->assertStatus(422)
        ->assertJson(['code' => 'challenge_expired']);
});

it('completes an mfa login with a valid code', function () {
    [$user] = userWithToken('mfa-login-ok@example.com');
    enrolMfa($user);

    // confirmEnrolment() already burned this window's code (replay guard).
    Cache::forget("mfa_last_totp:{$user->id}");

    $challenge = $this->postJson('/api/auth/login', [
        'email' => 'mfa-login-ok@example.com', 'password' => 'secret-password',
    ])->json('challenge');

    $body = $this->postJson('/api/auth/mfa/challenge', [
        'challenge' => $challenge, 'code' => currentCode($user),
    ])->assertOk()->json();

    expect($body['token'])->toBeString()->and($body['mfa_enabled'])->toBeTrue();
});

it('accepts a recovery code once and then refuses it', function () {
    [$user] = userWithToken('mfa-recovery@example.com');
    $codes = enrolMfa($user);

    $challenge = $this->postJson('/api/auth/login', [
        'email' => 'mfa-recovery@example.com', 'password' => 'secret-password',
    ])->json('challenge');

    $this->postJson('/api/auth/mfa/challenge', ['challenge' => $challenge, 'code' => $codes[0]])
        ->assertOk()
        ->assertJsonStructure(['token']);

    expect($user->fresh()->mfa_recovery_codes)->toHaveCount(7);

    // The same code must not work a second time.
    $challenge2 = $this->postJson('/api/auth/login', [
        'email' => 'mfa-recovery@example.com', 'password' => 'secret-password',
    ])->json('challenge');

    $this->postJson('/api/auth/mfa/challenge', ['challenge' => $challenge2, 'code' => $codes[0]])
        ->assertStatus(422)
        ->assertJson(['code' => 'invalid_code']);
});

it('refuses to replay a totp code inside its own window', function () {
    [$user] = userWithToken('mfa-replay@example.com');
    enrolMfa($user);
    $code = currentCode($user);

    // enrolMfa already consumed the current window's code via confirm(),
    // so re-presenting it must fail rather than authenticate again.
    expect(app(MfaService::class)->verify($user->fresh(), $code))->toBeFalse();
});

it('a disabled account cannot pass the mfa challenge', function () {
    [$user] = userWithToken('mfa-disabled@example.com');
    enrolMfa($user);

    $challenge = $this->postJson('/api/auth/login', [
        'email' => 'mfa-disabled@example.com', 'password' => 'secret-password',
    ])->json('challenge');

    $user->forceFill(['status' => 'disabled'])->save();

    $this->postJson('/api/auth/mfa/challenge', ['challenge' => $challenge, 'code' => currentCode($user)])
        ->assertStatus(422);
});

it('reauthentication demands the mfa code as well as the password', function () {
    [$user, $token] = userWithToken('mfa-reauth-code@example.com');
    enrolMfa($user);

    $this->withToken($token)
        ->postJson('/api/auth/reauthenticate', ['password' => 'secret-password'])
        ->assertStatus(422)
        ->assertJson(['code' => 'invalid_code']);

    // Wait for a fresh window: confirmEnrolment burned the current code.
    Cache::forget("mfa_last_totp:{$user->id}");

    $this->withToken($token)
        ->postJson('/api/auth/reauthenticate', ['password' => 'secret-password', 'code' => currentCode($user)])
        ->assertOk();
});

it('disabling mfa clears the secret and recovery codes', function () {
    [$user, $token] = userWithToken('mfa-off@example.com');
    enrolMfa($user);

    Cache::forget("mfa_last_totp:{$user->id}");
    $this->withToken($token)->postJson('/api/auth/reauthenticate', [
        'password' => 'secret-password', 'code' => currentCode($user),
    ])->assertOk();

    $this->withToken($token)->deleteJson('/api/auth/mfa')->assertOk();

    $fresh = $user->fresh();
    expect($fresh->hasMfa())->toBeFalse()
        ->and($fresh->mfa_secret)->toBeNull()
        ->and($fresh->mfa_recovery_codes)->toBeNull();
});

it('never exposes mfa material in the me payload', function () {
    [$user, $token] = userWithToken('mfa-leak@example.com');
    enrolMfa($user);

    $body = $this->withToken($token)->getJson('/api/auth/me')->assertOk()->getContent();

    expect($body)->not->toContain((string) $user->fresh()->mfa_secret)
        ->and($body)->not->toContain('mfa_recovery_codes');
});

it('the reauth mark is bound to one token, not the whole account', function () {
    [$user, $tokenA] = userWithToken('mfa-tokenbound@example.com');
    $tokenB = $user->createToken('second-device')->plainTextToken;

    $this->withToken($tokenA)->postJson('/api/auth/reauthenticate', ['password' => 'secret-password'])->assertOk();

    // Confirming on device A must not unlock device B.
    $this->withToken($tokenA)->postJson('/api/auth/mfa/setup')->assertOk();

    // Laravel keeps one container per test, so the auth guard caches the
    // first resolved user and its access token. A real request always gets
    // a fresh container; force that here before switching token/state.
    $this->app['auth']->forgetGuards();
    $this->withToken($tokenB)->postJson('/api/auth/mfa/setup')->assertStatus(428);
});

it('gates billing and license regeneration behind reauth', function () {
    [$user, $token] = userWithToken('mfa-billing@example.com');

    $plan = \App\Models\Plan::create([
        'name' => 'P', 'slug' => 'p-' . \Illuminate\Support\Str::random(6),
        'site_limit' => 1, 'user_limit' => 1, 'ai_insight_limit' => 1, 'report_limit' => 1, 'is_active' => true,
    ]);
    $org = \App\Models\Organization::create([
        'name' => 'Org', 'slug' => 'o-' . \Illuminate\Support\Str::random(6),
        'owner_user_id' => $user->id, 'plan_id' => $plan->id, 'status' => 'active',
    ]);
    $org->users()->attach($user->id, ['role' => 'owner']);

    $this->withToken($token)
        ->postJson('/api/billing/checkout', ['plan_slug' => $plan->slug])
        ->assertStatus(428)
        ->assertJson(['code' => 'reauth_required']);
});

it('emails the user when signing in from a new device, but not the same one', function () {
    // LifecycleMail is ShouldQueue, so Mail::fake() records it as queued
    // rather than sent — asserting on 'sent' here would always be false.
    Mail::fake();

    [$user] = userWithToken('mfa-newdevice@example.com');

    // First tracked sign-in records the fingerprint silently.
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10', 'HTTP_USER_AGENT' => 'Browser/1'])
        ->postJson('/api/auth/login', ['email' => 'mfa-newdevice@example.com', 'password' => 'secret-password'])
        ->assertOk();

    Mail::assertNothingQueued();

    // Same device again — still silent.
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10', 'HTTP_USER_AGENT' => 'Browser/1'])
        ->postJson('/api/auth/login', ['email' => 'mfa-newdevice@example.com', 'password' => 'secret-password'])
        ->assertOk();

    Mail::assertNothingQueued();

    // Different device — notify.
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7', 'HTTP_USER_AGENT' => 'Browser/2'])
        ->postJson('/api/auth/login', ['email' => 'mfa-newdevice@example.com', 'password' => 'secret-password'])
        ->assertOk();

    Mail::assertQueued(\App\Mail\LifecycleMail::class);
    expect(EmailLog::where('email_type', 'security_new_login')->count())->toBe(1);
});

it('does not store the raw ip or user agent as the fingerprint', function () {
    [$user] = userWithToken('mfa-privacy@example.com');

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.55', 'HTTP_USER_AGENT' => 'SecretAgent/9'])
        ->postJson('/api/auth/login', ['email' => 'mfa-privacy@example.com', 'password' => 'secret-password'])
        ->assertOk();

    $fingerprint = $user->fresh()->last_login_fingerprint;

    expect($fingerprint)->toHaveLength(64)
        ->and($fingerprint)->not->toContain('203.0.113.55')
        ->and($fingerprint)->not->toContain('SecretAgent');
});

it('status reports enrolment state without leaking the secret', function () {
    [$user, $token] = userWithToken('mfa-status@example.com');

    $before = $this->withToken($token)->getJson('/api/auth/mfa')->assertOk()->json();
    expect($before['enabled'])->toBeFalse()->and($before['recovery_codes_left'])->toBe(0);

    enrolMfa($user);

    // Laravel keeps one container per test, so the auth guard caches the
    // first resolved user and its access token. A real request always gets
    // a fresh container; force that here before switching token/state.
    $this->app['auth']->forgetGuards();
    $after = $this->withToken($token)->getJson('/api/auth/mfa')->assertOk();
    expect($after->json('enabled'))->toBeTrue()
        ->and($after->json('recovery_codes_left'))->toBe(8)
        ->and($after->getContent())->not->toContain((string) $user->fresh()->mfa_secret);
});
