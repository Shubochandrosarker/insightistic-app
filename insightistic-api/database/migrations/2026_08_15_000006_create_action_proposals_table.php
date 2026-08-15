<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bridgistic Approved Actions (spec: Insightistic VNext P9) — a proposed
 * action that ALWAYS requires an explicit human approval before it ever
 * executes. See docs/BRIDGISTIC-APPROVED-ACTIONS.md.
 *
 * There is no auto-execution path anywhere in this table's lifecycle:
 * `status` only ever moves pending -> approved+executed or pending ->
 * rejected, and both of those transitions require a `reviewed_by_user_id`
 * — App\Services\Actions\ActionProposalService enforces this in code, and
 * this schema has no column that could carry an unattended approval (no
 * "auto_approve" flag, no service-account reviewer sentinel).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('action_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            // 'manual' | 'wpagentistic_investigation' | 'anomaly' | 'automation_rule'
            $table->string('source_type', 40)->default('manual');
            $table->unsignedBigInteger('source_id')->nullable(); // investigation/anomaly/automation_rule id, matching source_type
            // One of App\Services\Actions\ActionType::ALL — the identical
            // allowlist and executor Automation Studio (P6) rules use.
            $table->string('action_type', 40);
            $table->jsonb('action_params_json');
            $table->text('rationale');
            $table->jsonb('evidence_run_ids_json')->nullable(); // evidence_runs.id this proposal is backed by
            // pending -> executed (approved, action succeeded) or failed
            // (approved, action itself errored) — or pending -> rejected
            // (never executed). Approval and execution happen in the same
            // request (ActionProposalService::approve()), so there is no
            // separate "approved but not yet run" state to leave dangling.
            $table->string('status', 20)->default('pending');
            $table->foreignId('proposed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->jsonb('result_json')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'site_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('action_proposals');
    }
};
