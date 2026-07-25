<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Insight lifecycle (spec Phase 6).
 *
 * `status` (unread|read|done) could not express the states users actually
 * need: an insight can be read *and* saved, and dismissing is not the same
 * as completing. Saved and dismissed are therefore separate timestamps
 * rather than more status values — they are orthogonal to how far through
 * it you are.
 *
 * `expires_at` is promoted out of `source_data_json`, where the generator
 * already wrote it, into a real column. Freshness was unqueryable while it
 * lived inside JSON, so nothing could filter or sort on it.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('ai_insights', function (Blueprint $table) {
            $table->timestamp('saved_at')->nullable()->after('status');
            $table->timestamp('dismissed_at')->nullable()->after('saved_at');
            $table->timestamp('expires_at')->nullable()->after('dismissed_at');
            $table->timestamp('actioned_at')->nullable()->after('expires_at');

            // The dashboard's default read: this site's live insights, newest
            // first, excluding dismissed ones.
            $table->index(['site_id', 'dismissed_at', 'created_at'], 'ai_insights_live_idx');
            $table->index(['organization_id', 'expires_at']);
        });

        // Backfill from the JSON the generator has been writing all along, so
        // existing insights get correct freshness instead of a null column.
        if (DB::getDriverName() === 'pgsql') {
            // jsonb_exists() rather than the `?` operator: PDO treats a bare
            // `?` as a parameter placeholder and mangles the statement.
            DB::statement(<<<'SQL'
                UPDATE ai_insights
                SET expires_at = (source_data_json ->> 'expires_at')::timestamptz
                WHERE jsonb_exists(source_data_json, 'expires_at')
                  AND expires_at IS NULL
            SQL);
        }
    }

    public function down(): void
    {
        Schema::table('ai_insights', function (Blueprint $table) {
            $table->dropIndex('ai_insights_live_idx');
            $table->dropIndex(['organization_id', 'expires_at']);
            $table->dropColumn(['saved_at', 'dismissed_at', 'expires_at', 'actioned_at']);
        });
    }
};
