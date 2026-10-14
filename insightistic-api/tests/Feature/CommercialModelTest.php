<?php

use App\Domain\Billing\AiCreditService;
use App\Domain\Billing\CreditWallet;
use App\Domain\Billing\Exceptions\BillingConfigurationException;
use App\Domain\Billing\Exceptions\InsufficientCreditsException;
use App\Domain\Billing\LtdCampaignService;
use App\Models\BillingTransaction;
use App\Models\CreditLedgerEntry;
use App\Models\EntitlementGrant;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Domain\Billing\PaddleTransactionProjector;
use Database\Seeders\PlanSeeder;

it('seeds all eight current account tiers with the approved commercial limits', function () {
    $this->seed(PlanSeeder::class);

    $expected = [
        'free' => [0, 0, 1, 1, 10, 1000, 0],
        'starter' => [2900, 29000, 1, 1, 50, 5000, 10],
        'growth' => [6900, 69000, 3, 3, 200, 15000, 40],
        'agency' => [14900, 149000, 10, 10, 600, 40000, 150],
        'agency_pro' => [29900, 299000, 30, 25, 1500, 100000, 500],
        'angel_solo' => [14900, 0, 3, 1, 25, 3000, 5],
        'angel_studio' => [29900, 0, 7, 3, 100, 8000, 25],
        'angel_agency' => [69900, 0, 15, 10, 250, 20000, 100],
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

it('reconciles completed lifetime sales and active grants into the Angel cap once', function () {
    config()->set('commercial.ltd_campaign.maximum_sales', 2);

    [, $transactionOrg] = makeOrgUser('angel-history-transaction@example.com');
    [, $grantOrg] = makeOrgUser('angel-history-grant@example.com');

    BillingTransaction::withoutGlobalScope('organization')->create([
        'organization_id' => $transactionOrg->id,
        'provider' => 'paddle',
        'provider_transaction_id' => 'txn_historical_angel_1',
        'provider_price_id' => 'pri_historical_angel',
        'kind' => 'lifetime',
        'status' => 'completed',
        'currency' => 'USD',
        'total_minor' => 14900,
        'completed_at' => now(),
    ]);

    EntitlementGrant::withoutGlobalScope('organization')->create([
        'organization_id' => $grantOrg->id,
        'source' => 'paddle_lifetime',
        'source_reference' => 'txn_historical_angel_2',
        'limits_json' => ['connected_websites' => 3],
    ]);

    $status = app(LtdCampaignService::class)->status();

    expect($status['successful_sales'])->toBe(2)
        ->and($status['remaining_sales'])->toBe(0)
        ->and($status['sold_out'])->toBeTrue();
});

it('reserves Angel inventory atomically and rejects the next checkout at the cap', function () {
    config()->set('commercial.ltd_campaign.maximum_sales', 1);
    [, $firstOrg] = makeOrgUser('angel-reserve-first@example.com');
    [, $secondOrg] = makeOrgUser('angel-reserve-second@example.com');
    $service = app(LtdCampaignService::class);

    $reservation = $service->reserve($firstOrg, 'angel_solo', '00000000-0000-4000-8000-000000000001');

    expect($reservation->status)->toBe('reserved')
        ->and($service->status()['reserved_sales'])->toBe(1)
        ->and(fn () => $service->reserve($secondOrg, 'angel_solo', '00000000-0000-4000-8000-000000000002'))
        ->toThrow(BillingConfigurationException::class);
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

it('reverses available purchased credits once after a verified full refund', function () {
    [, $org] = makeOrgUser('wallet-refund@example.com');
    $plan = Plan::create(['name' => 'Refund pack', 'slug' => 'refund-pack', 'is_active' => false]);
    $price = PlanPrice::create([
        'plan_id' => $plan->id,
        'provider' => 'paddle',
        'kind' => 'credit_pack',
        'provider_price_id' => 'pri_refund_pack',
        'currency' => 'USD',
        'amount_minor' => 1500,
        'is_active' => true,
        'metadata_json' => ['credits' => 500],
    ]);
    $txn = BillingTransaction::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id,
        'provider' => 'paddle',
        'provider_transaction_id' => 'txn_refund_pack',
        'provider_price_id' => $price->provider_price_id,
        'kind' => 'credit_pack',
        'status' => 'completed',
        'currency' => 'USD',
        'total_minor' => 1500,
    ]);
    app(CreditWallet::class)->grantPurchased($org, 500, $txn->provider_transaction_id);
    $adjustment = ['id' => 'adj_refund_pack', 'action' => 'refund', 'status' => 'approved', 'type' => 'full', 'transaction_id' => $txn->provider_transaction_id];

    app(PaddleTransactionProjector::class)->processAdjustment($adjustment);
    app(PaddleTransactionProjector::class)->processAdjustment($adjustment);

    expect(app(CreditWallet::class)->summary($org)['purchased']['remaining'])->toBe(0)
        ->and($txn->fresh()->summary_json['adjustment']['credits_reversed'])->toBe(500)
        ->and(CreditLedgerEntry::withoutGlobalScope('organization')->where('source_type', 'paddle_credit_refund')->count())->toBe(1);
});
