<?php

namespace App\Domain\Billing;

use App\Domain\Billing\Exceptions\BillingConfigurationException;
use App\Models\CreditLedgerEntry;
use App\Models\EntitlementGrant;
use App\Models\Organization;
use App\Models\Plan;
use Illuminate\Support\Facades\DB;

/**
 * No-payment trials (Shuvo, 2026-10-27): any regular plan can be activated
 * as a 7-day trial without a card. Only the Founding Angel LTD goes through
 * direct (paid) checkout. One trial per organization.
 */
final class TrialService
{
    public function __construct(
        private CreditWallet $wallet,
        private CreditLedger $ledger,
    ) {}

    public function trialDays(): int
    {
        return max(1, (int) config('commercial.trial_days', 7));
    }

    /** @return array{eligible:bool, reason:?string, days:int} */
    public function eligibility(Organization $org): array
    {
        $reason = null;
        $sub = $org->subscription;
        $hasLifetime = EntitlementGrant::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)->where('source', 'paddle_lifetime')
            ->whereNull('revoked_at')->exists();

        if ($org->trial_started_at !== null) {
            $reason = 'trial_already_used';
        } elseif ($sub && in_array($sub->status, ['active', 'trialing', 'past_due', 'paused'], true)) {
            $reason = 'paid_subscription_active';
        } elseif ($hasLifetime) {
            $reason = 'lifetime_plan_active';
        }

        return ['eligible' => $reason === null, 'reason' => $reason, 'days' => $this->trialDays()];
    }

    public function start(Organization $org, string $planSlug): Organization
    {
        if (! in_array($planSlug, config('commercial.recurring_plan_slugs', []), true)) {
            throw new BillingConfigurationException('plan_not_trialable', 'Only regular plans can be started as a free trial.');
        }
        $plan = Plan::where('slug', $planSlug)->where('is_active', true)->first();
        if (! $plan) {
            throw new BillingConfigurationException('plan_not_available', 'This plan is not available.');
        }

        $check = $this->eligibility($org);
        if (! $check['eligible']) {
            throw new BillingConfigurationException($check['reason'], match ($check['reason']) {
                'trial_already_used' => 'Your free trial has already been used. Choose a plan to continue.',
                default => 'Your account already has an active paid plan.',
            });
        }

        DB::transaction(function () use ($org, $plan): void {
            $org->forceFill([
                'plan_id' => $plan->id,
                'status' => 'trialing',
                'trial_started_at' => now(),
                'trial_plan_slug' => $plan->slug,
                'trial_ends_at' => now()->addDays($this->trialDays()),
            ])->save();
        });

        // Grant the trial plan's monthly allowance immediately (tops up any
        // Free allowance already granted this period — never double grants).
        $this->wallet->ensureCurrentPeriod($org->fresh());

        return $org->fresh();
    }

    /**
     * A lapsed trial falls back to Free (idempotent) and the unused trial allowance above
     * the Free allowance is removed, so the ledger never shows paid-plan
     * credits the org is no longer entitled to. Purchased credits untouched.
     */
    public function expire(Organization $org): void
    {
        if ($org->status !== 'trialing' || ! $org->trial_ends_at || $org->trial_ends_at->isFuture()) {
            return;
        }
        $free = Plan::where('slug', 'free')->first();
        // Status stays 'trialing' with a past trial_ends_at: that is the
        // existing "trial expired" projection (licenses, reminders, win-back).
        if ($free && $org->plan_id !== $free->id) {
            $org->forceFill(['plan_id' => $free->id])->save();
        }

        $period = $this->wallet->periodKey();
        $remaining = $this->ledger->bucketBalance($org, 'monthly_included', $period);
        $freeAllowance = (int) ($free?->limit('monthly_ai_credits') ?? 0);
        $excess = $remaining - $freeAllowance;
        if ($excess > 0) {
            $this->ledger->consumeBucket(
                $org, $excess, 'trial_expired', 'trial_expired_adjustment',
                $org->id . ':' . $period . ':trial-expired', 'monthly_included', $period,
            );
        }
    }
}
