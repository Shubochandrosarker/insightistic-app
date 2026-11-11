<?php

use App\Domain\Billing\EntitlementResolver;
use App\Domain\Billing\LtdCampaignService;
use App\Domain\Billing\TrialService;
use App\Models\BillingTransaction;
use App\Models\EntitlementGrant;
use App\Models\LtdCampaign;
use App\Models\LtdCheckoutReservation;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\StripeWebhookEvent;
use App\Models\Subscription;
use App\Services\StripeService;
use Illuminate\Support\Facades\DB;

/**
 * Dual-provider billing: Stripe next to Paddle (docs/billing-providers.md).
 * Stripe API calls are mocked at StripeService; webhooks go through the real
 * HTTP endpoint with real Stripe-format signatures.
 */

beforeEach(function () {
    config()->set('billing.stripe.secret', 'sk_test_dualprovider');
    config()->set('billing.stripe.webhook_secret', 'whsec_dual');
    config()->set('billing.checkout_providers', ['paddle', 'stripe']);
});

function dpPlan(string $slug, string $kind, string $priceId, int $amount, bool $active = true): Plan
{
    $plan = Plan::where('slug', $slug)->first() ?? Plan::create([
        'name' => ucfirst($slug), 'slug' => $slug, 'is_active' => $active,
        'site_limit' => 5, 'user_limit' => 5, 'ai_insight_limit' => 100, 'report_limit' => 100,
    ]);
    PlanPrice::create([
        'plan_id' => $plan->id, 'provider' => 'stripe', 'kind' => $kind, 'provider_price_id' => $priceId,
        'currency' => 'USD', 'amount_minor' => $amount, 'is_active' => true,
        'billing_interval' => $kind === 'lifetime' ? null : ($kind === 'yearly' ? 'year' : 'month'),
    ]);

    return $plan;
}

function dpOwner(string $email): array
{
    [$user, $org] = makeOrgUser($email);

    return [$user, $org, $user->createToken('t')->plainTextToken];
}

/** Capture the Checkout Session params the app would send to Stripe. */
function dpMockStripe(?array &$captured, string $customer = 'cus_dual'): void
{
    $mock = Mockery::mock(StripeService::class)->makePartial();
    $mock->shouldReceive('customerId')->andReturnUsing(function (Organization $org) use ($customer) {
        $org->forceFill(['stripe_customer_id' => $customer])->save();

        return $customer;
    });
    $mock->shouldReceive('createCheckoutSession')->andReturnUsing(function (array $params) use (&$captured) {
        $captured = $params;

        return (object) ['id' => 'cs_test_dual', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_dual'];
    });
    app()->instance(StripeService::class, $mock);
}

function dpWebhook($test, string $id, string $type, array $object)
{
    $body = json_encode(['id' => $id, 'object' => 'event', 'type' => $type, 'data' => ['object' => $object]]);
    $ts = time();
    $sig = hash_hmac('sha256', "{$ts}.{$body}", 'whsec_dual');

    return $test->call('POST', '/api/billing/webhook/stripe', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => "t={$ts},v1={$sig}",
    ], $body);
}

// ---------------------------------------------------------------- checkout

it('creates a subscription checkout with automatic tax and the 7-day trial for an eligible org', function () {
    dpPlan('starter', 'monthly', 'price_test_starter_m', 2900);
    [, $org, $token] = dpOwner('dp-sub@example.com');
    dpMockStripe($params);

    $this->withToken($token)->postJson('/api/billing/stripe/checkout', ['plan' => 'starter', 'kind' => 'monthly'])
        ->assertOk()->assertJson(['provider' => 'stripe', 'session_id' => 'cs_test_dual', 'trial_days' => 7]);

    expect($params['mode'])->toBe('subscription')
        ->and($params['automatic_tax'])->toBe(['enabled' => true])
        ->and($params['line_items'][0]['price'])->toBe('price_test_starter_m')
        ->and($params['subscription_data']['trial_period_days'])->toBe(7)
        ->and($params['subscription_data'])->not->toHaveKey('trial_end')
        ->and($params['metadata']['organization_id'])->toBe((string) $org->id);
});

