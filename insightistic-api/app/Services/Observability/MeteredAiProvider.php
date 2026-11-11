<?php

namespace App\Services\Observability;

use App\Services\Ai\AiProvider;
use App\Services\Ai\ReportsUsage;

/**
 * Wraps any AiProvider to record latency, outcome and cost (spec Phase 2.3).
 *
 * A decorator rather than instrumentation inside each provider: there are
 * three implementations today and the rule-based fallback is the one most
 * likely to be forgotten, which would make "AI is free this month" look like
 * a billing win rather than a provider silently falling back.
 *
 * Copilot writes its own `ai_runs` row with per-message detail. This records
 * the aggregate spend across *every* path — insight generation, reports and
 * Copilot alike — which is what makes "why is the provider bill that size"
 * answerable. Double counting Copilot is deliberate: the two tables answer
 * different questions and reconciling them by excluding one path would leave
 * the aggregate wrong.
 */
class MeteredAiProvider implements AiProvider, ReportsUsage
{
    public function __construct(
        private AiProvider $inner,
        private Telemetry $telemetry,
        private Tracing $tracing,
        private string $providerName,
    ) {}

    public function generate(string $system, array $context, ?int $identityId = null): ?array
    {
        $start = microtime(true);

        return $this->tracing->span('ai.generate', function () use ($system, $context, $identityId, $start) {
            $result = null;
            $failed = false;

            try {
                $result = $this->inner->generate($system, $context, $identityId);
                // Providers signal failure by returning null rather than
                // throwing, so a null result is the failure signal here.
                $failed = $result === null;
            } catch (\Throwable $e) {
                $failed = true;
                throw $e;
            } finally {
                $usage = $failed ? null : $this->lastUsage();
                $this->telemetry->recordAiCall(
                    kind: 'insight',
                    provider: $this->providerName,
                    model: $this->model(),
                    organizationId: $identityId,
                    latencyMs: (int) round((microtime(true) - $start) * 1000),
                    // Only provider-reported usage (ReportsUsage). No
                    // estimates: unreported stays null/0.
                    tokensIn: $usage['tokens_in'] ?? null,
                    tokensOut: $usage['tokens_out'] ?? null,
                    costUsd: $usage['cost_usd'] ?? null,
                    failed: $failed,
                );
            }

            return $result;
        }, [
            'ai.provider' => $this->providerName,
            'ai.model'    => $this->model(),
        ]);
    }

    public function model(): string
    {
        return $this->inner->model();
    }

    public function lastUsage(): ?array
    {
        return $this->inner instanceof ReportsUsage ? $this->inner->lastUsage() : null;
    }
}
