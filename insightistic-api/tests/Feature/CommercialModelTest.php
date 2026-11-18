<?php

use App\Domain\Billing\AiCreditService;
use App\Domain\Billing\CreditWallet;
use App\Domain\Billing\Exceptions\InsufficientCreditsException;
use App\Models\CreditLedgerEntry;
use App\Models\Plan;
use Database\Seeders\PlanSeeder;

it('seeds the internal trial, four recurring plans, and five AppSumo tiers', function () {
    $this->seed(PlanSeeder::class);

    $expected = [
        'free' => [0, 0, 1, 1, 10, 1000, 0],
        'starter' => [2900, 29000, 1, 1, 50, 5000, 10],
        'growth' => [6900, 69000, 3, 3, 200, 15000, 40],
        'agency' => [14900, 149000, 10, 10, 600, 40000, 150],
        'agency_pro' => [29900, 299000, 30, 25, 1500, 100000, 500],
        'appsumo_tier_1' => [4900, 0, 1, 4, 100, 5000, 5],
        'appsumo_tier_2' => [7900, 0, 3, 0, 200, 10000, 25],
        'appsumo_tier_3' => [14900, 0, 10, 0, 600, 30000, 100],
        'appsumo_tier_4' => [26900, 0, 25, 0, 1500, 75000, 250],
        'appsumo_tier_5' => [49900, 0, 50, 0, 5000, 250000, 500],
    ];

    foreach ($expected as $slug => [$monthly, $yearly, $sites, $seats, $standard, $credits, $reports]) {
        $plan = \App\Models\Plan::where('slug', $slug)->firstOrFail();
        expect($plan->price_monthly)->toBe($monthly)
            ->and($plan->price_yearly)->toBe($yearly)
            ->and($plan->site_limit)->toBe($sites)
            ->and($plan->user_limit)->toBe($seats)
            ->and($plan->limit('standard_ai_requests_monthly'))->toBe($standard)
            ->and($plan->limit('monthly_ai_credits'))->toBe($credits)
            ->and($plan->limit('client_ready_reports_monthly'))->toBe($reports);
    }
});
it('grants, spends, replays, and protects the monthly versus purchased wallet', function () {
    [, $org] = makeOrgUser('wallet@example.com', [
        'monthly_ai_credits' => 100,
        'standard_ai_requests_monthly' => 1,
    ]);
    $wallet = app(CreditWallet::class);
    $wallet->grantPurchased($org, 50, 'pack_wallet_1');

    expect($wallet->summary($org)['monthly_included']['remaining'])->toBe(100)
        ->and($wallet->summary($org)['purchased']['remaining'])->toBe(50);

    $wallet->consume($org, 120, 'test_ai_action', 'wallet-spend-1');
    $wallet->consume($org, 120, 'test_ai_action', 'wallet-spend-1');

    $summary = $wallet->summary($org);
    expect($summary['monthly_included']['remaining'])->toBe(0)
        ->and($summary['purchased']['remaining'])->toBe(30)
        ->and(CreditLedgerEntry::withoutGlobalScope('organization')->where('organization_id', $org->id)->where('amount_signed', '<', 0)->count())->toBe(2);

    expect(fn () => $wallet->consume($org, 31, 'test_ai_action', 'wallet-spend-2'))
        ->toThrow(InsufficientCreditsException::class);

    expect($wallet->summary($org)['purchased']['remaining'])->toBe(30);
});
it('uses the resolved plan allowance before charging the published AI tariff', function () {
    [, $org] = makeOrgUser('ai-tariff@example.com', [
        'monthly_ai_credits' => 100,
        'standard_ai_requests_monthly' => 1,
    ]);
    $service = app(AiCreditService::class);

    expect($service->charge($org, 'basic_wordpress_dashboard_insight', 'ai-tariff-1', true))
        ->toBe(['charged' => 'standard_request', 'cost' => 0]);

    expect($service->charge($org, 'basic_wordpress_dashboard_insight', 'ai-tariff-2', true))
        ->toBe(['charged' => 'insight_credits', 'cost' => 100]);
});

it('tops up the current monthly allowance after a plan upgrade without duplicating it', function () {
    [, $org] = makeOrgUser('wallet-plan-upgrade@example.com', [
        'monthly_ai_credits' => 100,
    ]);
    $wallet = app(CreditWallet::class);

    expect($wallet->summary($org)['monthly_included']['granted'])->toBe(100);

    $org->plan_id = Plan::create([
        'name' => 'Upgrade plan',
        'slug' => 'upgrade-plan-'.uniqid(),
        'site_limit' => 10,
        'user_limit' => 3,
        'ai_insight_limit' => 100,
        'report_limit' => 20,
        'limits_json' => ['monthly_ai_credits' => 250],
        'is_active' => true,
    ])->id;
    $org->save();

    $summary = $wallet->summary($org);
    expect($summary['monthly_included']['granted'])->toBe(250)
        ->and($summary['monthly_included']['remaining'])->toBe(250)
        ->and(CreditLedgerEntry::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)
            ->where('source_type', 'monthly_plan_adjustment')
            ->count())->toBe(1);

    $wallet->summary($org);
    expect(CreditLedgerEntry::withoutGlobalScope('organization')
        ->where('organization_id', $org->id)
        ->where('source_type', 'monthly_plan_adjustment')
        ->count())->toBe(1);
});
