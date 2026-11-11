<?php

use App\Domain\Billing\PaddleEventProcessor;
use App\Jobs\ProcessPaddleEvent;
use App\Models\BillingEvent;
use App\Models\BillingTransaction;
use App\Models\CreditLedgerEntry;
use App\Models\EntitlementGrant;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Subscription;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * Paddle webhook signature verification, idempotency and projection
 * (roadmap 23-STRIPE-TO-PADDLE-MIGRATION). Mirrors StripeWebhookTest's
 * approach: HTTP-level tests for signing/dedup, direct processor calls for
 * state projection so replay-safety is asserted without a queue.
 *
 * Both properties are load-bearing: an unverified webhook lets anyone move a
 * tenant to any plan, and a non-idempotent one lets Paddle's own retries
 * reprocess an event. Paddle redelivers in normal operation.
 */

/** Build a realistic Paddle subscription entity payload. */
function paddleSubscriptionData(array $overrides = []): array
{
    return array_merge([
        'id'                     => 'sub_pdl_01',
        'status'                 => 'active',
        'customer_id'            => 'ctm_pdl_01',
        'currency_code'          => 'USD',
        'items'                  => [['price' => ['id' => 'pri_pdl_01'], 'quantity' => 1]],
        'current_billing_period' => [
            'starts_at' => '2026-09-20T00:00:00.000Z',
            'ends_at'   => '2026-10-20T00:00:00.000Z',
        ],
        'next_billed_at'         => '2026-10-20T00:00:00.000Z',
        'scheduled_change'       => null,
        'custom_data'            => ['organization_id' => 1],
    ], $overrides);
}

/** Build a realistic Paddle transaction entity payload for a given kind. */
function paddleTransactionData(string $kind, array $overrides = []): array
{
    $priceId = 'pri_pdl_' . $kind;

    return array_merge([
        'id'             => 'txn_pdl_' . $kind,
        'status'         => 'completed',
        'customer_id'    => 'ctm_pdl_01',
        'subscription_id' => null,
        'invoice_id'     => 'inv_01',
        'currency_code'  => 'USD',
        'items'          => [['price' => ['id' => $priceId], 'quantity' => 1]],
        'details'        => [
            'totals' => ['subtotal' => '49900', 'tax' => '0', 'total' => '49900', 'currency_code' => 'USD'],
        ],
        'custom_data'    => ['organization_id' => 1],
        'billed_at'      => '2026-09-19T10:00:00.000Z',
        'created_at'     => '2026-09-19T09:59:00.000Z',
        'updated_at'     => '2026-09-19T10:00:00.000Z',
    ], $overrides);
}

/** Wrap a provider entity in the Paddle event envelope. */
function paddleEvent(string $type, array $data, string $eventId): array
{
    return [
        'event_id'    => $eventId,
        'event_type'  => $type,
        'occurred_at' => '2026-09-19T10:00:00.000Z',
        'data'        => $data,
    ];
}

/** Sign an event envelope the way Paddle signs a delivery (ts:body HMAC). */
function signedPaddleDelivery(array $event, string $secret, ?int $timestamp = null): array
{
    $body = json_encode($event);
    $timestamp ??= time();
    $signature = hash_hmac('sha256', $timestamp . ':' . $body, $secret);

    return [$body, "ts={$timestamp};h1={$signature}"];
}

/** POST a signed envelope to the Paddle webhook endpoint. */
function postPaddleWebhook(string $body, string $signature)
{
    return test()->call('POST', '/api/billing/webhook/paddle', [], [], [], [
        'CONTENT_TYPE'          => 'application/json',
        'HTTP_PADDLE_SIGNATURE' => $signature,
    ], $body);
}

/** A plan with one active Paddle price mapping. */
function paddlePlanPrice(string $kind, string $priceId, array $metadata = []): Plan
{
    $plan = Plan::create([
        'name'      => 'Paddle ' . ucfirst($kind) . ' ' . Str::random(4),
        'slug'      => 'paddle-' . $kind . '-' . Str::random(6),
        'is_active' => true,
    ]);

    PlanPrice::create([
        'plan_id'           => $plan->id,
        'provider'          => 'paddle',
        'kind'              => $kind,
        'provider_price_id' => $priceId,
        'currency'          => 'USD',
        'amount_minor'      => 49900,
        'is_active'         => true,
        'metadata_json'     => $metadata ?: null,
    ]);

    return $plan;
}

