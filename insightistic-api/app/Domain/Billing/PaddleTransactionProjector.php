<?php

namespace App\Domain\Billing;

use App\Models\BillingTransaction;
use App\Models\EntitlementGrant;
use App\Models\Organization;
use App\Models\PlanPrice;
use App\Models\Subscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Paddle -> local-state projection for transactions: financial history in
 * billing_transactions, plus fulfilment side effects for one-time purchases.
 * Subscription events stay authoritative for access — a completed
 * transaction of kind `subscription` records money only.
 *
 * Convergence-safe like the subscription projector: the upsert keys on the
 * provider transaction id, and fulfilment rides unique
 * (source, source_reference) / (source_type, source_reference) pairs, so a
 * replayed event or reconciler pass cannot grant or credit twice.
 */
final class PaddleTransactionProjector
{
    public function __construct(
        private CreditLedger $credits,
    ) {}

    /**
     * Upsert a Paddle transaction payload (webhook `data` or API entity).
     *
     * @param  string|null  $statusOverride  forces the stored status (e.g.
     *                                       transaction.payment_failed →
     *                                       'failed'); provider events alone
     *                                       never cancel a subscription.
     *
     * @throws \RuntimeException when the owning organization cannot be resolved
     */
    public function project(array $payload, ?string $statusOverride = null): BillingTransaction
    {
        $txn = BillingTransaction::withoutGlobalScope('organization')
            ->where('provider', 'paddle')
            ->where('provider_transaction_id', $payload['id'] ?? null)
            ->firstOrNew();

        $existingSummary = is_array($txn->summary_json) ? $txn->summary_json : [];
        $existingAdjustment = $existingSummary['adjustment'] ?? null;
        $priceId = $payload['items'][0]['price']['id'] ?? null;
        $planPrice = $this->planPriceFor($priceId);
        $kind = $this->kindFor($planPrice);
        $status = $statusOverride ?? $this->statusFor($payload['status'] ?? null);

        // A later transaction replay must not make a refunded/charged-back
        // LTD look paid again. The grant is also revoked, but retaining the
        // financial status makes the local ledger explainable.
        if (
            $status === 'completed'
            && is_array($existingAdjustment)
            && ($existingAdjustment['status'] ?? null) === 'approved'
            && ($existingAdjustment['type'] ?? null) === 'full'
            && in_array($existingAdjustment['action'] ?? null, ['refund', 'chargeback'], true)
        ) {
            $status = ($existingAdjustment['action'] ?? null) === 'chargeback' ? 'chargeback' : 'refunded';
        }

        $org = $this->resolveOrg($payload, $txn);

        if (! $org) {
            Log::warning('Paddle transaction event could not be resolved to an organization.', [
                'provider_transaction_id' => $payload['id'] ?? null,
                'subscription_id'         => $payload['subscription_id'] ?? null,
            ]);

            throw new \RuntimeException(
                'Paddle transaction ' . ($payload['id'] ?? '?') . ' has no resolvable organization.'
            );
        }

        $totals = $payload['details']['totals'] ?? [];
        $completed = $status === 'completed';

        $txn->organization_id           = $org->id;
        $txn->provider                  = 'paddle';
        $txn->provider_transaction_id   = $payload['id'];
        $txn->provider_subscription_id  = $payload['subscription_id'] ?? null;
        $txn->provider_price_id         = $priceId;
        $txn->kind                      = $kind;
        $txn->status                    = $status;
        $txn->currency                  = $totals['currency_code'] ?? $payload['currency_code'] ?? config('billing.paddle.default_currency', 'USD');
        $txn->subtotal_minor            = (int) ($totals['subtotal'] ?? 0);
        $txn->tax_minor                 = (int) ($totals['tax'] ?? 0);
        $txn->total_minor               = (int) ($totals['total'] ?? 0);
        $txn->invoice_number            = $payload['invoice_id'] ?? null;
        $txn->occurred_at               = $this->date($payload['billed_at'] ?? $payload['created_at'] ?? null);
        $txn->completed_at              = $completed ? $this->date($payload['billed_at'] ?? $payload['updated_at'] ?? null) : null;

        // Minimal pointer record only — never the full provider payload,
        // which can carry buyer PDL we do not want duplicated at rest.
        $txn->summary_json = array_merge($existingSummary, [
            'subscription_id' => $payload['subscription_id'] ?? null,
            'customer_id'     => $payload['customer_id'] ?? null,
            'custom_data'     => $payload['custom_data'] ?? null,
        ]);

        $txn->save();

        if ($completed) {
            $this->fulfill($txn);
        }

        return $txn;
    }

    /**
     * One-time-purchase fulfilment for a completed transaction. Idempotent:
     * both grant and credit lookups key on the provider transaction id, so
     * this is safe to re-run (the reconciler calls it directly).
     */
    public function fulfill(BillingTransaction $txn): void
    {
        $org = Organization::find($txn->organization_id);

        if (! $org) {
            return; // Org hard-deleted; nothing left to grant to.
        }

        $planPrice = $this->planPriceFor($txn->provider_price_id);

        if ($txn->kind === 'lifetime') {
            EntitlementGrant::withoutGlobalScope('organization')->firstOrCreate(
                [
                    'organization_id'  => $org->id,
                    'source'           => 'paddle_lifetime',
                    'source_reference' => $txn->provider_transaction_id,
                ],
                [
                    'plan_id'       => $planPrice?->plan_id,
                    'features_json' => null,
                    'limits_json'   => null,
                ],
            );

            return;
        }

        if ($txn->kind === 'credit_pack') {
            $credits = (int) ($planPrice?->metadata_json['credits'] ?? 0);

            if ($credits <= 0) {
                Log::warning('Paddle credit pack has no credits in plan_prices metadata; fulfilment skipped.', [
                    'provider_transaction_id' => $txn->provider_transaction_id,
                    'provider_price_id'       => $txn->provider_price_id,
                ]);

                return;
            }

            $this->credits->grant($org, $credits, 'credit_pack_purchase', 'paddle_credit_pack', (string) $txn->provider_transaction_id);
        }

        // kind 'subscription' (and any future kind): financial history only —
        // the subscription projector owns access state.
    }

