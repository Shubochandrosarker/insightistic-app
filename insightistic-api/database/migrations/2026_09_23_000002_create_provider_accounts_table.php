<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Organization-level provider accounts (2026-09-23 milestone, spec §5).
 *
 * ONE Google/Microsoft/Cloudflare/Shopify authorization serves every site in
 * the organization. Tokens live here, encrypted; per-site
 * data_source_credentials keep only the resource selection (which GSC
 * property / GA4 property) and reference the shared account.
 *
 * Also adds data_source_connections.connection_method (spec §12) so the UI
 * can distinguish "Google Search Console — connected via OAuth" from
 * "connected via WordPress plugin" for the same source.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32); // google|bing|cloudflare|shopify
            // Provider-stable account id (Google `sub`, Microsoft user id, …)
            $table->string('external_account_id', 191)->nullable();
            $table->string('email')->nullable();
            $table->string('display_name')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->json('scopes_json')->nullable();
            $table->json('metadata_json')->nullable();
            $table->foreignId('connected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24)->default('active'); // active|error|revoked
            $table->timestamps();

            $table->unique(['organization_id', 'provider', 'external_account_id'], 'provider_accounts_org_provider_ext_unique');
            $table->index(['organization_id', 'provider']);
        });

        Schema::table('data_source_credentials', function (Blueprint $table) {
            $table->foreignId('provider_account_id')->nullable()
                ->after('organization_id')->constrained()->nullOnDelete();
        });

        Schema::table('data_source_connections', function (Blueprint $table) {
            // oauth | api_key | api_token | wordpress_plugin | shopify_app | php_sdk | node_sdk | generic_api | manual
            $table->string('connection_method', 32)->nullable()->after('state');
        });
    }

    public function down(): void
    {
        Schema::table('data_source_connections', function (Blueprint $table) {
            $table->dropColumn('connection_method');
        });
        Schema::table('data_source_credentials', function (Blueprint $table) {
            $table->dropConstrainedForeignId('provider_account_id');
        });
        Schema::dropIfExists('provider_accounts');
    }
};
