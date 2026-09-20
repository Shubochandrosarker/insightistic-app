<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PlanSeeder::class);
        // Local mapping of the live Paddle catalog (pure DB rows, no API
        // calls) — the webhook projector and checkout-config resolve plan
        // from these rows.
        $this->call(PaddleCatalogSeeder::class);
    }
}
