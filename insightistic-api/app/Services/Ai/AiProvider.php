<?php

namespace App\Services\Ai;

/**
 * A provider turns a metrics context into a structured business insight.
 * Returns an assoc array with keys:
 *   title, summary, possible_reason, recommendation, severity, priority_score
 * or null on failure (caller must NOT meter usage on null).
 */
interface AiProvider
{
    /** $identityId: organization id, for providers that meter/rate-limit per caller. */
    public function generate(string $system, array $context, ?int $identityId = null): ?array;

    public function model(): string;
}
