<?php

use App\Models\Plan;
use App\Models\PlanPrice;
use Database\Seeders\PaddleCatalogSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Str;

/**
 * The live-catalog mapping (PaddleCatalogSeeder) must resolve every real
 * Paddle price id to the right plan/kind — the webhook projector's plan
 * resolution and checkout-config both depend on it.
 */
it('seeds the live paddle catalog into plan_prices with correct plan and kind mapping', function () {
    $this->seed(PlanSeeder::class);
    $this->seed(PaddleCatalogSeeder::class);

    expect(PlanPrice::query()->where('provider', 'paddle')->count())->toBe(22);

    $canonicalStarterMonthly = PlanPrice::query()
        ->where('provider', 'paddle')
        ->where('provider_price_id', 'pri_01m5a6ty51a3a2kqbhv2j66rmt')
        ->first();

    expect($canonicalStarterMonthly)->not->toBeNull()
        ->and($canonicalStarterMonthly->plan->slug)->toBe('starter')
        ->and($canonicalStarterMonthly->kind)->toBe('monthly')
        ->and($canonicalStarterMonthly->amount_minor)->toBe(2900)
        ->and($canonicalStarterMonthly->metadata_json['catalog_product'])->toBe('insightistic_saas');

    $starterMonthly = PlanPrice::query()
        ->where('provider', 'paddle')
        ->where('provider_price_id', 'pri_01m2y7dpx1tqvsmnepav833pjc')
        ->first();

    expect($starterMonthly)->not->toBeNull()
        ->and($starterMonthly->plan->slug)->toBe('starter')
        ->and($starterMonthly->kind)->toBe('monthly')
        ->and($starterMonthly->amount_minor)->toBe(2900)
        ->and($starterMonthly->is_active)->toBeTrue();

    $annual = PlanPrice::query()
        ->where('provider', 'paddle')
        ->where('provider_price_id', 'pri_01m2y7dvg1tk4tc7w34jtamfx4')
        ->first();

    expect($annual->plan->slug)->toBe('agency_pro')
        ->and($annual->kind)->toBe('yearly')
        ->and($annual->billing_interval)->toBe('year');
});

it('creates hidden ltd tiers and credit-pack plans with fulfillment metadata', function () {
    $this->seed(PlanSeeder::class);
    $this->seed(PaddleCatalogSeeder::class);

    $tier2 = Plan::query()->where('slug', 'founder_tier_2')->first();

    expect($tier2)->not->toBeNull()
        ->and($tier2->is_active)->toBeFalse()
        ->and($tier2->site_limit)->toBe(10)
        ->and((int) $tier2->limit('crawl_pages_monthly'))->toBe(40000);

    $credits = Plan::query()->where('slug', 'insight_credits')->first();
    expect($credits->is_active)->toBeFalse();

    $pack = PlanPrice::query()
        ->where('provider', 'paddle')
        ->where('provider_price_id', 'pri_01m2y7dymwecszpnpc511geb35')
        ->first();

    expect($pack->kind)->toBe('credit_pack')
        ->and($pack->plan->slug)->toBe('insight_credits')
        ->and($pack->metadata_json['credits'])->toBe(20000);
});

it('never silently repoints a price id at a different plan', function () {
    $this->seed(PlanSeeder::class);
    $this->seed(PaddleCatalogSeeder::class);

    // Point the starter monthly price at growth, then re-seed: the seeder
    // must warn and skip, leaving the (wrong) mapping untouched rather than
    // rewriting history behind the projector's back.
    $price = PlanPrice::query()
        ->where('provider', 'paddle')
        ->where('provider_price_id', 'pri_01m2y7dpx1tqvsmnepav833pjc')
        ->first();
    $price->update(['plan_id' => Plan::query()->where('slug', 'growth')->value('id')]);

    $this->seed(PaddleCatalogSeeder::class);

    expect($price->fresh()->plan->slug)->toBe('growth')
        ->and(PlanPrice::query()->where('provider', 'paddle')->count())->toBe(22);
});

it('maps configured Angel Paddle IDs to the new lifetime plans', function () {
    $this->seed(PlanSeeder::class);
    putenv('PADDLE_PRICE_ANGEL_SOLO=pri_test_angel_solo');
    $_ENV['PADDLE_PRICE_ANGEL_SOLO'] = $_SERVER['PADDLE_PRICE_ANGEL_SOLO'] = 'pri_test_angel_solo';
    putenv('PADDLE_PRICE_ANGEL_STUDIO=pri_test_angel_studio');
    $_ENV['PADDLE_PRICE_ANGEL_STUDIO'] = $_SERVER['PADDLE_PRICE_ANGEL_STUDIO'] = 'pri_test_angel_studio';
    putenv('PADDLE_PRICE_ANGEL_AGENCY=pri_test_angel_agency');
    $_ENV['PADDLE_PRICE_ANGEL_AGENCY'] = $_SERVER['PADDLE_PRICE_ANGEL_AGENCY'] = 'pri_test_angel_agency';

    try {
        $this->seed(PaddleCatalogSeeder::class);

        expect(PlanPrice::where('provider_price_id', 'pri_test_angel_solo')->first()->plan->slug)->toBe('angel_solo')
            ->and(PlanPrice::where('provider_price_id', 'pri_test_angel_studio')->first()->plan->slug)->toBe('angel_studio')
            ->and(PlanPrice::where('provider_price_id', 'pri_test_angel_agency')->first()->plan->slug)->toBe('angel_agency')
            ->and(PlanPrice::where('provider_price_id', 'pri_test_angel_agency')->first()->kind)->toBe('lifetime');
    } finally {
        putenv('PADDLE_PRICE_ANGEL_SOLO');
        unset($_ENV['PADDLE_PRICE_ANGEL_SOLO'], $_SERVER['PADDLE_PRICE_ANGEL_SOLO']);
        putenv('PADDLE_PRICE_ANGEL_STUDIO');
        unset($_ENV['PADDLE_PRICE_ANGEL_STUDIO'], $_SERVER['PADDLE_PRICE_ANGEL_STUDIO']);
        putenv('PADDLE_PRICE_ANGEL_AGENCY');
        unset($_ENV['PADDLE_PRICE_ANGEL_AGENCY'], $_SERVER['PADDLE_PRICE_ANGEL_AGENCY']);
    }
});
