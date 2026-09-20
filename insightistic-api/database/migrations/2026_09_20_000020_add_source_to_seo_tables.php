<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEO tables become multi-source (roadmap 11-BING-WEBMASTER-INDEXNOW):
 * `source` separates Search Console rows from Bing Webmaster rows in the
 * same tables, so the dashboard can filter or combine providers without
 * duplicating the schema.
 *
 * Default 'gsc' back-fills every existing row in place — no data migration
 * needed, pre-Bing history IS Search Console history. The Bing weekly query
 * numbers (AvgClickPosition / AvgImpressionPosition) have no GSC equivalent,
 * so they get their own nullable columns on `seo_query_snapshots`.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('seo_daily', function (Blueprint $table) {
            // Per-provider daily totals need a per-provider unique key.
            $table->dropUnique('seo_daily_site_id_date_unique');

            $table->string('source', 16)->default('gsc')->after('date');

            $table->unique(['site_id', 'source', 'date']);
        });

        Schema::table('seo_query_snapshots', function (Blueprint $table) {
            $table->string('source', 16)->default('gsc');

            $table->decimal('avg_click_position', 6, 2)->nullable();
            $table->decimal('avg_impression_position', 6, 2)->nullable();

            $table->index('source');
        });

        Schema::table('seo_page_snapshots', function (Blueprint $table) {
            $table->string('source', 16)->default('gsc');

            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::table('seo_daily', function (Blueprint $table) {
            $table->dropUnique('seo_daily_site_id_source_date_unique');
            $table->dropColumn('source');

            // Collapses providers back into one key — only valid while a
            // site carries a single source per date.
            $table->unique(['site_id', 'date']);
        });

        Schema::table('seo_query_snapshots', function (Blueprint $table) {
            $table->dropIndex('seo_query_snapshots_source_index');
            $table->dropColumn(['source', 'avg_click_position', 'avg_impression_position']);
        });

        Schema::table('seo_page_snapshots', function (Blueprint $table) {
            $table->dropIndex('seo_page_snapshots_source_index');
            $table->dropColumn('source');
        });
    }
};
