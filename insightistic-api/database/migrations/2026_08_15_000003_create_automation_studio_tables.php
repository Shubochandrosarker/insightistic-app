<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automation Studio (spec: Insightistic VNext P6) — trigger → action chains
 * an org admin configures once, that fire automatically without further
 * human involvement. See docs/AUTOMATION-STUDIO.md.
 *
 * `automation_rules` is the configuration; `automation_runs` is the audit
 * trail of every time a rule actually fired — mirroring `evidence_runs`'
 * "record every outcome, success or failure, never silently" convention.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('automation_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 150);
            $table->boolean('is_active')->default(true);
            // 'schedule' | 'alert_created' | 'anomaly_detected' — see App\Services\Automation\TriggerType.
            $table->string('trigger_type', 30);
            $table->jsonb('trigger_config_json');
            // One of App\Services\Actions\ActionType::ALL.
            $table->string('action_type', 40);
            $table->jsonb('action_params_json');
            // Only populated when action_type = 'send_webhook'. AES-encrypted
            // at rest via APP_KEY, same convention as Site::connector_secret.
            $table->text('webhook_secret')->nullable();
            $table->timestamp('last_fired_at')->nullable();
            $table->string('last_run_status', 20)->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'site_id']);
            $table->index(['site_id', 'trigger_type', 'is_active']);
        });

        Schema::create('automation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_rule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->timestamp('fired_at');
            $table->string('status', 20); // success|failed
            $table->jsonb('trigger_context_json')->nullable();
            $table->jsonb('action_result_json')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['automation_rule_id', 'fired_at']);
            $table->index(['organization_id', 'site_id', 'fired_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_runs');
        Schema::dropIfExists('automation_rules');
    }
};
