<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extends the existing sync_logs table (spec §8 "Sync operations") rather
 * than adding a parallel table — sync_logs already has site_id/job/status/
 * records/message/started_at/finished_at, which covers most of what the
 * spec asks for. This adds only what's missing: the fields connector v2's
 * chunked, resumable, idempotent sync needs that a single-shot v1 sync
 * never did.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('sync_logs', function (Blueprint $table) {
            $table->string('sync_batch_id')->nullable()->after('site_id');
            $table->string('schema_version')->nullable()->after('job');
            $table->string('cursor')->nullable()->after('records');
            $table->unsignedSmallInteger('attempts')->default(1)->after('cursor');
            $table->string('idempotency_key')->nullable()->after('attempts');
        });

        Schema::table('sync_logs', function (Blueprint $table) {
            $table->index('sync_batch_id');
            $table->index('idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::table('sync_logs', function (Blueprint $table) {
            $table->dropIndex(['sync_batch_id']);
            $table->dropIndex(['idempotency_key']);
            $table->dropColumn(['sync_batch_id', 'schema_version', 'cursor', 'attempts', 'idempotency_key']);
        });
    }
};
