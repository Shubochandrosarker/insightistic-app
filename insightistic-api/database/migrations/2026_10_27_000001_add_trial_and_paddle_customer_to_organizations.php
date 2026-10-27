<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 7-day no-payment trial bookkeeping (one trial per organization) and the
 * org-level Paddle customer link, so every Paddle customer resolves to exactly
 * one Insightistic organization even before a subscription exists (LTD and
 * credit-pack buyers never get a subscription row).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->timestamp('trial_started_at')->nullable();
            $table->string('trial_plan_slug', 64)->nullable();
            $table->string('paddle_customer_id', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropUnique(['paddle_customer_id']);
            $table->dropColumn(['trial_started_at', 'trial_plan_slug', 'paddle_customer_id']);
        });
    }
};
