<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Search Console-sourced SEO analytics (spec §8). */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('seo_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->date('date');

            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->decimal('ctr', 6, 4)->nullable();
            $table->decimal('avg_position', 6, 2)->nullable();

            $table->timestamps();

            $table->unique(['site_id', 'date']);
        });

        Schema::create('seo_query_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('query', 500);

            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->decimal('ctr', 6, 4)->nullable();
            $table->decimal('position', 6, 2)->nullable();

            $table->unsignedInteger('prev_clicks')->nullable();
            $table->unsignedInteger('prev_impressions')->nullable();
            $table->decimal('prev_ctr', 6, 4)->nullable();
            $table->decimal('prev_position', 6, 2)->nullable();

            // high_potential (high impressions, low ctr) | declining | content_decay | stable | null
            $table->string('opportunity')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['site_id', 'period_start', 'period_end'], 'seo_query_period_idx');
        });

        Schema::create('seo_page_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('page_path', 1000); // normalized path, no query string/host

            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->decimal('ctr', 6, 4)->nullable();
            $table->decimal('position', 6, 2)->nullable();

            $table->unsignedInteger('prev_clicks')->nullable();
            $table->unsignedInteger('prev_impressions')->nullable();
            $table->decimal('prev_ctr', 6, 4)->nullable();
            $table->decimal('prev_position', 6, 2)->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['site_id', 'period_start', 'period_end'], 'seo_page_period_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_page_snapshots');
        Schema::dropIfExists('seo_query_snapshots');
        Schema::dropIfExists('seo_daily');
    }
};
