<?php

use App\Models\Plan;
use App\Models\PlanPrice;
use Illuminate\Support\Str;

/**
 * Paddle checkout-config endpoint (roadmap 23-STRIPE-TO-PADDLE-MIGRATION):
 * owner-only, flag-gated, and priced exclusively from plan_prices. The 501
 * for a disabled flag (not 404) is deliberate — it tells the SPA the feature
 * exists but is off, while 403/422 keep the usual permission/validation
 * semantics.
 */

/** Owner (or other role) with a Sanctum token, per the PrivacyTest pattern. */
function paddleConfigOwner(string $email, string $role = 'owner'): array
{
    [$user, $org] = makeOrgUser($email, null, $role);
    $token = $user->createToken('test')->plainTextToken;

    return [$user, $org, $token];
}

/** Active plan with a monthly Paddle price mapping; returns the plan. */
function paddleConfiguredPlan(string $slug): Plan
{
    $plan = Plan::create([
        'name'      => 'Paddle Monthly ' . Str::random(4),
        'slug'      => $slug,
        'is_active' => true,
    ]);

    PlanPrice::create([
        'plan_id'           => $plan->id,
        'provider'          => 'paddle',
        'kind'              => 'monthly',
        'provider_price_id' => 'pri_cfg_' . Str::random(8),
        'currency'          => 'USD',
        'amount_minor'      => 4900,
        'is_active'         => true,
        'metadata_json'     => null,
    ]);

    return $plan;
}

it('returns checkout config for an owner when the flag is on and the price exists', function () {
    config()->set('feature-flags.paddle', true);
    config()->set('billing.paddle.client_token', 'test-client-token');

    [, $org, $token] = paddleConfigOwner('paddle-cfg-ok@example.com');
    $plan = paddleConfiguredPlan('paddle-cfg-ok-' . Str::random(4));

    $response = $this->withToken($token)
        ->postJson('/api/billing/checkout-config', ['plan' => $plan->slug, 'kind' => 'monthly'])
        ->assertOk()
        ->assertJson([
            'provider'     => 'paddle',
            'currency'     => 'USD',
            'amount_minor' => 4900,
            'custom_data'  => [
                'organization_id' => $org->id,
                'purchase_kind'   => 'monthly',
                'plan_slug'       => $plan->slug,
            ],
        ]);

    // The client token is served so the SPA can open Paddle.js checkout —
    // but the server-side API key must never appear anywhere in the payload.
    expect($response->json('client_token'))->toBeString()
        ->and($response->json('price_id'))->toBeString()
        ->and($response->json())->not->toHaveKey('api_key');
});

it('rejects non-owners with 403', function () {
    config()->set('feature-flags.paddle', true);

    $plan = paddleConfiguredPlan('paddle-cfg-role-' . Str::random(4));
    [, , $token] = paddleConfigOwner('paddle-cfg-analyst@example.com', 'analyst');

    $this->withToken($token)
        ->postJson('/api/billing/checkout-config', ['plan' => $plan->slug, 'kind' => 'monthly'])
        ->assertStatus(403)
        ->assertJson(['code' => 'insufficient_role']);
});

it('answers 501 while the paddle feature flag is off', function () {
    // feature-flags.paddle defaults to false.
    [$user, $org, $token] = paddleConfigOwner('paddle-cfg-flag@example.com');
    $plan = paddleConfiguredPlan('paddle-cfg-flag-' . Str::random(4));

    $this->withToken($token)
        ->postJson('/api/billing/checkout-config', ['plan' => $plan->slug, 'kind' => 'monthly'])
        ->assertStatus(501)
        ->assertJson(['code' => 'paddle_not_enabled']);
});

it('validates unknown plans through the standard exists rule', function () {
    config()->set('feature-flags.paddle', true);

    [, , $token] = paddleConfigOwner('paddle-cfg-unknown@example.com');

    $this->withToken($token)
        ->postJson('/api/billing/checkout-config', ['plan' => 'does-not-exist', 'kind' => 'monthly'])
        ->assertStatus(422);
});

