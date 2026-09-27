<?php

use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiTierResolver;
use App\Services\Ai\PlanAwareAiProvider;

class RoutingTestProvider implements AiProvider
{
    public function __construct(private string $label, private ?array $result) {}

    public function generate(string $system, array $context, ?int $identityId = null): ?array
    {
        return $this->result;
    }

    public function model(): string
    {
        return $this->label;
    }
}

it('routes free accounts to the free chain and active paid subscriptions to the paid chain', function () {
    [, $org] = makeOrgUser('ai-routing@example.com');
    $tiers = app(AiTierResolver::class);

    expect($tiers->isPaid($org->id))->toBeFalse();

    $paidPlan = Plan::create([
        'name' => 'Growth',
        'slug' => 'growth',
        'price_monthly' => 6900,
        'site_limit' => 10,
        'user_limit' => 3,
        'ai_insight_limit' => 100,
        'report_limit' => 20,
        'is_active' => true,
    ]);

    Subscription::create([
        'organization_id' => $org->id,
        'plan_id' => $paidPlan->id,
        'status' => 'active',
    ]);

    expect($tiers->isPaid($org->id))->toBeTrue()
        ->and($tiers->resolve($org->id)['gateway_tier'])->toBe('pro');
});

it('keeps a free trial on the free model tier even if a paid plan is attached', function () {
    [, $org] = makeOrgUser('ai-trial-routing@example.com');
    $paidPlan = Plan::create([
        'name' => 'Agency',
        'slug' => 'agency',
        'price_monthly' => 14900,
        'site_limit' => 30,
        'user_limit' => 10,
        'ai_insight_limit' => 1000,
        'report_limit' => 100,
        'is_active' => true,
    ]);
    $org->forceFill(['status' => 'trialing'])->save();
    Subscription::create([
        'organization_id' => $org->id,
        'plan_id' => $paidPlan->id,
        'status' => 'trialing',
    ]);

    expect(app(AiTierResolver::class)->isPaid($org->id))->toBeFalse();
});

it('continues to the next provider when the preferred provider returns no answer', function () {
    [, $org] = makeOrgUser('ai-routing-fallback@example.com');
    $first = new RoutingTestProvider('openrouter:openai/gpt-6-luna', null);
    $second = new RoutingTestProvider('wpistic-gateway:analytics-pro', ['title' => 'fallback answer']);
    $provider = new PlanAwareAiProvider(
        [new RoutingTestProvider('openrouter:openrouter/free', ['title' => 'free answer'])],
        [$first, $second],
        app(AiTierResolver::class),
    );

    $paidPlan = Plan::create([
        'name' => 'Starter',
        'slug' => 'starter',
        'price_monthly' => 2900,
        'site_limit' => 3,
        'user_limit' => 1,
        'ai_insight_limit' => 20,
        'report_limit' => 4,
        'is_active' => true,
    ]);
    Subscription::create([
        'organization_id' => $org->id,
        'plan_id' => $paidPlan->id,
        'status' => 'active',
    ]);

    expect($provider->generate('system', ['metrics' => []], $org->id))
        ->toBe(['title' => 'fallback answer'])
        ->and($provider->model())->toBe('wpistic-gateway:analytics-pro');
});
