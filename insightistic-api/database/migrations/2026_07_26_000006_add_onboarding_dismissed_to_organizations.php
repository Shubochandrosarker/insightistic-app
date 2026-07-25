<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Onboarding progress is *derived* from real state (does a site exist? is a
 * license activated? has a source synced?) rather than stored, so it can
 * never drift from reality and resumes correctly on any device without a
 * saved cursor.
 *
 * The one thing that genuinely cannot be derived is "I know, stop showing
 * me this" — that is a preference, not a fact about the account, so it is
 * the only field kept here.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->timestamp('onboarding_dismissed_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('onboarding_dismissed_at');
        });
    }
};
