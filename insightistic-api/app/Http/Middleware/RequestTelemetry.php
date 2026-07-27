<?php

namespace App\Http\Middleware;

use App\Services\Observability\Telemetry;
use App\Services\Observability\Tracing;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use OpenTelemetry\API\Trace\SpanKind;
use Symfony\Component\HttpFoundation\Response;

/**
 * Times every request and opens a trace span for it (spec Phase 2.3).
 *
 * Runs as the outermost application middleware so the measurement includes
 * everything the app does, including the other middleware. Timing from inside
 * the stack hides exactly the layers most likely to be slow — tenancy
 * resolution, rate limiting, auth.
 *
 * The route *pattern* is recorded rather than the resolved path. Storing
 * `sites/42/insights` would explode cardinality on a per-route metrics table
 * and put tenant ids into a table that is deliberately not tenant-scoped.
 */
class RequestTelemetry
{
    public function __construct(
        private Telemetry $telemetry,
        private Tracing $tracing,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->ignored($request)) {
            return $next($request);
        }

        $start = microtime(true);
        $span = null;
        $scope = null;

        if ($this->tracing->enabled()) {
            $span = $this->tracing->start('HTTP ' . $request->getMethod(), [
                'http.request.method' => $request->getMethod(),
                // Path only — query strings carry OAuth codes and tokens.
                'url.path'            => $request->getPathInfo(),
                'server.address'      => $request->getHost(),
                'user_agent.original' => Str::limit((string) $request->userAgent(), 200),
            ], SpanKind::KIND_SERVER);

            $scope = $span->activate();
        }

        try {
            $response = $next($request);
        } finally {
            $durationMs = (microtime(true) - $start) * 1000;
            $status = isset($response) ? $response->getStatusCode() : 500;
            $route = $this->routeName($request);

            if ($span !== null) {
                $span->setAttribute('http.route', $route);
                $span->setAttribute('http.response.status_code', $status);

                if ($status >= 500) {
                    $span->setStatus(\OpenTelemetry\API\Trace\StatusCode::STATUS_ERROR);
                }

                $scope?->detach();
                $span->end();
            }

            $this->telemetry->recordRequest($route, $request->getMethod(), $status, $durationMs);
        }

        return $response;
    }

    /**
     * PHP-FPM ends the process after the response, so batched spans have to be
     * pushed before that happens rather than at some later flush.
     */
    public function terminate(Request $request, Response $response): void
    {
        if ($this->tracing->enabled()) {
            $this->tracing->flush();
        }
    }

    /**
     * The matched route's URI pattern, or a coarse label when nothing matched.
     *
     * Unmatched requests collapse to a single `__unmatched` bucket on purpose:
     * a scanner probing a thousand random paths would otherwise write a
     * thousand rows an hour into the metrics table.
     */
    private function routeName(Request $request): string
    {
        $route = $request->route();

        if ($route === null) {
            return '__unmatched';
        }

        return $route->uri() ?: '/';
    }

    private function ignored(Request $request): bool
    {
        foreach ((array) config('otel.ignore_paths', []) as $path) {
            if ($request->is($path)) {
                return true;
            }
        }

        return false;
    }
}
