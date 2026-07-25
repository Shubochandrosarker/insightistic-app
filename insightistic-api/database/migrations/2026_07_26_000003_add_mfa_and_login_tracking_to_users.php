<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TOTP multi-factor auth + login-notification tracking (spec Phase 1.4).
 *
 * The shared secret and the recovery codes are both encrypted at rest via
 * Eloquent casts (APP_KEY): a database dump alone must not be enough to mint
 * valid codes or bypass the second factor.
 *
 * `mfa_confirmed_at` is what actually enables MFA — a secret is written the
 * moment setup starts, and enrolling is only complete once the user has
 * proved they can generate a code from it. Without that split, a failed
 * enrolment would lock a user out of their own account.
 *
 * `last_login_fingerprint` stores a hash of (IP, user agent), never the raw
 * values, so "this is a new device" can be answered without retaining a log
 * of where somebody signs in from.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('mfa_secret')->nullable()->after('password');
            $table->timestamp('mfa_confirmed_at')->nullable()->after('mfa_secret');
            $table->text('mfa_recovery_codes')->nullable()->after('mfa_confirmed_at');
            $table->string('last_login_fingerprint', 64)->nullable()->after('mfa_recovery_codes');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['mfa_secret', 'mfa_confirmed_at', 'mfa_recovery_codes', 'last_login_fingerprint']);
        });
    }
};
