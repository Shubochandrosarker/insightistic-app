<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Server-side credentials for direct SaaS integrations (spec Phase 3.2).
 *
 * Deliberately separate from `data_source_connections`: that table is the
 * display/health story and is documented as never holding a secret, and
 * keeping it that way means anything that renders it cannot leak a token by
 * accident. This table is the opposite — it holds nothing *but* the grant, is
 * never serialised to the browser, and every token column is encrypted at
 * rest through Eloquent's `encrypted` cast (APP_KEY).
 *
 * One row per (site, source). A single Google grant can serve both GA4 and
 * GSC, but the rows stay separate so a user can disconnect Search Console
 * without also losing Analytics.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('data_source_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();

            // ga4|gsc|cloudflare — matches DataSourceConnection::SOURCES
            $table->string('source', 30);
            // google|cloudflare — which credential exchange produced this row
            $table->string('provider', 30);

            // Encrypted at the application layer. Text (not string) because
            // ciphertext is substantially longer than the plaintext token.
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();

            $table->jsonb('scopes_json')->nullable();

            // Non-secret identity of the granting account, shown in the UI so
            // a user can tell *which* Google account is connected.
            $table->string('external_account_id', 320)->nullable();

            // The chosen GA4 property / GSC site / Cloudflare zone.
            $table->jsonb('resource_json')->nullable();

            $table->foreignId('connected_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['site_id', 'source']);
            $table->index(['organization_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_source_credentials');
    }
};
