<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * plan+kind no longer identifies a price: insight_credits carries three
     * active credit_pack rows (5k/20k/50k). `variant` is the discriminator
     * ('credits_5000' | 'credits_20000' | 'credits_50000'); regular rows keep
     * NULL and the old plan+kind lookup stays valid for them. Checkout-config
     * must fail loud when a plan+kind is still ambiguous without a variant —
     * guessing a price on the money path is never acceptable.
     */
    public function up(): void
    {
        Schema::table('plan_prices', function (Blueprint $table) {
            $table->string('variant', 64)->nullable()->after('kind');
            $table->index(['plan_id', 'provider', 'kind', 'variant']);
        });
    }

    public function down(): void
    {
        Schema::table('plan_prices', function (Blueprint $table) {
            $table->dropIndex(['plan_id', 'provider', 'kind', 'variant']);
            $table->dropColumn('variant');
        });
    }
};
