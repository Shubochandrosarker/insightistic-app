<?php

namespace App\Services\Billing;

use App\Domain\Billing\Exceptions\PaddleApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper over the Paddle Billing REST API. Requires:
 *   PADDLE_API_KEY / PADDLE_CLIENT_TOKEN (config/billing.php → paddle.*)
 *
 * Uses the Laravel Http facade against api.paddle.com /
 * sandbox-api.paddle.com rather than paddlehq/paddle-php-sdk: the endpoints
 * and payload shapes here are few and stable (verified against the SDK's
 * own generated clients, e.g. SubscriptionsClient::update → PATCH
 * /subscriptions/{id}), and going raw keeps the sandbox/live base-URL
 * switch, 15s timeout and error mapping explicit. Endpoint↔SDK parity:
 *   GET   /subscriptions/{id}                  (SubscriptionsClient::get)
 *   PATCH /subscriptions/{id}                  (SubscriptionsClient::update)
 *   POST  /customers/{id}/portal-sessions      (CustomerPortalSessionsClient::create)
 *
 * No retries here — Paddle retries its own webhooks and the billing
 * reconciler repairs drift, so a failed mutation is surfaced, not masked.
 */
class PaddleClient
{
    private const TIMEOUT_SECONDS = 15;

    /** True when the current environment has enough config to call Paddle. */
    public function configured(): bool
    {
        return (bool) config('billing.paddle.api_key');
    }

    /** GET /subscriptions/{id} → the subscription entity as a plain array. */
    public function subscription(string $providerSubscriptionId): array
    {
        return $this->call('get', "/subscriptions/{$providerSubscriptionId}");
    }

    /**
     * Schedule cancellation at the end of the current billing period. Paddle
     * models this as a scheduled_change on the subscription (the Stripe
     * cancel_at_period_end analogue); effective_at must be the next billing
     * date, so the subscription is fetched first.
     */
    public function cancelSubscriptionAtPeriodEnd(string $providerSubscriptionId): array
    {
        $current = $this->subscription($providerSubscriptionId);

        $effectiveAt = $current['next_billed_at'] ?? null;

        if (! $effectiveAt) {
            throw new PaddleApiException(
                "Subscription {$providerSubscriptionId} has no next_billed_at to schedule a cancellation against.",
                0,
            );
        }

        return $this->call('patch', "/subscriptions/{$providerSubscriptionId}", [
            'scheduled_change' => [
                'action'       => 'cancel',
                'effective_at' => $effectiveAt,
            ],
        ]);
    }

    /**
     * Move a subscription onto a new price. Paddle replaces the whole item
     * list on update — a single-item subscription is sent back as that one
     * item at the new price, quantity 1.
     */
    public function changePlan(
        string $providerSubscriptionId,
        string $newPriceId,
        string $prorationBillingMode = 'prorated_immediately',
    ): array {
        return $this->call('patch', "/subscriptions/{$providerSubscriptionId}", [
            'items'                  => [['price_id' => $newPriceId, 'quantity' => 1]],
            'proration_billing_mode' => $prorationBillingMode,
        ]);
    }

    /**
     * Open a Paddle-hosted customer portal session; returns the overview URL
     * (urls.general.overview), or null when the API shape differs — the
     * portal is a convenience redirect, so the caller degrades gracefully
     * instead of 502-ing on a payload we didn't anticipate.
     */
    public function customerPortalSession(string $providerCustomerId): ?string
    {
        $data = $this->call('post', "/customers/{$providerCustomerId}/portal-sessions");

        return $data['urls']['general']['overview'] ?? null;
    }

    /** Cheap authenticated probe for System Health; null on success, else a short reason (never the key). */
    public function ping(): ?string
    {
        try {
            $this->call('get', '/event-types');

            return null;
        } catch (PaddleApiException $e) {
            return $e->getCode() ? 'http_' . $e->getCode() : 'unreachable';
        } catch (\Throwable $e) {
            return class_basename($e);
        }
    }

    // ------------------------------------------------------------------

    /**
     * One authenticated API call. Errors become PaddleApiException carrying
     * the provider's detail message and HTTP status — never the API key.
     *
     * @return array the `data` node of the Paddle response envelope
     */
    private function call(string $verb, string $path, ?array $body = null): array
    {
        $apiKey = (string) config('billing.paddle.api_key');

        if ($apiKey === '') {
            throw new PaddleApiException('Paddle API key is not configured.', 0);
        }

        try {
            $request = Http::baseUrl($this->baseUrl())
                ->timeout(self::TIMEOUT_SECONDS)
                ->withToken($apiKey)
                ->acceptJson();

            $response = match ($verb) {
                'get'   => $request->get($path),
                'patch' => $request->patch($path, $body ?? []),
                'post'  => $request->post($path, $body ?? []),
                default => throw new \InvalidArgumentException("Unsupported Paddle API verb [{$verb}]."),
            };
        } catch (ConnectionException $e) {
            throw new PaddleApiException('Paddle API unreachable: ' . $e->getMessage(), 0);
        }

        $payload = $response->json() ?? [];

        if (! $response->successful()) {
            $detail = $payload['error']['detail']
                ?? $payload['error']['code']
                ?? 'HTTP ' . $response->status();

            throw new PaddleApiException("Paddle API error on {$path}: {$detail}", $response->status());
        }

        $data = $payload['data'] ?? null;

        if (! is_array($data)) {
            throw new PaddleApiException("Paddle API response for {$path} has no data node.", $response->status());
        }

        return $data;
    }

    private function baseUrl(): string
    {
        return config('billing.paddle.sandbox')
            ? 'https://sandbox-api.paddle.com'
            : 'https://api.paddle.com';
    }
}
