<?php

use App\Models\Subscription;
use Illuminate\Support\Str;

/**
 * Tenant billing actions for legacy Paddle-billed orgs (roadmap doc 23):
 * Paddle cancellation semantics (scheduled cancel, no un-cancel) remain for
 * subscriptions still billed through Paddle, while the customer portal is
 * Stripe-only — a Paddle org's portal opens on a Stripe customer created
 * on the fly (Paddle surface retired).
 */

/** Org whose subscription row points at Paddle, with a re-authed owner token. */
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

    // cancel/portal ride the reauth middleware — mark this token as
    // recently re-authenticated (PrivacyTest pattern).
    test()->withToken($token)
        ->postJson('/api/auth/reauthenticate', ['password' => 'secret-password'])
        ->assertOk();

    // PaddleClient refuses to call out without a key; tests never hit the
    // real API (Http::fake below) but the guard runs first.
    config()->set('billing.paddle.api_key', 'pdl_test_' . Str::random(12));

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

it('opens the Stripe portal for paddle orgs — the Stripe customer is created on the fly', function () {
    [$org, $token] = paddleActionOrg('paddle-portal@example.com');

    $stripe = Mockery::mock(\App\Services\StripeService::class)->makePartial();
    $stripe->shouldReceive('customerId')->once()->with(Mockery::on(fn ($o) => $o->id === $org->id))->andReturn('cus_created');
    $stripe->shouldReceive('portalUrl')->once()->with('cus_created', Mockery::type('string'))->andReturn('https://billing.stripe.com/p/session/test');
    app()->instance(\App\Services\StripeService::class, $stripe);

    $this->withToken($token)
        ->postJson('/api/billing/portal')
        ->assertOk()
        ->assertJsonPath('url', 'https://billing.stripe.com/p/session/test');
});

it('refuses to reactivate a canceled paddle subscription', function () {
    [, $token] = paddleActionOrg('paddle-react@example.com');

    $this->withToken($token)
        ->postJson('/api/billing/reactivate')
        ->assertStatus(422)
        ->assertJson(['code' => 'paddle_no_reactivate']);
});
