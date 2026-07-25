<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Insightistic AI Copilot — conversation, run-telemetry, citation and
 * feedback tables (spec Phase 5). Every table carries organization_id so
 * tenant isolation is structural, not conventional.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('ai_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 200)->default('New conversation');
            // Sticky context selections (date range, sources, knowledge bases).
            $table->jsonb('context_json')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'user_id', 'archived_at']);
            $table->index(['organization_id', 'last_message_at']);
        });

        Schema::create('ai_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_thread_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('role', 20); // user|assistant|system
            $table->text('content');
            // Structured answer-contract payload for assistant messages
            // (key_finding, metrics, actions, confidence, limitations, ...).
            $table->jsonb('answer_json')->nullable();
            $table->string('status', 20)->default('completed'); // pending|streaming|completed|failed|cancelled
            $table->string('error_code', 60)->nullable();
            $table->timestamps();

            $table->index(['ai_thread_id', 'created_at']);
            $table->index('organization_id');
        });

        Schema::create('ai_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_message_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 60);
            $table->string('model', 120);
            $table->string('prompt_version', 40)->default('v1');
            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedInteger('tokens_in')->nullable();
            $table->unsignedInteger('tokens_out')->nullable();
            $table->decimal('cost_usd', 10, 6)->nullable();
            $table->string('status', 20)->default('completed'); // completed|failed|cancelled
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
            $table->index('ai_message_id');
        });

        Schema::create('ai_citations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_message_id')->constrained()->cascadeOnDelete();
            // Null when the citation points at live analytics evidence rather
            // than a knowledge chunk (source_type says which system).
            $table->foreignId('knowledge_chunk_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('knowledge_source_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_type', 40); // knowledge|woocommerce|ga4|gsc|pagespeed|cloudflare|alerts|reports
            $table->string('title', 300);
            $table->text('url')->nullable();
            $table->text('snippet')->nullable();
            $table->decimal('score', 8, 6)->nullable();
            $table->date('data_date')->nullable();
            $table->timestamp('last_indexed_at')->nullable();
            $table->timestamps();

            $table->index('ai_message_id');
            $table->index('organization_id');
        });

        Schema::create('ai_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->smallInteger('rating'); // 1 = up, -1 = down
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->unique(['ai_message_id', 'user_id']);
            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_feedback');
        Schema::dropIfExists('ai_citations');
        Schema::dropIfExists('ai_runs');
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_threads');
    }
};
