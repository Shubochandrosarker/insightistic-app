<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 technical crawler (docs/roadmap-2026/12-SITE-CRAWLER.md, 05-DATA-MODEL.md).
 *
 * crawl_runs        one crawl of one site, with its own page budget
 * crawl_frontier    per-run URL queue (atomic claim: pending → crawling → done|failed|skipped;
 *                   "crawling" is a transient in-flight marker so N queue workers can
 *                   SKIP LOCKED-claim rows without ever touching the same URL twice)
 * crawl_pages       per-run page observations (run-scoped facts, unique per run+url)
 * crawl_links       per-run link graph (source page → target URL)
 * crawl_issues      site-scoped issue lifecycle; fingerprint = sha1(code|target)
 *                   tracks first/last seen across runs, resolved when absent, never
 *                   touched while ignored.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('crawl_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('status', 32)->default('pending')->comment('pending|running|completed|failed|cancelled');
            $table->string('trigger', 32)->default('manual')->comment('manual|schedule');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('max_pages')->default(1000);
            $table->unsignedInteger('pages_discovered')->default(0);
            $table->unsignedInteger('pages_crawled')->default(0);
            $table->unsignedInteger('issues_found')->default(0);
            $table->json('config_json')->nullable();
            $table->text('error_summary')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'created_at']);
        });

        Schema::create('crawl_frontier', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('crawl_runs')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->char('url_hash', 40);
            $table->string('url', 1000);
            $table->unsignedSmallInteger('depth')->default(0);
            $table->string('status', 32)->default('pending')->comment('pending|crawling|done|failed|skipped');
            $table->text('error')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['run_id', 'url_hash']);
            $table->index(['run_id', 'status']);
        });

        Schema::create('crawl_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('crawl_runs')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->char('url_hash', 40);
            $table->string('url', 1000);
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->string('content_type', 160)->nullable()->comment('HTTP Content-Type; HTML-only checks apply only to text/html and XHTML');
            $table->string('redirect_to', 1000)->nullable();
            $table->boolean('indexable')->default(false);
            $table->boolean('robots_noindex')->default(false);
            $table->string('canonical_url', 1000)->nullable();
            $table->string('title', 500)->nullable();
            $table->string('meta_description', 1000)->nullable();
            $table->unsignedSmallInteger('h1_count')->default(0);
            $table->unsignedInteger('word_count')->default(0);
            $table->char('content_hash', 40)->nullable();
            $table->json('schema_types_json')->nullable();
            $table->unsignedInteger('response_ms')->nullable();
            $table->unsignedInteger('size_bytes')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['run_id', 'url_hash']);
            $table->index(['site_id', 'url_hash']);
        });

        Schema::create('crawl_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('crawl_runs')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_page_id')->nullable()->constrained('crawl_pages')->nullOnDelete();
            $table->char('target_url_hash', 40);
            $table->string('target_url', 1000);
            $table->boolean('is_internal')->default(true);
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->boolean('is_nofollow')->default(false);
            $table->string('anchor', 300)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('run_id');
            $table->index(['site_id', 'target_url_hash']);
        });

        Schema::create('crawl_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('run_id')->constrained('crawl_runs')->cascadeOnDelete();
            $table->string('issue_code', 48);
            $table->string('severity', 16)->default('low')->comment('low|medium|high|critical');
            $table->string('category', 32)->default('on_page')->comment('crawlability|on_page|links|content|structured_data');
            $table->char('fingerprint', 40);
            $table->string('target_url', 1000)->nullable();
            $table->foreignId('page_id')->nullable()->constrained('crawl_pages')->nullOnDelete();
            $table->json('evidence_json')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->string('status', 16)->default('open')->comment('open|resolved|ignored');
            $table->timestamps();

            $table->unique(['site_id', 'fingerprint']);
            $table->index(['site_id', 'status', 'severity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crawl_issues');
        Schema::dropIfExists('crawl_links');
        Schema::dropIfExists('crawl_pages');
        Schema::dropIfExists('crawl_frontier');
        Schema::dropIfExists('crawl_runs');
    }
};
