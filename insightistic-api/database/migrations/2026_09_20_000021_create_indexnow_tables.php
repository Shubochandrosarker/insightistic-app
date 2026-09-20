<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IndexNow (Phase 6, docs/roadmap-2026/11-BING-WEBMASTER-INDEXNOW §IndexNow).
 *
 * indexnow_keys — one key per site. The SaaS generates and stores the key;
 * the WORDPRESS PLUGIN hosts the key file at https://{domain}/{key}.txt and
 * reports back once it serves it (key_file_confirmed), so a "not confirmed"
 * key explains a 403 from the IndexNow endpoint without any guessing.
 *
 * indexnow_submissions — an immutable per-URL submission log (created_at
 * only, mirroring the seo daily-grain tables): what was submitted, by whom
 * (manual|plugin|auto), in which batch, and what the endpoint said. Grain:
 * UNIQUE (batch_id, url_hash) so a retried batch cannot double-log a URL.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('indexnow_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('key', 64)->unique(); // 32 hex chars, but column is forgiving
            $table->boolean('key_file_confirmed')->default(false);
            $table->timestamps();

            $table->unique('site_id'); // exactly one key per site
        });

        Schema::create('indexnow_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('url', 1000);
            $table->char('url_hash', 40); // sha1 of the normalized URL
            $table->string('source', 32)->comment('manual|plugin|auto');
            $table->string('batch_id', 64)->nullable();
            $table->string('status', 32)->default('pending')->comment('pending|accepted|failed|skipped_duplicate');
            $table->unsignedInteger('http_status')->nullable();
            $table->boolean('retryable')->default(false);
            $table->text('error')->nullable();

            $table->timestamp('created_at')->nullable(); // immutable log: no updated_at

            $table->index(['site_id', 'created_at'], 'indexnow_submissions_site_date_idx');
            $table->unique(['batch_id', 'url_hash']); // a retried batch cannot double-log
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('indexnow_submissions');
        Schema::dropIfExists('indexnow_keys');
    }
};
