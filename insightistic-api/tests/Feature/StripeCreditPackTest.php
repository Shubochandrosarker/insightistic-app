<?php

use App\Domain\Billing\CreditWallet;
use App\Models\BillingTransaction;
use App\Models\CreditLedgerEntry;
use App\Models\EntitlementGrant;
use App\Models\Organization;
use App\Models\StripeWebhookEvent;
use App\Services\StripeService;

/**
 * Stripe credit packs (AppSumo-ready plan §"Buy on Stripe").
 *
 * A pack is a one-time payment, so the two load-bearing properties are:
 *   1. the session carries the catalogue amount and org+pack metadata — never
 *      a client-supplied price;
 *   2. credits move exactly once per Stripe session id, whether the delivery
 *      is duplicated, replayed under a *new* event id, or arrives late through
 *      the async-payment path.
 *
 * Stripe API calls are mocked at StripeService (same seam as
 * StripeDualProviderBillingTest); webhooks go through the real HTTP endpoint
 * with real Stripe-format signatures.
 */

beforeEach(function () {
    config()->set('billing.stripe.secret', 'sk_test_creditpacks');
    config()->set('billing.stripe.webhook_secret', 'whsec_packs');
    config()->set('billing.checkout_providers', ['paddle', 'stripe']);
    config()->set('insightistic.app_url', 'https://app.insightistic.com');
});

/** @return array{0: \App\Models\User, 1: Organization, 2: string} */
function cpOwner(string $email, array $limits = ['monthly_ai_credits' => 1000]): array
{
    [$user, $org] = makeOrgUser($email, $limits);

    return [$user, $org, $user->createToken('t')->plainTextToken];
}

/** Capture the Checkout Session params the app would send to Stripe. */
function cpMockStripe(?array &$captured, string $customer = 'cus_packs'): void
{
    $mock = Mockery::mock(StripeService::class)->makePartial();
    $mock->shouldReceive('customerId')->andReturnUsing(function (Organization $org) use ($customer) {
        $org->forceFill(['stripe_customer_id' => $customer])->save();

        return $customer;
    });
    $mock->shouldReceive('createCheckoutSession')->andReturnUsing(function (array $params) use (&$captured) {
        $captured = $params;

        return (object) ['id' => 'cs_test_pack', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_pack'];
    });
    app()->instance(StripeService::class, $mock);
}

function cpWebhook($test, string $id, string $type, array $object)
{
    $body = json_encode(['id' => $id, 'object' => 'event', 'type' => $type, 'data' => ['object' => $object]]);
    $ts = time();
    $sig = hash_hmac('sha256', "{$ts}.{$body}", 'whsec_packs');

    return $test->call('POST', '/api/billing/webhook/stripe', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => "t={$ts},v1={$sig}",
    ], $body);
}

/** A credit-pack Checkout Session as Stripe delivers it. */
function cpSession(Organization $org, string $pack = 'credits_20000', array $overrides = []): array
{
    return array_merge([
        'id' => 'cs_test_pack_paid',
        'object' => 'checkout.session',
        'mode' => 'payment',
        'payment_status' => 'paid',
        'customer' => 'cus_packs',
        'payment_intent' => 'pi_test_pack',
        'currency' => 'usd',
        'amount_subtotal' => 4900,
        'amount_total' => 4900,
        'total_details' => ['amount_tax' => 0],
        'metadata' => [
            'organization_id' => (string) $org->id,
            'purchase_kind' => 'credit_pack',
            'pack' => $pack,
            'credits' => '20000',
        ],
    ], $overrides);
}

function cpPurchasedBalance(Organization $org): int
{
    return (int) CreditLedgerEntry::withoutGlobalScope('organization')
        ->where('organization_id', $org->id)
        ->where('bucket', 'purchased')
        ->sum('amount_signed');
}

// ---------------------------------------------------------------- checkout

it('creates a one-time pack checkout priced from the catalogue with org+pack metadata', function () {
    [, $org, $token] = cpOwner('cp-checkout@example.com');
    cpMockStripe($params);

    $this->withToken($token)->postJson('/api/billing/credits/checkout', ['pack' => 'credits_20000'])
        ->assertOk()
        ->assertJson([
            'provider'   => 'stripe',
            'url'        => 'https://checkout.stripe.com/c/pay/cs_test_pack',
            'session_id' => 'cs_test_pack',
            'pack'       => 'credits_20000',
            'credits'    => 20000,
        ]);

    expect($params['mode'])->toBe('payment')
        ->and($params)->not->toHaveKey('subscription_data')
        ->and($params['line_items'][0]['quantity'])->toBe(1)
        ->and($params['line_items'][0]['price_data']['currency'])->toBe('usd')
        ->and($params['line_items'][0]['price_data']['unit_amount'])->toBe(4900)
        ->and($params['line_items'][0]['price_data'])->not->toHaveKey('price')
        ->and($params['client_reference_id'])->toBe((string) $org->id)
        ->and($params['metadata'])->toMatchArray([
            'organization_id' => (string) $org->id,
            'purchase_kind'   => 'credit_pack',
            'pack'            => 'credits_20000',
            'credits'         => '20000',
        ])
        ->and($params['payment_intent_data']['metadata']['pack'])->toBe('credits_20000')
        ->and($params['success_url'])->toContain('https://app.insightistic.com/dashboard/billing?credits=success')
        ->and($params['success_url'])->toContain('{CHECKOUT_SESSION_ID}')
        ->and($params['cancel_url'])->toContain('https://app.insightistic.com/dashboard/billing?credits=cancel');

    // The response never grants: crediting is webhook-only.
    expect(CreditLedgerEntry::withoutGlobalScope('organization')->count())->toBe(0);
});

