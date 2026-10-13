<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\PlanPrice;
use Illuminate\Database\Seeder;

/**
 * Maps the LIVE Paddle Billing catalog (products/prices created 2026-09-20
 * on the WPistic vendor account) onto local plans via plan_prices.
 *
 * Run: php artisan db:seed --class=PaddleCatalogSeeder
 *
 * Everything here is re-runnable: plans and price rows are upserted, and a
 * price id is never repointed at a different plan silently — a mismatch is
 * skipped loudly instead (a price id uniquely identifies what a customer
 * bought; repointing it would rewrite entitlement history).
 */
class PaddleCatalogSeeder extends Seeder
{
    private const PROVIDER = 'paddle';

    /**
     * Price id => [plan_slug, kind, amount_minor, metadata, variant].
     * Amounts are the source of truth for what was created in Paddle.
     * `variant` discriminates sibling prices that share one plan+kind (the
     * credit packs); regular rows keep null.
     */
    private const PRICES = [
        // Recurring (Paddle product "Insightistic SaaS — <Plan>")
        'pri_01m2y7dpx1tqvsmnepav833pjc' => ['starter',    'monthly',     2900,   []],
        'pri_01m2y7dqcnwnwtnzmxt5zxq0fn' => ['starter',    'yearly',      29000,  []],
        'pri_01m2y7dqw8cf49rhsewd2660b4' => ['growth',     'monthly',     6900,   []],
        'pri_01m2y7drfcathw2bdmbey4ckhj' => ['growth',     'yearly',      69000,  []],
        'pri_01m2y7dspyxr9em1y8jt0k0gmh' => ['agency',     'monthly',     14900,  []],
        'pri_01m2y7dtchqyzhr2zsmz6bpsr6' => ['agency',     'yearly',      149000, []],
        'pri_01m2y7dtzybt5qa1k6yx7ha6hz' => ['agency_pro', 'monthly',     29900,  []],
        'pri_01m2y7dvg1tk4tc7w34jtamfx4' => ['agency_pro', 'yearly',      299000, []],

        // Founder LTD (one-time => permanent entitlement_grant on purchase)
        'pri_01m2y7dw3kd8162wzm54j6k7z3' => ['founder_tier_1', 'lifetime', 7900,  []],
        'pri_01m2y7dwmfewpennma8gjffm88' => ['founder_tier_2', 'lifetime', 14900, []],
        'pri_01m2y7dx628b2ahgzf2vqafey4' => ['founder_tier_3', 'lifetime', 24900, []],

        // Insight Credit packs (one-time => credit_ledger grant; credits in
        // metadata drive fulfillment — see PaddleTransactionProjector). One
        // plan+kind carries three prices, so each needs its checkout variant.
        'pri_01m2y7dxpgpp7sajjt9maky1j7' => ['insight_credits', 'credit_pack', 1500, ['credits' => 5000], 'credits_5000'],
        'pri_01m2y7dymwecszpnpc511geb35' => ['insight_credits', 'credit_pack', 4900, ['credits' => 20000], 'credits_20000'],
        'pri_01m2y7dzdf1j627w2tbmx0nr52' => ['insight_credits', 'credit_pack', 9900, ['credits' => 50000], 'credits_50000'],
    ];

    /**
     * Hidden plans the FK requires but no pricing page shows. LTD tiers are
     * real feature/limit tiers (roadmap doc 24) — access comes from a
     * permanent entitlement grant, not a subscription. insight_credits is
     * just an anchor for the credit-pack price rows.
     */
    private const HIDDEN_PLANS = [
        'founder_tier_1' => [
            'name' => 'Founder Tier 1', 'price' => 7900, 'sites' => 3, 'users' => 1,
            'crawl' => 10000, 'per_run' => 500, 'kw' => 150, 'prompts' => 10, 'reports' => 4,
        ],
        'founder_tier_2' => [
            'name' => 'Founder Tier 2', 'price' => 14900, 'sites' => 10, 'users' => 3,
            'crawl' => 40000, 'per_run' => 1000, 'kw' => 500, 'prompts' => 40, 'reports' => 20,
        ],
        'founder_tier_3' => [
            'name' => 'Founder Tier 3', 'price' => 24900, 'sites' => 30, 'users' => 5,
            'crawl' => 100000, 'per_run' => 2000, 'kw' => 1500, 'prompts' => 100, 'reports' => 100,
        ],
        'insight_credits' => [
            'name' => 'Insight Credits', 'price' => 0, 'sites' => 0, 'users' => 0,
            'crawl' => 0, 'per_run' => 50, 'kw' => 0, 'prompts' => 0, 'reports' => 0,
        ],
    ];

    public function run(): void
    {
        foreach (self::HIDDEN_PLANS as $slug => $p) {
            Plan::updateOrCreate(['slug' => $slug], [
                'name'              => $p['name'],
                'price_monthly'     => $p['price'],
                'price_yearly'      => 0,
                'site_limit'        => $p['sites'],
                'user_limit'        => $p['users'],
                'ai_insight_limit'  => 0,
                'report_limit'      => $p['reports'],
                'is_active'         => false,
                'is_featured'       => false,
                'features_json'     => [],
                'limits_json'       => [
                    'crawl_pages_monthly'    => $p['crawl'],
                    'crawl_pages_per_run'    => $p['per_run'],
                    'tracked_keywords'       => $p['kw'],
                    'ai_prompt_runs_monthly' => $p['prompts'],
                ],
            ]);
        }

        $plans = Plan::query()
            ->whereIn('slug', array_merge(['starter', 'growth', 'agency', 'agency_pro'], array_keys(self::HIDDEN_PLANS)))
            ->pluck('id', 'slug');

        foreach (self::PRICES as $priceId => [$planSlug, $kind, $amount, $metadata, $variant]) {
            $planId = $plans->get($planSlug);

            if (! $planId) {
                $this->command?->warn("plan_prices: skipped $priceId — plan '$planSlug' missing (run PlanSeeder)");

                continue;
            }

            // A price id permanently identifies what was purchased: update
            // the row, but never silently move it to a different plan/kind.
            $existing = PlanPrice::query()
                ->where('provider', self::PROVIDER)
                ->where('provider_price_id', $priceId)
                ->first();

            if ($existing && ($existing->plan_id !== $planId || $existing->kind !== $kind)) {
                $this->command?->warn("plan_prices: CONFLICT $priceId already maps to a different plan/kind — skipped");

                continue;
            }

            PlanPrice::updateOrCreate(
                [
                    'provider'          => self::PROVIDER,
                    'provider_price_id' => $priceId,
                ],
                [
                    'plan_id'             => $planId,
                    'kind'                => $kind,
                    'variant'             => $variant,
                    'currency'            => 'USD',
                    'amount_minor'        => $amount,
                    'billing_interval'    => $kind === 'monthly' ? 'month' : ($kind === 'yearly' ? 'year' : null),
                    'is_active'           => true,
                    'metadata_json'       => $metadata ?: null,
                ],
            );
        }
    }
}
