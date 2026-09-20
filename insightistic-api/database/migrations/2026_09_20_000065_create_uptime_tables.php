<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 12 uptime monitoring (docs/roadmap-2026/16-PERFORMANCE-UPTIME.md):
 * one monitor per site (MVP), the compact per-probe checks, and the
 * incident lifecycle rows (healthy -> suspected -> confirmed -> recovered).
 *
 * uptime_monitors is UNIQUE on site_id — one monitor per site is a
 * database-level impossibility to violate rather than a controller promise.
 * url is nullable because the overwhelmingly common case is "watch the
 * site's own domain": null resolves to https://{domain} at probe time
 * (UptimeMonitor::probeUrl()), while a custom URL (status page, API health
 * endpoint) can be pinned explicitly.
 *
 * ssl_expires_at / ssl_checked_at piggyback on the monitor row: the probe
 * job refreshes them at most once per 24h (SslInspector), so SSL state has
 * no table of its own.
 *
 * uptime_checks is append-only history (no updated_at): incident recovery
 * semantics live in uptime_incidents, checks are immutable observations.
 * Incidents are never deleted on recovery — "history is visible" is an
 * acceptance criterion, and availability math (uptime_checks, 30d window)
 * is computed over checks, not incidents.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('uptime_monitors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('url', 1000)->nullable()->comment('defaults to https://domain');
            $table->string('method', 8)->default('GET');
            $table->unsignedSmallInteger('expected_status')->default(200);
            // Plan-derived at creation (roadmap 16: Starter 10, Growth 5,
            // Agency 1-5); the scheduler enforces the per-monitor value.
            $table->unsignedSmallInteger('interval_minutes')->default(5);
            $table->boolean('active')->default(true);
            $table->timestamp('last_checked_at')->nullable();
            // SSL state, refreshed at most daily by SslInspector.
            $table->date('ssl_expires_at')->nullable();
            $table->timestamp('ssl_checked_at')->nullable();
            $table->timestamps();

            $table->unique('site_id');
        });

        Schema::create('uptime_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitor_id')->constrained('uptime_monitors')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('status', 8)->comment('up|down');
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedInteger('response_ms')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('checked_at');

            $table->index(['monitor_id', 'checked_at']);
        });

        Schema::create('uptime_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitor_id')->constrained('uptime_monitors')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->timestamp('started_at');
            // null confirmed_at = SUSPECTED (one failed probe, doc 16: a
            // single failure never alerts). Set once, by the second
            // consecutive down probe, >= interval after started_at.
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('recovered_at')->nullable();
            $table->string('cause', 255)->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uptime_incidents');
        Schema::dropIfExists('uptime_checks');
        Schema::dropIfExists('uptime_monitors');
    }
};
