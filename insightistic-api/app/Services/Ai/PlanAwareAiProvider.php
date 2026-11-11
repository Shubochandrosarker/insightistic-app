<?php

namespace App\Services\Ai;

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
    ) {}

    public function generate(string $system, array $context, ?int $identityId = null): ?array
    {
        $this->activeChain = $this->tiers->isPaid($identityId) ? $this->paidProviders : $this->freeProviders;

        foreach ($this->activeChain as $provider) {
            $this->lastProvider = $provider;

            try {
                $result = $provider->generate($system, $context, $identityId);

                if ($result !== null) {
                    return $result;
                }
            } catch (\Throwable $e) {
                Log::warning('Insightistic AI provider failed; trying the next configured fallback', [
                    'provider_model' => $provider->model(),
                    'exception'      => $e::class,
                ]);
            }
        }

        return null;
    }

    public function model(): string
    {
        return $this->lastProvider?->model()
            ?? ($this->activeChain[0] ?? null)?->model()
            ?? 'rule-based-v1';
    }

    /** Usage of the provider that answered the last call. */
    public function lastUsage(): ?array
    {
        return $this->lastProvider instanceof ReportsUsage ? $this->lastProvider->lastUsage() : null;
    }
}
