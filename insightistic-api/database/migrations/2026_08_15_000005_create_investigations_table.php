<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WPAgentistic Investigations (spec: Insightistic VNext P8) — a read-only
 * diagnostic run across several metrics, gathering real evidence to answer
 * a question like "why did revenue drop?" See docs/WPAGENTISTIC-INVESTIGATIONS.md.
 *
 * `findings_json`/`narrative_json` mirror the shape App\Services\Ask
 * already established (P3) for the same "deterministic evidence first,
 * optional AI narration that can never touch confidence/citations" pattern
 * — an Investigation is that same guarantee applied across multiple
 * metrics/evidence runs instead of one query result.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('investigations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            // 'manual' | 'alert' | 'anomaly' — what prompted this investigation.
            $table->string('trigger_source', 20)->default('manual');
            $table->unsignedBigInteger('trigger_ref_id')->nullable(); // alert id or anomaly id, matching trigger_source
            $table->string('subject', 255);
            $table->string('status', 20); // completed|error
            $table->jsonb('findings_json');
            $table->jsonb('narrative_json');
            $table->timestamps();

            $table->index(['organization_id', 'site_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investigations');
    }
};
