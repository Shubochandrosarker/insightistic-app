<?php

namespace App\Services\Observability;

use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\Sampler\ParentBased;
use OpenTelemetry\SDK\Trace\Sampler\TraceIdRatioBasedSampler;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\ResourceAttributes;

/**
 * OpenTelemetry tracing (spec Phase 2.3).
 *
 * Deliberately a thin, lazily-built wrapper rather than the auto-instrumentation
 * package: that one needs `ext-opentelemetry`, which the runtime image does not
 * carry, so depending on it would mean tracing that works on a developer laptop
 * and silently does nothing in production.
 *
 * Nothing is constructed until the first span is requested, and nothing at all
 * when tracing is disabled — the SDK's no-op tracer costs a method call and no
 * allocation, so instrumentation can be left in the code paths unconditionally.
 */
class Tracing
{
    private ?TracerProvider $provider = null;

    private ?TracerInterface $tracer = null;

    private bool $initialised = false;

    public function enabled(): bool
    {
        return (bool) config('otel.enabled') && ! empty(config('otel.endpoint'));
    }

    /**
     * Run a closure inside a span, recording exceptions and timing.
     *
     * The span is ended in a `finally` so a throwing callback still closes it —
     * an unclosed span is worse than no span, because the trace waits for a
     * child that never finishes and gets reported as a timeout.
     */
    public function span(string $name, callable $callback, array $attributes = [], int $kind = SpanKind::KIND_INTERNAL)
    {
        if (! $this->enabled()) {
            return $callback();
        }

        $span = $this->start($name, $attributes, $kind);
        $scope = $span->activate();

        try {
            return $callback($span);
        } catch (\Throwable $e) {
            $span->recordException($e);
            $span->setStatus(\OpenTelemetry\API\Trace\StatusCode::STATUS_ERROR, $e->getMessage());
            throw $e;
        } finally {
            $scope->detach();
            $span->end();
        }
    }

    public function start(string $name, array $attributes = [], int $kind = SpanKind::KIND_INTERNAL): SpanInterface
    {
        $builder = $this->tracer()->spanBuilder($name)->setSpanKind($kind);

        foreach ($attributes as $key => $value) {
            // Attribute values reach the collector verbatim, so they go
            // through the same key filter as error payloads.
            $builder->setAttribute($key, EventScrubber::isSensitive((string) $key) ? EventScrubber::REDACTED : $value);
        }

        return $builder->startSpan();
    }

    public function tracer(): TracerInterface
    {
        if ($this->tracer !== null) {
            return $this->tracer;
        }

        $this->boot();

        return $this->tracer;
    }

    /**
     * Flush pending spans.
     *
     * PHP-FPM tears the process down at the end of a request, so a batch
     * processor holding spans in memory would lose them. The middleware calls
     * this after the response is sent.
     */
    public function flush(): void
    {
        if ($this->provider !== null) {
            $this->provider->forceFlush();
        }
    }

    public function shutdown(): void
    {
        if ($this->provider !== null) {
            $this->provider->shutdown();
            $this->provider = null;
            $this->tracer = null;
            $this->initialised = false;
        }
    }

    private function boot(): void
    {
        if ($this->initialised) {
            return;
        }

        $this->initialised = true;

        if (! $this->enabled()) {
            // The API package's no-op tracer: spans are created and discarded
            // without touching the network.
            $this->tracer = (new \OpenTelemetry\API\Trace\NoopTracerProvider())->getTracer('insightistic');

            return;
        }

        try {
            $transport = (new OtlpHttpTransportFactory())->create(
                (string) config('otel.endpoint'),
                'application/x-protobuf',
                $this->headers(),
                null,
                0,
                100,
                1,
                (int) config('otel.timeout', 5),
            );

            $resource = ResourceInfoFactory::defaultResource()->merge(ResourceInfo::create(Attributes::create([
                ResourceAttributes::SERVICE_NAME       => (string) config('otel.service_name'),
                ResourceAttributes::SERVICE_VERSION    => (string) config('otel.service_version'),
                ResourceAttributes::DEPLOYMENT_ENVIRONMENT_NAME => (string) config('app.env'),
            ])));

            $this->provider = TracerProvider::builder()
                ->addSpanProcessor(new BatchSpanProcessor(new SpanExporter($transport), \OpenTelemetry\SDK\Common\Time\ClockFactory::getDefault()))
                ->setResource($resource)
                // ParentBased means a request arriving with an upstream
                // sampling decision keeps it, so a trace is never half-captured.
                ->setSampler(new ParentBased(new TraceIdRatioBasedSampler((float) config('otel.sample_rate', 0.05))))
                ->build();

            $this->tracer = $this->provider->getTracer('insightistic');
        } catch (\Throwable $e) {
            // A misconfigured collector must not take the application with it.
            report($e);
            $this->tracer = (new \OpenTelemetry\API\Trace\NoopTracerProvider())->getTracer('insightistic');
        }
    }

    /** Parse `key=value,key2=value2` into an exporter header map. */
    private function headers(): array
    {
        $raw = trim((string) config('otel.headers', ''));

        if ($raw === '') {
            return [];
        }

        $headers = [];

        foreach (explode(',', $raw) as $pair) {
            if (! str_contains($pair, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $pair, 2);
            $headers[trim($key)] = trim($value);
        }

        return $headers;
    }
}