it('prefers a configured Stripe price id over the inline catalogue price', function () {
    config()->set('billing.stripe.credit_prices.credits_5000', 'price_credits_5000');
    [, , $token] = cpOwner('cp-price@example.com');
    cpMockStripe($params);

    $this->withToken($token)->postJson('/api/billing/credits/checkout', ['pack' => 'credits_5000'])->assertOk();

    expect($params['line_items'][0])->toBe(['price' => 'price_credits_5000', 'quantity' => 1]);
});

it('refuses an unknown pack', function () {
    [, , $token] = cpOwner('cp-unknown@example.com');

    $this->withToken($token)->postJson('/api/billing/credits/checkout', ['pack' => 'credits_999999'])
        ->assertStatus(422)->assertJsonValidationErrors('pack');

    $this->withToken($token)->postJson('/api/billing/credits/checkout', [])
        ->assertStatus(422)->assertJsonValidationErrors('pack');
});

it('lets only an owner buy a pack', function () {
    [$admin] = makeOrgUser('cp-admin@example.com', null, 'admin');
    cpMockStripe($params);

    $this->withToken($admin->createToken('t')->plainTextToken)
        ->postJson('/api/billing/credits/checkout', ['pack' => 'credits_5000'])
        ->assertForbidden();

    expect($params)->toBeNull();
});

it('refuses a pack checkout when Stripe is not configured', function () {
    config()->set('billing.stripe.secret', null);
    config()->set('insightistic.stripe.secret', null);
    [, , $token] = cpOwner('cp-unconfigured@example.com');
    cpMockStripe($params);

    $this->withToken($token)->postJson('/api/billing/credits/checkout', ['pack' => 'credits_5000'])
        ->assertStatus(422)->assertJson(['code' => 'stripe_not_configured']);

    expect($params)->toBeNull();
});

// ---------------------------------------------------------------- webhooks

it('credits a paid pack once and acknowledges a duplicate delivery', function () {
    [, $org] = cpOwner('cp-webhook@example.com');
    $session = cpSession($org, 'credits_20000');

    cpWebhook($this, 'evt_pack_paid', 'checkout.session.completed', $session)
        ->assertOk()->assertJson(['duplicate' => false]);
    cpWebhook($this, 'evt_pack_paid', 'checkout.session.completed', $session)
        ->assertOk()->assertJson(['duplicate' => true]);

    $entry = CreditLedgerEntry::withoutGlobalScope('organization')
        ->where('organization_id', $org->id)->where('source_type', 'stripe_credit_pack')->sole();

    expect(cpPurchasedBalance($org))->toBe(20000)
        ->and($entry->reason)->toBe('stripe_credit_pack')
        ->and($entry->bucket)->toBe('purchased')
        // The session id is the idempotency key that outlives the event id.
        ->and($entry->source_reference)->toBe('cs_test_pack_paid')
        // Financial history for the billing page: one row, not two.
        ->and(BillingTransaction::withoutGlobalScope('organization')
            ->where('provider', 'stripe')->where('provider_transaction_id', 'cs_test_pack_paid')->count())->toBe(1);
});

it('stays exactly-once when Stripe re-sends the same session under a new event id', function () {
    [, $org] = cpOwner('cp-replay@example.com');
    $session = cpSession($org, 'credits_50000', ['id' => 'cs_test_pack_replay', 'amount_total' => 9900]);

    cpWebhook($this, 'evt_pack_a', 'checkout.session.completed', $session)->assertOk();
    cpWebhook($this, 'evt_pack_b', 'checkout.session.completed', $session)->assertOk()->assertJson(['duplicate' => false]);

    expect(cpPurchasedBalance($org))->toBe(50000)
        ->and(StripeWebhookEvent::whereIn('stripe_event_id', ['evt_pack_a', 'evt_pack_b'])->count())->toBe(2)
        ->and(CreditLedgerEntry::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)->where('source_type', 'stripe_credit_pack')->count())->toBe(1);
});

