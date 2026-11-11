<?php

namespace App\Services\Ai;

use App\Domain\Billing\AiAllowanceContext;
use Illuminate\Support\Facades\Log;

/** Selects a provider chain from server-resolved billing tier and fails over safely. */
class PlanAwareAiProvider implements AiProvider, ReportsUsage
{
    private array $activeChain = [];

    private ?AiProvider $lastProvider = null;

    public function __construct(
        private array $freeProviders,
        private array $paidProviders,
        private AiTierResolver $tiers,
        private ?AiAllowanceContext $allowance = null,
    ) {}

    /**
     * The paid chain is the only one that costs money, so it is the only one
     * that reserves: the action's credit cost is held before the first paid
     * provider is called, committed when a real model answers, and released
     * when every paid provider fails or the answer came from the $0 rule
     * fallback.
     *
     * A short allowance is not an error (pricing architecture §5: "AI never
     * hard-stops"): the action drops to the free-tier chain at no charge
     * instead of surfacing a 402 after the user already asked for analysis.
     */
    public function generate(string $system, array $context, ?int $identityId = null): ?array
    {
        $paid = $this->tiers->isPaid($identityId);
        $reservation = null;

        if ($paid && $this->allowance !== null) {
            $decision = $this->allowance->reserve($identityId);

            if ($decision !== null) {
                if ($decision['allowed']) {
                    $reservation = $decision['reservation'];
                } else {
                    $paid = false;
                    Log::info('ai_allowance.free_fallback', [
                        'organization_id' => $identityId,
                        'action'          => $decision['action'],
                        'reason'          => $decision['reason'],
                        'cost'            => $decision['cost'],
                        'available'       => $decision['available'],
                    ]);
                }
            }
        }

        $this->activeChain = $paid ? $this->paidProviders : $this->freeProviders;

        foreach ($this->activeChain as $provider) {
            $this->lastProvider = $provider;

            try {
                $result = $provider->generate($system, $context, $identityId);

                if ($result !== null) {
                    if ($paid && ! $this->isDeterministicFallback($provider)) {
                        $this->allowance?->commit($reservation);
                    } else {
                        $this->allowance?->release($reservation);
                    }

                    return $result;
                }
            } catch (\Throwable $e) {
                Log::warning('Insightistic AI provider failed; trying the next configured fallback', [
                    'provider_model' => $provider->model(),
                    'exception'      => $e::class,
                ]);
            }
        }

        // Nothing answered (or only the rule fallback would have): the hold
        // must not outlive the attempt.
        $this->allowance?->release($reservation);

        return null;
    }

    public function model(): string
    {
        return $this->lastProvider?->model()
            ?? ($this->activeChain[0] ?? null)?->model()
            ?? 'rule-based-v1';
    }

    /** The $0 deterministic provider costs nothing, so an answer from it must not be charged. */
    private function isDeterministicFallback(AiProvider $provider): bool
    {
        return str_starts_with($provider->model(), 'rule-based');
    }

    /** Usage of the provider that answered the last call. */
    public function lastUsage(): ?array
    {
        return $this->lastProvider instanceof ReportsUsage ? $this->lastProvider->lastUsage() : null;
    }
}
