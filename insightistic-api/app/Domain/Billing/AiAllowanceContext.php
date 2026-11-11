<?php

namespace App\Domain\Billing;

use App\Models\AiAllowanceReservation;

/**
 * Request/job-scoped hand-off between a billing call site and the AI provider
 * chain. The call site knows the tariff action and the run id; PlanAwareAiProvider
 * is the only place that knows whether a paid model is about to run — and it is
 * exactly where the cost must be reserved.
 *
 * The scope is single-shot: reserve() consumes it, so one action can never bill
 * a second AI call it did not ask for. It is bound as a scoped instance, which
 * Laravel flushes between requests and queued jobs.
 */
final class AiAllowanceContext
{
    /** @var array{organization_id:int, action:string, run_reference:string}|null */
    private ?array $scope = null;

    public function __construct(private AiAllowanceGuard $guard) {}

    /** Arm the next paid-model call with the action it should be billed as. */
    public function for(int $organizationId, string $action, string|int $runReference): void
    {
        $this->scope = [
            'organization_id' => $organizationId,
            'action' => $action,
            'run_reference' => (string) $runReference,
        ];
    }

    public function clear(): void
    {
        $this->scope = null;
    }

    /**
     * Resolve the armed scope into a reservation.
     *
     * `null` means no scope was armed for this organization — the caller has
     * not opted into allowance enforcement (or the answer is free by plan, e.g.
     * a standard-request slot), so the provider chain bills nothing.
     *
     * @return array{allowed: bool, reservation: ?AiAllowanceReservation, action: string, reason: string, cost: int, available: int}|null
     */
    public function reserve(?int $organizationId): ?array
    {
        $scope = $this->scope;
        $this->scope = null;

        if ($scope === null || $organizationId === null || $scope['organization_id'] !== $organizationId) {
            return null;
        }

        return $this->guard->reserve($scope['organization_id'], $scope['action'], $scope['run_reference']);
    }

    public function commit(?AiAllowanceReservation $reservation): void
    {
        $this->guard->commit($reservation);
    }

    public function release(?AiAllowanceReservation $reservation): void
    {
        $this->guard->release($reservation);
    }
}
