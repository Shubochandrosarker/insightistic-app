<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-neutral Site model (2026-09-23 connection-architecture milestone).
 *
 * A "site" is a website project, not a WordPress install. New columns:
 * - normalized_domain  canonical dedupe key (lowercase host, no www.)
 * - canonical_url      scheme://host the user entered (or GSC property root)
 * - detected_platform  sniffed from the live site (wordpress/shopify/...)
 * - platform_override  user-chosen platform when detection is wrong
 * - country/language   optional locale metadata
 * - status             active|paused|archived lifecycle (archived_at kept
 *                      for back-compat with the Phase 13 archive feature)
 *
 * The legacy `platform` column stays (existing plugin activations read it)
 * but stops being the feature gate; new URL-created sites are 'generic'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('normalized_domain')->nullable()->after('domain');
            $table->string('canonical_url')->nullable()->after('normalized_domain');
            $table->string('detected_platform', 32)->nullable()->after('platform');
            $table->string('platform_override', 32)->nullable()->after('detected_platform');
            $table->string('country', 2)->nullable()->after('timezone');
            $table->string('language', 12)->nullable()->after('country');
            $table->string('status', 24)->default('active')->after('language');
            $table->index(['organization_id', 'normalized_domain'], 'sites_org_normalized_domain_idx');
        });

        // Backfill: every existing site gets a dedupe key + detected platform
        // mirroring its legacy platform value, and an explicit active status.
        foreach (DB::table('sites')->whereNull('normalized_domain')->get(['id', 'domain', 'platform']) as $site) {
            DB::table('sites')->where('id', $site->id)->update([
                'normalized_domain' => App\Support\DomainNormalizer::hostKey((string) $site->domain),
                'detected_platform' => $site->platform ?: null,
                'status'            => 'active',
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropIndex('sites_org_normalized_domain_idx');
            $table->dropColumn([
                'normalized_domain', 'canonical_url', 'detected_platform',
                'platform_override', 'country', 'language', 'status',
            ]);
        });
    }
};
