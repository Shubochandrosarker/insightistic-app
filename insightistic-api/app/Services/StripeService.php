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
}