it('gives no Stripe trial when the org already used its app-side trial', function () {
    dpPlan('starter', 'yearly', 'price_test_starter_y', 29000);
    [, $org, $token] = dpOwner('dp-notrial@example.com');
    $org->forceFill(['trial_started_at' => now()->subDays(20), 'trial_ends_at' => now()->subDays(13)])->save();
    dpMockStripe($params);

    $this->withToken($token)->postJson('/api/billing/stripe/checkout', ['plan' => 'starter', 'kind' => 'yearly'])
        ->assertOk()->assertJson(['trial_days' => 0]);

    expect($params['subscription_data'])->not->toHaveKey('trial_period_days')
        ->and($params['subscription_data'])->not->toHaveKey('trial_end');
});

it('carries only the remaining in-app trial into Stripe (never a second trial)', function () {
    dpPlan('growth', 'monthly', 'price_test_growth_m', 6900);
    [, $org, $token] = dpOwner('dp-midtrial@example.com');
    $ends = now()->addDays(4)->startOfSecond();
    $org->forceFill(['trial_started_at' => now()->subDays(3), 'trial_ends_at' => $ends, 'status' => 'trialing'])->save();
    dpMockStripe($params);

    $this->withToken($token)->postJson('/api/billing/stripe/checkout', ['plan' => 'growth', 'kind' => 'monthly'])->assertOk();

    expect($params['subscription_data']['trial_end'])->toBe($ends->getTimestamp())
        ->and($params['subscription_data'])->not->toHaveKey('trial_period_days');
});

it('creates a one-time LTD checkout (mode=payment, no trial) holding a shared Founding Angel slot', function () {
    dpPlan('angel_solo', 'lifetime', 'price_test_angel_solo', 14900, false);
    [, $org, $token] = dpOwner('dp-ltd@example.com');
    dpMockStripe($params);

    $this->withToken($token)->postJson('/api/billing/stripe/checkout', ['plan' => 'angel_solo', 'kind' => 'lifetime'])->assertOk();

    $reservation = LtdCheckoutReservation::where('organization_id', $org->id)->first();
    expect($params['mode'])->toBe('payment')
        ->and($params['automatic_tax'])->toBe(['enabled' => true])
        ->and($params)->not->toHaveKey('subscription_data')
        ->and($params['metadata']['ltd_reservation_id'])->toBe((string) $reservation->id)
        ->and($reservation->status)->toBe('reserved');
});

it('releases an LTD reservation when Stripe customer creation fails', function () {
    dpPlan('angel_solo', 'lifetime', 'price_test_angel_solo', 14900, false);
    [, $org, $token] = dpOwner('dp-ltd-customer-failure@example.com');

    $mock = Mockery::mock(StripeService::class)->makePartial();
    $mock->shouldReceive('customerId')->andThrow(new RuntimeException('stripe unavailable'));
    app()->instance(StripeService::class, $mock);

    $this->withToken($token)->postJson('/api/billing/stripe/checkout', [
        'plan' => 'angel_solo', 'kind' => 'lifetime',
    ])->assertStatus(502);

    expect(LtdCheckoutReservation::where('organization_id', $org->id)->first()?->status)->toBe('released');
});

it('refuses a Stripe LTD checkout once the 100 spots are sold across both providers', function () {
    dpPlan('angel_solo', 'lifetime', 'price_test_angel_solo', 14900, false);
    [, , $token] = dpOwner('dp-soldout@example.com');
    app(LtdCampaignService::class)->campaign()->forceFill(['successful_sales' => 100])->save();
    dpMockStripe($params);

    $this->withToken($token)->postJson('/api/billing/stripe/checkout', ['plan' => 'angel_solo', 'kind' => 'lifetime'])
        ->assertStatus(422)->assertJson(['code' => 'ltd_sold_out']);
    expect($params)->toBeNull();
});

it('keeps the plan checkout endpoint from selling credit packs', function () {
    [, , $token] = dpOwner('dp-credits@example.com');
    Plan::create(['name' => 'Credits', 'slug' => 'insight_credits', 'is_active' => false]);
    dpMockStripe($params);

    $this->withToken($token)->postJson('/api/billing/stripe/checkout', ['plan' => 'insight_credits', 'kind' => 'credit_pack'])
        ->assertStatus(422)->assertJson(['code' => 'provider_not_supported']);
});

