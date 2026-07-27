<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Request and AI telemetry (spec Phase 2.3).
 *
 * Requests are stored **pre-aggregated into hourly buckets per route**, not
 * one row per request. A busy site would write millions of rows a day, and the
 * questions this data answers — which endpoints are slow, which are erroring,
 * how latency moved this week — are all bucket questions. Per-request detail
 * belongs in traces (OTLP) and errors (Sentry), which are sampled and expire;
 * duplicating them in Postgres forever would cost more than it is worth.
 *
 * Percentiles are approximated by keeping a running max plus bucketed counts
 * rather than storing every duration. An exact p95 needs the full sample; a
 * counter of "how many requests took over 1s" answers the operational question
 * without unbounded storage.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('telemetry_requests', function (Blueprint $table) {
            $table->id();

            // The hour this bucket covers, always UTC — comparing latency
            // across deployments in different timezones is otherwise a trap.
            $table->timestamp('bucket_hour');

            // Route *pattern*, never the resolved URI: `sites/{site}/insights`
            // rather than `sites/42/insights`. Storing resolved paths would
            // both explode cardinality and put tenant ids in a table that is
            // deliberately not tenant-scoped.
            $table->string('route', 200);
            $table->string('method', 10);
            $table->unsignedSmallInteger('status');

            $table->unsignedInteger('requests')->default(0);
            $table->unsignedBigInteger('duration_ms_total')->default(0);
            $table->unsignedInteger('duration_ms_max')->default(0);

            // Threshold counters, in place of storing every sample.
            $table->unsignedInteger('over_500ms')->default(0);
            $table->unsignedInteger('over_1s')->default(0);
            $table->unsignedInteger('over_3s')->default(0);

            $table->timestamps();

            $table->unique(['bucket_hour', 'route', 'method', 'status'], 'telemetry_requests_bucket_unique');
            $table->index('bucket_hour');
        });

        Schema::create('telemetry_ai_usage', function (Blueprint $table) {
            $table->id();

            $table->timestamp('bucket_hour');

            /*
             * Nullable on purpose: embeddings and scheduled insight generation
             * happen outside any organization's request cycle for platform
             * maintenance, and forcing a tenant id would mean either inventing
             * one or dropping the row.
             *
             * Deliberately NOT a foreign key. Two reasons, both learned the
             * hard way:
             *
             *  - A failing FK check inside a transaction poisons it, and this
             *    write happens *inside whatever transaction the caller is
             *    running*. A telemetry insert must never be able to take down
             *    the request it is measuring.
             *  - `nullOnDelete` would silently merge a departed customer's
             *    buckets into the platform-level (null) rows, corrupting the
             *    aggregate rather than removing it. Deletion is handled by the
             *    privacy purger, which scopes by this column like any other
             *    org-owned table.
             */
            $table->unsignedBigInteger('organization_id')->nullable();

            // copilot|insight|embedding|report — what the spend was for.
            $table->string('kind', 40);
            $table->string('provider', 60);
            $table->string('model', 120);

            $table->unsignedInteger('calls')->default(0);
            $table->unsignedInteger('failures')->default(0);
            $table->unsignedBigInteger('latency_ms_total')->default(0);
            $table->unsignedInteger('latency_ms_max')->default(0);
            $table->unsignedBigInteger('tokens_in')->default(0);
            $table->unsignedBigInteger('tokens_out')->default(0);

            // 10,6 matches ai_runs.cost_usd — per-call costs are fractions of a
            // cent and rounding them to 2dp would report every call as free.
            $table->decimal('cost_usd', 12, 6)->default(0);

            $table->timestamps();

            $table->unique(['bucket_hour', 'organization_id', 'kind', 'provider', 'model'], 'telemetry_ai_bucket_unique');
            $table->index('bucket_hour');
            $table->index(['organization_id', 'bucket_hour']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telemetry_ai_usage');
        Schema::dropIfExists('telemetry_requests');
    }
};
