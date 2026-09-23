<?php

use App\Models\ProductEvent;
use Illuminate\Support\Facades\Http;

/**
 * Marketing attribution + funnel events (spec §32/§33): UTM/gclid captured
 * at signup, first/last touch preserved, fixed event taxonomy, purchase
 * recorded server-side from verified Paddle webhook state exactly once.
 */
it('stores sanitized first/last touch and fires signup_completed on register', function () {
    $response = $this->postJson('/api/auth/register', [
        'name'              => 'Ad Buyer',
        'email'             => 'attr-signup@example.com',
        'password'          => 'super-secret-1',
        'password_confirmation' => 'super-secret-1',
        'organization_name' => 'Ad Org',
        'attribution'       => [
            'first_touch' => [
                'landing_url'  => 'https://insightistic.com/pricing?utm_source=google&utm_medium=cpc&utm_campaign=ltd_launch&gclid=EAIaIQ',
                'referrer'     => 'https://www.google.com/',
                'utm_source'   => 'google',
                'utm_medium'   => 'cpc',
                'utm_campaign' => 'ltd_launch',
                'gclid'        => 'EAIaIQ',
            ],
            'last_touch' => [
                'utm_source' => 'newsletter',
                'evil_key'   => 'DROP ME',
            ],
        ],
    ])->assertCreated();

    $orgId = $response->json('organization.id');

    $org = \App\Models\Organization::withoutGlobalScope('organization')->find($orgId);
    expect($org->first_touch_json['utm_source'])->toBe('google')
        ->and($org->first_touch_json['gclid'])->toBe('EAIaIQ')
        ->and($org->last_touch_json['utm_source'])->toBe('newsletter')
        ->and($org->last_touch_json)->not->toHaveKey('evil_key');

    $event = ProductEvent::where('event', 'signup_completed')->first();
    expect($event)->not->toBeNull()
        ->and($event->props_json['utm_source'])->toBe('google')
        ->and($event->props_json)->not->toHaveKey('evil_key')
        ->and($event->props_json)->not->toHaveKey('gclid'); // not in prop whitelist
});

it('drops non-http landing urls and junk values from attribution', function () {
    $analytics = app(\App\Services\Analytics\AttributionService::class);
    $clean = $analytics->sanitize([
        'landing_url' => 'javascript:alert(1)',
        'referrer'    => 'ftp://x',
        'utm_source'  => '  meta  ',
        'gclid'       => str_repeat('x', 500),
    ]);

    expect($clean)->not->toHaveKey('landing_url')
        ->and($clean)->not->toHaveKey('referrer')
        ->and($clean['utm_source'])->toBe('meta')
        ->and(strlen($clean['gclid']))->toBe(300);
});

it('rejects unknown events and unknown props at the API', function () {
    [$user, $org] = makeOrgUser('ev-api@example.com');

    $this->actingAs($user)->postJson('/api/events', [
        'event' => 'totally_made_up',
        'props' => ['anything' => 'x'],
    ])->assertOk()->assertJsonPath('recorded', false);

    $this->actingAs($user)->postJson('/api/events', [
        'event' => 'first_insight_viewed',
        'props' => ['page' => 'overview', 'password' => 'hunter2'],
    ])->assertOk()->assertJsonPath('recorded', true);

    $event = ProductEvent::where('event', 'first_insight_viewed')->first();
    expect($event->props_json)->not->toHaveKey('password')
        ->and($event->props_json)->not->toHaveKey('page'); // not whitelisted either
});

it('records purchase_completed server-side exactly once per Paddle transaction', function () {
    [$user, $org] = makeOrgUser('ev-paddle@example.com');

    $payload = [
        'id'       => 'txn_dedupe_1',
        'status'   => 'completed',
        'items'    => [['price' => ['id' => 'pri_test_ltd']]],
        'custom_data' => ['organization_id' => $org->id],
        'details'  => ['totals' => ['subtotal' => 9900, 'tax' => 0, 'total' => 9900, 'currency_code' => 'USD']],
        'billed_at' => now()->toIso8601String(),
    ];

    // seed a lifetime plan price so the txn maps to a kind
    \App\Models\PlanPrice::create([
        'provider' => 'paddle', 'provider_price_id' => 'pri_test_ltd',
        'plan_id' => $org->plan_id, 'kind' => 'lifetime',
        'currency' => 'USD', 'amount_minor' => 9900, 'interval' => null,
    ]);

    config()->set('billing.provider', 'paddle');

    // project TWICE (webhook replay / reconciler)
    app(\App\Domain\Billing\PaddleTransactionProjector::class)->project($payload);
    app(\App\Domain\Billing\PaddleTransactionProjector::class)->project($payload);

    expect(ProductEvent::where('event', 'purchase_completed')->count())->toBe(1)
        ->and(ProductEvent::where('event', 'ltd_purchased')->count())->toBe(1);

    $purchase = ProductEvent::where('event', 'purchase_completed')->first();
    expect($purchase->organization_id)->toBe($org->id)
        ->and($purchase->props_json['transaction_id'])->toBe('txn_dedupe_1')
        ->and($purchase->props_json['amount'])->toBe(9900);
});

it('fires checkout_started when a paddle checkout config is requested', function () {
    [$user, $org] = makeOrgUser('ev-checkout@example.com');
    config()->set('feature-flags.paddle', true);
    config()->set('billing.paddle.api_key', 'test');

    Http::fake(['*paddle*' => Http::response([], 200)]);

    try {
        $this->actingAs($user)->postJson('/api/billing/checkout-config', [
            'plan' => $org->plan->slug, 'kind' => 'lifetime',
        ]);
    } catch (\Throwable) {
        // config errors are fine — the event fires before provider calls
    }

    expect(ProductEvent::where('event', 'checkout_started')->count())->toBe(1);
});