it('returns 422 when Stripe is not configured instead of calling it', function () {
    config()->set('billing.stripe.secret', null);
    config()->set('insightistic.stripe.secret', null);
    dpPlan('starter', 'monthly', 'price_test_starter_m', 2900);
    [, , $token] = dpOwner('dp-unconfigured@example.com');

    $this->withToken($token)->postJson('/api/billing/stripe/checkout', ['plan' => 'starter', 'kind' => 'monthly'])
        ->assertStatus(422)->assertJson(['code' => 'stripe_not_configured']);
});

it('refuses live Stripe keys unless explicitly allowed', function () {
    config()->set('billing.stripe.secret', 'sk_live_should_not_be_used');
    config()->set('billing.stripe.allow_live', false);
    [, $org] = makeOrgUser('dp-live@example.com');

    expect(StripeService::mode())->toBe('live')
        ->and(fn () => app(StripeService::class)->customerId($org))->toThrow(RuntimeException::class, 'live keys are disabled');
});

it('lists both providers for the customer to choose', function () {
    config()->set('feature-flags.paddle', true);
    config()->set('billing.paddle.client_token', 'test_tok');
    [, , $token] = dpOwner('dp-providers@example.com');

    $ids = collect($this->withToken($token)->getJson('/api/billing/providers')->assertOk()->json('providers'))->pluck('id')->all();
    expect($ids)->toContain('stripe');
});

// ---------------------------------------------------------------- webhooks

it('projects a Stripe subscription onto the same entitlement service and tags the provider', function () {
    $plan = dpPlan('growth', 'monthly', 'price_test_growth_m', 6900);
    [, $org] = dpOwner('dp-wh-sub@example.com');
    $org->forceFill(['stripe_customer_id' => 'cus_wh_sub'])->save();

    dpWebhook($this, 'evt_sub_1', 'customer.subscription.created', [
        'id' => 'sub_test_1', 'object' => 'subscription', 'customer' => 'cus_wh_sub', 'status' => 'trialing',
        'trial_end' => now()->addDays(7)->getTimestamp(), 'cancel_at_period_end' => false,
        'metadata' => ['organization_id' => (string) $org->id],
        'items' => ['data' => [['price' => ['id' => 'price_test_growth_m'],
            'current_period_start' => now()->getTimestamp(), 'current_period_end' => now()->addDays(7)->getTimestamp()]]],
    ])->assertOk()->assertJson(['duplicate' => false]);

    $sub = Subscription::withoutGlobalScope('organization')->where('organization_id', $org->id)->first();
    $org->refresh();
    expect($sub->billing_provider)->toBe('stripe')
        ->and($sub->status)->toBe('trialing')
        ->and($sub->plan_id)->toBe($plan->id)
        ->and($sub->current_period_end)->not->toBeNull()
        ->and($org->billing_provider)->toBe('stripe')
        ->and(app(EntitlementResolver::class)->resolve($org)['plan']['slug'])->toBe('growth');
});

it('acknowledges a duplicate event id without processing it twice', function () {
    dpPlan('starter', 'monthly', 'price_test_starter_m', 2900);
    [, $org] = dpOwner('dp-dupe@example.com');
    $payload = ['id' => 'in_dupe', 'object' => 'invoice', 'customer' => 'cus_dupe', 'subscription' => 'sub_dupe',
        'metadata' => ['organization_id' => (string) $org->id], 'currency' => 'usd', 'total' => 2900, 'subtotal' => 2900, 'tax' => 0, 'number' => 'INV-1'];

    dpWebhook($this, 'evt_dupe', 'invoice.paid', $payload)->assertOk()->assertJson(['duplicate' => false]);
    dpWebhook($this, 'evt_dupe', 'invoice.paid', $payload)->assertOk()->assertJson(['duplicate' => true]);

    expect(StripeWebhookEvent::where('stripe_event_id', 'evt_dupe')->count())->toBe(1)
        ->and(BillingTransaction::withoutGlobalScope('organization')->where('provider_transaction_id', 'in_dupe')->count())->toBe(1);
});

