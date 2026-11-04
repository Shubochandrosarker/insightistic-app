<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\PlanPrice;
use App\Services\StripeService;
use Illuminate\Database\Seeder;

/**
 * Maps Stripe prices onto plan_prices (provider = stripe). Idempotent.
 *
 *   php artisan db:seed --class=StripeCatalogSeeder
 *
 * Reads database/seeders/data/stripe_catalog.{test|live}.json. The live file
 * does not exist yet (no live objects created). The seeder refuses to map
 * test ids while a live key is configured and vice versa, and every amount
 * is checked against config/commercial.php so prices can't drift.
 */
class StripeCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $mode = StripeService::mode() === 'live' ? 'live' : 'test';
        $file = database_path("seeders/data/stripe_catalog.{$mode}.json");
        if (! is_file($file)) {
            $this->command?->warn("No Stripe {$mode} catalog file; skipping.");

            return;
        }
        $catalog = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        if (($catalog['livemode'] ?? null) !== ($mode === 'live')) {
            throw new \RuntimeException('Stripe catalog livemode does not match the configured key mode.');
        }

        $expected = $this->expectedAmounts();
        foreach ($catalog['prices'] as $row) {
            $want = $expected[$row['plan']][$row['kind']] ?? null;
            if ($want === null || (int) $want !== (int) $row['amount_minor']) {
                throw new \RuntimeException("Stripe price for {$row['plan']}/{$row['kind']} does not match config/commercial.php.");
            }
            $plan = Plan::where('slug', $row['plan'])->first();
            if (! $plan) {
                $this->command?->warn("Plan {$row['plan']} missing; skipped.");
                continue;
            }
            PlanPrice::updateOrCreate(
                ['plan_id' => $plan->id, 'provider' => 'stripe', 'kind' => $row['kind']],
                [
                    'provider_product_id' => $row['product'],
                    'provider_price_id'   => $row['price'],
                    'currency'            => 'USD',
                    'amount_minor'        => (int) $row['amount_minor'],
                    'billing_interval'    => $row['interval'],
                    'is_active'           => true,
                    'metadata_json'       => ['livemode' => $mode === 'live', 'stripe_account' => $catalog['account'] ?? null],
                ],
            );
        }
    }

    /** @return array<string, array<string, int>> */
    private function expectedAmounts(): array
    {
        $out = [];
        foreach ((array) config('commercial.regular_plans', []) as $slug => $p) {
            $out[$slug] = ['monthly' => (int) $p['monthly'], 'yearly' => (int) $p['yearly']];
        }
        foreach ((array) config('commercial.angel_plans', []) as $slug => $p) {
            $out[$slug] = ['lifetime' => (int) $p['price'] * 100];
        }

        return $out;
    }
}
