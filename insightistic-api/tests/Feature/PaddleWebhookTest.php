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

/** Build the adjustment entity delivered for a refund or chargeback. */
function paddleAdjustmentData(string $transactionId, array $overrides = []): array
{
    return array_merge([
        'id'             => 'adj_pdl_01',
        'action'         => 'refund',
        'type'           => 'full',
        'transaction_id' => $transactionId,
        'status'         => 'pending_approval',
        'updated_at'     => '2026-09-19T10:01:00.000Z',
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

it('revokes a lifetime grant only after an approved full adjustment and stays safe on replay', function () {
    config()->set('billing.paddle.webhook_secret', 'pdlwh_test');

    paddlePlanPrice('lifetime', 'pri_pdl_lifetime');
    [, $org] = makeOrgUser('paddle-refund@example.com');

    $transaction = paddleTransactionData('lifetime', [
        'custom_data' => ['organization_id' => $org->id],
    ]);
    $processor = app(PaddleEventProcessor::class);
    $processor->process('transaction.completed', $transaction);

    $grant = EntitlementGrant::withoutGlobalScope('organization')->firstOrFail();
    expect($grant->isActive())->toBeTrue();

    // A refund awaiting Paddle approval must not remove access.
    $pending = paddleAdjustmentData('txn_pdl_lifetime');
    $processor->process('adjustment.created', $pending);
    expect($grant->refresh()->isActive())->toBeTrue();

    // Approval revokes once; duplicate adjustment.updated delivery is a no-op.
    $approved = array_merge($pending, [
        'status'     => 'approved',
        'updated_at' => '2026-09-19T10:02:00.000Z',
    ]);
    $processor->process('adjustment.updated', $approved);
    $processor->process('adjustment.updated', $approved);

    expect($grant->refresh()->isActive())->toBeFalse()
        ->and($grant->revoked_at)->not->toBeNull()
        ->and(BillingTransaction::withoutGlobalScope('organization')->firstOrFail()->status)->toBe('refunded');

    // A full transaction replay retains the refunded ledger state and cannot
    // fulfil a second entitlement.
    $processor->process('transaction.completed', $transaction);
    expect(EntitlementGrant::withoutGlobalScope('organization')->count())->toBe(1)
        ->and(BillingTransaction::withoutGlobalScope('organization')->firstOrFail()->status)->toBe('refunded');
});

it('does not revoke a lifetime grant for a partial approved adjustment', function () {
    paddlePlanPrice('lifetime', 'pri_pdl_lifetime');
    [, $org] = makeOrgUser('paddle-partial-refund@example.com');

    $transaction = paddleTransactionData('lifetime', [
        'custom_data' => ['organization_id' => $org->id],
    ]);
    $processor = app(PaddleEventProcessor::class);
    $processor->process('transaction.completed', $transaction);
    $processor->process('adjustment.updated', paddleAdjustmentData('txn_pdl_lifetime', [
        'status' => 'approved',
        'type'   => 'partial',
    ]));

    expect(EntitlementGrant::withoutGlobalScope('organization')->firstOrFail()->isActive())->toBeTrue();
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
