<?php

namespace App\Services\Integrations;

/**
 * A data-source operation failed for a reason worth showing the user;
 * `errorCode` becomes data_source_connections.last_error_code.
 *
 * The property is deliberately NOT called `code`: Exception already declares
 * a non-readonly `$code`, and redeclaring an inherited property as readonly
 * is a fatal error that makes the whole class unloadable.
 */
class IntegrationFailure extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly bool $retryable = false,
    ) {
        parent::__construct($message);
    }
}
