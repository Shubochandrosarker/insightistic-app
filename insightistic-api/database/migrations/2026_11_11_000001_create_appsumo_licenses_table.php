<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AppSumo Licensing API v2 intake (partner guide). One row per AppSumo
 * license_key, upserted by webhook events and the OAuth claim flow. Partner
 * guide rule: all license_key data must be stored and searchable by support —
 * AppSumo keeps no customer emails, only keys.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appsumo_licenses', function (Blueprint $table) {
            $table->id();
            // AppSumo-managed UUID — the join key for every event and lookup.
            $table->string('license_key', 64)->unique();
            // Last webhook event seen: purchase|activate|upgrade|downgrade|migrate|deactivate.
            $table->string('event', 20)->nullable();
            // AppSumo-side status: inactive|active|deactivated.
            $table->string('license_status', 20)->default('inactive');
            $table->unsignedInteger('tier')->nullable();
            // upgrade/downgrade issue a NEW key; prev_license_key links to the
            // replaced one (the old key also gets a simultaneous deactivate).
            $table->string('prev_license_key', 64)->nullable()->index();
            // Deal add-ons only: link to the parent deal's license key.
            $table->string('parent_license_key', 64)->nullable();
            $table->string('partner_plan_name', 120)->nullable();
            $table->unsignedInteger('unit_quantity')->nullable();
            // appsumo_solo|appsumo_studio|appsumo_agency (tier → commercial.appsumo_plans order).
            $table->string('plan_slug', 60)->nullable();
            // Set once the buyer claims the license through the OAuth flow.
            $table->foreignId('org_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // The org-level `licenses` row whose plan/status we project onto.
            $table->foreignId('license_id')->nullable()->constrained()->nullOnDelete();
            $table->jsonb('raw')->nullable();
            $table->unsignedBigInteger('event_timestamp')->default(0);
            $table->boolean('is_test')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appsumo_licenses');
    }
};
