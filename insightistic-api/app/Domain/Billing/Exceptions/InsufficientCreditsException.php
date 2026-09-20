<?php

namespace App\Domain\Billing\Exceptions;

/**
 * Thrown by CreditLedger::consume() when the balance cannot cover the spend.
 * The ledger is left untouched.
 */
final class InsufficientCreditsException extends \RuntimeException
{
    public function __construct(
        public readonly int $expected,
        public readonly int $available,
    ) {
        parent::__construct("Insufficient credits: needed {$expected}, available {$available}.");
    }
}
