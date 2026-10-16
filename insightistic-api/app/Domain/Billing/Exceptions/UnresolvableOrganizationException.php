<?php

namespace App\Domain\Billing\Exceptions;

/**
 * A verified provider event is structurally valid but cannot be attached to
 * a local tenant. Retrying cannot repair missing tenant metadata, so the
 * queue job records the event as a terminal data-quality failure.
 */
final class UnresolvableOrganizationException extends \RuntimeException
{
    public function __construct(string $entityType, string $entityId)
    {
        parent::__construct($entityType.' '.$entityId.' has no resolvable organization.');
    }
}
