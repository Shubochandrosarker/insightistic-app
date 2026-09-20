<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 11 AI Visibility engine (docs/roadmap-2026/14-AI-VISIBILITY.md):
 * stable prompts monitored over time, per-site competitor definitions, and
 * the append-only observation stores (runs → mentions → citations) every
 * visibility metric is computed from.
 *
 * Acceptance criteria this schema serves (doc 14):
 * - every run carries provider/model metadata (which surface answered) and
 *   its usage/cost units — cost is metered per run, never reconstructed;
 * - runs/mentions/citations are immutable observation rows: metrics are
 *   computed FROM them, never regenerated from memory;
 * - response_hash (md5 of the answer text) lets the UI spot identical
 *   answers across runs without storing raw responses.
 *
 * mentions/citations carry a denormalized site_id so per-site retention and
 * metric queries never have to join through runs.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('ai_visibility_prompts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('prompt', 500);
            // Coarse grouping for the UI (doc 14: category templates are a
            // prompt source) — e.g. 'best-crm', 'category', 'how-to'.
            $table->string('category', 32)->nullable()->comment('e.g. best-crm|category');
            // Soft-disable (active=false) keeps history queryable — doc 14:
            // never automatically add prompts because each has recurring cost,
            // so deactivation (not deletion) is the lifecycle default.
            $table->boolean('active')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'active']);
        });

        Schema::create('ai_visibility_competitors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            // Extra match strings per doc 14's normalized-alias guidance:
            // product names, domains, common abbreviations.
            $table->json('aliases_json')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'name']);
        });

        Schema::create('ai_visibility_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('prompt_id')->constrained('ai_visibility_prompts')->cascadeOnDelete();
            // Which surface actually answered (doc 14: label provider/model on
            // every run — "the provider that ran, not the one configured").
            $table->string('provider', 32);
            $table->string('model', 64)->nullable();
            $table->string('status', 16)->default('success')->comment('success|failed');
            // md5 of the answer text — duplicate-answer detection without
            // storing raw provider responses.
            $table->char('response_hash', 32)->nullable();
            // Usage actually reported by the provider payload; 0 when the
            // provider contract doesn't surface it (MeteredAiProvider's
            // no-fabricated-numbers rule — an estimate would be a lie in a
            // cost table).
            $table->unsignedInteger('tokens_used')->default(0);
            $table->decimal('cost_units', 10, 4)->default(0);
            $table->boolean('brand_mentioned')->default(false);
            $table->text('error')->nullable();
            $table->timestamp('checked_at');
            // Grain-style: no updated_at, observations never change.
            $table->timestamp('created_at');

            $table->index(['site_id', 'checked_at']);
            $table->index(['prompt_id', 'checked_at']);
        });

        Schema::create('ai_visibility_mentions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('ai_visibility_runs')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('entity_name', 120);
            $table->boolean('is_owned')->default(false);
            $table->boolean('is_competitor')->default(false);
            // Order of first appearance in the answer (doc 14: this is
            // appearance ORDER, deliberately not called a universal "rank").
            $table->unsignedSmallInteger('position_order')->nullable();
            // Heuristic only — see MentionExtractor (doc 14: sentiment
            // "if confidently derived"; this naive near-word classifier is
            // documented, never presented as provider ground truth).
            $table->string('sentiment', 16)->nullable()->comment('positive|neutral|negative');
            $table->timestamp('created_at');

            $table->index(['run_id']);
        });

        Schema::create('ai_visibility_citations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('ai_visibility_runs')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('url', 1000);
            $table->string('domain', 255);
            $table->boolean('is_owned')->default(false);
            $table->timestamp('created_at');

            $table->index(['run_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_visibility_citations');
        Schema::dropIfExists('ai_visibility_mentions');
        Schema::dropIfExists('ai_visibility_runs');
        Schema::dropIfExists('ai_visibility_competitors');
        Schema::dropIfExists('ai_visibility_prompts');
    }
};
