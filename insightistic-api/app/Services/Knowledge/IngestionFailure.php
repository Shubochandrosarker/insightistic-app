<?php

namespace App\Services\Knowledge;

/** A source could not be ingested; `code` becomes knowledge_sources.error_code. */
class IngestionFailure extends \RuntimeException
{
    public function __construct(public readonly string $code, string $message)
    {
        parent::__construct($message);
    }
}
