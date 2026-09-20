<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 search-intelligence daily grain (docs/roadmap-2026/10-GSC §storage):
 * query, page and query×page rows keyed by (site, date, hash) so GSC pulls
 * replay idempotently (doc 08), plus the per-site backfill cursor that makes
 * multi-window backfills resumable. Grain rows are immutable facts —
 * created_at only, no updated_at — with metrics refreshed in place by UPSERT.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('seo_query_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->char('query_hash', 40);
            $table->string('query', 500);

            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->decimal('ctr', 6, 4)->nullable();
            $table->decimal('position', 6, 2)->nullable();

            $table->timestamp('created_at')->nullable();

            $table->unique(['site_id', 'date', 'query_hash']);
            $table->index(['site_id', 'query_hash', 'date'], 'seo_query_daily_query_idx');
            $table->index(['site_id', 'date'], 'seo_query_daily_date_idx');
        });

        Schema::create('seo_page_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->char('page_hash', 40);
            $table->string('page_path', 1000); // normalized path, no query string/host

            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->decimal('ctr', 6, 4)->nullable();
            $table->decimal('position', 6, 2)->nullable();

            $table->timestamp('created_at')->nullable();

            $table->unique(['site_id', 'date', 'page_hash']);
            $table->index(['site_id', 'page_hash', 'date'], 'seo_page_daily_page_idx');
            $table->index(['site_id', 'date'], 'seo_page_daily_date_idx');
        });

        Schema::create('seo_query_page_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->char('query_hash', 40);
            $table->char('page_hash', 40);

            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->decimal('position', 6, 2)->nullable();

            $table->timestamp('created_at')->nullable();

            $table->unique(['site_id', 'date', 'query_hash', 'page_hash']);
            $table->index(['site_id', 'query_hash', 'date'], 'seo_query_page_daily_query_idx');
            $table->index(['site_id', 'page_hash', 'date'], 'seo_query_page_daily_page_idx');
        });

        Schema::create('seo_backfill_state', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('dimension', 32)->comment('queries|pages|query_page');
            $table->date('covered_from')->nullable();
            $table->date('covered_to')->nullable();
            $table->string('status', 32)->default('pending')->comment('pending|running|done|failed');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();

            $table->timestamps();

            $table->unique(['site_id', 'dimension']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_backfill_state');
        Schema::dropIfExists('seo_query_page_daily');
        Schema::dropIfExists('seo_page_daily');
        Schema::dropIfExists('seo_query_daily');
    }
};
