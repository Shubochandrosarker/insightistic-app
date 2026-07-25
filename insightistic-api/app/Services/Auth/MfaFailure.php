<?php

namespace App\Services\Auth;

/**
 * An MFA operation failed in a way worth showing the user.
 *
 * Named `errorCode`, not `code`: Exception already declares a non-readonly
 * `$code`, and redeclaring an inherited property as readonly is a fatal
 * error that makes the class unloadable.
 */
class MfaFailure extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
