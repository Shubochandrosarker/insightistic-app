<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10 rank tracker (docs/roadmap-2026/13-RANK-TRACKER.md): tracked
 * keywords and the point-in-time SERP observations recorded against them.
 *
 * rank_keywords is UNIQUE on (site_id, config_hash) where config_hash =
 * sha1 of the normalized check configuration (keyword|engine|country|
 * language|device|location) — see RankKeyword::makeConfigHash(). That makes
 * "the same keyword tracked twice for one site" a database-level
 * impossibility rather than a controller promise.
 *
 * rank_observations is deliberately append-only history (no UNIQUE): the
 * same-day dedupe is an application concern (RankChecker skips keywords
 * whose last_checked_at is inside today, site timezone), so a retried job
 * can never double-charge a provider call by construction. position NULL
 * means "not found in the provider's top 100", not "no data yet".
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('rank_keywords', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('keyword', 255);
            $table->string('engine', 16)->default('google');
            $table->string('country', 8)->nullable()->comment('ISO-2, e.g. in');
            $table->string('language', 8)->nullable();
            $table->string('device', 16)->default('desktop')->comment('desktop|mobile');
            $table->string('location', 64)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
            // sha1(keyword|engine|country|language|device|location) — rebuilt
            // via RankKeyword::makeConfigHash(); mirrors the stored values.
            $table->char('config_hash', 40)->comment('sha1(keyword|engine|country|language|device|location)');

            $table->unique(['site_id', 'config_hash']);
            $table->index(['site_id', 'active']);
        });

        Schema::create('rank_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('keyword_id')->constrained('rank_keywords')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->timestamp('checked_at');
            $table->unsignedSmallInteger('position')->nullable()->comment('null = not found in top 100');
            $table->string('ranking_url', 1000)->nullable();
            $table->json('serp_features_json')->nullable();
            $table->string('provider', 32)->default('dataforseo');
            $table->string('provider_request_id', 64)->nullable();
            // Billable units actually spent on THIS observation (doc 13:
            // every check records cost). Provider batch cost is distributed
            // evenly across the keywords in the batch.
            $table->decimal('cost_units', 10, 4)->default(0);
            $table->timestamp('created_at')->comment('grain-style: no updated_at, observations never change');

            $table->index(['keyword_id', 'checked_at']);
            $table->index(['site_id', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rank_observations');
        Schema::dropIfExists('rank_keywords');
    }
};
