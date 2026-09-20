<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Webhook idempotency ledger for all billing providers (Paddle guide §4.3),
 * generalizing stripe_webhook_events. The unique (provider, provider_event_id)
 * pair makes concurrent deliveries of the same event race to insert, and
 * exactly one wins — no SELECT-then-INSERT gap.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('billing_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32);
            $table->string('provider_event_id');
            $table->string('provider_notification_id')->nullable();
            $table->string('event_type');
            $table->timestamp('occurred_at')->nullable();
            $table->string('payload_hash')->nullable();
            $table->string('processing_status', 32)->default('pending');
            $table->timestamp('processed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamps();

            $table->unique(['provider', 'provider_event_id']);
            $table->index('processing_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_events');
    }
};
