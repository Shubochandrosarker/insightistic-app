<?php

namespace App\Domain\Billing;

use App\Domain\Billing\Exceptions\InsufficientCreditsException;
use App\Models\CreditLedgerEntry;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;

/**
 * Append-only credit ledger per organization (roadmap doc 24). Every
 * mutation is idempotent on (source_type, source_reference): a replayed
 * Paddle webhook or a retried job credits/deducts exactly once and gets the
 * original entry back — the acceptance criterion that a one-time transaction
 * can never be replayed into a second credit.
 *
 * Balance computation locks the organization row first: PostgreSQL has no
 * gap locks, so FOR UPDATE over the (possibly empty) entry rows alone would
 * not stop two concurrent writers from computing the same balance. The org
 * row is the per-org serialization point.
 */
final class CreditLedger
{
    public function balance(Organization $organization): int
    {
        return (int) CreditLedgerEntry::withoutGlobalScope('organization')
            ->where('organization_id', $organization->id)
            ->where(function ($query) {
                $query->where('bucket', 'purchased')->orWhereNull('bucket');
            })
            ->sum('amount_signed');
    }

    /** Add credits. A duplicate (source_type, source_reference) returns the original entry, no double credit. */
    public function grant(
        Organization $organization,
        int $amount,
        string $reason,
        string $sourceType,
        string $sourceReference,
        string $bucket = 'purchased',
        ?string $periodKey = null,
        $expiresAt = null,
        ?array $metadata = null,
    ): CreditLedgerEntry
    {
        $this->assertPositive($amount);

        return DB::transaction(function () use ($organization, $amount, $reason, $sourceType, $sourceReference, $bucket, $periodKey, $expiresAt, $metadata) {
            $balance = $this->lockedBalance($organization);

            return CreditLedgerEntry::withoutGlobalScope('organization')->firstOrCreate(
                [
                    'organization_id'  => $organization->id,
                    'source_type'      => $sourceType,
                    'source_reference' => $sourceReference,
                ],
                [
                    'amount_signed' => $amount,
                    'reason'        => $reason,
                    'balance_after' => $balance + $amount,
                    'bucket'        => $bucket,
                    'period_key'    => $periodKey,
                    'expires_at'    => $expiresAt,
                    'metadata_json' => $metadata,
                ],
            );
        });
    }

    /** Spend credits. Throws InsufficientCreditsException (ledger untouched) when the balance is short. */
    public function consume(Organization $organization, int $amount, string $reason, string $sourceType, string $sourceReference): CreditLedgerEntry
    {
        $this->assertPositive($amount);

        return DB::transaction(function () use ($organization, $amount, $reason, $sourceType, $sourceReference) {
            $balance = $this->lockedBalance($organization);

            // A replayed consume returns the original deduction without a
            // balance check or a second deduction — the balance has usually
            // moved on since the first consume ran.
            $existing = $this->findBySource($organization, $sourceType, $sourceReference);

            if ($existing) {
                return $existing;
            }

            if ($balance < $amount) {
                throw new InsufficientCreditsException($amount, $balance);
            }

            return CreditLedgerEntry::withoutGlobalScope('organization')->create([
                'organization_id'  => $organization->id,
                'amount_signed'    => -$amount,
                'reason'           => $reason,
                'source_type'      => $sourceType,
                'source_reference' => $sourceReference,
                'balance_after'    => $balance - $amount,
            ]);
        });
    }

    /** Balance for one non-expiring or period-scoped bucket. */
    public function bucketBalance(Organization $organization, string $bucket, ?string $periodKey = null): int
    {
        return (int) CreditLedgerEntry::withoutGlobalScope('organization')
            ->where('organization_id', $organization->id)
            ->where('bucket', $bucket)
            ->when($periodKey !== null, fn ($q) => $q->where('period_key', $periodKey))
            ->when($periodKey === null, fn ($q) => $q->whereNull('period_key'))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->sum('amount_signed');
    }

    /** Idempotent deduction restricted to a single credit bucket. */
    public function consumeBucket(
        Organization $organization,
        int $amount,
        string $reason,
        string $sourceType,
        string $sourceReference,
        string $bucket,
        ?string $periodKey = null,
    ): CreditLedgerEntry {
        $this->assertPositive($amount);

        return DB::transaction(function () use ($organization, $amount, $reason, $sourceType, $sourceReference, $bucket, $periodKey) {
            Organization::whereKey($organization->id)->lockForUpdate()->first();
            $existing = CreditLedgerEntry::withoutGlobalScope('organization')
                ->where('organization_id', $organization->id)
                ->where('source_type', $sourceType)
                ->where('source_reference', $sourceReference)
                ->first();
            if ($existing) {
                return $existing;
            }

            $balance = $this->bucketBalance($organization, $bucket, $periodKey);
            if ($balance < $amount) {
                throw new InsufficientCreditsException($amount, $balance);
            }

            return CreditLedgerEntry::withoutGlobalScope('organization')->create([
                'organization_id' => $organization->id,
                'amount_signed' => -$amount,
                'reason' => $reason,
                'source_type' => $sourceType,
                'source_reference' => $sourceReference,
                'balance_after' => $balance - $amount,
                'bucket' => $bucket,
                'period_key' => $periodKey,
            ]);
        });
    }

    // ------------------------------------------------------------------

    private function assertPositive(int $amount): void
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException("Credit amount must be positive, got {$amount}.");
        }
    }

    private function findBySource(Organization $organization, string $sourceType, string $sourceReference): ?CreditLedgerEntry
    {
        return CreditLedgerEntry::withoutGlobalScope('organization')
            ->where('organization_id', $organization->id)
            ->where('source_type', $sourceType)
            ->where('source_reference', $sourceReference)
            ->first();
    }

    /** Current balance under a write lock; must run inside a transaction. */
    private function lockedBalance(Organization $organization): int
    {
        Organization::whereKey($organization->id)->lockForUpdate()->first();

        // PostgreSQL rejects FOR UPDATE with an aggregate, so lock the rows
        // first and sum in PHP.
        return (int) CreditLedgerEntry::withoutGlobalScope('organization')
            ->where('organization_id', $organization->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['amount_signed'])
            ->sum('amount_signed');
    }
}
