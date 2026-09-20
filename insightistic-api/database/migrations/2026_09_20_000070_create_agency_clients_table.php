<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 13 Agency portfolio (docs/roadmap-2026/20-REPORTS-AGENCY-WHITELABEL.md,
 * "Client grouping"): the OPTIONAL client/account entity between organization
 * and site.
 *
 *   organization -> clients -> sites
 *
 * Client grouping is never forced on single-site users — a site with
 * client_id null simply belongs to no client, and every portfolio query
 * treats that as a first-class state. Deleting a client must NOT delete its
 * sites, so the sites.client_id FK is nullOnDelete: the controller also
 * unassigns explicitly first, but the database level rule is the backstop.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('agency_clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('contact_email')->nullable();
            // Free-form agency notes about the client relationship
            // (billing contact, engagement scope...). Not exposed to
            // client viewers — the agency's own scratchpad.
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index('organization_id');
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->foreignId('client_id')
                ->nullable()
                ->constrained('agency_clients')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_id');
        });

        Schema::dropIfExists('agency_clients');
    }
};
