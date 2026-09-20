<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8 change monitoring (docs/roadmap-2026/12-SITE-CRAWLER.md
 * §Change monitoring): per-page diffs between two consecutive completed
 * crawl runs of one site.
 *
 * One row per changed (page, field) per run transition. Deliberately
 * UNIQUE-less: the same transition may legitimately recur across
 * non-adjacent runs, so identical-transition dedupe is an application
 * concern (ChangeDetection compares against the latest logged row), not a
 * schema constraint.
 *
 * impressions is the GSC 28-day impact snapshot RESOLVED AT DETECTION
 * TIME — it freezes the page's traffic weight when the change happened,
 * so later GSC fluctuations never rewrite history.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('crawl_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('run_id')->constrained('crawl_runs')->cascadeOnDelete();
            // Same hash the crawler used for the page URL (crawl_pages.url_hash).
            $table->char('page_hash', 40);
            $table->string('url', 1000);
            $table->string('field', 32)->comment('title|meta_description|canonical|indexability|status_code|h1_count|content');
            $table->string('severity', 16)->default('low')->comment('low|medium|high|critical');
            $table->string('old_value', 500)->nullable();
            $table->string('new_value', 500)->nullable();
            $table->unsignedInteger('impressions')->default(0)->comment('GSC 28d impressions for the page path, snapshotted at detection time');
            $table->timestamps();

            $table->index(['site_id', 'created_at']);
            $table->index('run_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crawl_changes');
    }
};
