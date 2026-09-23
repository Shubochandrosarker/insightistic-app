<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marketing attribution + product event taxonomy (spec §32/§33).
 *
 * Attribution: first-touch and last-touch are stored as whitelisted JSON on
 * the ORGANIZATION (signup creates the org) — landing URL, referrer, UTM
 * params, gclid/fbclid/wbraid click ids. No PII beyond what the user
 * already gave us; values are length-capped.
 *
 * product_events: the stable funnel taxonomy (signup_completed, site_added,
 * gsc_connected, checkout_started, purchase_completed, ltd_purchased, …).
 * props_json passes through a key whitelist — passwords, tokens and
 * customer PII can never end up in an advertising payload because they are
 * never accepted in the first place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->json('first_touch_json')->nullable();
            $table->json('last_touch_json')->nullable();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->json('attribution_json')->nullable();
        });

        Schema::create('product_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 64);
            $table->json('props_json')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();

            $table->index(['event', 'occurred_at']);
            $table->index(['organization_id', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_events');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('attribution_json');
        });
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['first_touch_json', 'last_touch_json']);
        });
    }
};
