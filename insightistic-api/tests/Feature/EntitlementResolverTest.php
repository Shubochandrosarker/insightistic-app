<?php

use App\Domain\Billing\EntitlementResolver;
use App\Models\EntitlementGrant;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Support\Str;

/**
 * EntitlementResolver precedence (roadmap doc 24): active subscription >
 * lifetime grant > manual/promotion grants merged additively > free, with
 * org suspension cutting through everything and an admin override lifting a
 * plan back onto a subscription regardless of its status.
 */

function resolverPlan(array $overrides = []): Plan
{
    return Plan::create(array_merge([
        'name'             => 'Growth',
        'slug'             => 'growth-'.Str::random(8),
        'site_limit'       => 10,
        'user_limit'       => 3,
        'ai_insight_limit' => 75,
        'report_limit'     => 20,
        'features_json'    => ['technical_crawler', 'ai_visibility'],
        'limits_json'      => ['crawl_pages_monthly' => 50000],
        'is_active'        => true,
    ], $overrides));
}

function resolverOrg(string $email): Organization
{
    return makeOrgUser($email)[1];
}

it('resolves a free org with no subscription to the none source', function () {
    $org = resolverOrg('resolver-free@example.com');

    $resolved = app(EntitlementResolver::class)->resolve($org);

    expect($resolved['source'])->toBe('none')
        ->and($resolved['plan']['id'])->toBeNull()
        ->and($resolved['features'])->toBe([])
        ->and($resolved['limits'])->toBe([]);
});

it('resolves an active subscription to its plan features and limits including the legacy columns', function () {
    $plan = resolverPlan();
    $org = resolverOrg('resolver-sub@example.com');
    Subscription::create([
        'organization_id' => $org->id,
        'plan_id'         => $plan->id,
        'status'          => 'active',
    ]);

    $resolved = app(EntitlementResolver::class)->resolve($org);

    expect($resolved['source'])->toBe('subscription')
        ->and($resolved['status'])->toBe('active')
        ->and($resolved['plan']['id'])->toBe($plan->id)
        ->and($resolved['plan']['slug'])->toBe($plan->slug)
        ->and($resolved['plan']['name'])->toBe('Growth')
        ->and($resolved['features'])->toBe(['technical_crawler', 'ai_visibility'])
        ->and($resolved['limits']['crawl_pages_monthly'])->toBe(50000)
        ->and($resolved['limits']['sites'])->toBe(10)
        ->and($resolved['limits']['users'])->toBe(3)
        ->and($resolved['limits']['ai_insights'])->toBe(75)
        ->and($resolved['limits']['reports'])->toBe(20)
        // LIMIT_DEFAULTS backstop for a key the plan predates.
        ->and($resolved['limits']['knowledge_bases'])->toBe(1);
});

it('lets an active lifetime grant carry the plan when no subscription exists', function () {
    $plan = resolverPlan(['name' => 'Founder Tier 2', 'slug' => 'founder-2-'.Str::random(8)]);
    $org = resolverOrg('resolver-ltd@example.com');
    EntitlementGrant::create([
        'organization_id'  => $org->id,
        'plan_id'          => $plan->id,
        'source'           => 'paddle_lifetime',
        'source_reference' => 'txn_'.Str::random(12),
    ]);

    $resolved = app(EntitlementResolver::class)->resolve($org);

    expect($resolved['source'])->toBe('lifetime')
        ->and($resolved['plan']['id'])->toBe($plan->id)
        ->and($resolved['plan']['name'])->toBe('Founder Tier 2')
        ->and($resolved['features'])->toBe(['technical_crawler', 'ai_visibility']);
});

it('unions a manual grant feature on top of the subscription plan features', function () {
    $plan = resolverPlan();
    $org = resolverOrg('resolver-manual@example.com');
    Subscription::create([
        'organization_id' => $org->id,
        'plan_id'         => $plan->id,
        'status'          => 'active',
    ]);
    EntitlementGrant::create([
        'organization_id'  => $org->id,
        'source'           => 'manual',
        'source_reference' => 'manual_'.Str::random(12),
        'features_json'    => ['bing_webmaster'],
    ]);

    $resolved = app(EntitlementResolver::class)->resolve($org);

    expect($resolved['source'])->toBe('subscription')
        ->and($resolved['features'])->toBe(['technical_crawler', 'ai_visibility', 'bing_webmaster']);
});

