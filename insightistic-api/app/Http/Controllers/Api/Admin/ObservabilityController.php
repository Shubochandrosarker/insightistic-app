<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Observability\QueueHealth;
use App\Services\Observability\Telemetry;
use App\Services\Observability\Tracing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Platform observability (spec Phase 2.3).
 *
 * Super-admin only, and deliberately not tenant-scoped: these are questions
 * about the platform, not about any one organization. Route latency covers
 * every tenant's traffic and AI spend is aggregate, so exposing it inside an
 * organization's dashboard would leak the shape of other customers' usage.
 */
class ObservabilityController extends Controller
{
    public function __construct(
        private Telemetry $telemetry,
        private QueueHealth $queue,
        private Tracing $tracing,
    ) {}

    public function overview(Request $request)
    {
        $hours = $this->hours($request);

        return response()->json([
            'window_hours' => $hours,
            'requests'     => $this->telemetry->requestSummary($hours),
            'series'       => $this->telemetry->requestSeries($hours),
            'ai'           => $this->telemetry->aiSummary($hours),
            'queue'        => $this->queue->snapshot(),
            'exporters'    => $this->exporters(),
        ]);
    }

    public function requests(Request $request)
    {
        return response()->json($this->telemetry->requestSummary($this->hours($request), 50));
    }

    public function ai(Request $request)
    {
        return response()->json($this->telemetry->aiSummary($this->hours($request)));
    }

    public function queue()
    {
        return response()->json($this->queue->snapshot());
    }

    /**
     * Which telemetry sinks are actually wired up.
     *
     * Reports configured/not-configured, never the DSN or headers themselves —
     * those carry credentials, and an admin UI is not a reason to put a
     * vendor API key on the wire.
     */
    private function exporters(): array
    {
        $dsn = (string) config('sentry.dsn');

        return [
            'errors' => [
                'driver'     => 'sentry',
                'configured' => $dsn !== '',
                'environment' => config('sentry.environment'),
                'release'    => config('sentry.release'),
                'traces_sample_rate' => (float) config('sentry.traces_sample_rate'),
            ],
            'tracing' => [
                'driver'     => 'opentelemetry',
                'enabled'    => (bool) config('otel.enabled'),
                'exporting'  => $this->tracing->enabled(),
                'service'    => config('otel.service_name'),
                'sample_rate' => (float) config('otel.sample_rate'),
                // Host only: the full endpoint can embed a token in its path.
                'endpoint_host' => config('otel.endpoint') ? parse_url((string) config('otel.endpoint'), PHP_URL_HOST) : null,
            ],
            'metrics' => [
                'driver'         => 'database',
                'enabled'        => (bool) config('otel.request_metrics', true),
                'retention_days' => (int) config('otel.retention_days', 30),
                'buckets'        => [
                    'requests' => DB::table('telemetry_requests')->count(),
                    'ai'       => DB::table('telemetry_ai_usage')->count(),
                ],
            ],
        ];
    }

    /** Bounded window: an unbounded one would table-scan the whole history. */
    private function hours(Request $request): int
    {
        return max(1, min(720, (int) $request->integer('hours', 24)));
    }
}
