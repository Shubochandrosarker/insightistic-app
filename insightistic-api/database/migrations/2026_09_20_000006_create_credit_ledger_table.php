<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only credit ledger. The unique
 * (source_type, source_reference) pair ensures a provider transaction id
 * (e.g. txn_...) cannot credit a balance twice.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('credit_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->integer('amount_signed');              // positive = credit, negative = spend
            $table->string('reason');
            $table->string('source_type', 32);
            $table->string('source_reference');
            $table->integer('balance_after')->nullable();
            $table->timestamps();

            $table->unique(['source_type', 'source_reference']);
            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_ledger');
    }
};
