<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-site, per-source connection state (spec §7.4). This is what lets the
 * plugin diagnostics screen, the dashboard's Integrations page, and Super
 * Admin all show the same "GA4: connected, last synced 2h ago" story from
 * one row instead of three different ad-hoc derivations. Never stores a
 * credential — `label` is a human-readable account/property name at most,
 * and `metadata_json` is meant to stay a small, explicitly allowlisted set
 * of non-secret fields (enforced in the writing service, not the schema).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('data_source_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();

            // wordpress|woocommerce|ga4|gsc|pagespeed|cloudflare|engagement
            $table->string('source');
            // connected|not_configured|syncing|degraded|error|disabled
            $table->string('state')->default('not_configured');

            $table->string('label')->nullable();

            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('last_successful_at')->nullable();
            $table->timestamp('latest_data_at')->nullable();
            $table->string('freshness')->nullable(); // fresh|stale|unknown

            $table->string('last_error_code')->nullable();
            $table->text('last_error_message')->nullable();

            $table->jsonb('metadata_json')->nullable();

            $table->timestamps();

            $table->unique(['site_id', 'source']);
            $table->index(['organization_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_source_connections');
    }
};
