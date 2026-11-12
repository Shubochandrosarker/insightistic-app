<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AppSumoLicenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * AppSumo Licensing API v2 webhook intake (partner guide: webhooks). Public
 * route, no auth — the HMAC signature is the authentication:
 * X-Appsumo-Signature = hash_hmac('sha256', X-Appsumo-Timestamp . rawBody, API key).
 *
 * Acknowledge with 200 only AFTER persistent processing succeeds. A real
 * processing failure returns 503, so the provider can retry and operations
 * can reconcile it; test pings remain side-effect-free 200 responses.
 * Test deliveries (Partner Portal "save" ping, payload.test === true) are
 * acknowledged without any side effects.
 */
class AppSumoWebhookController extends Controller
{
    public function __construct(private AppSumoLicenseService $licenses) {}

    public function __invoke(Request $request): JsonResponse
    {
        $rawBody = $request->getContent();

        if (! $this->verifySignature($request, $rawBody)) {
            return response()->json(['error' => 'invalid_signature'], 401);
        }

        $payload = json_decode($rawBody, true);

        // AppSumo also allows application/x-www-form-urlencoded bodies.
        if (! is_array($payload)) {
            $payload = $request->post();
        }
        $payload = is_array($payload) ? $payload : [];

        if (($payload['test'] ?? false) === true) {
            // AppSumo's validation ping: never allocate an account or license.
            return response()->json(['event' => $payload['event'] ?? null, 'success' => true]);
        }

        try {
            $this->licenses->upsertFromPayload($payload);
        } catch (Throwable $e) {
            // A 200 here tells AppSumo the delivery succeeded and can lose a
            // paid customer forever. Fail retryably without exposing keys or
            // provider secrets in responses or application logs.
            Log::error('AppSumo webhook processing failed', [
                'event' => $payload['event'] ?? null,
                'license_fingerprint' => isset($payload['license_key'])
                    ? substr(hash('sha256', (string) $payload['license_key']), 0, 12)
                    : null,
                'exception' => get_class($e),
            ]);

            return response()->json([
                'event' => $payload['event'] ?? null,
                'success' => false,
                'error' => 'delivery_not_processed',
            ], 503);
        }

        return response()->json(['event' => $payload['event'] ?? null, 'success' => true]);
    }

    /**
     * Verify AppSumo's signature: HMAC-SHA256 of "{timestamp}{rawBody}" under
     * the licensing API key (the guide's shared secret), compared
     * timing-safely. The guide specifies no replay window and retries are
     * legitimate, so the timestamp is only an HMAC input here.
     */
    private function verifySignature(Request $request, string $rawBody): bool
    {
        $secret = (string) (config('services.appsumo.key') ?: config('services.appsumo.webhook_secret'));

        if ($secret === '') {
            // Fail closed and loudly — without the key nothing can be verified
            // (same stance as the Paddle intake).
            Log::critical('AppSumo licensing API key is not configured; rejecting webhook.');

            return false;
        }

        $timestamp = (string) $request->header('X-Appsumo-Timestamp');
        $signature = (string) $request->header('X-Appsumo-Signature');

        if ($timestamp === '' || $signature === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . $rawBody, $secret);

        return hash_equals($expected, $signature);
    }
}
