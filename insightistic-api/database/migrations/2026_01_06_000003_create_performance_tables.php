<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lighthouse/PageSpeed performance history (spec §8). Persisted status
 * columns are limited to the three official Core Web Vitals (LCP, INP, CLS)
 * — those are the only metrics Google assigns good/needs-improvement/poor
 * thresholds to; FCP/TBT/Speed Index/TTFB are lab-only diagnostics without
 * an official status classification, so they're stored as plain numbers.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('performance_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('url', 1000);
            $table->string('strategy'); // mobile|desktop
            $table->timestamp('run_at');

            $table->string('lighthouse_version')->nullable();
            $table->timestamp('fetch_time')->nullable();

            $table->unsignedTinyInteger('performance_score')->nullable();
            $table->unsignedTinyInteger('accessibility_score')->nullable();
            $table->unsignedTinyInteger('best_practices_score')->nullable();
            $table->unsignedTinyInteger('seo_score')->nullable();
            $table->unsignedTinyInteger('ai_readiness_score')->nullable();

            $table->unsignedInteger('lcp_ms')->nullable();
            $table->string('lcp_status')->nullable(); // good|needs_improvement|poor
            $table->unsignedInteger('inp_ms')->nullable();
            $table->string('inp_status')->nullable();
            $table->decimal('cls', 6, 3)->nullable();
            $table->string('cls_status')->nullable();

            $table->unsignedInteger('fcp_ms')->nullable();
            $table->unsignedInteger('tbt_ms')->nullable();
            $table->unsignedInteger('speed_index_ms')->nullable();
            $table->unsignedInteger('ttfb_ms')->nullable();

            $table->boolean('field_data_available')->default(false);
            $table->boolean('lab_data_available')->default(false);

            $table->string('status')->default('queued'); // queued|running|complete|failed
            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->index(['site_id', 'strategy', 'run_at']);
        });

        Schema::create('performance_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('performance_run_id')->constrained()->cascadeOnDelete();
            $table->string('audit_key');
            $table->string('category'); // performance|accessibility|best-practices|seo
            $table->string('title');

            $table->string('severity')->default('medium'); // low|medium|high
            $table->unsignedInteger('savings_ms')->nullable();
            $table->unsignedBigInteger('savings_bytes')->nullable();
            $table->string('display_value')->nullable();
            $table->text('description')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index('performance_run_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_issues');
        Schema::dropIfExists('performance_runs');
    }
};
