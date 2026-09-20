<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Financial history projected from provider transactions (Paddle guide §4.4):
 * subscriptions, lifetime purchases, credit packs and adjustments. Access is
 * decided by subscription/entitlement state — this table records what was paid.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('billing_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('provider_transaction_id')->unique();
            $table->string('provider_subscription_id')->nullable();
            $table->string('provider_price_id')->nullable();
            $table->string('kind', 32);                    // subscription|lifetime|credits|adjustment
            $table->string('status', 32);
            $table->string('currency', 8);
            $table->unsignedBigInteger('subtotal_minor')->default(0); // cents
            $table->unsignedBigInteger('tax_minor')->default(0);      // cents
            $table->unsignedBigInteger('total_minor')->default(0);    // cents
            $table->string('invoice_number')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('summary_json')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_transactions');
    }
};
