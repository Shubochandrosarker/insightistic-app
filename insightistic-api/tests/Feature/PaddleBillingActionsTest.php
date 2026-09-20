<?php

use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Subscription;
use Illuminate\Support\Str;

/**
 * Provider-aware tenant billing actions (roadmap doc 23): Paddle orgs get a
 * Paddle portal link and Paddle cancellation semantics (scheduled cancel,
 * no un-cancel), while Stripe orgs keep their exact legacy behavior.
 */

/** Org whose subscription row points at Paddle, with a Sanctum owner token. */
function paddleActionOrg(string $email): array
{
    [$user, $org] = makeOrgUser($email);
    $token = $user->createToken('test')->plainTextToken;

    Subscription::create([
        'organization_id'          => $org->id,
        'plan_id'                  => null,
        'status'                   => 'active',
        'billing_provider'         => 'paddle',
        'provider_customer_id'     => 'cus_' . Str::random(8),
        'provider_subscription_id' => 'sub_' . Str::random(8),
        'provider_price_id'        => 'pri_' . Str::random(8),
        'provider_status'          => 'active',
    ]);

    return [$org, $token];
}

/** Fake both Paddle calls the cancel flow makes: GET current, PATCH scheduled change. */
function fakePaddleCancel(string $subscriptionId, string $endsAt): void
{
    Http::fake([
        'https://sandbox-api.paddle.com/subscriptions/' . $subscriptionId => function ($request) use ($subscriptionId, $endsAt) {
            if ($request->method() === 'GET') {
                return Http::response(['data' => [
                    'id'              => $subscriptionId,
                    'status'          => 'active',
                    'next_billed_at'  => $endsAt,
                    'items'           => [['price' => ['id' => 'pri_fake']]],
                ]]);
            }

            return Http::response(['data' => [
                'id'                       => $subscriptionId,
                'status'                   => 'active',
                'customer_id'              => 'cus_fake',
                'items'                    => [['price' => ['id' => 'pri_fake']]],
                'current_billing_period'   => ['starts_at' => '2026-09-01T00:00:00Z', 'ends_at' => $endsAt],
                'next_billed_at'           => $endsAt,
                'scheduled_change'         => ['action' => 'cancel', 'effective_at' => $endsAt],
            ]]);
        },
    ]);
}

it('returns production environment in checkout config when sandbox is off', function () {
    config()->set('feature-flags.paddle', true);
    config()->set('billing.paddle.sandbox', false);
    config()->set('billing.paddle.client_token', 'live_test_token');

    [$user, $org] = makeOrgUser('paddle-env@example.com');
    $token = $user->createToken('test')->plainTextToken;

    $plan = Plan::create(['name' => 'Env ' . Str::random(4), 'slug' => 'paddle-env-' . Str::random(4), 'is_active' => true]);
    PlanPrice::create([
        'plan_id'           => $plan->id,
        'provider'          => 'paddle',
        'kind'              => 'monthly',
        'provider_price_id' => 'pri_env_' . Str::random(8),
        'currency'          => 'USD',
        'amount_minor'      => 2900,
        'is_active'         => true,
    ]);

    $this->withToken($token)
        ->postJson('/api/billing/checkout-config', ['plan' => $plan->slug, 'kind' => 'monthly'])
        ->assertOk()
        ->assertJsonPath('environment', 'production');
});

it('cancels a paddle subscription through paddle and records the scheduled change', function () {
    [$org, $token] = paddleActionOrg('paddle-cancel@example.com');
    $sub = $org->subscription;
    $endsAt = '2026-10-01T00:00:00Z';

    fakePaddleCancel($sub->provider_subscription_id, $endsAt);

    $this->withToken($token)
        ->postJson('/api/billing/cancel')
        ->assertOk()
        ->assertJsonPath('ok', true);

    $sub->refresh();

    expect($sub->scheduled_change_action)->toBe('cancel')
        ->and($sub->scheduled_change_at?->toIso8601String())->toContain('2026-10-01')
        // The projector mirrors Paddle's scheduled change onto the
        // Stripe-shaped flag so existing UI keeps rendering the banner.
        ->and($sub->cancel_at_period_end)->toBeTrue();
});

it('opens a paddle customer portal for paddle orgs', function () {
    [$org, $token] = paddleActionOrg('paddle-portal@example.com');
    $customerId = $org->subscription->provider_customer_id;

    Http::fake([
        'https://sandbox-api.paddle.com/customers/' . $customerId . '/portal-sessions' => Http::response([
            'data' => ['urls' => ['general' => ['overview' => 'https://login.paddle.com/portal/session']]],
        ]),
    ]);

    $this->withToken($token)
        ->postJson('/api/billing/portal')
        ->assertOk()
        ->assertJsonPath('url', 'https://login.paddle.com/portal/session');
});

it('refuses to reactivate a canceled paddle subscription', function () {
    [, $token] = paddleActionOrg('paddle-react@example.com');

    $this->withToken($token)
        ->postJson('/api/billing/reactivate')
        ->assertStatus(422)
        ->assertJson(['code' => 'paddle_no_reactivate']);
});
