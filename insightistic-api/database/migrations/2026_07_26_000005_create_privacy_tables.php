<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Privacy and compliance (spec Phase 10): organization data export and the
 * organization deletion workflow.
 *
 * Deletion is deliberately two-stage — a request sets a scheduled date, and
 * a purge command carries it out after the grace window. Immediate hard
 * deletion has no undo, and "I clicked the wrong button" is a far more
 * common event than "I need this gone within the hour". The grace window is
 * also what makes the request cancellable.
 *
 * Exports expire: an archive of a whole tenant's data sitting on disk
 * indefinitely is a liability, so the purge command removes both the row and
 * the file once `expires_at` passes.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('organization_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // queued|processing|ready|failed|expired
            $table->string('status', 20)->default('queued');
            $table->string('disk', 40)->nullable();
            $table->string('storage_path', 500)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->text('error_message')->nullable();

            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index('expires_at');
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->timestamp('deletion_requested_at')->nullable()->after('status');
            $table->timestamp('deletion_scheduled_for')->nullable()->after('deletion_requested_at');
            $table->foreignId('deletion_requested_by_user_id')->nullable()
                ->after('deletion_scheduled_for')
                ->constrained('users')->nullOnDelete();

            // The purge command scans on this; without it the nightly job
            // table-scans every organization forever.
            $table->index('deletion_scheduled_for');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropForeign(['deletion_requested_by_user_id']);
            $table->dropIndex(['deletion_scheduled_for']);
            $table->dropColumn(['deletion_requested_at', 'deletion_scheduled_for', 'deletion_requested_by_user_id']);
        });

        Schema::dropIfExists('organization_exports');
    }
};
