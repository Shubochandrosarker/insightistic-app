<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        // Prices in cents. Matches spec section 6 pricing table.
        $plans = [
            ['starter',    'Starter',    900,   9000,   1,   1,   20,   4,   false, false],
            ['growth',     'Growth',     2900,  29000,  3,   3,   100,  20,  false, false],
            ['business',   'Business',   7900,  79000,  10,  10,  300,  100, false, false],
            ['agency',     'Agency',     19900, 199000, 30,  30,  1000, 300, true,  false],
            ['agency_pro', 'Agency Pro', 39900, 399000, 100, 100, 3000, 1000, true, true],
        ];

        foreach ($plans as [$slug, $name, $m, $y, $sites, $users, $ai, $reports, $wl, $cd]) {
            Plan::updateOrCreate(['slug' => $slug], [
                'name'                  => $name,
                'price_monthly'         => $m,
                'price_yearly'          => $y,
                'site_limit'            => $sites,
                'user_limit'            => $users,
                'ai_insight_limit'      => $ai,
                'report_limit'          => $reports,
                'white_label_enabled'   => $wl,
                'custom_domain_enabled' => $cd,
                'is_active'             => true,
            ]);
        }
    }
}
