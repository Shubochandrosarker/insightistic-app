<?php

namespace App\Http\Controllers\Api;

use App\Jobs\ProcessPaddleEvent;
use App\Models\BillingEvent;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Paddle webhook intake (roadmap 23-STRIPE-TO-PADDLE-MIGRATION §4.3). Public
 * route, no auth — the HMAC signature is the authentication.
 *
 * Responsibilities end at "accept exactly once and queue": the 200 means the
 * delivery was recorded, not that processing succeeded, and a processing
 * failure must never block the ack (that would only make Paddle redeliver
 * into the same failure). Deduplication is the billing_events unique
 * (provider, provider_event_id) pair — insertOrIgnore, like the Stripe
 * processor, so concurrent deliveries race safely on PostgreSQL.
 */
class PaddleWebhookController extends Controller
{
    /** Paddle signs at delivery time; a timestamp this stale is a replay. */
    private const TIMESTAMP_TOLERANCE_SECONDS = 300;

    public function __invoke(Request $request): JsonResponse
    {
        $rawBody = $request->getContent();

        if (! $this->verifySignature($request, $rawBody)) {
            return response()->json(['error' => 'invalid_signature'], 400);
        }

        $payload = json_decode($rawBody, true);

        if (! is_array($payload) || ! ($payload['event_id'] ?? null)) {
            return response()->json(['error' => 'invalid_payload'], 400);
        }

        // insertOrIgnore rather than create()-and-catch: on PostgreSQL a
        // failed INSERT aborts the surrounding transaction (25P02), while
        // ON CONFLICT DO NOTHING never raises and the row count still tells
        // us who won the race between concurrent redeliveries.
        $inserted = BillingEvent::query()->insertOrIgnore([
            'provider'                 => 'paddle',
            'provider_event_id'        => $payload['event_id'],
            'provider_notification_id' => $request->header('Paddle-Notification-Id'),
            'event_type'               => $payload['event_type'] ?? 'unknown',
            'occurred_at'              => $payload['occurred_at'] ?? null,
            'payload_hash'             => hash('sha256', $rawBody),
            'created_at'               => now(),
            'updated_at'               => now(),
        ]);

        if ($inserted === 0) {
            // Redelivery of an event we already hold — acknowledge, don't
            // reprocess. Returning an error would only invite another retry.
            return response()->json(['received' => true, 'duplicate' => true]);
        }

        $event = BillingEvent::query()
            ->where('provider', 'paddle')
            ->where('provider_event_id', $payload['event_id'])
            ->firstOrFail();

        // Normal queue dispatch — never inline, never afterResponse. The
        // payload is passed in parsed: billing_events deliberately stores no
        // payload column (only id, type, hash), so the queued job carries the
        // decoded data node itself.
        ProcessPaddleEvent::dispatch($event, $payload['data'] ?? []);

        return response()->json(['received' => true, 'duplicate' => false]);
    }

    /**
     * Verify Paddle's "ts=…;h1=…" signature: HMAC-SHA256 of
     * "{timestamp}:{rawBody}" under the webhook secret, with a replay
     * tolerance on the timestamp (Paddle docs §verify-webhook-signature).
     */
    private function verifySignature(Request $request, string $rawBody): bool
    {
        $secret = config('billing.paddle.webhook_secret');

        if (! $secret) {
            // Fail closed and loudly: without the secret nothing can be
            // verified, and silently accepting would let anyone move plans.
            Log::critical('Paddle webhook secret is not configured; rejecting delivery.', [
                'notification_id' => $request->header('Paddle-Notification-Id'),
            ]);

            return false;
        }

        $header = (string) $request->header('Paddle-Signature');
        $parts = [];

        foreach (explode(';', $header) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, null);
            $parts[$key] = $value;
        }

        $timestamp = $parts['ts'] ?? null;
        $signature = $parts['h1'] ?? null;

        if (! $timestamp || ! $signature) {
            return false;
        }

        if (abs(time() - (int) $timestamp) > self::TIMESTAMP_TOLERANCE_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . ':' . $rawBody, $secret);

        return hash_equals($expected, $signature);
    }
}
