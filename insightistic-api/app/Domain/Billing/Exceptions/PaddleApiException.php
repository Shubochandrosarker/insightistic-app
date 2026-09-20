<?php

namespace App\Domain\Billing\Exceptions;

/**
 * Thrown by PaddleClient when the Paddle Billing API returns an error.
 * Carries the provider's message and HTTP status so callers (reconciler,
 * controllers) can branch on 404 vs 4xx vs 5xx without parsing strings.
 * The API key itself is never included in the message.
 */
final class PaddleApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }
}
