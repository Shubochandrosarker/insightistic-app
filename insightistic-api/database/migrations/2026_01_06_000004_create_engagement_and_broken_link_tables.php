<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * First-party engagement events and technical-SEO 404 tracking (spec §8).
 * Data minimization is enforced at the schema level, not just in ingestion
 * code: broken_link_snapshots has no column for a visitor IP or a full
 * query string, and referrer_hosts is a bounded host-only JSON array — the
 * shape itself makes it impossible to accidentally persist either.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('engagement_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('event_name');
            $table->unsignedInteger('count')->default(0);

            $table->timestamps();

            $table->unique(['site_id', 'date', 'event_name']);
        });

        Schema::create('broken_link_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('path', 1000); // normalized, query string stripped

            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->unsignedInteger('hit_count')->default(1);

            // Bounded array of external referrer hostnames only (no path,
            // no query string, never an IP) — capped at write time.
            $table->jsonb('referrer_hosts')->nullable();

            $table->string('status')->default('open'); // open|resolved
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['site_id', 'path']);
            $table->index(['site_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broken_link_snapshots');
        Schema::dropIfExists('engagement_daily');
    }
};
