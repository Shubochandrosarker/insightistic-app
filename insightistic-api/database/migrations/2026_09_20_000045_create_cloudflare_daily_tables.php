<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9 Cloudflare Edge Intelligence (docs/roadmap-2026/15-CLOUDFLARE-EDGE-INTELLIGENCE.md).
 *
 * cloudflare_daily     one edge snapshot per site per UTC day, written by the
 *                      nightly IntegrationManager cloudflare sync (idempotent
 *                      upsert on site+date). KPI columns are the roadmap's
 *                      Traffic & Edge Overview list; countries/status/firewall
 *                      breakdowns live in json columns so the endpoint never
 *                      has to call Cloudflare live. Firewall actions get their
 *                      own firewall_json ({action: count}) rather than being
 *                      squeezed into status_breakdown_json — they are security
 *                      events, not response statuses, and the Security section
 *                      reads them separately.
 * cloudflare_bot_daily known-bot requests per site/day/bot, classified against
 *                      roadmap 15's crawler list. Only bots we can actually
 *                      classify are stored, so the table stays bounded.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('cloudflare_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedBigInteger('requests')->default(0);
            $table->unsignedBigInteger('bytes')->default(0);
            $table->unsignedBigInteger('cached_requests')->default(0);
            $table->unsignedBigInteger('cached_bytes')->default(0);
            $table->unsignedBigInteger('encrypted_requests')->default(0);
            $table->unsignedBigInteger('pageviews')->default(0);
            $table->unsignedBigInteger('threats')->default(0);
            $table->unsignedBigInteger('client_4xx')->default(0);
            $table->unsignedBigInteger('client_5xx')->default(0);
            $table->json('countries_json')->nullable()->comment('top 10 {country: requests} for this day');
            $table->json('status_breakdown_json')->nullable()->comment('{edgeResponseStatus: requests}');
            $table->json('firewall_json')->nullable()->comment('{action: events} for this day');
            $table->timestamps();

            $table->unique(['site_id', 'date']);
        });

        Schema::create('cloudflare_bot_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('bot_name', 64);
            $table->string('classification', 32)->default('ai_crawler')->comment('ai_crawler|search_bot|other');
            $table->unsignedBigInteger('requests')->default(0);
            $table->unsignedBigInteger('blocked')->default(0);
            $table->timestamps();

            $table->unique(['site_id', 'date', 'bot_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cloudflare_bot_daily');
        Schema::dropIfExists('cloudflare_daily');
    }
};
