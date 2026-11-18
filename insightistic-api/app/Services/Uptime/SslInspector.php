<?php

namespace App\Services\Uptime;

use App\Models\Alert;
use App\Models\Site;
use App\Models\UptimeMonitor;
use App\Services\Automation\AutomationDispatcher;
use Illuminate\Support\Carbon;

/**
 * Daily SSL expiry inspection (Phase 12, doc 16 "additional checks"),
 * piggybacking on the probe job: at most one certificate handshake per
 * monitor per 24h (ssl_checked_at is stamped after a valid target has been
 * attempted, so an unreachable 443 is retried tomorrow, not on every
 * 5-minute probe).
 *
 * Opens a raw TLS stream to host:443 with capture_session_meta and parses
 * the peer certificate with openssl_x509_parse — no HTTP round-trip, no
 * Guzzle involvement, 10s cap like the probe itself.
 *
 * Threshold alerts (doc 16): <21 days a low warning, <7 days critical.
 * Dedupe is AlertDetector's open-alert idiom — an UNRESOLVED alert of the
 * same type suppresses duplicates; a renewed certificate (days back >= 21)
 * auto-resolves both types, exactly like AlertDetector::clear() would.
 */
class SslInspector
{
    public const WARN_DAYS = 21;

    public const CRITICAL_DAYS = 7;

    public const ALERT_TYPE_WARNING = 'ssl_expiry_warning';

    public const ALERT_TYPE_CRITICAL = 'ssl_expiry_critical';

    public function __construct(private readonly AutomationDispatcher $dispatcher) {}

    /** Days until certificate expiry, or null when the check could not run. */
    public function check(UptimeMonitor $monitor): ?int
    {
        if ($monitor->ssl_checked_at !== null && $monitor->ssl_checked_at->gt(Carbon::now()->subDay())) {
            return $monitor->ssl_expires_at !== null
                ? (int) max(0, Carbon::now()->startOfDay()->diffInDays(Carbon::parse($monitor->ssl_expires_at), false))
                : null;
        }

        $host = $this->hostOf($monitor);

        if ($host === null) {
            // Keep the state pending when the target cannot be parsed. A
            // malformed/legacy monitor must not look like a completed SSL
            // check, and the next probe can retry after the monitor is fixed.
            return null;
        }

        $expiresAt = $this->certificateExpiry($host);

        // Stamp after a valid target was attempted, even on a TLS failure:
        // unreachable 443 must not be re-probed every monitor interval for
        // the next 24 hours.
        $monitor->ssl_checked_at = Carbon::now();
        $monitor->ssl_expires_at = $expiresAt?->toDateString();
        $monitor->save();

        if ($expiresAt === null) {
            return null;
        }

        $days = (int) floor(Carbon::now()->startOfDay()->diffInDays($expiresAt, false));

        $site = $monitor->site;
        $this->applyExpiryThreshold($site, $host, $days);

        return $days;
    }

    /** ssl://host:443 handshake; null on any connect/handshake/parse failure. */
    private function certificateExpiry(string $host): ?Carbon
    {
        $context = stream_context_create([
            'ssl' => [
                'capture_session_meta' => true,
                'peer_name'            => $host,
                'verify_peer'          => false,
                'verify_peer_name'     => false,
                'SNI_enabled'          => true,
            ],
            'socket' => [
                'bindto' => '0:0', // any local interface, IPv4+IPv6
            ],
        ]);

        $socket = @stream_socket_client(
            'ssl://'.$host.':443',
            $errno,
            $errstr,
            Probe::TIMEOUT_SECONDS,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($socket === false) {
            return null;
        }

        try {
            $params = stream_context_get_params($context);
            $certificate = $params['options']['ssl']['peer_certificate'] ?? null;

            if ($certificate === null) {
                return null;
            }

            $parsed = openssl_x509_parse($certificate);
            $validTo = $parsed['validTo_time_t'] ?? null;

            return $validTo === null
                ? null
                : Carbon::createFromTimestamp((int) $validTo, 'UTC')->startOfDay();
        } finally {
            fclose($socket);
        }
    }

    /**
     * Threshold crossing alerts with open-alert dedupe, and auto-resolve
     * once the certificate is healthy again (renewed). Public because it
     * is the testable policy core of this class — the TLS handshake it
     * guards cannot run under Http::fake.
     */
    public function applyExpiryThreshold(Site $site, string $host, int $days): void
    {
        if ($days >= self::WARN_DAYS) {
            $this->resolve($site, self::ALERT_TYPE_WARNING);
            $this->resolve($site, self::ALERT_TYPE_CRITICAL);

            return;
        }

        if ($days < self::CRITICAL_DAYS) {
            // Crossing into critical supersedes the lesser warning.
            $this->resolve($site, self::ALERT_TYPE_WARNING);
            $this->raise($site, self::ALERT_TYPE_CRITICAL, 'SSL certificate expiring',
                sprintf('%s SSL certificate expires in %d day(s) — renew now.', $host, $days), 'critical');

            return;
        }

        $this->resolve($site, self::ALERT_TYPE_CRITICAL);
        $this->raise($site, self::ALERT_TYPE_WARNING, 'SSL certificate expiring',
            sprintf('%s SSL certificate expires in %d day(s).', $host, $days), 'low');
    }

    /** AlertDetector::raise idiom: skip when an UNRESOLVED alert of the type exists. */
    private function raise(Site $site, string $type, string $title, string $message, string $severity): ?Alert
    {
        $exists = Alert::query()
            ->where('site_id', $site->id)
            ->where('type', $type)
            ->where('is_resolved', false)
            ->exists();

        if ($exists) {
            return null;
        }

        $alert = Alert::query()->create([
            'site_id'     => $site->id,
            'type'        => $type,
            'title'       => $title,
            'message'     => $message,
            'severity'    => $severity,
            'is_resolved' => false,
        ]);

        $this->dispatcher->dispatch('alert_created', $site, [
            'alert_type' => $type,
            'alert_id'   => $alert->id,
        ]);

        return $alert;
    }

    /** AlertDetector::clear idiom: the condition is no longer true. */
    private function resolve(Site $site, string $type): void
    {
        Alert::query()
            ->where('site_id', $site->id)
            ->where('type', $type)
            ->where('is_resolved', false)
            ->update(['is_resolved' => true]);
    }

    /**
     * Hostname of the resolved probe URL, with a site fallback for older
     * monitors whose explicit URL was stored in a malformed form.
     */
    private function hostOf(UptimeMonitor $monitor): ?string
    {
        $candidates = [
            $monitor->probeUrl(),
            $monitor->site?->canonical_url,
            $monitor->site?->domain,
        ];

        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate === '') {
                continue;
            }

            if (! preg_match('#^https?://#i', $candidate)) {
                $candidate = 'https://'.$candidate;
            }

            $host = parse_url($candidate, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                return strtolower(rtrim($host, '.'));
            }
        }

        return null;
    }
}
