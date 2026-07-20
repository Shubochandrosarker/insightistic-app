<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Traffic analytics (spec §8). Separate from the WooCommerce-oriented
 * metric_snapshots table on purpose — traffic has its own source dimension
 * (ga4|connector engagement|...) and a much higher-cardinality dimension
 * breakdown table that metric_snapshots was never designed to hold.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('traffic_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('source'); // ga4|connector_engagement|...

            $table->unsignedInteger('users')->default(0);
            $table->unsignedInteger('new_users')->default(0);
            $table->unsignedInteger('sessions')->default(0);
            $table->unsignedInteger('engaged_sessions')->default(0);
            $table->unsignedInteger('page_views')->default(0);
            $table->decimal('engagement_rate', 5, 2)->nullable();
            $table->decimal('bounce_rate', 5, 2)->nullable();
            $table->unsignedInteger('avg_engagement_duration_seconds')->nullable();
            $table->unsignedInteger('conversions')->default(0);
            $table->unsignedInteger('transactions')->default(0);
            $table->decimal('revenue', 14, 2)->nullable();

            $table->timestamps();

            $table->unique(['site_id', 'source', 'date']);
        });

        Schema::create('traffic_dimension_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('source');

            // channel|source_medium|country|device|landing_page|page|content
            $table->string('dimension_type');
            $table->string('dimension_value');
            $table->string('url')->nullable();
            $table->string('title')->nullable();

            $table->unsignedInteger('sessions')->default(0);
            $table->unsignedInteger('users')->default(0);
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('conversions')->default(0);
            $table->decimal('revenue', 14, 2)->nullable();
            $table->decimal('engagement_rate', 5, 2)->nullable();
            $table->unsignedSmallInteger('rank')->nullable();

            $table->timestamp('created_at')->nullable();

            // Bounded top-N per period/dimension is enforced at write time
            // (ingestion truncates before insert); this index is what makes
            // "give me this site's top 10 countries for last 28 days" cheap
            // without a table scan once cardinality grows.
            $table->index(['site_id', 'dimension_type', 'period_start', 'period_end'], 'traffic_dim_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('traffic_dimension_snapshots');
        Schema::dropIfExists('traffic_daily');
    }
};