it('answers 422 price_not_configured when the plan has no Paddle price', function () {
    config()->set('feature-flags.paddle', true);

    [, , $token] = paddleConfigOwner('paddle-cfg-noprice@example.com');

    $plan = Plan::create([
        'name'      => 'Unmapped ' . Str::random(4),
        'slug'      => 'paddle-cfg-noprice-' . Str::random(4),
        'is_active' => true,
    ]);

    $this->withToken($token)
        ->postJson('/api/billing/checkout-config', ['plan' => $plan->slug, 'kind' => 'lifetime'])
        ->assertStatus(422)
        ->assertJson(['code' => 'price_not_configured']);
});

it('sells lifetime and credit-pack kinds from hidden (is_active=false) plans', function () {
    config()->set('feature-flags.paddle', true);
    config()->set('billing.paddle.client_token', 'tok_hidden_plans');

    [, , $token] = paddleConfigOwner('paddle-hidden@example.com');

    // Founder LTD tiers are seeded is_active=false on purpose: hidden plans
    // sell one-time kinds through their active Paddle price, not the plan flag.
    $hidden = Plan::create([
        'name'      => 'Founder Tier (hidden) ' . Str::random(4),
        'slug'      => 'paddle-hidden-' . Str::random(4),
        'is_active' => false,
    ]);
    PlanPrice::create([
        'plan_id'           => $hidden->id,
        'provider'          => 'paddle',
        'kind'              => 'lifetime',
        'provider_price_id' => 'pri_hidden_' . Str::random(8),
        'currency'          => 'USD',
        'amount_minor'      => 7900,
        'is_active'         => true,
    ]);

    $this->withToken($token)
        ->postJson('/api/billing/checkout-config', ['plan' => $hidden->slug, 'kind' => 'lifetime'])
        ->assertOk()
        ->assertJsonPath('amount_minor', 7900);

    // Recurring kinds on a hidden plan stay rejected — hiding a plan must
    // take it off the subscription menu.
    $this->withToken($token)
        ->postJson('/api/billing/checkout-config', ['plan' => $hidden->slug, 'kind' => 'monthly'])
        ->assertStatus(422)
        ->assertJson(['code' => 'plan_not_available']);
});

it('selects the requested credit pack instead of silently taking the first price', function () {
    config()->set('feature-flags.paddle', true);
    config()->set('billing.paddle.client_token', 'tok_credit_pack');

    [, $org, $token] = paddleConfigOwner('paddle-credit-pack@example.com');
    $plan = Plan::create([
        'name' => 'Insight Credits',
        'slug' => 'insight_credits',
        'is_active' => false,
    ]);

    foreach ([
        ['slug' => 'credits_5000', 'amount' => 1500, 'credits' => 5000],
        ['slug' => 'credits_20000', 'amount' => 4900, 'credits' => 20000],
    ] as $pack) {
        PlanPrice::create([
            'plan_id' => $plan->id,
            'provider' => 'paddle',
            'kind' => 'credit_pack',
            'provider_price_id' => 'pri_' . $pack['slug'],
            'currency' => 'USD',
            'amount_minor' => $pack['amount'],
            'is_active' => true,
            'metadata_json' => ['credits' => $pack['credits']],
        ]);
    }

    $this->withToken($token)
        ->postJson('/api/billing/checkout-config', [
            'plan' => $plan->slug,
            'kind' => 'credit_pack',
            'credit_pack' => 'credits_20000',
        ])
        ->assertOk()
        ->assertJson([
            'price_id' => 'pri_credits_20000',
            'amount_minor' => 4900,
            'custom_data' => [
                'organization_id' => $org->id,
                'purchase_kind' => 'credit_pack',
                'plan_slug' => 'insight_credits',
                'credit_pack' => 'credits_20000',
                'credit_amount' => 20000,
            ],
        ]);
});
