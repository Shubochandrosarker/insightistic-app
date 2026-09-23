<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $defaults = [
            'free' => 50, 'starter' => 200, 'growth' => 500,
            'business' => 1000, 'agency' => 2000, 'agency_pro' => 5000,
        ];

        foreach (DB::table('plans')->get(['id', 'slug', 'limits_json']) as $plan) {
            $limits = is_array($plan->limits_json)
                ? $plan->limits_json
                : (json_decode((string) $plan->limits_json, true) ?: []);
            $limits['crawl_pages_per_run'] ??= $defaults[$plan->slug] ?? 50;
            DB::table('plans')->where('id', $plan->id)->update(['limits_json' => json_encode($limits)]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('plans')->get(['id', 'limits_json']) as $plan) {
            $limits = is_array($plan->limits_json)
                ? $plan->limits_json
                : (json_decode((string) $plan->limits_json, true) ?: []);
            unset($limits['crawl_pages_per_run']);
            DB::table('plans')->where('id', $plan->id)->update(['limits_json' => json_encode($limits)]);
        }
    }
};