it('leaves a failed webhook retryable instead of permanently claiming its event id', function () {
    $attempts = 0;
    $processor = Mockery::mock(\App\Services\StripeWebhookProcessor::class, [
        app(\App\Services\LicenseService::class),
        app(\App\Services\LifecycleMailer::class),
    ])->makePartial();
    $processor->shouldReceive('process')->twice()->andReturnUsing(function () use (&$attempts): void {
        if (++$attempts === 1) {
            throw new RuntimeException('temporary projection failure');
        }
    });
    $event = \Stripe\Event::constructFrom([
        'id' => 'evt_retryable', 'object' => 'event', 'type' => 'test.noop',
        'data' => ['object' => []],
    ]);

    expect(fn () => $processor->processOnce($event))->toThrow(RuntimeException::class, 'temporary projection failure')
        ->and(StripeWebhookEvent::where('stripe_event_id', 'evt_retryable')->exists())->toBeFalse()
        ->and($processor->processOnce($event))->toBeTrue()
        ->and(StripeWebhookEvent::where('stripe_event_id', 'evt_retryable')->whereNotNull('processed_at')->exists())->toBeTrue();
});

it('marks past_due on invoice.payment_failed and clears it on invoice.paid', function () {
    [, $org] = dpOwner('dp-dunning@example.com');
    $org->forceFill(['stripe_customer_id' => 'cus_dun'])->save();
    Subscription::create(['organization_id' => $org->id, 'billing_provider' => 'stripe', 'stripe_customer_id' => 'cus_dun',
        'stripe_subscription_id' => 'sub_dun', 'status' => 'active', 'plan_id' => $org->plan_id]);

    dpWebhook($this, 'evt_fail', 'invoice.payment_failed', ['id' => 'in_f', 'object' => 'invoice', 'customer' => 'cus_dun'])->assertOk();
    expect($org->fresh()->status)->toBe('past_due');

    Subscription::withoutGlobalScope('organization')->where('organization_id', $org->id)->update(['status' => 'past_due']);
    dpWebhook($this, 'evt_paid', 'invoice.paid', ['id' => 'in_p', 'object' => 'invoice', 'customer' => 'cus_dun',
        'subscription' => 'sub_dun', 'currency' => 'usd', 'total' => 2900, 'subtotal' => 2900, 'tax' => 0,
        'hosted_invoice_url' => 'https://invoice.stripe.com/i/test'])->assertOk();

    expect($org->fresh()->status)->toBe('active')
        ->and(Subscription::withoutGlobalScope('organization')->where('organization_id', $org->id)->value('status'))->toBe('active');
});

it('fulfils a Stripe LTD purchase into the shared cap, is idempotent, and revokes on full refund', function () {
    $plan = dpPlan('angel_studio', 'lifetime', 'price_test_angel_studio', 29900, false);
    [, $org] = dpOwner('dp-wh-ltd@example.com');
    $before = (int) app(LtdCampaignService::class)->campaign()->successful_sales;
    $reservation = app(LtdCampaignService::class)->reserve($org, 'angel_studio', (string) \Illuminate\Support\Str::uuid());

    $session = ['id' => 'cs_test_ltd', 'object' => 'checkout.session', 'mode' => 'payment', 'payment_status' => 'paid',
        'customer' => 'cus_ltd', 'payment_intent' => 'pi_test_ltd', 'currency' => 'usd',
        'amount_subtotal' => 29900, 'amount_total' => 29900, 'total_details' => ['amount_tax' => 0],
        'metadata' => ['organization_id' => (string) $org->id, 'plan_id' => (string) $plan->id, 'purchase_kind' => 'lifetime',
            'ltd_reservation_id' => (string) $reservation->id]];

    dpWebhook($this, 'evt_ltd_1', 'checkout.session.completed', $session)->assertOk();
    dpWebhook($this, 'evt_ltd_1', 'checkout.session.completed', $session)->assertOk()->assertJson(['duplicate' => true]);
    // Even a re-sent event with a NEW id must not count twice.
    dpWebhook($this, 'evt_ltd_2', 'checkout.session.completed', $session)->assertOk();

    $campaign = LtdCampaign::first();
    expect($campaign->successful_sales)->toBe($before + 1)
        ->and($reservation->fresh()->status)->toBe('consumed')
        ->and(EntitlementGrant::withoutGlobalScope('organization')->where('source', 'stripe_lifetime')->count())->toBe(1)
        ->and(app(EntitlementResolver::class)->resolve($org->fresh())['source'])->toBe('lifetime')
        ->and(app(TrialService::class)->eligibility($org->fresh())['eligible'])->toBeFalse();

    dpWebhook($this, 'evt_refund', 'charge.refunded', ['id' => 'ch_test_ltd', 'object' => 'charge',
        'payment_intent' => 'pi_test_ltd', 'refunded' => true, 'amount_refunded' => 29900])->assertOk();

    expect(EntitlementGrant::withoutGlobalScope('organization')->where('source', 'stripe_lifetime')->whereNull('revoked_at')->count())->toBe(0)
        ->and(BillingTransaction::withoutGlobalScope('organization')->where('provider_transaction_id', 'cs_test_ltd')->value('status'))->toBe('refunded');
});