it('rejects a webhook with a missing or forged signature', function () {
    config()->set('billing.paddle.webhook_secret', 'pdlwh_test');

    [$body] = signedPaddleDelivery(paddleEvent('subscription.updated', paddleSubscriptionData(), 'evt_bad_1'), 'pdlwh_test');

    // No signature at all.
    $this->call('POST', '/api/billing/webhook/paddle', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)
        ->assertStatus(400);

    // Signature computed with the wrong secret.
    [$body2, $sig2] = signedPaddleDelivery(paddleEvent('subscription.updated', paddleSubscriptionData(), 'evt_bad_2'), 'pdlwh_wrong');

    postPaddleWebhook($body2, $sig2)->assertStatus(400);

    expect(BillingEvent::count())->toBe(0);
});

it('accepts a signed subscription event, records it pending and dispatches the job', function () {
    config()->set('billing.paddle.webhook_secret', 'pdlwh_test');
    Queue::fake();

    [, $org] = makeOrgUser('paddle-accept@example.com');

    $data = paddleSubscriptionData(['custom_data' => ['organization_id' => $org->id]]);
    [$body, $sig] = signedPaddleDelivery(paddleEvent('subscription.updated', $data, 'evt_sub_ok'), 'pdlwh_test');

    postPaddleWebhook($body, $sig)
        ->assertOk()
        ->assertJson(['received' => true, 'duplicate' => false]);

    $row = BillingEvent::firstOrFail();
    expect($row->provider_event_id)->toBe('evt_sub_ok')
        ->and($row->event_type)->toBe('subscription.updated')
        ->and($row->processing_status)->toBe('pending')
        ->and($row->payload_hash)->toBe(hash('sha256', $body));

    Queue::assertPushed(ProcessPaddleEvent::class, fn (ProcessPaddleEvent $job) => $job->event->provider_event_id === 'evt_sub_ok');

    // Projection, run the way the job would (mirrors StripeWebhookTest
    // calling the processor directly): the subscription lands on the org.
    app(PaddleEventProcessor::class)->process('subscription.updated', $data);

    $sub = Subscription::withoutGlobalScope('organization')->where('provider_subscription_id', 'sub_pdl_01')->firstOrFail();
    expect($sub->billing_provider)->toBe('paddle')
        ->and($sub->status)->toBe('active')
        ->and($sub->organization_id)->toBe($org->id)
        ->and($sub->provider_customer_id)->toBe('ctm_pdl_01')
        ->and($sub->current_period_end?->format('Y-m-d'))->toBe('2026-10-20')
        ->and($org->fresh()->status)->toBe('active');
});

it('marks an accepted Paddle event processed after the queued projector succeeds', function () {
    config()->set('billing.paddle.webhook_secret', 'pdlwh_test');

    [, $org] = makeOrgUser('paddle-process@example.com');
    $data = paddleSubscriptionData(['custom_data' => ['organization_id' => $org->id]]);
    [$body, $sig] = signedPaddleDelivery(paddleEvent('subscription.updated', $data, 'evt_process_ok'), 'pdlwh_test');

    postPaddleWebhook($body, $sig)->assertOk();

    $event = BillingEvent::firstOrFail();
    (new ProcessPaddleEvent($event, $data))->handle(app(PaddleEventProcessor::class));

    expect($event->fresh()->processing_status)->toBe('processed')
        ->and($event->fresh()->processed_at)->not->toBeNull()
        ->and($event->fresh()->attempts)->toBe(0);
});

it('marks a Paddle event with no organization as terminal without retrying it', function () {
    config()->set('billing.paddle.webhook_secret', 'pdlwh_test');
    Queue::fake();

    $data = paddleTransactionData('lifetime', ['custom_data' => null]);
    [$body, $sig] = signedPaddleDelivery(
        paddleEvent('transaction.completed', $data, 'evt_missing_org'),
        'pdlwh_test'
    );

    postPaddleWebhook($body, $sig)->assertOk();

    $event = BillingEvent::firstOrFail();
    (new ProcessPaddleEvent($event, $data))->handle(app(PaddleEventProcessor::class));

    $event = $event->fresh();
    expect($event->processing_status)->toBe('failed')
        ->and($event->last_error)->toContain('no resolvable organization')
        ->and($event->attempts)->toBe(1)
        ->and($event->processed_at)->toBeNull();
});