it('credits nothing while an async payment is pending and credits once on async success', function () {
    [, $org] = cpOwner('cp-async@example.com');
    $pending = cpSession($org, 'credits_50000', [
        'id' => 'cs_test_pack_async', 'payment_status' => 'unpaid', 'amount_total' => 9900,
    ]);

    cpWebhook($this, 'evt_pack_pending', 'checkout.session.completed', $pending)->assertOk();
    expect(cpPurchasedBalance($org))->toBe(0);

    $paid = array_merge($pending, ['payment_status' => 'paid']);
    cpWebhook($this, 'evt_pack_async_ok', 'checkout.session.async_payment_succeeded', $paid)->assertOk();
    cpWebhook($this, 'evt_pack_async_ok', 'checkout.session.async_payment_succeeded', $paid)->assertOk();

    expect(cpPurchasedBalance($org))->toBe(50000);
});

it('never credits a session whose pack is not in the catalogue', function () {
    [, $org] = cpOwner('cp-badpack@example.com');

    cpWebhook($this, 'evt_bad_pack', 'checkout.session.completed', cpSession($org, 'credits_999999'))->assertOk();

    expect(cpPurchasedBalance($org))->toBe(0)
        ->and(CreditLedgerEntry::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)->where('source_type', 'stripe_credit_pack')->count())->toBe(0);
});

it('does not mistake a pack payment for a lifetime purchase', function () {
    [, $org] = cpOwner('cp-not-ltd@example.com');

    cpWebhook($this, 'evt_pack_only', 'checkout.session.completed', cpSession($org))->assertOk();

    expect(cpPurchasedBalance($org))->toBe(20000)
        ->and(EntitlementGrant::withoutGlobalScope('organization')->where('organization_id', $org->id)->count())->toBe(0)
        ->and(BillingTransaction::withoutGlobalScope('organization')
            ->where('provider_transaction_id', 'cs_test_pack_paid')->value('kind'))->toBe('credit_pack');
});

// ---------------------------------------------------------------- balance

it('serves per-bucket balances, the plan allowance and the warning thresholds', function () {
    [, $org, $token] = cpOwner('cp-balance@example.com', ['monthly_ai_credits' => 1000]);

    $body = $this->withToken($token)->getJson('/api/billing/credits/balance')->assertOk()->json();

    expect($body['buckets']['monthly_included']['remaining'])->toBe(1000)
        ->and($body['buckets']['purchased']['remaining'])->toBe(0)
        ->and($body['buckets']['total_usable'])->toBe(1000)
        ->and($body['buckets']['period'])->toBe(app(CreditWallet::class)->periodKey())
        ->and($body['buckets']['next_reset_at'])->not->toBeNull()
        ->and($body['allowance']['monthly_ai_credits'])->toBe(1000)
        ->and($body['allowance']['remaining'])->toBe(1000)
        ->and($body['allowance']['percent_remaining'])->toEqual(100.0)
        ->and($body['thresholds'])->toMatchArray([
            'low' => 20, 'critical' => 5, 'zero' => 0,
            'low_at' => 200, 'critical_at' => 50, 'state' => 'ok',
        ]);
});

it('moves the threshold state down as the monthly allowance is spent', function () {
    [, $org, $token] = cpOwner('cp-thresholds@example.com', ['monthly_ai_credits' => 1000]);
    $wallet = app(CreditWallet::class);
    $wallet->summary($org); // grant this period's allowance
    $state = fn (): string => $this->withToken($token)->getJson('/api/billing/credits/balance')->json('thresholds.state');

    expect($state())->toBe('ok');

    $wallet->consume($org, 850, 'test_spend', 'spend:850');
    expect($state())->toBe('low');

    $wallet->consume($org, 100, 'test_spend', 'spend:950');
    expect($state())->toBe('critical');

    $wallet->consume($org, 50, 'test_spend', 'spend:1000');
    expect($state())->toBe('zero');
});

it('adds a purchased pack on top of the monthly allowance in the balance view', function () {
    [, $org, $token] = cpOwner('cp-balance-pack@example.com', ['monthly_ai_credits' => 1000]);
    cpWebhook($this, 'evt_pack_balance', 'checkout.session.completed', cpSession($org))->assertOk();

    $body = $this->withToken($token)->getJson('/api/billing/credits/balance')->assertOk()->json();

    // The pack lands in the non-expiring bucket, never in the monthly one.
    expect($body['buckets']['monthly_included']['remaining'])->toBe(1000)
        ->and($body['buckets']['purchased']['remaining'])->toBe(20000)
        ->and($body['buckets']['total_usable'])->toBe(21000)
        ->and($body['allowance']['remaining'])->toBe(1000)
        ->and($body['thresholds']['state'])->toBe('ok');
});

it('shows admins the balance but keeps no allowance state for an empty plan', function () {
    [$admin] = makeOrgUser('cp-balance-admin@example.com', null, 'admin');

    $body = $this->withToken($admin->createToken('t')->plainTextToken)
        ->getJson('/api/billing/credits/balance')->assertOk()->json();

    // No monthly_ai_credits on the plan → no allowance to meter, no warning.
    expect($body['allowance']['monthly_ai_credits'])->toBe(0)
        ->and($body['allowance']['percent_remaining'])->toBeNull()
        ->and($body['thresholds']['state'])->toBe('none');
});
