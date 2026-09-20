<?php

namespace App\Domain\Billing\Exceptions;

/**
 * Thrown by PaddleBillingService when local billing configuration cannot
 * serve a request — no active plan, no PlanPrice row for the requested
 * kind/currency. Controllers map errorCode to the API's {"code": ...} error
 * shape (422), the same convention the Stripe checkout path uses inline.
 */
final class BillingConfigurationException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
