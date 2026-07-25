<?php

use App\Console\Commands\IssueSuperAdminToken;
use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

function activeUser(string $email, bool $superAdmin = false): User
{
    $user = User::create([
        'name'     => 'U ' . $email,
        'email'    => $email,
        'password' => 'secret-password',
        'status'   => 'active',
    ]);
    if ($superAdmin) {
        $user->forceFill(['is_super_admin' => true])->save();
    }

    return $user;
}

function cacheBootstrapToken(User $user, string $raw = 'sabt_testtoken'): string
{
    Cache::put(
        IssueSuperAdminToken::CACHE_PREFIX . hash('sha256', $raw),
        ['user_id' => $user->id, 'issued_at' => now()->toIso8601String()],
        now()->addMinutes(15),
    );

    return $raw;
}

it('redeems a bootstrap token exactly once and records an audit entry', function () {
    $user = activeUser('bootstrap@example.com');
    $raw  = cacheBootstrapToken($user);

    $first = $this->actingAs($user)->postJson('/api/auth/super-admin/redeem', ['token' => $raw]);
    $first->assertOk()->assertJsonPath('user.is_super_admin', true);
    expect($user->fresh()->is_super_admin)->toBeTrue();

    expect(AdminAuditLog::where('action', 'user.super_admin_token_redeemed')->count())->toBe(1);

    // Single use: replaying the same token fails.
    $replay = $this->actingAs($user)->postJson('/api/auth/super-admin/redeem', ['token' => $raw]);
    $replay->assertStatus(422);
});

it('rejects redemption by a different authenticated user and burns the token', function () {
    $target   = activeUser('target@example.com');
    $attacker = activeUser('attacker@example.com');
    $raw      = cacheBootstrapToken($target);

    $res = $this->actingAs($attacker)->postJson('/api/auth/super-admin/redeem', ['token' => $raw]);
    $res->assertStatus(422);

    expect($attacker->fresh()->is_super_admin)->toBeFalse();
    expect($target->fresh()->is_super_admin)->toBeFalse();

    // Burned: even the rightful owner can no longer use it.
    $late = $this->actingAs($target)->postJson('/api/auth/super-admin/redeem', ['token' => $raw]);
    $late->assertStatus(422);
});

it('rejects garbage tokens', function () {
    $user = activeUser('nobody@example.com');

    $this->actingAs($user)
        ->postJson('/api/auth/super-admin/redeem', ['token' => 'sabt_wrong'])
        ->assertStatus(422);

    expect($user->fresh()->is_super_admin)->toBeFalse();
});

it('make-super-admin refuses when an active super admin already exists (without --force)', function () {
    activeUser('root@example.com', superAdmin: true);
    $other = activeUser('second@example.com');

    $this->artisan('insightistic:make-super-admin', ['email' => $other->email])
        ->assertExitCode(1);

    expect($other->fresh()->is_super_admin)->toBeFalse();
});

it('make-super-admin promotes the first super admin and audits it', function () {
    $user = activeUser('first@example.com');

    $this->artisan('insightistic:make-super-admin', ['email' => $user->email])
        ->assertExitCode(0);

    expect($user->fresh()->is_super_admin)->toBeTrue();
    expect(AdminAuditLog::where('action', 'user.promote_super_admin')->count())->toBe(1);
});

it('issue-token refuses non-active accounts', function () {
    $user = User::create([
        'name'     => 'Disabled',
        'email'    => 'disabled-issue@example.com',
        'password' => 'x',
        'status'   => 'disabled',
    ]);

    $this->artisan('insightistic:super-admin:issue-token', ['email' => $user->email])
        ->assertExitCode(1);
});
