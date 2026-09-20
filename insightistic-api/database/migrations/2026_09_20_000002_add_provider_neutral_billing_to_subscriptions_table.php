<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provider-neutral subscription columns (Paddle guide §4.2). Added beside the
 * existing Stripe columns so legacy Stripe subscriptions keep working while
 * Paddle projects into the same table — nothing is dropped.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('billing_provider')->nullable()->index();
            $table->string('provider_customer_id')->nullable();
            $table->string('provider_subscription_id')->nullable()->unique();
            $table->string('provider_price_id')->nullable();
            $table->string('provider_status')->nullable();
            $table->timestamp('next_billed_at')->nullable();
            $table->string('scheduled_change_action')->nullable();
            $table->timestamp('scheduled_change_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn([
                'billing_provider',
                'provider_customer_id',
                'provider_subscription_id',
                'provider_price_id',
                'provider_status',
                'next_billed_at',
                'scheduled_change_action',
                'scheduled_change_at',
            ]);
        });
    }
};
