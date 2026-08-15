<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anomaly Engine (spec: Insightistic VNext P7) — persisted, per-metric,
 * per-day anomaly findings from a median/MAD detector, generalizing the
 * single-purpose GA4-sessions-only detector already shipped in
 * `App\Services\Intelligence\AcquisitionIntelligence::trafficAnomalies()`
 * to a small fixed set of metrics across all three query-engine sources.
 * See docs/ANOMALY-ENGINE.md.
 *
 * `unique(site_id, metric_id, date)` is the dedup key — a day is either
 * anomalous for a metric or it isn't; re-scanning never creates a
 * duplicate row for a day already recorded.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('anomalies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('metric_id', 60);
            $table->date('date');
            $table->decimal('value', 16, 4);
            $table->decimal('baseline_median', 16, 4);
            $table->decimal('mad', 16, 4);
            $table->decimal('score', 8, 2);
            $table->string('direction', 10); // spike|drop
            $table->string('severity', 10); // low|medium|high
            $table->boolean('is_acknowledged')->default(false);
            $table->timestamp('detected_at');
            $table->timestamps();

            $table->unique(['site_id', 'metric_id', 'date']);
            $table->index(['organization_id', 'site_id', 'detected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anomalies');
    }
};
