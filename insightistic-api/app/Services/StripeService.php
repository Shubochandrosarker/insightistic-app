<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Subscription;

/**
 * Thin wrapper over the official Stripe SDK. Requires:
 *   composer require stripe/stripe-php
 *
 * Config in config/insightistic.php → stripe.{secret,webhook_secret}.
 */
class StripeService
{
    private function client(): \Stripe\StripeClient
    {
        return new \Stripe\StripeClient(config('insightistic.stripe.secret'));
    }

    /** Get-or-create the Stripe customer for an org; persists the id locally. */
    public function customerId(Organization $org): string
    {
        $sub = $org->subscription;
        if ($sub && $sub->stripe_customer_id) {
            return $sub->stripe_customer_id;
        }

        $customer = $this->client()->customers->create([
            'name'     => $org->name,
            'email'    => $org->owner?->email,
            'metadata' => ['organization_id' => $org->id],
        ]);

        $sub ??= Subscription::create([
            'organization_id' => $org->id,
            'status'          => 'incomplete',
        ]);
        $sub->update(['stripe_customer_id' => $customer->id]);

        return $customer->id;
    }

    public function checkoutUrl(Organization $org, $plan, string $priceId, string $successUrl, string $cancelUrl): string
    {
        $session = $this->client()->checkout->sessions->create([
            'mode'              => 'subscription',
            'customer'          => $this->customerId($org),
            'line_items'        => [['price' => $priceId, 'quantity' => 1]],
            'success_url'       => $successUrl,
            'cancel_url'        => $cancelUrl,
            'metadata'          => ['organization_id' => $org->id, 'plan_id' => $plan->id],
            'subscription_data' => ['metadata' => ['organization_id' => $org->id, 'plan_id' => $plan->id]],
        ]);

        return $session->url;
    }

    /** Flip cancel-at-period-end (cancel / reactivate without leaving the app). */
    public function setCancelAtPeriodEnd(string $subscriptionId, bool $cancel): void
    {
        $this->client()->subscriptions->update($subscriptionId, [
            'cancel_at_period_end' => $cancel,
        ]);
    }

    public function portalUrl(string $customerId, string $returnUrl): string
    {
        $session = $this->client()->billingPortal->sessions->create([
            'customer'   => $customerId,
            'return_url' => $returnUrl,
        ]);

        return $session->url;
    }

    /** @return \Stripe\Event */
    public function verifyWebhook(string $payload, ?string $signature)
    {
        return \Stripe\Webhook::constructEvent(
            $payload,
            (string) $signature,
            config('insightistic.stripe.webhook_secret')
        );
    }

    // --- Super Admin inspection/mutation (spec §13.8) -------------------------

    /** @return \Stripe\Subscription */
    public function getSubscription(string $subscriptionId)
    {
        return $this->client()->subscriptions->retrieve($subscriptionId, [
            'expand' => ['latest_invoice', 'default_payment_method', 'items.data.price'],
        ]);
    }

    /** @return \Stripe\Customer */
    public function getCustomer(string $customerId)
    {
        return $this->client()->customers->retrieve($customerId, [
            'expand' => ['invoice_settings.default_payment_method'],
        ]);
    }

    /** @return \Stripe\Collection */
    public function listInvoices(string $customerId, int $limit = 10)
    {
        return $this->client()->invoices->all(['customer' => $customerId, 'limit' => $limit]);
    }

    /** Move a live subscription onto a different price. */
    public function changePlan(string $subscriptionId, string $newPriceId, string $prorationBehavior = 'create_prorations'): \Stripe\Subscription
    {
        $sub = $this->client()->subscriptions->retrieve($subscriptionId);
        $itemId = $sub->items->data[0]->id;

        return $this->client()->subscriptions->update($subscriptionId, [
            'items'              => [['id' => $itemId, 'price' => $newPriceId]],
            'proration_behavior' => $prorationBehavior,
        ]);
    }

    /** Cancel right now instead of at period end. */
    public function cancelImmediately(string $subscriptionId): void
    {
        $this->client()->subscriptions->cancel($subscriptionId);
    }

    /** Push a new trial_end onto the live Stripe subscription. */
    public function extendTrial(string $subscriptionId, \DateTimeInterface $trialEnd): \Stripe\Subscription
    {
        return $this->client()->subscriptions->update($subscriptionId, [
            'trial_end'          => $trialEnd->getTimestamp(),
            'proration_behavior' => 'none',
        ]);
    }

    /** Fetch a specific event by id so it can be replayed through the same handler as the live webhook. */
    public function retrieveEvent(string $eventId): \Stripe\Event
    {
        return $this->client()->events->retrieve($eventId);
    }
}
