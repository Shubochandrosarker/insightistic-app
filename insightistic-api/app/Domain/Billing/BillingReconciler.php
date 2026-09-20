<?php

namespace App\Domain\Billing;

use App\Domain\Billing\Exceptions\PaddleApiException;
use App\Models\BillingTransaction;
use App\Models\CreditLedgerEntry;
use App\Models\EntitlementGrant;
use App\Models\Subscription;
use App\Services\Billing\PaddleClient;
use Illuminate\Support\Facades\Log;

/**
 * Repairs drift between local billing state and Paddle's (roadmap
 * 23-STRIPE-TO-PADDLE-MIGRATION §4.3 "reconciliation"). Webhooks are
 * eventually-consistent — a delivery lost during an outage, a job that
 * exhausted its retries, a price unmapped at fulfilment time — and this is
 * the safety net that converges local state back onto the provider.
 *
 * The reconciler repairs and marks, never deletes: billing rows are the
 * financial history and are only ever projected forward.
 */
final class BillingReconciler
{
    public function __construct(
        private PaddleClient $client,
        private PaddleSubscriptionProjector $subscriptions,
        private PaddleTransactionProjector $transactions,
    ) {}

    /**
     * Re-fetch every local Paddle subscription from the API and re-project.
     * Convergence-safe by construction — projection is idempotent, so this
     * can run nightly without a change window.
     *
     * @return array{checked:int, projected:int, missing:int, errors:int}
     */
    public function reconcileSubscriptions(?int $organizationId = null): array
    {
        $summary = ['checked' => 0, 'projected' => 0, 'missing' => 0, 'errors' => 0];

        Subscription::withoutGlobalScope('organization')
            ->where('billing_provider', 'paddle')
            ->whereNotNull('provider_subscription_id')
            ->when($organizationId, fn ($query) => $query->where('organization_id', $organizationId))
            ->orderBy('id')
            ->each(function (Subscription $sub) use (&$summary) {
                $summary['checked']++;

                try {
                    $payload = $this->client->subscription($sub->provider_subscription_id);
                } catch (PaddleApiException $e) {
                    if ($e->status === 404) {
                        // Subscription is gone provider-side. Keep the local
                        // row (financial history) but flag it so operators
                        // can see why it no longer moves.
                        $summary['missing']++;
                        $sub->update(['provider_status' => 'not_found']);

                        Log::warning('Paddle subscription not found during reconciliation; marked not_found.', [
                            'organization_id'          => $sub->organization_id,
                            'provider_subscription_id' => $sub->provider_subscription_id,
                        ]);

                        return;
                    }

                    $summary['errors']++;
                    $this->logError('subscription', $sub->provider_subscription_id, $e);

                    return;
                }

                $this->subscriptions->project($payload);
                $summary['projected']++;
            });

        return $summary;
    }

    /**
     * Re-run fulfilment for recent completed one-time transactions and verify
     * a grant/credit actually landed. Catches the worst silent failure mode:
     * money taken, entitlement never delivered.
     *
     * @return array{checked:int, repaired_grants:int, repaired_credits:int, missing_credits:int, errors:int}
     */
    public function reconcileUnfulfilledTransactions(int $days = 7): array
    {
        $summary = ['checked' => 0, 'repaired_grants' => 0, 'repaired_credits' => 0, 'missing_credits' => 0, 'errors' => 0];

        BillingTransaction::withoutGlobalScope('organization')
            ->where('provider', 'paddle')
            ->whereIn('kind', ['lifetime', 'credit_pack'])
            ->where('status', 'completed')
            ->where('created_at', '>=', now()->subDays($days))
            ->orderBy('id')
            ->each(function (BillingTransaction $txn) use (&$summary, $days) {
                $summary['checked']++;

                try {
                    $alreadyGranted = EntitlementGrant::withoutGlobalScope('organization')
                        ->where('source', 'paddle_lifetime')
                        ->where('source_reference', $txn->provider_transaction_id)
                        ->exists();

                    $alreadyCredited = CreditLedgerEntry::withoutGlobalScope('organization')
                        ->where('source_type', 'paddle_credit_pack')
                        ->where('source_reference', $txn->provider_transaction_id)
                        ->exists();

                    $this->transactions->fulfill($txn);

                    if ($txn->kind === 'lifetime' && ! $alreadyGranted) {
                        if ($this->grantExists($txn)) {
                            $summary['repaired_grants']++;
                            $this->logRepair('entitlement grant', $txn);
                        } else {
                            $summary['errors']++;
                            Log::warning('Billing reconciler could not repair a missing lifetime grant.', [
                                'provider_transaction_id' => $txn->provider_transaction_id,
                            ]);
                        }
                    }

                    if ($txn->kind === 'credit_pack' && ! $alreadyCredited) {
                        if ($this->creditExists($txn)) {
                            $summary['repaired_credits']++;
                            $this->logRepair('credit grant', $txn);
                        } else {
                            // A credit pack whose plan_prices metadata lacks
                            // `credits` can never fulfil — surface it every
                            // run instead of "repairing" it into silence.
                            $summary['missing_credits']++;
                            Log::warning('Billing reconciler found a credit pack that still has no ledger entry (price metadata missing `credits`?).', [
                                'provider_transaction_id' => $txn->provider_transaction_id,
                                'provider_price_id'       => $txn->provider_price_id,
                            ]);
                        }
                    }
                } catch (\Throwable $e) {
                    $summary['errors']++;
                    Log::error('Billing reconciler failed to fulfil Paddle transaction.', [
                        'provider_transaction_id' => $txn->provider_transaction_id,
                        'kind'                    => $txn->kind,
                        'error'                   => $e->getMessage(),
                        'window_days'             => $days,
                    ]);
                }
            });

        return $summary;
    }

    // ------------------------------------------------------------------

    private function grantExists(BillingTransaction $txn): bool
    {
        return EntitlementGrant::withoutGlobalScope('organization')
            ->where('source', 'paddle_lifetime')
            ->where('source_reference', $txn->provider_transaction_id)
            ->exists();
    }

    private function creditExists(BillingTransaction $txn): bool
    {
        return CreditLedgerEntry::withoutGlobalScope('organization')
            ->where('source_type', 'paddle_credit_pack')
            ->where('source_reference', $txn->provider_transaction_id)
            ->exists();
    }

    private function logRepair(string $what, BillingTransaction $txn): void
    {
        Log::warning("Billing reconciler repaired a missing {$what}.", [
            'provider_transaction_id' => $txn->provider_transaction_id,
            'organization_id'         => $txn->organization_id,
            'kind'                    => $txn->kind,
        ]);
    }

    private function logError(string $what, string $providerId, \Throwable $e): void
    {
        Log::error("Billing reconciler could not refresh Paddle {$what}.", [
            'provider_id' => $providerId,
            'status'      => $e instanceof PaddleApiException ? $e->status : null,
            'error'       => $e->getMessage(),
        ]);
    }
}
