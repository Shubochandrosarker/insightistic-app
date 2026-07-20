<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Super Admin entitlement override (spec §13.8): a way to grant access
 * without touching Stripe's own state (comps, disputes-in-progress, manual
 * deals). LicenseService::computeStatus() treats internal_override_active
 * as "force active" — Stripe's subscription/invoice data is never rewritten
 * to pretend the override happened there.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->boolean('internal_override_active')->default(false)->after('cancel_at_period_end');
            $table->text('internal_override_reason')->nullable()->after('internal_override_active');
            $table->foreignId('internal_override_by')->nullable()->after('internal_override_reason')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('internal_override_at')->nullable()->after('internal_override_by');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('internal_override_by');
            $table->dropColumn(['internal_override_active', 'internal_override_reason', 'internal_override_at']);
        });
    }
};
