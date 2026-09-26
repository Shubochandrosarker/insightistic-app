<?php

namespace App\Domain\Billing;

use App\Models\BillingTransaction;
use App\Models\EntitlementGrant;
use App\Models\Organization;
use App\Models\PlanPrice;
use App\Models\ProductEvent;
use App\Models\Subscription;
use App\Jobs\DeliverPurchaseConversions;
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

        $priceId = $payload['items'][0]['price']['id'] ?? null;
        $planPrice = $this->planPriceFor($priceId);
        $kind = $this->kindFor($planPrice);
        $status = $statusOverride ?? $this->statusFor($payload['status'] ?? null);
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
        $txn->summary_json = [
            'subscription_id' => $payload['subscription_id'] ?? null,
            'customer_id'     => $payload['customer_id'] ?? null,
            'custom_data'     => $payload['custom_data'] ?? null,
        ];

        $txn->save();

        if ($completed) {
            $this->fulfill($txn);
            $this->firePurchaseEvents($txn, $org);
        }

        return $txn;
    }

    /**
     * Server-side purchase conversion events (spec §32/§33) — recorded from
     * VERIFIED Paddle webhook state, never from a browser callback. Exactly-
     * once per transaction: dedupe key is transaction_id inside props.
     */
    private function firePurchaseEvents(BillingTransaction $txn, Organization $org): void
    {
        try {
            $analytics = app(\App\Services\Analytics\AttributionService::class);

            $already = ProductEvent::withoutGlobalScope('organization')
                ->where('event', 'purchase_completed')
                ->where('props_json->transaction_id', $txn->provider_transaction_id)
                ->exists();
            if ($already) {
                return;
            }

            $analytics->event('purchase_completed', $org, null, [
                'transaction_id'  => $txn->provider_transaction_id,
                'subscription_id' => $txn->provider_subscription_id,
                'plan_kind'       => $txn->kind,
                'price_id'        => $txn->provider_price_id,
                'currency'        => $txn->currency,
                'amount'          => $txn->total_minor,
            ]);

            DeliverPurchaseConversions::dispatch($txn->id, $org->id);

            match ($txn->kind) {
                'lifetime'    => $analytics->event('ltd_purchased', $org, null, ['transaction_id' => $txn->provider_transaction_id, 'price_id' => $txn->provider_price_id]),
                'credit_pack' => $analytics->event('credit_pack_purchased', $org, null, ['transaction_id' => $txn->provider_transaction_id, 'price_id' => $txn->provider_price_id]),
                default       => null,
            };
        } catch (\Throwable $e) {
            Log::warning('purchase event recording failed', ['txn' => $txn->id, 'error' => $e->getMessage()]);
        }
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

        // Purchases created outside our checkout (Paddle dashboard quick
        // sales, payment links, customer-portal upgrades) may carry no
        // custom_data or subscription. Use known Paddle customer references,
        // but only when they identify exactly one organization; a shared
        // customer must never be guessed onto the most recent tenant.
        $customerId = $payload['customer_id'] ?? null;

        if ($customerId) {
            $transactionOrganizations = BillingTransaction::withoutGlobalScope('organization')
                ->where('provider', 'paddle')
                ->where('summary_json->customer_id', $customerId)
                ->whereNotNull('organization_id')
                ->pluck('organization_id');
            $subscriptionOrganizations = Subscription::withoutGlobalScope('organization')
                ->where('billing_provider', 'paddle')
                ->where('provider_customer_id', $customerId)
                ->whereNotNull('organization_id')
                ->pluck('organization_id');

            $organizationIds = $transactionOrganizations
                ->merge($subscriptionOrganizations)
                ->unique()
                ->values();

            if ($organizationIds->count() === 1 && ($org = Organization::find($organizationIds->first()))) {
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

        // Identity lookup, deliberately without an is_active filter: the
        // projector must keep classifying historical prices (founder LTDs,
        // retired plans) correctly when an old webhook is replayed.
        return PlanPrice::query()
            ->where('provider', 'paddle')
            ->where('provider_price_id', $priceId)
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
