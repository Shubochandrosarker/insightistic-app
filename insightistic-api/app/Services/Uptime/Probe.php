<?php

namespace App\Services\Uptime;

use App\Models\UptimeMonitor;
use App\Support\PublicHttpGuard;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The HTTP probe (Phase 12, doc 16): one monitor URL, one attempt, 10s cap,
 * redirects are followed through a bounded, SSRF-guarded chain. A normal
 * 301/302 -> final 2xx navigation is healthy; a loop, missing Location, or
 * non-2xx final response is not.
 *
 * "Up" is strict on the FINAL response: status === expected_status (default
 * 200). This keeps custom expected statuses useful without treating a normal
 * redirect as an outage.
 *
 * Returns a plain array rather than a value object: this crosses into
 * uptime_checks columns 1:1 and the IncidentManager is its only consumer.
 *
 * @return array{up: bool, status_code: int|null, response_ms: int|null, error: string|null}
 */
class Probe
{
    /** Hard wall-clock cap per probe, matching the job's expectations. */
    public const TIMEOUT_SECONDS = 10;

    private const MAX_REDIRECTS = 5;

    public function probe(UptimeMonitor $monitor): array
    {
        $startedAt = microtime(true);

        try {
            $url = $monitor->probeUrl();
            PublicHttpGuard::assertFetchable($url);
            $redirects = 0;

            do {
                $response = Http::withOptions(['allow_redirects' => false])
                    ->timeout(self::TIMEOUT_SECONDS)
                    ->connectTimeout(self::TIMEOUT_SECONDS)
                    ->{strtolower($monitor->method)}($url);

                $statusCode = $response->status();
                if (! in_array($statusCode, [301, 302, 303, 307, 308], true)) {
                    break;
                }

                $location = $response->header('Location');
                if (! is_string($location) || trim($location) === '') {
                    break;
                }

                if (++$redirects > self::MAX_REDIRECTS) {
                    return $this->failure($startedAt, 'redirect limit exceeded', $statusCode);
                }

                $url = UriResolver::resolve(new Uri($url), new Uri($location))->__toString();
                // Redirect destinations are user-controlled responses; check
                // every hop before the next outbound request.
                PublicHttpGuard::assertFetchable($url);
            } while (true);
        } catch (ConnectionException $e) {
            return $this->failure($startedAt, $this->shortError($e));
        } catch (Throwable $e) {
            return $this->failure($startedAt, $this->shortError($e));
        }

        $responseMs = (int) round((microtime(true) - $startedAt) * 1000);
        $statusCode ??= $response->status();

        return [
            'up'          => $statusCode === (int) $monitor->expected_status,
            'status_code' => $statusCode,
            'response_ms' => $responseMs,
            'error'       => $statusCode === (int) $monitor->expected_status
                ? null
                : 'unexpected status',
        ];
    }

    /** @return array{up: bool, status_code: int|null, response_ms: int|null, error: string|null} */
    private function failure(float $startedAt, string $error, ?int $statusCode = null): array
    {
        return [
            'up'          => false,
            'status_code' => $statusCode,
            'response_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'error'       => $error,
        ];
    }

    /** Exception messages can carry full stack/URL noise; keep the cause line. */
    private function shortError(Throwable $e): string
    {
        return mb_substr($e->getMessage(), 0, 255);
    }
}
