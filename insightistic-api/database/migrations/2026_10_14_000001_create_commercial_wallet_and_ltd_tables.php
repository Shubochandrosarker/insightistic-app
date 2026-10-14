<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('credit_ledger', function (Blueprint $table) {
            $table->string('bucket', 32)->default('purchased');
            $table->string('period_key', 7)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->json('metadata_json')->nullable();
            $table->index(['organization_id', 'bucket', 'period_key']);
        });

        Schema::create('ltd_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->unsignedInteger('maximum_sales');
            $table->unsignedInteger('successful_sales')->default(0);
            $table->string('state', 24)->default('draft'); // draft|active|sold_out|closed
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('ltd_checkout_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('ltd_campaigns')->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('plan_slug', 64);
            $table->uuid('idempotency_key')->unique();
            $table->string('checkout_id')->nullable()->index();
            $table->string('provider_transaction_id')->nullable()->unique();
            $table->string('status', 24)->default('reserved'); // reserved|consumed|expired|released
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['campaign_id', 'status', 'expires_at']);
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ltd_checkout_reservations');
        Schema::dropIfExists('ltd_campaigns');
        Schema::table('credit_ledger', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'bucket', 'period_key']);
            $table->dropColumn(['bucket', 'period_key', 'expires_at', 'metadata_json']);
        });
    }
};
