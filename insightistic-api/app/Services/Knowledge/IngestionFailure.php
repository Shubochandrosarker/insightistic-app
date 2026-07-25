<?php

namespace App\Services\Knowledge;

/**
 * A source could not be ingested; `errorCode` becomes
 * knowledge_sources.error_code.
 *
 * The property is deliberately NOT called `code`: Exception already declares a
 * non-readonly `$code`, and redeclaring an inherited property as readonly is a
 * fatal error, so naming it `code` makes this class unloadable.
 */
class IngestionFailure extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
