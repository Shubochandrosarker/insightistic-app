<?php

namespace App\Services\Ai;

/**
 * Optional companion to AiProvider: the usage the provider itself reported
 * for its most recent generate() call. Only real provider numbers go in
 * here, never estimates, so cost tables stay honest.
 *
 * Shape: ['tokens_in' => ?int, 'tokens_out' => ?int, 'cost_usd' => ?float]
 * or null when the last call reported nothing (or failed).
 */
interface ReportsUsage
{
    /** @return array{tokens_in: ?int, tokens_out: ?int, cost_usd: ?float}|null */
    public function lastUsage(): ?array;
}
