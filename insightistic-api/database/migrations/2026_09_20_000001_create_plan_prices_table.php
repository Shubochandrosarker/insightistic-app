<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provider-neutral price mapping (Paddle guide §4.1): Paddle price_id ->
 * internal plan_id + kind. Plans are never resolved by amount or product
 * name — webhooks look up the price here and take the mapped plan.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('plan_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32)->default('paddle');
            $table->string('kind', 32);                    // monthly|yearly|lifetime|credit_pack
            $table->string('provider_product_id')->nullable();
            $table->string('provider_price_id')->unique();
            $table->string('currency', 8)->default('USD');
            $table->unsignedBigInteger('amount_minor');    // cents
            $table->string('billing_interval')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->unique(['plan_id', 'provider', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_prices');
    }
};
