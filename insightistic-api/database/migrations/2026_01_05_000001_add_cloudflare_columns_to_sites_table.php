<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-site Cloudflare zone link (insightistic/docs/APP-CONNECT-WORKFLOW.md
 * §10) — the hosted, zero-config Traffic Insights path. A site links to at
 * most one Cloudflare zone; the token is a read-only API token the org owner
 * pastes on the Insightistic dashboard (not in WordPress). Nullable: most
 * sites will have none of these set until that dashboard flow exists.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('cloudflare_zone_id')->nullable()->after('plugin_version');
            $table->string('cloudflare_account_id')->nullable()->after('cloudflare_zone_id');
            $table->text('cloudflare_api_token')->nullable()->after('cloudflare_account_id'); // encrypted at rest
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['cloudflare_zone_id', 'cloudflare_account_id', 'cloudflare_api_token']);
        });
    }
};
