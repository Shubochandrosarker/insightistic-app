<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable Super Admin audit trail (spec §13.1). Every admin mutation from
 * this point on writes exactly one row here — see App\Services\AuditLogger.
 * Never logs secrets or full license keys; before/after diffs are built by
 * callers from already-redacted arrays (e.g. Admin\LicenseController never
 * has the raw key in scope to log in the first place).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('admin_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action'); // e.g. organization.archive, license.revoke, user.promote
            $table->string('target_type'); // e.g. Organization, User, Site, License
            $table->unsignedBigInteger('target_id')->nullable();
            $table->jsonb('before_json')->nullable();
            $table->jsonb('after_json')->nullable();
            $table->text('reason')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->uuid('correlation_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['target_type', 'target_id']);
            $table->index('actor_user_id');
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_audit_logs');
    }
};
