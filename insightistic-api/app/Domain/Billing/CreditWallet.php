<?php

namespace App\Domain\Billing;

use App\Domain\Billing\Exceptions\InsufficientCreditsException;
use App\Models\CreditLedgerEntry;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Separates expiring monthly allowance from purchased, non-expiring credits. */
final class CreditWallet
{
    public function __construct(
        private CreditLedger $ledger,
        private EntitlementResolver $entitlements,
    ) {}

    public function periodKey(?CarbonImmutable $now = null): string
    {
        return ($now ?: CarbonImmutable::now('UTC'))->format('Y-m');
    }

    public function ensureCurrentPeriod(Organization $organization): void
    {
        $period = $this->periodKey();
        DB::transaction(function () use ($organization, $period): void {
            // The organization row serializes lazy grant creation for the
            // first concurrent requests in a new billing period.
            $lockedOrganization = Organization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $entries = CreditLedgerEntry::withoutGlobalScope('organization')
                ->where('organization_id', $lockedOrganization->id)->where('bucket', 'monthly_included')
                ->where('period_key', $period)->get();

            $resolved = $this->entitlements->resolve($lockedOrganization);
            $amount = (int) ($resolved['limits']['monthly_ai_credits'] ?? 0);
            if ($amount <= 0 && ! ($resolved['plan']['id'] ?? null) && $lockedOrganization->plan) {
                $amount = $lockedOrganization->plan->limit('monthly_ai_credits');
            }
            if ($amount <= 0) {
                return;
            }

            $granted = (int) $entries
                ->whereIn('source_type', ['monthly_grant', 'monthly_plan_adjustment'])
                ->sum('amount_signed');
            if ($granted >= $amount) {
                return;
            }

            $grantAmount = $amount - $granted;
            $planSlug = (string) ($resolved['plan']['slug'] ?? 'unknown');
            $sourceType = $granted > 0 ? 'monthly_plan_adjustment' : 'monthly_grant';
            $sourceReference = $granted > 0
                ? $lockedOrganization->id . ':' . $period . ':plan-adjustment:' . $planSlug . ':' . $amount
                : $lockedOrganization->id . ':' . $period;

            $this->ledger->grant(
                $lockedOrganization, $grantAmount, $sourceType === 'monthly_grant' ? 'monthly_included_credits' : 'monthly_plan_upgrade', $sourceType,
                $sourceReference, 'monthly_included', $period,
                CarbonImmutable::now('UTC')->startOfMonth()->addMonth(),
                ['plan_slug' => $planSlug, 'catalog_version' => config('commercial.version')],
            );
        });
    }

    public function summary(Organization $organization): array
    {
        $this->ensureCurrentPeriod($organization);
        $period = $this->periodKey();
        $monthly = $this->ledger->bucketBalance($organization, 'monthly_included', $period);
        $purchased = $this->ledger->bucketBalance($organization, 'purchased');
        $granted = (int) CreditLedgerEntry::withoutGlobalScope('organization')
            ->where('organization_id', $organization->id)->where('bucket', 'monthly_included')->where('period_key', $period)
            ->where('amount_signed', '>', 0)->sum('amount_signed');

        return [
            'monthly_included' => ['granted' => $granted, 'remaining' => max(0, $monthly)],
            'purchased' => ['remaining' => max(0, $purchased)],
            'total_usable' => max(0, $monthly) + max(0, $purchased),
            'period' => $period,
            'next_reset_at' => CarbonImmutable::now('UTC')->startOfMonth()->addMonth()->toIso8601String(),
        ];
    }

    /**
     * Purchased (non-expiring) credits from a one-time pack. `reference` is
     * the provider transaction/session id and doubles as the ledger's
     * idempotency key, so a replayed webhook credits exactly once.
     */
    public function grantPurchased(
        Organization $organization,
        int $amount,
        string $reference,
        string $reason = 'credit_pack_purchase',
        string $sourceType = 'paddle_credit_pack',
        array $metadata = [],
    ): void {
        $this->ledger->grant(
            $organization, $amount, $reason, $sourceType, $reference, 'purchased', null, null,
            ['catalog_version' => config('commercial.version')] + $metadata,
        );
    }

    /** Consume included credits first, then purchased credits, atomically/idempotently. */
    public function consume(Organization $organization, int $amount, string $reason, string $reference): array
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Credit amount must be positive.');
        }

        $this->ensureCurrentPeriod($organization);
        $period = $this->periodKey();
        $includedRef = $reference . ':included';
        $purchasedRef = $reference . ':purchased';
        return DB::transaction(function () use ($organization, $amount, $reason, $period, $includedRef, $purchasedRef): array {
            Organization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $existing = CreditLedgerEntry::withoutGlobalScope('organization')
                ->where('organization_id', $organization->id)
                ->whereIn('source_reference', [$includedRef, $purchasedRef])->get();
            $already = abs((int) $existing->sum('amount_signed'));
            $remaining = max(0, $amount - $already);
            $entries = $existing->all();

            if ($remaining <= 0) {
                return $entries;
            }

            // Check the complete wallet before creating either deduction so a
            // failed mixed spend never consumes only its monthly half.
            $monthlyAvailable = max(0, $this->ledger->bucketBalance($organization, 'monthly_included', $period));
            $purchasedAvailable = max(0, $this->ledger->bucketBalance($organization, 'purchased'));
            if (($monthlyAvailable + $purchasedAvailable) < $remaining) {
                throw new InsufficientCreditsException($amount, $monthlyAvailable + $purchasedAvailable + $already);
            }

            $monthlySpend = min($remaining, $monthlyAvailable);
            if ($monthlySpend > 0) {
                $entries[] = $this->ledger->consumeBucket($organization, $monthlySpend, $reason, 'ai_usage_monthly', $includedRef, 'monthly_included', $period);
                $remaining -= $monthlySpend;
            }
            if ($remaining > 0) {
                $entries[] = $this->ledger->consumeBucket($organization, $remaining, $reason, 'ai_usage_purchased', $purchasedRef, 'purchased');
            }

            return $entries;
        });
    }

    public function transactions(Organization $organization, int $limit = 50)
    {
        return CreditLedgerEntry::withoutGlobalScope('organization')
            ->where('organization_id', $organization->id)->latest('id')->limit($limit)->get();
    }
}
