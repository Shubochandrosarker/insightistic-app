<?php

namespace App\Domain\Billing;

use App\Domain\Billing\Exceptions\InsufficientCreditsException;
use App\Models\AiAllowanceReservation;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Reserves an AI action's credit cost before the model call, commits it on
 * success and releases it on failure or timeout (pricing architecture §6:
 * "Reserve the action cost before the AI call, commit on success, release on
 * failure or timeout. Idempotency key is the ai_runs id").
 *
 * The hold lives in `ai_allowance_reservations`, not in `credit_ledger`, so
 * the ledger stays append-only and money moves exactly once — in commit(),
 * through CreditWallet::consume(). Two consequences worth stating: a released
 * hold leaves no compensating ledger rows behind, and `balance_after` stays a
 * running sum of amount_signed with no status column to filter. Open holds are
 * subtracted from the wallet's usable balance here, which is what stops two
 * concurrent actions from both passing the same preflight.
 *
 * Idempotency is (organization_id, action, run_reference): a retried run
 * settles at most one charge, and a double commit is a no-op.
 */
final class AiAllowanceGuard
{
    public function __construct(
        private CreditWallet $wallet,
        private AiCreditService $tariff,
    ) {}

    /**
     * @return array{allowed: bool, reservation: ?AiAllowanceReservation, action: string, reason: string, cost: int, available: int}
     */
    public function reserve(int $organizationId, string $action, string|int $runReference): array
    {
        $cost = $this->tariff->cost($action);

        if ($cost <= 0) {
            // No published tariff for this action (or a $0 one): nothing to
            // hold, and the caller may run a paid model for free.
            return ['allowed' => true, 'reservation' => null, 'action' => $action, 'reason' => 'no_tariff', 'cost' => 0, 'available' => 0];
        }

        $runReference = (string) $runReference;

        return DB::transaction(function () use ($organizationId, $action, $runReference, $cost): array {
            // The organization row is the per-org serialization point (the
            // same one CreditLedger uses) — without it two concurrent
            // reserves both read the same available balance.
            $organization = Organization::withoutGlobalScope('organization')
                ->whereKey($organizationId)
                ->lockForUpdate()
                ->firstOrFail();

            $existing = AiAllowanceReservation::withoutGlobalScope('organization')
                ->where('organization_id', $organization->id)
                ->where('action', $action)
                ->where('run_reference', $runReference)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $available = $this->available($organization);

                // A retry of the same run. A released attempt is reopened so
                // the retry is still covered by a hold — but only if the
                // balance can cover it now; a committed one stays committed,
                // so the retry cannot bill twice.
                if ($existing->status === AiAllowanceReservation::STATUS_RELEASED) {
                    if ($available < $existing->cost) {
                        return [
                            'allowed' => false,
                            'reservation' => null,
                            'action' => $action,
                            'reason' => 'insufficient_credits',
                            'cost' => $existing->cost,
                            'available' => $available,
                        ];
                    }

                    $existing->forceFill([
                        'status' => AiAllowanceReservation::STATUS_RESERVED,
                        'released_at' => null,
                    ])->save();
                }

                return [
                    'allowed' => true,
                    'reservation' => $existing,
                    'action' => $action,
                    'reason' => 'existing_reservation',
                    'cost' => $existing->cost,
                    'available' => $available,
                ];
            }

            $available = $this->available($organization);

            if ($available < $cost) {                return [
                    'allowed' => false,
                    'reservation' => null,
                    'action' => $action,
                    'reason' => 'insufficient_credits',
                    'cost' => $cost,
                    'available' => $available,
                ];
            }

            $reservation = AiAllowanceReservation::withoutGlobalScope('organization')->create([
                'organization_id' => $organization->id,
                'action' => $action,
                'run_reference' => $runReference,
                'cost' => $cost,
                'status' => AiAllowanceReservation::STATUS_RESERVED,
            ]);

            return [
                'allowed' => true,
                'reservation' => $reservation,
                'action' => $action,
                'reason' => 'reserved',
                'cost' => $cost,
                'available' => $available,
            ];
        });
    }

    /** Settle a successful call: the hold becomes one ledger charge. */
    public function commit(?AiAllowanceReservation $reservation): ?AiAllowanceReservation
    {
        if ($reservation === null) {
            return null;
        }

        return DB::transaction(function () use ($reservation): ?AiAllowanceReservation {
            $fresh = AiAllowanceReservation::withoutGlobalScope('organization')
                ->whereKey($reservation->getKey())
                ->lockForUpdate()
                ->first();

            if ($fresh === null) {
                return null;
            }

            if ($fresh->status !== AiAllowanceReservation::STATUS_RESERVED) {
                // committed: already charged (idempotent double commit).
                // released: the run failed; a late commit must never
                // resurrect a charge.
                $reservation->setRawAttributes($fresh->getAttributes(), true);

                return $fresh;
            }

            $organization = Organization::withoutGlobalScope('organization')->findOrFail($fresh->organization_id);

            try {
                $this->wallet->consume(
                    $organization,
                    (int) $fresh->cost,
                    'ai_' . $fresh->action,
                    $this->ledgerReference($fresh),
                );
            } catch (InsufficientCreditsException $e) {
                // Balance moved between reserve and commit (another action
                // settled first). The model already answered, so the only
                // honest option is to bill nothing: never overdraw a wallet,
                // never turn a delivered answer into a 402.
                Log::warning('ai_allowance.commit_shortfall', [
                    'organization_id' => $fresh->organization_id,
                    'action' => $fresh->action,
                    'run_reference' => $fresh->run_reference,
                    'cost' => $fresh->cost,
                    'available' => $e->available,
                ]);

                $fresh->forceFill([
                    'status' => AiAllowanceReservation::STATUS_RELEASED,
                    'released_at' => now(),
                ])->save();

                return $fresh;
            }

            $fresh->forceFill([
                'status' => AiAllowanceReservation::STATUS_COMMITTED,
                'committed_at' => now(),
            ])->save();

            return $fresh;
        });
    }

    /** Drop a hold without charging — the call failed, timed out, or fell back to a free model. */
    public function release(?AiAllowanceReservation $reservation): ?AiAllowanceReservation
    {
        if ($reservation === null) {
            return null;
        }

        return DB::transaction(function () use ($reservation): ?AiAllowanceReservation {
            $fresh = AiAllowanceReservation::withoutGlobalScope('organization')
                ->whereKey($reservation->getKey())
                ->lockForUpdate()
                ->first();

            if ($fresh === null) {
                return null;
            }

            if ($fresh->status !== AiAllowanceReservation::STATUS_RESERVED) {
                $reservation->setRawAttributes($fresh->getAttributes(), true);

                return $fresh;
            }

            $fresh->forceFill([
                'status' => AiAllowanceReservation::STATUS_RELEASED,
                'released_at' => now(),
            ])->save();

            return $fresh;
        });
    }

    // ------------------------------------------------------------------

    /** Usable credits minus every hold still open — the real spending headroom. */
    private function available(Organization $organization): int
    {
        $this->wallet->ensureCurrentPeriod($organization);

        return $this->wallet->summary($organization)['total_usable'] - $this->openHolds($organization);
    }

    private function openHolds(Organization $organization): int
    {
        return (int) AiAllowanceReservation::withoutGlobalScope('organization')
            ->where('organization_id', $organization->id)
            ->where('status', AiAllowanceReservation::STATUS_RESERVED)
            ->sum('cost');
    }

    /** Stable across retries, unique per reservation: CreditWallet::consume() is idempotent on it. */
    private function ledgerReference(AiAllowanceReservation $reservation): string
    {
        return 'ai_allowance:' . $reservation->organization_id . ':' . $reservation->action . ':' . $reservation->run_reference;
    }
}
