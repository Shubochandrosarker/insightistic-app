<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stripe webhook idempotency ledger.
 *
 * Stripe redelivers events — on its own retry schedule, and again whenever a
 * delivery is replayed from the dashboard. Without a ledger, a redelivered
 * `invoice.payment_failed` re-runs the past-due path and a replayed
 * subscription event can resurrect state that has since moved on.
 *
 * The unique index on `stripe_event_id` is what actually enforces this: two
 * concurrent deliveries of the same event race to insert, and exactly one
 * wins. Checking "have I seen this?" with a SELECT first would leave a gap
 * between the check and the write.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('stripe_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('stripe_event_id', 191)->unique();
            $table->string('type', 100);
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // Lets an operator prune old rows without a full scan.
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_webhook_events');
    }
};
