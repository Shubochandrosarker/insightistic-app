<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\EntitlementGrant;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Support\Carbon;

/**
 * Core Stripe -> local-state projection (spec §13.8). Shared by the live
 * webhook endpoint (BillingController::webhook) and the Super Admin tools
 * that replay a specific event or resync straight from Stripe
 * (Admin\SubscriptionController). Every public method here converges to the
 * same state no matter how many times it runs on the same input — that's
 * what makes "replay an event" and "resync from Stripe" safe to expose as
 * admin actions instead of one-shot webhook-only logic.
 */
class StripeWebhookProcessor
{
    public function __construct(
        private LicenseService $licenses,
        private LifecycleMailer $mailer,
    ) {}

    /**
     * Handle a live webhook delivery exactly once.
     *
     * Stripe retries on its own schedule and an operator can replay a
     * delivery from the dashboard, so the same event id arrives more than
     * once in normal operation. The unique index on stripe_event_id decides
     * the winner: a duplicate insert throws and we return without
     * reprocessing. A SELECT-then-INSERT check would leave a race window
     * between two concurrent deliveries.
     *
     * Deliberately NOT applied to process() itself — Super Admin's "replay
     * this event" tool calls the public projection methods directly and is
     * *meant* to re-run, which is safe because each converges to the same
     * state.
     *
     * @return bool false when this delivery was a duplicate
     */
    public function processOnce(\Stripe\Event $event): bool
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($event): bool {
            // insertOrIgnore rather than create()-and-catch: on PostgreSQL a
            // failed INSERT aborts the surrounding transaction. Keeping the
            // projection in this transaction also means a failed delivery is
            // rolled back, so Stripe's retry can process it again.
            $inserted = \Illuminate\Support\Facades\DB::table('stripe_webhook_events')->insertOrIgnore([
                'stripe_event_id' => $event->id,
                'type'            => $event->type,
                'created_at'      => now(),
            ]);

            if ($inserted === 0) {
                $existing = \Illuminate\Support\Facades\DB::table('stripe_webhook_events')
                    ->where('stripe_event_id', $event->id)
                    ->lockForUpdate()
                    ->first();

                if ($existing?->processed_at !== null) {
                    return false;
                }
            }

            $this->process($event);

            \App\Models\StripeWebhookEvent::where('stripe_event_id', $event->id)
                ->update(['processed_at' => now()]);

            return true;
        });
    }

    public function process(\Stripe\Event $event): void
    {
        switch ($event->type) {
            case 'checkout.session.completed':
            case 'checkout.session.async_payment_succeeded':
                if (($event->data->object->mode ?? null) === 'payment') {
                    $this->fulfillLifetime($event->data->object);
                    break;
                }
                $this->syncSubscription($event);
                $this->markTrialConsumed($event->data->object);
                break;
            case 'checkout.session.async_payment_failed':
                $this->releaseReservation($event->data->object);
                break;
            case 'customer.subscription.created':
            case 'customer.subscription.updated':
                $this->syncSubscription($event);
                break;
            case 'customer.subscription.deleted':
                $this->cancelSubscription($event);
                break;
            case 'invoice.payment_failed':
                $this->markPastDue($event);
                break;
            case 'checkout.session.expired':
                $this->releaseReservation($event->data->object);
                break;
            case 'invoice.paid':
                $this->markPaid($event->data->object);
                break;
            case 'charge.refunded':
                $this->handleRefund($event->data->object);
                break;
        }
    }

    public function syncSubscription(\Stripe\Event $event): void
    {
        $object = $event->data->object;

        // checkout.session.completed carries the subscription id, not the object itself.
        $stripeSub = ($event->type === 'checkout.session.completed') ? null : $object;
        $metaOrg = $object->metadata->organization_id ?? null;
        $metaPlan = $object->metadata->plan_id ?? null;
        $customerId = $object->customer ?? null;

        $org = $this->resolveOrg($metaOrg, $customerId);
        if (! $org) {
            return;
        }

        $this->applyStripeState(
            $org,
            $customerId,
            $object->subscription ?? ($stripeSub->id ?? null),
            $stripeSub,
            $metaPlan,
            $event->type === 'checkout.session.completed',
            $object->payment_status ?? null,
        );
    }

    /** Admin "resync from Stripe" (spec §13.8) — pulls live state instead of replaying a stored event. */
    public function resyncFromLive(Organization $org, \Stripe\Subscription $stripeSub): void
    {
        $this->applyStripeState($org, $stripeSub->customer, $stripeSub->id, $stripeSub, null, false);
    }

    private function applyStripeState(
        Organization $org,
        ?string $customerId,
        ?string $subscriptionId,
        $stripeSub,
        $metaPlan,
        bool $isCheckout,
        ?string $paymentStatus = null,
    ): void {
        $sub = Subscription::withoutGlobalScope('organization')
            ->firstOrNew(['organization_id' => $org->id]);

        $sub->organization_id        = $org->id;
        $sub->billing_provider       = 'stripe';
        $sub->stripe_customer_id     = $customerId ?: $sub->stripe_customer_id;
        $sub->stripe_subscription_id = $subscriptionId ?: $sub->stripe_subscription_id;
        $sub->provider_customer_id   = $sub->stripe_customer_id;

        if ($stripeSub) {
            $item = $stripeSub->items->data[0] ?? null;
            // API ≥ 2025-03 moved the period onto the subscription item.
            $periodStart = $stripeSub->current_period_start ?? ($item->current_period_start ?? null);
            $periodEnd   = $stripeSub->current_period_end ?? ($item->current_period_end ?? null);
            $sub->status               = $stripeSub->status;
            $sub->provider_status      = $stripeSub->status;
            $sub->current_period_start = $this->ts($periodStart);
            $sub->current_period_end   = $this->ts($periodEnd);
            $sub->cancel_at_period_end = (bool) ($stripeSub->cancel_at_period_end ?? false);
            $sub->next_billed_at       = $sub->cancel_at_period_end ? null : $this->ts($periodEnd);
            $priceId = $item->price->id ?? null;
            $sub->provider_price_id    = $priceId;
            $plan = $this->planFromPrice($priceId) ?? ($metaPlan ? Plan::find($metaPlan) : null);
        } else {
            // checkout.session.completed (subscription mode): the session has
            // no status; the customer.subscription.* event carries the truth.
            // Trial checkouts complete with payment_status=no_payment_required.
            if (! $sub->exists || ! $sub->status || $sub->status === 'incomplete') {
                $sub->status = $paymentStatus === 'no_payment_required' ? 'trialing' : 'active';
            }
            $plan = $metaPlan ? Plan::find($metaPlan) : null;
        }

        $previousPlanId = $org->plan_id;
        $hadSubscription = (bool) $sub->exists;

        if ($plan) {
            $sub->plan_id = $plan->id;
            $org->plan_id = $plan->id;
            EntitlementGrant::withoutGlobalScope('organization')
                ->where('organization_id', $org->id)
                ->where('source', 'manual')
                ->where('source_reference', 'admin_plan_override:' . $org->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);
        }
        $sub->save();

        $org->status = $this->orgStatus($sub->status);
        $org->billing_provider = 'stripe';
        if ($stripeSub && ($stripeSub->status ?? null) === 'trialing' && ! empty($stripeSub->trial_end)) {
            $org->trial_ends_at = $this->ts($stripeSub->trial_end);
        }
        if ($customerId && ! $org->stripe_customer_id) {
            $org->stripe_customer_id = $customerId;
        }
        $org->save();

        // License status/limits are a projection of subscription state.
        $this->licenses->syncForOrganization($org->fresh(['plan']));

        if (! $hadSubscription || $isCheckout) {
            $this->mailer->send($org->fresh('plan'), 'subscription_started', [], 'sub:' . ($sub->stripe_subscription_id ?: 'first'));
        } elseif ($plan && $previousPlanId && $plan->id !== $previousPlanId) {
            $this->mailer->send($org->fresh('plan'), 'plan_changed', [], 'plan:' . $plan->id . ':' . now()->format('Ymd'));
        }
    }

    public function cancelSubscription(\Stripe\Event $event): void
    {
        $object = $event->data->object;
        $org = $this->resolveOrg($object->metadata->organization_id ?? null, $object->customer ?? null);
        if (! $org) {
            return;
        }

        Subscription::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)
            ->update(['status' => 'canceled', 'cancel_at_period_end' => false]);

        $org->update(['status' => 'canceled']);
        $this->licenses->syncForOrganization($org->fresh());

        $this->mailer->send($org, 'subscription_cancelled', [
            'period_end' => $org->subscription?->current_period_end?->format('M j, Y'),
        ], 'cancel-hook:' . now()->format('Ymd'));
    }

    public function markPastDue(\Stripe\Event $event): void
    {
        $object = $event->data->object;
        $org = $this->resolveOrg(null, $object->customer ?? null);
        if ($org) {
            $org->update(['status' => 'past_due']);
            $this->licenses->syncForOrganization($org->fresh());
            $this->mailer->send($org, 'payment_failed', [], 'pf:' . now()->format('Ym'));
        }
    }

    /**
     * invoice.paid — a renewal/first charge succeeded. Clears past_due and
     * records the invoice as a provider-neutral billing transaction.
     */
    public function markPaid($invoice): void
    {
        $org = $this->resolveOrg($invoice->metadata->organization_id ?? null, $invoice->customer ?? null);
        if (! $org) {
            return;
        }

        $subscriptionId = $invoice->subscription
            ?? ($invoice->parent->subscription_details->subscription ?? null);
        if (! $subscriptionId) {
            // One-time (LTD) invoice: checkout.session.completed owns the
            // transaction + fulfilment. Recording it here too would count the
            // sale twice against the shared 100-spot cap.
            return;
        }

        $this->recordTransaction($org, (string) $invoice->id, 'subscription', 'completed', $invoice, [
            'invoice_number' => $invoice->number ?? null,
            'hosted_invoice_url' => $invoice->hosted_invoice_url ?? null,
            'invoice_pdf' => $invoice->invoice_pdf ?? null,
        ], $subscriptionId);

        $sub = Subscription::withoutGlobalScope('organization')->where('organization_id', $org->id)->first();
        if ($sub && in_array($sub->status, ['past_due', 'unpaid', 'incomplete'], true)) {
            $sub->update(['status' => 'active', 'provider_status' => 'active']);
        }
        if (in_array($org->status, ['past_due', 'incomplete'], true)) {
            $org->update(['status' => 'active']);
        }
        $this->licenses->syncForOrganization($org->fresh());
    }

    /**
     * checkout.session.completed with mode=payment — the Founding Angel LTD.
     * Shares the 100-spot cap with Paddle via LtdCampaignService::confirmSale
     * and grants the same entitlement shape (source stripe_lifetime).
     */
    public function fulfillLifetime($session): void
    {
        if (($session->payment_status ?? null) !== 'paid') {
            return; // async payment methods complete later via checkout.session.async_payment_succeeded
        }
        $meta = $session->metadata ?? null;
        $org = $this->resolveOrg($meta->organization_id ?? null, $session->customer ?? null);
        $plan = isset($meta->plan_id) ? Plan::find($meta->plan_id) : null;
        if (! $org || ! $plan) {
            \Illuminate\Support\Facades\Log::warning('Stripe LTD session without org/plan metadata', ['session' => $session->id ?? null]);

            return;
        }

        $price = \App\Models\PlanPrice::where('plan_id', $plan->id)->where('provider', 'stripe')->where('kind', 'lifetime')->first();
        // Recorded as 'pending' until confirmSale() binds the reservation:
        // campaign() resyncs successful_sales from COMPLETED lifetime rows,
        // so a completed row written first would be counted twice.
        $txn = $this->recordTransaction($org, (string) $session->id, 'lifetime', 'pending', $session, [
            'payment_intent' => $session->payment_intent ?? null,
            'custom_data' => ['ltd_reservation_id' => $meta->ltd_reservation_id ?? null],
        ], null, $price?->provider_price_id);

        app(\App\Domain\Billing\LtdCampaignService::class)->confirmSale($txn, ['ltd_reservation_id' => $meta->ltd_reservation_id ?? null], $price);
        if ($txn->status === 'pending') {
            $txn->forceFill(['status' => 'completed', 'completed_at' => now()])->save();
        }

        EntitlementGrant::withoutGlobalScope('organization')->firstOrCreate(
            ['organization_id' => $org->id, 'source' => 'stripe_lifetime', 'source_reference' => $txn->provider_transaction_id],
            ['plan_id' => $plan->id, 'features_json' => null, 'limits_json' => null],
        );

        $org->forceFill([
            'plan_id' => $plan->id,
            'status' => 'active',
            'billing_provider' => 'stripe',
            'trial_ends_at' => null,
            'trial_plan_slug' => null,
            'stripe_customer_id' => $org->stripe_customer_id ?: ($session->customer ?? null),
        ])->save();
        $this->licenses->syncForOrganization($org->fresh(['plan']));
        $this->mailer->send($org->fresh(['plan', 'owner']), 'lifetime_purchased', [
            'provider'        => 'Stripe',
            'amount_minor'    => $txn->total_minor,
            'currency'        => $txn->currency,
            'transaction_id'  => $txn->provider_transaction_id,
            'invoice_number'  => $txn->invoice_number,
            'purchase_date'   => $txn->completed_at?->format('M j, Y'),
            'refund_deadline' => $txn->completed_at?->copy()->addDays((int) config('commercial.refund_window_days', 30))->format('M j, Y'),
            'plan_name'       => $plan->name,
        ], 'ltd:' . $txn->provider_transaction_id);
    }

    /**
     * charge.refunded — a FULL refund of an LTD payment revokes the lifetime
     * grant (30-day money-back). Subscriptions are non-refundable by policy;
     * if one is refunded manually anyway we only record it — access follows
     * the subscription events.
     */
    public function handleRefund($charge): void
    {
        $paymentIntent = $charge->payment_intent ?? null;
        $full = (bool) ($charge->refunded ?? false);
        $txn = \App\Models\BillingTransaction::withoutGlobalScope('organization')
            ->where('provider', 'stripe')
            ->where(function ($q) use ($paymentIntent, $charge) {
                $q->where('summary_json->payment_intent', $paymentIntent)
                  ->orWhere('summary_json->charge', $charge->id ?? '__none__');
            })
            ->where('kind', 'lifetime')
            ->first();

        if (! $txn) {
            \Illuminate\Support\Facades\Log::info('Stripe charge.refunded with no local lifetime transaction', ['charge' => $charge->id ?? null]);

            return;
        }

        $summary = is_array($txn->summary_json) ? $txn->summary_json : [];
        $summary['refund'] = ['charge' => $charge->id ?? null, 'amount_refunded' => $charge->amount_refunded ?? null, 'full' => $full];
        $txn->forceFill(['status' => $full ? 'refunded' : 'partially_refunded', 'summary_json' => $summary])->save();

        if (! $full) {
            return;
        }

        EntitlementGrant::withoutGlobalScope('organization')
            ->where('organization_id', $txn->organization_id)
            ->where('source', 'stripe_lifetime')
            ->where('source_reference', $txn->provider_transaction_id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        if ($org = Organization::find($txn->organization_id)) {
            $this->licenses->syncForOrganization($org->fresh());
        }
    }

    /** An abandoned LTD checkout frees its Founding Angel slot right away. */
    public function releaseReservation($session): void
    {
        $id = $session->metadata->ltd_reservation_id ?? null;
        if ($id && ($reservation = \App\Models\LtdCheckoutReservation::find($id))) {
            app(\App\Domain\Billing\LtdCampaignService::class)->release($reservation);
        }
    }

    /** A Stripe trial checkout uses up the org's one trial (TrialService is the single source). */
    private function markTrialConsumed($session): void
    {
        if ((int) ($session->metadata->trial_days ?? 0) <= 0) {
            return;
        }
        $org = $this->resolveOrg($session->metadata->organization_id ?? null, $session->customer ?? null);
        if ($org && $org->trial_started_at === null) {
            $org->forceFill([
                'trial_started_at' => now(),
                'trial_plan_slug' => $session->metadata->plan_slug ?? null,
                'trial_ends_at' => now()->addDays((int) $session->metadata->trial_days),
            ])->save();
        }
    }

    private function recordTransaction(Organization $org, string $id, string $kind, string $status, $object, array $extra, ?string $subscriptionId = null, ?string $priceId = null): \App\Models\BillingTransaction
    {
        $tax = $object->total_details->amount_tax ?? ($object->tax ?? 0);

        return \App\Models\BillingTransaction::withoutGlobalScope('organization')->firstOrCreate(
            ['provider_transaction_id' => $id],
            [
                'organization_id' => $org->id,
                'provider' => 'stripe',
                'provider_subscription_id' => $subscriptionId,
                'provider_price_id' => $priceId ?? ($object->lines->data[0]->pricing->price_details->price ?? ($object->lines->data[0]->price->id ?? null)),
                'kind' => $kind,
                'status' => $status,
                'currency' => strtoupper((string) ($object->currency ?? 'usd')),
                'subtotal_minor' => (int) ($object->amount_subtotal ?? ($object->subtotal ?? 0)),
                'tax_minor' => (int) $tax,
                'total_minor' => (int) ($object->amount_total ?? ($object->total ?? ($object->amount_paid ?? 0))),
                'invoice_number' => $extra['invoice_number'] ?? null,
                'occurred_at' => now(),
                'completed_at' => $status === 'completed' ? now() : null,
                'summary_json' => array_filter($extra, fn ($v) => $v !== null),
            ],
        );
    }

    private function resolveOrg($metaOrgId, $customerId): ?Organization
    {
        if ($metaOrgId && ($org = Organization::find($metaOrgId))) {
            return $org;
        }
        if ($customerId && ($org = Organization::where('stripe_customer_id', $customerId)->first())) {
            return $org;
        }
        if ($customerId) {
            return Subscription::withoutGlobalScope('organization')
                ->where('stripe_customer_id', $customerId)
                ->first()?->organization;
        }
        return null;
    }

    private function planFromPrice(?string $priceId): ?Plan
    {
        if (! $priceId) {
            return null;
        }
        $mapped = \App\Models\PlanPrice::where('provider', 'stripe')->where('provider_price_id', $priceId)->first();
        if ($mapped) {
            return Plan::find($mapped->plan_id);
        }
        return Plan::where('stripe_price_id_monthly', $priceId)
            ->orWhere('stripe_price_id_yearly', $priceId)
            ->first();
    }

    private function orgStatus(string $stripeStatus): string
    {
        return match ($stripeStatus) {
            'active'   => 'active',
            'trialing' => 'trialing',
            'past_due', 'unpaid' => 'past_due',
            'canceled', 'incomplete_expired' => 'canceled',
            default => 'active',
        };
    }

    private function ts($unix): ?Carbon
    {
        return $unix ? Carbon::createFromTimestamp($unix) : null;
    }
}
