<?php

use App\Models\Organization;
use App\Models\Plan;
use App\Models\StripeWebhookEvent;
use App\Models\Subscription;
use App\Services\StripeWebhookProcessor;

/**
 * Stripe webhook signature verification and idempotency.
 *
 * Both are load-bearing: an unverified webhook lets anyone move a tenant to
 * any plan, and a non-idempotent one lets Stripe's own retries reprocess an
 * event. Stripe redelivers in normal operation, so this is not a rare path.
 */

function stripeOrg(string $email): Organization
{
    [, $org] = makeOrgUser($email);

    return $org;
}

/** Build a signed Stripe webhook request the way Stripe itself signs one. */
function signedStripePayload(array $payload, string $secret, ?int $timestamp = null): array
{
    $body = json_encode($payload);
    $timestamp ??= time();
    $signature = hash_hmac('sha256', "{$timestamp}.{$body}", $secret);

    return [$body, "t={$timestamp},v1={$signature}"];
}

it('rejects a webhook with a missing or forged signature', function () {
    config()->set('insightistic.stripe.webhook_secret', 'whsec_test');

    [$body] = signedStripePayload(['id' => 'evt_1', 'type' => 'customer.subscription.updated'], 'whsec_test');

    // No signature at all.
    $this->call('POST', '/api/billing/webhook/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)
        ->assertStatus(400);

    // Signature computed with the wrong secret.
    [$body2, $sig2] = signedStripePayload(['id' => 'evt_2', 'type' => 'customer.subscription.updated'], 'whsec_wrong');

    $this->call('POST', '/api/billing/webhook/stripe', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $sig2,
    ], $body2)->assertStatus(400);

    expect(StripeWebhookEvent::count())->toBe(0);
});

it('rejects a correctly signed webhook whose timestamp is far outside tolerance', function () {
    config()->set('insightistic.stripe.webhook_secret', 'whsec_test');

    // Valid HMAC, but replayed from hours ago — Stripe's tolerance rejects it.
    [$body, $sig] = signedStripePayload(
        ['id' => 'evt_old', 'type' => 'customer.subscription.updated'],
        'whsec_test',
        time() - 86400,
    );

    $this->call('POST', '/api/billing/webhook/stripe', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $sig,
    ], $body)->assertStatus(400);

    expect(StripeWebhookEvent::count())->toBe(0);
});

it('accepts a properly signed webhook and records the event id', function () {
    config()->set('insightistic.stripe.webhook_secret', 'whsec_test');

    [$body, $sig] = signedStripePayload([
        'id'      => 'evt_ok',
        'type'    => 'invoice.payment_failed',
        'object'  => 'event',
        'data'    => ['object' => ['customer' => 'cus_missing']],
    ], 'whsec_test');

    $this->call('POST', '/api/billing/webhook/stripe', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $sig,
    ], $body)->assertOk()->assertJson(['received' => true, 'duplicate' => false]);

    $row = StripeWebhookEvent::firstOrFail();
    expect($row->stripe_event_id)->toBe('evt_ok')
        ->and($row->type)->toBe('invoice.payment_failed')
        ->and($row->processed_at)->not->toBeNull();
});

it('processes a redelivered event only once', function () {
    config()->set('insightistic.stripe.webhook_secret', 'whsec_test');

    $org = stripeOrg('stripe-dup@example.com');
    Subscription::create([
        'organization_id'        => $org->id,
        'stripe_customer_id'     => 'cus_dup',
        'stripe_subscription_id' => 'sub_dup',
        'status'                 => 'active',
    ]);

    [$body, $sig] = signedStripePayload([
        'id'     => 'evt_dup',
        'type'   => 'invoice.payment_failed',
        'object' => 'event',
        'data'   => ['object' => ['customer' => 'cus_dup']],
    ], 'whsec_test');

    $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $sig];

    $this->call('POST', '/api/billing/webhook/stripe', [], [], [], $headers, $body)
        ->assertOk()->assertJson(['duplicate' => false]);

    // Stripe retries the same event id — must be acknowledged, not reprocessed.
    $this->call('POST', '/api/billing/webhook/stripe', [], [], [], $headers, $body)
        ->assertOk()->assertJson(['duplicate' => true]);

    expect(StripeWebhookEvent::where('stripe_event_id', 'evt_dup')->count())->toBe(1);
});

it('a duplicate delivery cannot undo state that moved on since', function () {
    $org = stripeOrg('stripe-stale@example.com');
    $subscription = Subscription::create([
        'organization_id'        => $org->id,
        'stripe_customer_id'     => 'cus_stale',
        'stripe_subscription_id' => 'sub_stale',
        'status'                 => 'active',
    ]);

    $event = \Stripe\Event::constructFrom([
        'id'     => 'evt_stale',
        'type'   => 'invoice.payment_failed',
        'data'   => ['object' => ['customer' => 'cus_stale']],
    ]);

    $processor = app(StripeWebhookProcessor::class);

    expect($processor->processOnce($event))->toBeTrue();
    expect($org->fresh()->status)->toBe('past_due');

    // The customer pays; state moves on.
    $org->refresh()->update(['status' => 'active']);

    // Stripe redelivers the old failure — it must not drag state backwards.
    expect($processor->processOnce($event))->toBeFalse();
    expect($org->fresh()->status)->toBe('active');
});

it('still lets super admin deliberately replay an event through process()', function () {
    $org = stripeOrg('stripe-replay@example.com');
    $subscription = Subscription::create([
        'organization_id'        => $org->id,
        'stripe_customer_id'     => 'cus_replay',
        'stripe_subscription_id' => 'sub_replay',
        'status'                 => 'active',
    ]);

    $event = \Stripe\Event::constructFrom([
        'id'   => 'evt_replay',
        'type' => 'invoice.payment_failed',
        'data' => ['object' => ['customer' => 'cus_replay']],
    ]);

    // The admin replay tool calls process() directly and is *meant* to re-run.
    app(StripeWebhookProcessor::class)->process($event);
    expect($org->fresh()->status)->toBe('past_due');

    $org->refresh()->update(['status' => 'active']);
    app(StripeWebhookProcessor::class)->process($event);
    expect($org->fresh()->status)->toBe('past_due');

    // Direct replay bypasses the ledger by design.
    expect(StripeWebhookEvent::count())->toBe(0);
});
