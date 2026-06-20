<?php

namespace App\Http\Middleware;

use App\Models\Site;
use App\Support\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * HMAC authentication for the Insightistic WordPress plugin.
 *
 * The plugin signs every request with its secret (never transmitted). We
 * recompute the signature and constant-time compare. A captured request can't
 * be replayed: it carries a timestamp (rejected outside a 5-min window) and a
 * one-time nonce (rejected if seen again inside the window).
 *
 * Canonical signing string (newline-joined, exact order):
 *   METHOD \n PATH \n TIMESTAMP \n NONCE \n sha256_hex(rawBody)
 *
 * Required headers:
 *   X-INS-Key-Id, X-INS-Timestamp, X-INS-Nonce, X-INS-Signature (hex hmac-sha256)
 */
class ConnectorAuth
{
    public const SKEW_SECONDS  = 300;  // ±5 min clock tolerance
    private const NONCE_TTL     = 600;  // remember nonces for 10 min

    public function handle(Request $request, Closure $next): Response
    {
        $keyId     = $request->header('X-INS-Key-Id');
        $timestamp = $request->header('X-INS-Timestamp');
        $nonce     = $request->header('X-INS-Nonce');
        $signature = $request->header('X-INS-Signature');

        if (! $keyId || ! $timestamp || ! $nonce || ! $signature) {
            return $this->deny('Missing signature headers.');
        }

        // 1. Clock skew
        if (! is_numeric($timestamp) || abs(time() - (int) $timestamp) > self::SKEW_SECONDS) {
            return $this->deny('Request timestamp outside allowed window.');
        }

        // 2. Identify the site (no tenant set yet → unscoped lookup)
        $site = Site::findByKeyId($keyId);
        if (! $site || ! $site->connector_secret) {
            return $this->deny('Unknown connector key.');
        }

        // 3. Replay protection (per-site nonce)
        $nonceKey = "conn_nonce:{$site->id}:{$nonce}";
        if (Cache::has($nonceKey)) {
            return $this->deny('Replayed nonce.');
        }

        // 4. Recompute signature over the EXACT raw body bytes
        $bodyHash = hash('sha256', $request->getContent());
        $canonical = implode("\n", [
            strtoupper($request->getMethod()),
            $request->getPathInfo(),
            $timestamp,
            $nonce,
            $bodyHash,
        ]);
        $expected = hash_hmac('sha256', $canonical, $site->connector_secret);

        if (! hash_equals($expected, (string) $signature)) {
            return $this->deny('Invalid signature.');
        }

        // 5. Consume the nonce + bind tenant context
        Cache::put($nonceKey, 1, self::NONCE_TTL);

        $tenancy = app(Tenancy::class);
        $tenancy->setOrganization($site->organization);
        $tenancy->setSite($site);
        $request->attributes->set('connector_site', $site);

        return $next($request);
    }

    private function deny(string $message): Response
    {
        return response()->json(['message' => $message], 401);
    }
}