it('fulfils an asynchronous Stripe LTD payment and releases failed async payments', function () {
    $plan = dpPlan('angel_studio', 'lifetime', 'price_test_angel_studio', 29900, false);
    [, $org] = dpOwner('dp-wh-async-ltd@example.com');
    $reservation = app(LtdCampaignService::class)->reserve($org, 'angel_studio', (string) \Illuminate\Support\Str::uuid());
    $session = ['id' => 'cs_test_async_ltd', 'object' => 'checkout.session', 'mode' => 'payment', 'payment_status' => 'paid',
        'customer' => 'cus_async_ltd', 'payment_intent' => 'pi_async_ltd', 'currency' => 'usd',
        'amount_subtotal' => 29900, 'amount_total' => 29900, 'total_details' => ['amount_tax' => 0],
        'metadata' => ['organization_id' => (string) $org->id, 'plan_id' => (string) $plan->id,
            'ltd_reservation_id' => (string) $reservation->id]];

    dpWebhook($this, 'evt_async_ltd', 'checkout.session.async_payment_succeeded', $session)->assertOk();
    expect($reservation->fresh()->status)->toBe('consumed')
        ->and(EntitlementGrant::withoutGlobalScope('organization')->where('source', 'stripe_lifetime')->count())->toBe(1);

    [, $failedOrg] = dpOwner('dp-wh-async-failed@example.com');
    $failedReservation = app(LtdCampaignService::class)->reserve($failedOrg, 'angel_studio', (string) \Illuminate\Support\Str::uuid());
    dpWebhook($this, 'evt_async_ltd_failed', 'checkout.session.async_payment_failed', [
        'id' => 'cs_test_async_failed', 'object' => 'checkout.session', 'mode' => 'payment',
        'metadata' => ['ltd_reservation_id' => (string) $failedReservation->id],
    ])->assertOk();
    expect($failedReservation->fresh()->status)->toBe('released');
});

it('releases the Founding Angel slot when an LTD checkout session expires', function () {
    dpPlan('angel_solo', 'lifetime', 'price_test_angel_solo', 14900, false);
    [, $org] = dpOwner('dp-expire@example.com');
    $reservation = app(LtdCampaignService::class)->reserve($org, 'angel_solo', (string) \Illuminate\Support\Str::uuid());

    dpWebhook($this, 'evt_exp', 'checkout.session.expired', ['id' => 'cs_exp', 'object' => 'checkout.session', 'mode' => 'payment',
        'metadata' => ['ltd_reservation_id' => (string) $reservation->id]])->assertOk();

    expect($reservation->fresh()->status)->toBe('released');
});