it('processes a redelivered event only once', function () {
    config()->set('billing.paddle.webhook_secret', 'pdlwh_test');
    Queue::fake();

    [, $org] = makeOrgUser('paddle-dup@example.com');

    $data = paddleSubscriptionData(['custom_data' => ['organization_id' => $org->id]]);
    [$body, $sig] = signedPaddleDelivery(paddleEvent('subscription.updated', $data, 'evt_dup'), 'pdlwh_test');

    postPaddleWebhook($body, $sig)->assertOk()->assertJson(['duplicate' => false]);

    // Paddle retries the same event id — acknowledged, not reprocessed.
    postPaddleWebhook($body, $sig)->assertOk()->assertJson(['duplicate' => true]);

    expect(BillingEvent::where('provider_event_id', 'evt_dup')->count())->toBe(1);
    Queue::assertPushed(ProcessPaddleEvent::class, 1);
});

it('grants lifetime access once for a completed purchase, never twice on replay', function () {
    config()->set('billing.paddle.webhook_secret', 'pdlwh_test');
    Queue::fake();

    $plan = paddlePlanPrice('lifetime', 'pri_pdl_lifetime');
    [, $org] = makeOrgUser('paddle-ltd@example.com');

    $data = paddleTransactionData('lifetime', ['custom_data' => ['organization_id' => $org->id]]);
    [$body, $sig] = signedPaddleDelivery(paddleEvent('transaction.completed', $data, 'evt_ltd'), 'pdlwh_test');

    postPaddleWebhook($body, $sig)->assertOk();

    // First processing grants; a replay (retried job, admin re-run) is a no-op.
    $processor = app(PaddleEventProcessor::class);
    $processor->process('transaction.completed', $data);
    $processor->process('transaction.completed', $data);

    expect(EntitlementGrant::withoutGlobalScope('organization')->count())->toBe(1);

    $grant = EntitlementGrant::withoutGlobalScope('organization')->firstOrFail();
    expect($grant->source)->toBe('paddle_lifetime')
        ->and($grant->source_reference)->toBe('txn_pdl_lifetime')
        ->and($grant->plan_id)->toBe($plan->id)
        ->and($grant->isActive())->toBeTrue();

    $txn = BillingTransaction::withoutGlobalScope('organization')->firstOrFail();
    expect($txn->kind)->toBe('lifetime')
        ->and($txn->status)->toBe('completed')
        ->and($txn->total_minor)->toBe(49900)
        ->and($txn->invoice_number)->toBe('inv_01');
});

it('credits a credit pack exactly once even when the event replays', function () {
    config()->set('billing.paddle.webhook_secret', 'pdlwh_test');
    Queue::fake();

    paddlePlanPrice('credit_pack', 'pri_pdl_credit_pack', ['credits' => 100]);
    [, $org] = makeOrgUser('paddle-credits@example.com');

    $data = paddleTransactionData('credit_pack', ['custom_data' => ['organization_id' => $org->id]]);
    [$body, $sig] = signedPaddleDelivery(paddleEvent('transaction.completed', $data, 'evt_credits'), 'pdlwh_test');

    postPaddleWebhook($body, $sig)->assertOk();

    $processor = app(PaddleEventProcessor::class);
    $processor->process('transaction.completed', $data);
    $processor->process('transaction.completed', $data);

    expect(CreditLedgerEntry::withoutGlobalScope('organization')->count())->toBe(1)
        ->and(CreditLedgerEntry::withoutGlobalScope('organization')->sum('amount_signed'))->toBe(100);
});

it('records a payment failure without touching the subscription status', function () {
    config()->set('billing.paddle.webhook_secret', 'pdlwh_test');
    Queue::fake();

    [, $org] = makeOrgUser('paddle-fail@example.com');

    Subscription::withoutGlobalScope('organization')->create([
        'organization_id'          => $org->id,
        'billing_provider'         => 'paddle',
        'provider_subscription_id' => 'sub_pdl_fail',
        'provider_customer_id'     => 'ctm_pdl_fail',
        'status'                   => 'active',
    ]);

    $data = paddleTransactionData('subscription', [
        'subscription_id' => 'sub_pdl_fail',
        'status'          => 'past_due',
        'custom_data'     => ['organization_id' => $org->id],
    ]);
    [$body, $sig] = signedPaddleDelivery(paddleEvent('transaction.payment_failed', $data, 'evt_fail'), 'pdlwh_test');

    postPaddleWebhook($body, $sig)->assertOk();

    app(PaddleEventProcessor::class)->process('transaction.payment_failed', $data);

    // Financial record only — subscription events alone decide status.
    $txn = BillingTransaction::withoutGlobalScope('organization')->firstOrFail();
    expect($txn->status)->toBe('failed')
        ->and($txn->kind)->toBe('subscription');

    $sub = Subscription::withoutGlobalScope('organization')->where('provider_subscription_id', 'sub_pdl_fail')->firstOrFail();
    expect($sub->status)->toBe('active')
        ->and($org->fresh()->status)->toBe('active');
});

