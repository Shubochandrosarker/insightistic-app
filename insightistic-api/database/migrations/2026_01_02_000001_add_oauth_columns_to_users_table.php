<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Social login support. OAuth users (Google / Microsoft / GitHub) have no local
 * password, so `password` becomes nullable and we record the originating
 * provider for account linking + display.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'provider')) {
                $table->string('provider')->nullable()->after('status');        // google|microsoft|github
            }
            if (! Schema::hasColumn('users', 'provider_id')) {
                $table->string('provider_id')->nullable()->after('provider');
            }
            if (! Schema::hasColumn('users', 'avatar_url')) {
                $table->string('avatar_url')->nullable()->after('provider_id');
            }
        });

        // Make password nullable so OAuth-only accounts are valid.
        if (Schema::hasColumn('users', 'password')) {
            $driver = DB::getDriverName();
            if ($driver === 'pgsql') {
                DB::statement('ALTER TABLE users ALTER COLUMN password DROP NOT NULL');
            } elseif ($driver === 'mysql' || $driver === 'mariadb') {
                DB::statement('ALTER TABLE users MODIFY password VARCHAR(255) NULL');
            }
            // sqlite columns are nullable-friendly by default; nothing to do.
        }

        Schema::table('users', function (Blueprint $table) {
            $table->index(['provider', 'provider_id']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['provider', 'provider_id']);
            $table->dropColumn(['provider', 'provider_id', 'avatar_url']);
        });
    }
};
