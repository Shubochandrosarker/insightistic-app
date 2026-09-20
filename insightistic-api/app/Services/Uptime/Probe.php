<?php

namespace App\Services\Uptime;

use App\Models\UptimeMonitor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The HTTP probe (Phase 12, doc 16): one monitor URL, one attempt, 10s cap,
 * redirects NOT followed (a 301 to elsewhere is itself data — and a redirect
 * loop should read as down, not as a success after 5 hops).
 *
 * "Up" is deliberately strict: status === expected_status (default 200).
 * Any other status — 3xx, 4xx, 5xx — is down; the expected_status column
 * lets a monitor pin a non-200 healthy response when it must.
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

    public function probe(UptimeMonitor $monitor): array
    {
        $startedAt = microtime(true);

        try {
            $response = Http::withOptions(['allow_redirects' => false])
                ->timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::TIMEOUT_SECONDS)
                ->{strtolower($monitor->method)}($monitor->probeUrl());
        } catch (ConnectionException $e) {
            return $this->failure($startedAt, $this->shortError($e));
        } catch (Throwable $e) {
            return $this->failure($startedAt, $this->shortError($e));
        }

        $responseMs = (int) round((microtime(true) - $startedAt) * 1000);
        $statusCode = $response->status();

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
    private function failure(float $startedAt, string $error): array
    {
        return [
            'up'          => false,
            'status_code' => null,
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
