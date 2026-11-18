<?php

use App\Models\Organization;
use App\Models\ProductEvent;
use App\Services\Analytics\AttributionService;

/**
 * Marketing attribution + funnel events (spec §32/§33): UTM/gclid captured
 * at signup, first/last touch preserved, and fixed event taxonomy. Payment
 * provider webhook projection is covered by the Stripe billing tests.
 */
it('stores sanitized first/last touch and fires signup_completed on register', function () {
    $response = $this->postJson('/api/auth/register', [
        'name' => 'Ad Buyer',
        'email' => 'attr-signup@example.com',
        'password' => 'super-secret-1',
        'password_confirmation' => 'super-secret-1',
        'organization_name' => 'Ad Org',
        'attribution' => [
            'first_touch' => [
                'landing_url' => 'https://insightistic.com/pricing?utm_source=google&utm_medium=cpc&utm_campaign=ltd_launch&gclid=EAIaIQ',
                'referrer' => 'https://www.google.com/',
                'utm_source' => 'google',
                'utm_medium' => 'cpc',
                'utm_campaign' => 'ltd_launch',
                'gclid' => 'EAIaIQ',
            ],
            'last_touch' => [
                'utm_source' => 'newsletter',
                'evil_key' => 'DROP ME',
            ],
        ],
    ])->assertCreated();

    $orgId = $response->json('organization.id');

    $org = Organization::withoutGlobalScope('organization')->find($orgId);
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
    $analytics = app(AttributionService::class);
    $clean = $analytics->sanitize([
        'landing_url' => 'javascript:alert(1)',
        'referrer' => 'ftp://x',
        'utm_source' => '  meta  ',
        'gclid' => str_repeat('x', 500),
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
