<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan entitlements for the AI knowledge base + Copilot (spec Phase 8), and
 * per-organization AI privacy settings (spec Phase 10).
 *
 * plans.limits_json keys (null column = code defaults, see Plan::limit()):
 *   knowledge_bases, knowledge_sources, storage_mb, indexed_chunks,
 *   monthly_embeddings, monthly_ai_messages, scheduled_reports,
 *   data_retention_days
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->jsonb('limits_json')->nullable();
        });

        Schema::table('usage_counters', function (Blueprint $table) {
            $table->unsignedInteger('ai_messages_used')->default(0);
            $table->unsignedInteger('embeddings_used')->default(0);
        });

        Schema::table('organizations', function (Blueprint $table) {
            // Consent switch: when false, Copilot/insights only ever use the
            // deterministic local RuleProvider — nothing leaves the platform.
            $table->boolean('allow_external_ai')->default(true);
            $table->jsonb('ai_settings_json')->nullable(); // pii redaction options etc.
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['allow_external_ai', 'ai_settings_json']);
        });
        Schema::table('usage_counters', function (Blueprint $table) {
            $table->dropColumn(['ai_messages_used', 'embeddings_used']);
        });
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('limits_json');
        });
    }
};
