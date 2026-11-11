<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI allowance holds (pricing architecture §6: "Reserve the action cost
 * before the AI call, commit on success, release on failure or timeout").
 *
 * Deliberately not a second ledger: `credit_ledger` stays append-only and
 * remains the only place a balance lives. A row here is an intent to spend
 * that only moves money when the call succeeds — AiAllowanceGuard::commit()
 * then writes the one real deduction through CreditWallet. Keeping holds out
 * of the ledger is what makes release() a plain status flip with no
 * compensating entries, and leaves `balance_after` a running sum of
 * amount_signed with nothing to filter.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('ai_allowance_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('action', 64);
            // The ai_runs id where the caller has one, otherwise a stable
            // per-attempt key. Idempotency triple: (organization_id, action,
            // run_reference) — a retried run settles at most one charge.
            $table->string('run_reference', 160);
            $table->unsignedInteger('cost');
            $table->string('status', 16)->default('reserved'); // reserved|committed|released
            $table->timestamp('committed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'action', 'run_reference']);
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_allowance_reservations');
    }
};
