<?php

use App\Services\LifecycleMailer;
use function Pest\Laravel\actingAs;

/**
 * Settled 2026-11-11: the trial is 7 days and card-free, everywhere.
 * One config value (commercial.trial_days ← INSIGHTISTIC_TRIAL_DAYS) feeds
 * the API, Stripe, emails and the admin health check. The legacy
 * INSIGHTISTIC_SIGNUP_TRIAL_DAYS (14 on prod) is read by nothing.
 */

it('defaults to a 7-day trial with no card', function () {
    expect(config('commercial.trial_days'))->toBe(7)
        ->and(config('insightistic.trial_days'))->toBe(7)
        ->and(config('commercial.trial_card_required'))->toBeFalse();
});

it('publishes the same trial length on /public/plans', function () {
    $this->getJson('/api/public/plans')->assertOk()->assertJsonPath('trial_days', 7);
});

it('ignores the legacy INSIGHTISTIC_SIGNUP_TRIAL_DAYS value', function () {
    config(['commercial.legacy_signup_trial_days_env' => '14']);

    expect(config('commercial.trial_days'))->toBe(7);
});

it('uses the organization\'s real trial length in the trial email heading', function () {
    [, $org] = makeOrgUser('trial-heading@example.com');

    expect(LifecycleMailer::trialLengthDays($org))->toBe(7); // no dates yet → config

    $org->forceFill(['trial_started_at' => now(), 'trial_ends_at' => now()->addDays(7)])->save();
    expect(LifecycleMailer::trialLengthDays($org->fresh()))->toBe(7);

    $org->forceFill(['trial_started_at' => now()->subDays(20), 'trial_ends_at' => now()->subDays(6)])->save();
    expect(LifecycleMailer::trialLengthDays($org->fresh()))->toBe(14); // legacy 14-day account stays truthful
});

it('shows the trial alignment on System Health', function () {
    $admin = App\Models\User::create(['name' => 'SA', 'email' => 'trial-sa@example.com', 'password' => 'secret-password', 'status' => 'active']);
    $admin->forceFill(['is_super_admin' => true])->save();
    config(['commercial.legacy_signup_trial_days_env' => '14']);

    actingAs($admin)->getJson('/api/admin/system-health')
        ->assertOk()
        ->assertJsonPath('trial.days', 7)
        ->assertJsonPath('trial.card_required', false)
        ->assertJsonPath('trial.stripe_trial_period_days', 7)
        ->assertJsonPath('trial.legacy_signup_trial_days_env', '14');
});
