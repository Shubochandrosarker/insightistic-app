<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Explicit archive/restore (spec §13) via a nullable `archived_at`, not
 * Laravel's SoftDeletes — this codebase has no models using SoftDeletes
 * anywhere, and its global "hide archived rows automatically" scope would
 * silently change what every existing query across the app returns.
 * Archived-aware filtering is opt-in per query (Admin controllers only).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('status');
            $table->text('internal_notes')->nullable()->after('archived_at');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('status');
        });
        Schema::table('sites', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('connection_status');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['archived_at', 'internal_notes']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('archived_at');
        });
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('archived_at');
        });
    }
};
