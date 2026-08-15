<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outcome Verification (spec: Insightistic VNext P11) — closes the loop
 * P9 (Bridgistic Approved Actions) opens: for every EXECUTED
 * ActionProposal that has a metric worth watching, capture a baseline
 * evidence run at execution time, then re-measure the same metric later
 * and record whether it actually improved. See docs/OUTCOME-VERIFICATION.md.
 *
 * `baseline_evidence_run_id`/`followup_evidence_run_id` point at real
 * `evidence_runs` rows — this table never stores a value it measured
 * itself, only which two already-recorded, independently-verifiable
 * evidence runs it's comparing.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('outcome_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('action_proposal_id')->constrained()->cascadeOnDelete();
            $table->string('watched_metric_id', 60)->nullable();
            $table->foreignId('baseline_evidence_run_id')->nullable()->constrained('evidence_runs')->nullOnDelete();
            $table->foreignId('followup_evidence_run_id')->nullable()->constrained('evidence_runs')->nullOnDelete();
            // evidence_runs stores the plan/hash/outcome, not a copy of the
            // computed value (P2's documented scope) — so the value each
            // run actually measured is captured here directly, at the
            // moment it's computed, rather than re-derived from the
            // evidence run afterward.
            $table->decimal('baseline_value', 16, 4)->nullable();
            $table->decimal('followup_value', 16, 4)->nullable();
            $table->timestamp('verified_at')->nullable();
            // 'improved' | 'unchanged' | 'worsened' | 'no_metric_to_verify' —
            // the last one set immediately at capture time when no metric
            // could be derived from the proposal's source, never left as a
            // silent null forever.
            $table->string('outcome', 30)->nullable();
            $table->decimal('delta_pct', 8, 2)->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'site_id']);
            $table->index(['action_proposal_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outcome_verifications');
    }
};
