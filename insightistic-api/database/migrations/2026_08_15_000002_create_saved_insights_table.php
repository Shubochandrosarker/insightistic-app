<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saved Insights (spec: Insightistic VNext P5) — a named, reusable
 * `SemanticQueryPlan` a user can re-run on demand instead of re-composing
 * the same question/plan every time. See docs/SAVED-INSIGHTS.md.
 *
 * `semantic_plan_json` / `metric_versions_json` deliberately mirror
 * `evidence_runs`' columns of the same name (spec: Analytical Evidence
 * Ledger, P2) — a saved insight IS a plan, frozen at whatever metric
 * versions were live when it was saved, exactly like an evidence run
 * freezes them at execution time. `chart_spec_json` stores only the
 * STRUCTURAL chart type (`{"type":"kpi"}` / `{"type":"bar","dimension":...}`)
 * — never computed values, which would go stale the moment underlying data
 * changes. Fresh numbers only ever come from actually running the plan
 * (`POST .../saved-insights/{id}/run`), which records its own EvidenceRun.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('saved_insights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->jsonb('semantic_plan_json');
            $table->jsonb('metric_versions_json');
            $table->jsonb('chart_spec_json');
            // 'organization' (visible to everyone in the org, default — this
            // is a small-team product, there is no finer-grained sharing
            // model yet) or 'private' (visible only to owner_user_id).
            $table->string('scope', 20)->default('organization');
            // Reserved for a future alerting feature (spec mentions
            // threshold-based notification on a saved insight's value) —
            // stored but NOT evaluated by anything yet. See
            // docs/SAVED-INSIGHTS.md "What P5 deliberately does not do".
            $table->jsonb('thresholds_json')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'site_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_insights');
    }
};