it('marks the app-side trial used when a Stripe trial checkout completes', function () {
    $plan = dpPlan('starter', 'monthly', 'price_test_starter_m', 2900);
    [, $org] = dpOwner('dp-trialused@example.com');

    dpWebhook($this, 'evt_cs_trial', 'checkout.session.completed', ['id' => 'cs_trial', 'object' => 'checkout.session',
        'mode' => 'subscription', 'payment_status' => 'no_payment_required', 'customer' => 'cus_trial', 'subscription' => 'sub_trial',
        'metadata' => ['organization_id' => (string) $org->id, 'plan_id' => (string) $plan->id, 'plan_slug' => 'starter', 'trial_days' => '7']])->assertOk();

    $org->refresh();
    expect($org->trial_started_at)->not->toBeNull()
        ->and(Subscription::withoutGlobalScope('organization')->where('organization_id', $org->id)->value('status'))->toBe('trialing')
        ->and(app(TrialService::class)->eligibility($org)['eligible'])->toBeFalse();
});

// ---------------------------------------------------------------- portal + summary

it('opens the Stripe Customer Portal for a Stripe customer', function () {
    config()->set('billing.stripe.portal_configuration', 'bpc_test');
    [, $org, $token] = dpOwner('dp-portal@example.com');
    $org->forceFill(['billing_provider' => 'stripe', 'stripe_customer_id' => 'cus_portal'])->save();

    $mock = Mockery::mock(StripeService::class)->makePartial();
    $mock->shouldReceive('portalUrl')->once()->with('cus_portal', Mockery::type('string'))->andReturn('https://billing.stripe.com/p/session/test');
    app()->instance(StripeService::class, $mock);

    $this->withToken($token)->postJson('/api/billing/portal')->assertOk()->assertJson(['url' => 'https://billing.stripe.com/p/session/test']);
});

it('keeps the Paddle portal for Paddle customers', function () {
    [, $org, $token] = dpOwner('dp-paddle-portal@example.com');
    $org->forceFill(['billing_provider' => 'paddle', 'paddle_customer_id' => 'ctm_test'])->save();
    Subscription::create(['organization_id' => $org->id, 'billing_provider' => 'paddle', 'provider_customer_id' => 'ctm_test', 'status' => 'active']);

    $paddle = Mockery::mock(\App\Services\Billing\PaddleClient::class);
    $paddle->shouldReceive('customerPortalSession')->once()->with('ctm_test')->andReturn('https://customer-portal.paddle.com/test');
    app()->instance(\App\Services\Billing\PaddleClient::class, $paddle);
    $stripe = Mockery::mock(StripeService::class)->makePartial();
    $stripe->shouldNotReceive('portalUrl');
    app()->instance(StripeService::class, $stripe);

    $this->withToken($token)->postJson('/api/billing/portal')->assertOk()->assertJson(['url' => 'https://customer-portal.paddle.com/test']);
});

it('returns one provider-neutral billing summary with invoices', function () {
    [, $org, $token] = dpOwner('dp-summary@example.com');
    $org->forceFill(['billing_provider' => 'stripe', 'stripe_customer_id' => 'cus_sum'])->save();
    Subscription::create(['organization_id' => $org->id, 'billing_provider' => 'stripe', 'stripe_customer_id' => 'cus_sum',
        'stripe_subscription_id' => 'sub_sum', 'status' => 'active', 'plan_id' => $org->plan_id,
        'current_period_end' => now()->addMonth(), 'next_billed_at' => now()->addMonth()]);
    BillingTransaction::create(['organization_id' => $org->id, 'provider' => 'stripe', 'provider_transaction_id' => 'in_sum',
        'kind' => 'subscription', 'status' => 'completed', 'currency' => 'USD', 'total_minor' => 2900, 'invoice_number' => 'INV-9',
        'occurred_at' => now(), 'summary_json' => ['hosted_invoice_url' => 'https://invoice.stripe.com/i/x']]);

    $this->withToken($token)->getJson('/api/billing/summary')->assertOk()
        ->assertJsonPath('provider', 'stripe')
        ->assertJsonPath('status', 'active')
        ->assertJsonPath('portal.type', 'stripe_portal')
        ->assertJsonPath('invoices.0.number', 'INV-9')
        ->assertJsonPath('invoices.0.url', 'https://invoice.stripe.com/i/x');
});

// ---------------------------------------------------------------- migration backfill

