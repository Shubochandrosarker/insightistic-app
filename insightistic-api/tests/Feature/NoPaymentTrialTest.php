<?php

use App\Domain\Billing\CreditLedger;
use App\Domain\Billing\CreditWallet;
use App\Domain\Billing\EntitlementResolver;
use App\Domain\Billing\PaddleCustomerLinker;
use App\Domain\Billing\TrialService;
use App\Models\Organization;
use Database\Seeders\PlanSeeder;

/**
 * Shuvo 2026-10-27: every regular plan can be activated as a 7-day trial
 * with no payment; only the /ltd lifetime deal goes to paid checkout.
 */
beforeEach(function () {
    $this->seed(PlanSeeder::class);
});

it('defaults the trial to 7 days', function () {
    expect(config('commercial.trial_days'))->toBe(7)
        ->and(config('commercial.refund_window_days'))->toBe(30);
});

it('starts a 7-day no-payment trial on a regular plan and grants its credits', function () {
    [$user, $org] = makeOrgUser('trial-owner@example.com', null, 'owner');
    $token = $user->createToken('t')->plainTextToken;

    $this->withToken($token)->postJson('/api/billing/start-trial', ['plan' => 'growth'])
        ->assertOk()->assertJsonPath('organization.status', 'trialing');

    $org = Organization::find($org->id);
    expect($org->trial_plan_slug)->toBe('growth')
        ->and((int) round(now()->diffInHours($org->trial_ends_at) / 24))->toBe(7)
        ->and(app(EntitlementResolver::class)->resolve($org)['plan']['slug'])->toBe('growth');

    $summary = app(CreditWallet::class)->summary($org);
    expect((int) $summary['monthly_included']['granted'])->toBe(15000);
});

it('allows only one trial and never trials an LTD tier', function () {
    [$user, $org] = makeOrgUser('trial-twice@example.com', null, 'owner');
    $token = $user->createToken('t')->plainTextToken;

    $this->withToken($token)->postJson('/api/billing/start-trial', ['plan' => 'angel_solo'])
        ->assertStatus(422)->assertJsonPath('code', 'plan_not_trialable');
    $this->withToken($token)->postJson('/api/billing/start-trial', ['plan' => 'starter'])->assertOk();
    $this->withToken($token)->postJson('/api/billing/start-trial', ['plan' => 'agency'])
        ->assertStatus(422)->assertJsonPath('code', 'trial_already_used');
});

it('falls back to Free and removes unused trial allowance when the trial lapses', function () {
    [, $org] = makeOrgUser('trial-lapse@example.com', null, 'owner');
    $org = app(TrialService::class)->start($org, 'agency');
    $org->forceFill(['trial_ends_at' => now()->subMinute()])->save();

    app(TrialService::class)->expire($org->fresh());
    $org = $org->fresh();

    expect(app(EntitlementResolver::class)->resolve($org)['plan']['slug'])->toBe('free');
    $remaining = app(CreditLedger::class)->bucketBalance($org, 'monthly_included', app(CreditWallet::class)->periodKey());
    expect($remaining)->toBeLessThanOrEqual((int) (\App\Models\Plan::where('slug', 'free')->first()->limit('monthly_ai_credits')));

    // idempotent
    app(TrialService::class)->expire($org);
    expect(app(CreditLedger::class)->bucketBalance($org, 'monthly_included', app(CreditWallet::class)->periodKey()))->toBe($remaining);
});

it('links a Paddle customer to exactly one organization', function () {
    [, $a] = makeOrgUser('link-a@example.com', null, 'owner');
    [, $b] = makeOrgUser('link-b@example.com', null, 'owner');
    $linker = app(PaddleCustomerLinker::class);

    $linker->link($a, 'ctm_aaa');
    $linker->link($b, 'ctm_aaa'); // already owned by A → ignored
    expect($a->fresh()->paddle_customer_id)->toBe('ctm_aaa')
        ->and($b->fresh()->paddle_customer_id)->toBeNull();

    $linker->fromCustomerEvent(['id' => 'ctm_bbb', 'email' => 'link-b@example.com']);
    expect($b->fresh()->paddle_customer_id)->toBe('ctm_bbb');
});
