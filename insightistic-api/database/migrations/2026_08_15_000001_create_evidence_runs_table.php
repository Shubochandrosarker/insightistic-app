<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Analytical Evidence Ledger (spec Phase 2 / P2). One row per Safe Query
 * Engine execution (direct or Ask-triggered) — org/site, source, the
 * validated plan, which metric *version* produced the numbers, a
 * reproducibility hash, timing/row counts, freshness and outcome.
 *
 * Deliberately separate from `ai_runs`: that table is Copilot-chat-specific
 * (a NOT NULL foreign key to ai_messages, meant for AI-call cost/latency
 * telemetry) and repurposing it for every query-engine execution — most of
 * which involve no AI call at all — would corrupt its existing usage
 * metering. See docs/ANALYTICAL-EVIDENCE.md.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('evidence_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            // Who asked, when known — null for system-triggered runs (P6 Automation Studio).
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('source', 40); // woocommerce|ga4|gsc (MetricSource::*)
            $table->string('run_type', 20); // query|ask
            $table->string('validator_version', 20); // PlanValidator/compiler contract version

            $table->jsonb('semantic_plan_json');
            // metric id => version actually used, e.g. {"net_revenue": 1, "orders": 1} —
            // frozen here even if the registry catalog later bumps a version.
            $table->jsonb('metric_versions_json');
            $table->string('query_hash', 64)->nullable();

            $table->unsignedInteger('row_count')->nullable();
            $table->decimal('duration_ms', 10, 1)->nullable();
            $table->boolean('read_only_identity_active')->default(false);
            $table->jsonb('freshness_json')->nullable();

            $table->string('status', 20); // success|error
            $table->string('error_code', 60)->nullable();
            // Sanitized message only (QueryEngineException's own safe text) — never a stack trace or raw SQL.
            $table->text('error_message')->nullable();

            // Populated only by P3 Ask runs that actually invoked an AI
            // provider (plan generation and/or answer synthesis) — null for
            // every plain query-engine run and for Ask runs that used the
            // deterministic fallback path. No cost column yet: MeteredAiProvider
            // records cost into aggregated Telemetry buckets, not a
            // structured per-call return value this ledger could read
            // honestly today — see docs/ANALYTICAL-EVIDENCE.md.
            $table->boolean('ai_used')->default(false);
            $table->string('ai_model', 120)->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'site_id', 'created_at']);
            $table->index('query_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evidence_runs');
    }
};
