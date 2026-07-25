<?php

namespace App\Services\Email;

/**
 * An email provider call failed for a reason worth showing the user;
 * `errorCode` becomes data_source_connections.last_error_code.
 *
 * Named `errorCode`, not `code`: Exception already declares a non-readonly
 * `$code`, and redeclaring an inherited property as readonly is a fatal
 * error that makes the class unloadable.
 */
class EmailProviderFailure extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly bool $retryable = false,
    ) {
        parent::__construct($message);
    }
}