it('stores a subscription with an unmapped price without failing', function () {
    config()->set('billing.paddle.webhook_secret', 'pdlwh_test');
    Queue::fake();

    [, $org] = makeOrgUser('paddle-unmapped@example.com');

    $data = paddleSubscriptionData([
        'items'       => [['price' => ['id' => 'pri_never_mapped'], 'quantity' => 1]],
        'custom_data' => ['organization_id' => $org->id],
    ]);
    [$body, $sig] = signedPaddleDelivery(paddleEvent('subscription.updated', $data, 'evt_unmapped'), 'pdlwh_test');

    postPaddleWebhook($body, $sig)->assertOk();

    Log::spy();

    app(PaddleEventProcessor::class)->process('subscription.updated', $data);

    // The row lands, but the plan is never guessed from amount/product —
    // only the plan_prices mapping decides, and there isn't one.
    $sub = Subscription::withoutGlobalScope('organization')->where('provider_subscription_id', 'sub_pdl_01')->firstOrFail();
    expect($sub->plan_id)->toBeNull()
        ->and($sub->status)->toBe('active');

    Log::assertLogged('warning');
});

/** Regression (2026-11-04): each Paddle LTD sale used to consume two of the 100 spots. */
it('counts one Paddle LTD sale as exactly one Founding Angel spot, even on replay', function () {
    $plan = \App\Models\Plan::create(['name'=>'A','slug'=>'angel_solo','is_active'=>false]);
    \App\Models\PlanPrice::create(['plan_id'=>$plan->id,'provider'=>'paddle','kind'=>'lifetime','provider_price_id'=>'pri_pdl_lifetime','currency'=>'USD','amount_minor'=>14900,'is_active'=>true]);
    [, $org] = makeOrgUser('probe@example.com');
    $r = app(\App\Domain\Billing\LtdCampaignService::class)->reserve($org, 'angel_solo', (string) \Illuminate\Support\Str::uuid());
    $data = paddleTransactionData('lifetime', ['custom_data' => ['organization_id' => $org->id, 'ltd_reservation_id' => $r->id]]);
    app(\App\Domain\Billing\PaddleEventProcessor::class)->process('transaction.completed', $data);
    app(\App\Domain\Billing\PaddleEventProcessor::class)->process('transaction.completed', $data);
    expect(app(\App\Domain\Billing\LtdCampaignService::class)->campaign()->successful_sales)->toBe(1);
});

/** AppSumo 2026 (Option A): the second campaign counts its own sales only. */
it('counts an AppSumo LTD sale against appsumo_2026 and never the Founding Angel cap', function () {
    config()->set('feature-flags.ltd_appsumo', true);
    $plan = \App\Models\Plan::create(['name' => 'AppSumo Solo', 'slug' => 'appsumo_solo', 'is_active' => false]);
    \App\Models\PlanPrice::create(['plan_id' => $plan->id, 'provider' => 'paddle', 'kind' => 'lifetime', 'provider_price_id' => 'pri_pdl_appsumo', 'currency' => 'USD', 'amount_minor' => 7900, 'is_active' => true]);
    [, $org] = makeOrgUser('appsumo-ltd@example.com');
    $service = app(\App\Domain\Billing\LtdCampaignService::class);
    $reservation = $service->reserve($org, 'appsumo_solo', (string) \Illuminate\Support\Str::uuid());

    // The sale must carry the AppSumo price (the helper defaults to pri_pdl_lifetime,
    // which maps to no plan here, so the AppSumo path was never exercised).
    $data = paddleTransactionData('lifetime', [
        'id'          => 'txn_pdl_appsumo',
        'items'       => [['price' => ['id' => 'pri_pdl_appsumo'], 'quantity' => 1]],
        'details'     => ['totals' => ['subtotal' => '7900', 'tax' => '0', 'total' => '7900', 'currency_code' => 'USD']],
        'custom_data' => ['organization_id' => $org->id, 'ltd_reservation_id' => $reservation->id],
    ]);
    app(\App\Domain\Billing\PaddleEventProcessor::class)->process('transaction.completed', $data);
    app(\App\Domain\Billing\PaddleEventProcessor::class)->process('transaction.completed', $data);

    // AppSumo owns the sale; Founding Angel's count is untouched by it.
    expect(\App\Models\LtdCampaign::where('slug', 'appsumo_2026')->value('successful_sales'))->toBe(1)
        ->and($service->campaign()->successful_sales)->toBe(0);
});
