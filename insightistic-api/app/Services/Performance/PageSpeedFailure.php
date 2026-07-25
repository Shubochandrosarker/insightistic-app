<?php

namespace App\Services\Performance;

/**
 * A PageSpeed run failed in a way worth showing the user.
 *
 * Named `errorCode`, not `code`: Exception already declares a non-readonly
 * `$code`, and redeclaring an inherited property as readonly is a fatal
 * error that makes the class unloadable.
 */
class PageSpeedFailure extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly bool $retryable = false,
    ) {
        parent::__construct($message);
    }
}