it('lets grant limits_json override the plan limit for the same key, up or down', function () {
    $plan = resolverPlan();
    $org = resolverOrg('resolver-override@example.com');
    Subscription::create([
        'organization_id' => $org->id,
        'plan_id'         => $plan->id,
        'status'          => 'active',
    ]);
    EntitlementGrant::create([
        'organization_id'  => $org->id,
        'source'           => 'promotion',
        'source_reference' => 'promo_'.Str::random(12),
        'limits_json'      => ['crawl_pages_monthly' => 100000, 'sites' => 3],
    ]);

    $resolved = app(EntitlementResolver::class)->resolve($org);

    // Raised above the plan value...
    expect($resolved['limits']['crawl_pages_monthly'])->toBe(100000)
        // ...and reduced below the plan's legacy site_limit of 10 (max() would fail this).
        ->and($resolved['limits']['sites'])->toBe(3);
});

it('returns the suspended result for a suspended org regardless of subscription', function () {
    $plan = resolverPlan();
    $org = resolverOrg('resolver-suspended@example.com');
    Subscription::create([
        'organization_id' => $org->id,
        'plan_id'         => $plan->id,
        'status'          => 'active',
    ]);
    $org->update(['status' => 'suspended']);

    $resolved = app(EntitlementResolver::class)->resolve($org);

    expect($resolved['status'])->toBe('suspended')
        ->and($resolved['source'])->toBe('none')
        ->and($resolved['plan']['id'])->toBeNull()
        ->and($resolved['features'])->toBe([])
        ->and($resolved['limits'])->toBe([]);
});

it('honors an admin override on an otherwise inactive subscription', function () {
    $plan = resolverPlan();
    $org = resolverOrg('resolver-override-admin@example.com');
    Subscription::create([
        'organization_id'          => $org->id,
        'plan_id'                  => $plan->id,
        'status'                   => 'canceled',
        'internal_override_active' => true,
        'internal_override_reason' => 'manual comp',
    ]);

    $resolved = app(EntitlementResolver::class)->resolve($org);

    expect($resolved['status'])->toBe('override')
        ->and($resolved['source'])->toBe('subscription')
        ->and($resolved['plan']['id'])->toBe($plan->id)
        ->and($resolved['features'])->toBe(['technical_crawler', 'ai_visibility'])
        ->and($resolved['limits']['sites'])->toBe(10);
});

it('lets an explicit admin plan assignment override an active provider subscription', function () {
    $providerPlan = resolverPlan(['name' => 'Provider Growth', 'slug' => 'provider-growth-'.Str::random(8)]);
    $adminPlan = resolverPlan(['name' => 'Admin Agency', 'slug' => 'admin-agency-'.Str::random(8), 'site_limit' => 30]);
    $org = resolverOrg('resolver-admin-plan@example.com');
    Subscription::create([
        'organization_id' => $org->id,
        'plan_id' => $providerPlan->id,
        'status' => 'active',
    ]);
    EntitlementGrant::create([
        'organization_id' => $org->id,
        'plan_id' => $adminPlan->id,
        'source' => 'manual',
        'source_reference' => 'admin_plan_override:'.$org->id,
        'starts_at' => now(),
    ]);

    $resolved = app(EntitlementResolver::class)->resolve($org);

    expect($resolved['source'])->toBe('manual')
        ->and($resolved['plan']['id'])->toBe($adminPlan->id)
        ->and($resolved['plan']['slug'])->toBe($adminPlan->slug)
        ->and($resolved['limits']['sites'])->toBe(30);
});

it('exposes limitFor and hasFeature as thin uncached wrappers', function () {
    $plan = resolverPlan(['features_json' => ['white_label']]);
    $org = resolverOrg('resolver-wrappers@example.com');
    Subscription::create([
        'organization_id' => $org->id,
        'plan_id'         => $plan->id,
        'status'          => 'active',
    ]);

    $resolver = app(EntitlementResolver::class);

    expect($resolver->limitFor($org, 'sites'))->toBe(10)
        ->and($resolver->limitFor($org, 'not_a_limit'))->toBe(0)
        ->and($resolver->hasFeature($org, 'white_label'))->toBeTrue()
        ->and($resolver->hasFeature($org, 'api_access'))->toBeFalse();
});
