<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * UNIQUE(plan_id, provider, kind) assumed one price per kind per plan.
     * Credit packs break that: one hidden plan carries several one-time
     * pack prices (5k / 20k / 50k). The provider price id stays the global
     * unique key — the composite becomes a plain lookup index.
     */
    public function up(): void
    {
        Schema::table('plan_prices', function (Blueprint $table) {
            $table->dropUnique('plan_prices_plan_id_provider_kind_unique');
            $table->index(['plan_id', 'provider', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::table('plan_prices', function (Blueprint $table) {
            $table->dropIndex(['plan_id', 'provider', 'kind']);
            $table->unique(['plan_id', 'provider', 'kind']);
        });
    }
};