it('backfills billing_provider reversibly', function () {
    $migration = require database_path('migrations/2026_11_04_000002_add_billing_provider_to_organizations.php');
    [, $paddleOrg] = makeOrgUser('bf-paddle@example.com');
    [, $stripeOrg] = makeOrgUser('bf-stripe@example.com');
    [, $freeOrg] = makeOrgUser('bf-free@example.com');

    $migration->down();
    DB::table('organizations')->where('id', $paddleOrg->id)->update(['paddle_customer_id' => 'ctm_bf']);
    DB::table('subscriptions')->insert(['organization_id' => $stripeOrg->id, 'status' => 'active', 'stripe_customer_id' => 'cus_bf',
        'stripe_subscription_id' => 'sub_bf', 'created_at' => now(), 'updated_at' => now()]);
    $migration->up();

    expect(DB::table('organizations')->where('id', $paddleOrg->id)->value('billing_provider'))->toBe('paddle')
        ->and(DB::table('organizations')->where('id', $stripeOrg->id)->value('billing_provider'))->toBe('stripe')
        ->and(DB::table('organizations')->where('id', $stripeOrg->id)->value('stripe_customer_id'))->toBe('cus_bf')
        ->and(DB::table('organizations')->where('id', $freeOrg->id)->value('billing_provider'))->toBeNull()
        ->and(DB::table('subscriptions')->where('organization_id', $stripeOrg->id)->value('billing_provider'))->toBe('stripe');

    $migration->down();
    expect(\Illuminate\Support\Facades\Schema::hasColumn('organizations', 'billing_provider'))->toBeFalse()
        ->and(DB::table('subscriptions')->where('organization_id', $stripeOrg->id)->value('billing_provider'))->toBeNull();
    $migration->up();
});

// ---------------------------------------------------------------- admin health

it('reports both providers on System Health without exposing keys', function () {
    config()->set('billing.stripe.secret', null);
    config()->set('insightistic.stripe.secret', null);
    $admin = \App\Models\User::create(['name' => 'SA', 'email' => 'dp-sa@example.com', 'password' => 'secret-password', 'status' => 'active']);
    $admin->forceFill(['is_super_admin' => true])->save();
    DB::table('stripe_webhook_events')->insert(['stripe_event_id' => 'evt_h', 'type' => 'invoice.paid', 'created_at' => now()]);

    $body = $this->withToken($admin->createToken('t')->plainTextToken)->getJson('/api/admin/system-health')->assertOk()->json();

    expect($body['stripe'])->toHaveKeys(['configured', 'api', 'webhook_signing_configured', 'last_webhook_at', 'webhook_recent'])
        ->and($body['stripe']['webhook_recent'])->toBeTrue()
        ->and($body['stripe']['api'])->toBe('not_configured')
        ->and($body['paddle'])->toHaveKeys(['configured', 'api', 'last_webhook_at'])
        ->and(json_encode($body))->not->toContain('whsec_')
        ->and(json_encode($body))->not->toContain('sk_test');
});

it('seeds the Stripe test catalog onto plan_prices with amounts matching config/commercial.php', function () {
    foreach (['starter', 'growth', 'agency', 'agency_pro', 'angel_solo', 'angel_studio', 'angel_agency'] as $slug) {
        Plan::create(['name' => $slug, 'slug' => $slug, 'is_active' => ! str_starts_with($slug, 'angel_')]);
    }
    $this->seed(\Database\Seeders\StripeCatalogSeeder::class);
    $this->seed(\Database\Seeders\StripeCatalogSeeder::class); // idempotent

    $rows = PlanPrice::where('provider', 'stripe')->get();
    expect($rows)->toHaveCount(11)
        ->and($rows->firstWhere('provider_price_id', 'price_1UXta9BUynykllDPyWmqYZdR')->amount_minor)->toBe(29900)
        ->and($rows->where('kind', 'lifetime')->pluck('amount_minor')->sort()->values()->all())->toBe([14900, 29900, 69900]);
});

it('refuses to seed test catalog ids while a live key is configured', function () {
    config()->set('billing.stripe.secret', 'sk_live_x');
    $this->seed(\Database\Seeders\StripeCatalogSeeder::class); // no live file → skip, nothing mapped
    expect(PlanPrice::where('provider', 'stripe')->count())->toBe(0);
});
