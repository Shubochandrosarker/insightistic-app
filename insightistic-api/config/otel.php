<?php

/*
|--------------------------------------------------------------------------
| OpenTelemetry / internal telemetry
|--------------------------------------------------------------------------
| Tracing is off unless OTEL_ENABLED=true and an exporter endpoint is set, so
| a default install neither exports anything nor pays the cost of building
| spans it will throw away.
|
| The exporter is OTLP over HTTP/protobuf, which every collector (Grafana
| Tempo, Honeycomb, Jaeger, Datadog's OTLP intake, Sentry) accepts. gRPC would
| need ext-grpc, which the runtime image does not carry.
*/

return [

    'enabled' => filter_var(env('OTEL_ENABLED', false), FILTER_VALIDATE_BOOL),

    'service_name' => env('OTEL_SERVICE_NAME', 'insightistic-api'),

    'service_version' => env('OTEL_SERVICE_VERSION', env('SENTRY_RELEASE', 'dev')),

    /*
     * Full OTLP traces endpoint, e.g. http://collector:4318/v1/traces.
     * Left empty, tracing stays off no matter what OTEL_ENABLED says — an
     * exporter with nowhere to send is just latency.
     */
    'endpoint' => env('OTEL_EXPORTER_OTLP_ENDPOINT'),

    /*
     * Extra headers for the exporter, as `key=value,key2=value2` — this is
     * where a vendor API key goes. Read from the environment and never logged.
     */
    'headers' => env('OTEL_EXPORTER_OTLP_HEADERS', ''),

    'timeout' => (int) env('OTEL_EXPORTER_TIMEOUT', 5),

    /*
     * Head sampling. 1.0 traces everything, which is fine for a low-traffic
     * install and ruinous for a busy one; the default of 0.05 keeps a useful
     * sample without flooding a collector.
     */
    'sample_rate' => (float) env('OTEL_SAMPLE_RATE', 0.05),

    /*
     * Request timing written to `telemetry_requests`. Independent of tracing:
     * it is two integer writes per request into an hourly bucket, cheap enough
     * to leave on by default, and it is what the admin dashboard reads.
     */
    'request_metrics' => filter_var(env('OTEL_REQUEST_METRICS', true), FILTER_VALIDATE_BOOL),

    /*
     * How long hourly telemetry buckets are kept. Short by design — this data
     * answers "what is happening now" and "what changed this week"; anything
     * older is a question for the traces and errors that carry more detail.
     */
    'retention_days' => (int) env('OTEL_RETENTION_DAYS', 30),

    /*
     * Paths never traced or timed. Health checks run every few seconds and
     * would otherwise dominate both the sample and the metrics table.
     */
    'ignore_paths' => [
        'up',
        'api/health',
    ],
];