    /**
     * Apply an approved full refund or chargeback to a one-time entitlement.
     *
     * Paddle exposes refunds as adjustment.created/adjustment.updated events.
     * Pending approvals and partial adjustments deliberately leave access
     * unchanged. Chargeback reversals do not auto-regrant access; an operator
     * can review those cases without turning a billing reversal into an
     * entitlement race.
     */
    public function processAdjustment(array $payload): void
    {
        $action = (string) ($payload['action'] ?? '');
        $status = (string) ($payload['status'] ?? '');
        $type = (string) ($payload['type'] ?? '');
        $transactionId = $payload['transaction_id'] ?? null;

        if (! $transactionId) {
            Log::warning('Paddle adjustment has no transaction id; entitlement state was not changed.', [
                'adjustment_id' => $payload['id'] ?? null,
                'action'        => $action,
            ]);

            return;
        }

        if (
            ! in_array($action, ['refund', 'chargeback'], true)
            || $status !== 'approved'
            || $type !== 'full'
        ) {
            Log::debug('Paddle adjustment acknowledged without entitlement revocation.', [
                'adjustment_id' => $payload['id'] ?? null,
                'transaction_id' => $transactionId,
                'action'        => $action,
                'status'        => $status,
                'type'          => $type,
            ]);

            return;
        }

        $txn = BillingTransaction::withoutGlobalScope('organization')
            ->where('provider', 'paddle')
            ->where('provider_transaction_id', $transactionId)
            ->first();

        if (! $txn) {
            // Throw so the queued event gets a retry if Paddle delivered the
            // adjustment before the completed transaction.
            throw new \RuntimeException(
                'Paddle adjustment ' . ($payload['id'] ?? '?') . ' references missing transaction ' . $transactionId . '.'
            );
        }

        if ($txn->kind !== 'lifetime') {
            Log::info('Paddle adjustment does not target a lifetime entitlement.', [
                'adjustment_id'            => $payload['id'] ?? null,
                'provider_transaction_id'  => $transactionId,
                'kind'                     => $txn->kind,
            ]);

            return;
        }

        $summary = is_array($txn->summary_json) ? $txn->summary_json : [];
        $summary['adjustment'] = [
            'id'         => $payload['id'] ?? null,
            'action'     => $action,
            'status'     => $status,
            'type'       => $type,
            'updated_at' => $payload['updated_at'] ?? null,
        ];

        $txn->forceFill([
            'status'       => $action === 'chargeback' ? 'chargeback' : 'refunded',
            'summary_json' => $summary,
        ])->save();

        $grant = EntitlementGrant::withoutGlobalScope('organization')
            ->where('organization_id', $txn->organization_id)
            ->where('source', 'paddle_lifetime')
            ->where('source_reference', $txn->provider_transaction_id)
            ->whereNull('revoked_at')
            ->first();

        if (! $grant) {
            Log::info('Paddle lifetime entitlement was already revoked or never granted.', [
                'adjustment_id'           => $payload['id'] ?? null,
                'provider_transaction_id' => $transactionId,
            ]);

            return;
        }

        $grant->forceFill(['revoked_at' => now()])->save();

        Log::warning('Paddle lifetime entitlement revoked after an approved full adjustment.', [
            'adjustment_id'           => $payload['id'] ?? null,
            'provider_transaction_id' => $transactionId,
            'organization_id'         => $txn->organization_id,
            'action'                  => $action,
        ]);
    }

    // ------------------------------------------------------------------

    private function resolveOrg(array $payload, BillingTransaction $txn): ?Organization
    {
        $organizationId = $payload['custom_data']['organization_id'] ?? null;

        if ($organizationId && ($org = Organization::find($organizationId))) {
            return $org;
        }

        if ($payload['subscription_id'] ?? null) {
            $sub = Subscription::withoutGlobalScope('organization')
                ->where('billing_provider', 'paddle')
                ->where('provider_subscription_id', $payload['subscription_id'])
                ->first();

            if ($sub && ($org = Organization::find($sub->organization_id))) {
                return $org;
            }
        }

        return $txn->exists ? Organization::find($txn->organization_id) : null;
    }

    private function planPriceFor(?string $priceId): ?PlanPrice
    {
        if (! $priceId) {
            return null;
        }

        return PlanPrice::query()
            ->where('provider', 'paddle')
            ->where('provider_price_id', $priceId)
            ->where('is_active', true)
            ->first();
    }

    /** Mapped one-time kinds are honoured; recurring/unmapped prices are subscriptions. */
    private function kindFor(?PlanPrice $planPrice): string
    {
        return in_array($planPrice?->kind, ['lifetime', 'credit_pack'], true)
            ? $planPrice->kind
            : 'subscription';
    }

    private function statusFor(?string $providerStatus): string
    {
        return $providerStatus === 'completed' ? 'completed' : (string) $providerStatus;
    }

    private function date($value): ?Carbon
    {
        return $value ? Carbon::parse($value) : null;
    }
}
