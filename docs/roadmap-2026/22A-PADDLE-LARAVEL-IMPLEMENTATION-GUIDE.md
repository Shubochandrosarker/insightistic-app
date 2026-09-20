# Paddle + Laravel Implementation Guide

This file is the concrete engineering companion to `22-PADDLE-BILLING.md`.

## 1. SDK choice

### Recommended for Insightistic migration

Use the official Paddle PHP SDK:

```bash
composer require paddlehq/paddle-php-sdk
```

Why this recommendation for Insightistic:

- the current application already has a custom billing/domain model
- existing Stripe customers may need temporary coexistence
- Insightistic needs one-time LTD purchases and credit packs as well as subscriptions
- a provider-neutral local projection is easier to preserve with a thin provider service

Paddle documentation also points Laravel users to Laravel Cashier Paddle. Cashier is a valid choice for a greenfield Paddle-only Laravel application, but adopting it during a Stripe-to-Paddle transition may couple product billing behavior more tightly to Paddle-specific model conventions. Re-evaluate Cashier after the migration if it materially reduces maintenance.

## 2. Composer migration

Current dependency:

```json
"stripe/stripe-php": "^21.0"
```

Transition state:

```json
"stripe/stripe-php": "^21.0",
"paddlehq/paddle-php-sdk": "^1.17"
```

Do not remove Stripe package until no legacy Stripe organization needs management or webhook processing.

## 3. Configuration

Create `config/billing.php`:

```php
<?php

return [
    'provider' => env('BILLING_PROVIDER', 'paddle'),

    'paddle' => [
        'environment' => env('PADDLE_ENV', 'sandbox'),
        'api_key' => env('PADDLE_API_KEY'),
        'client_token' => env('PADDLE_CLIENT_TOKEN'),
        'webhook_secret' => env('PADDLE_WEBHOOK_SECRET'),
    ],

    'stripe' => [
        // legacy migration-only configuration
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],
];
```

Never expose `PADDLE_API_KEY` or `PADDLE_WEBHOOK_SECRET` through `NEXT_PUBLIC_*` variables.

## 4. Database migrations

### 4.1 `plan_prices`

Illustrative migration:

```php
Schema::create('plan_prices', function (Blueprint $table) {
    $table->id();
    $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
    $table->string('provider', 32);             // paddle|stripe
    $table->string('kind', 32);                 // monthly|yearly|lifetime|credit_pack
    $table->string('provider_product_id')->nullable();
    $table->string('provider_price_id')->unique();
    $table->string('currency', 3)->default('USD');
    $table->unsignedBigInteger('amount_minor')->nullable();
    $table->string('billing_interval', 16)->nullable();
    $table->boolean('is_active')->default(true);
    $table->jsonb('metadata_json')->nullable();
    $table->timestamps();

    $table->unique(['plan_id', 'provider', 'kind']);
});
```

If multiple currencies/country-specific prices are configured, replace the last unique key with one that includes currency/market.

### 4.2 provider-neutral `subscriptions`

Add rather than immediately drop old Stripe columns:

```php
Schema::table('subscriptions', function (Blueprint $table) {
    $table->string('billing_provider', 32)->nullable()->index();
    $table->string('provider_customer_id')->nullable()->index();
    $table->string('provider_subscription_id')->nullable()->unique();
    $table->string('provider_price_id')->nullable()->index();
    $table->string('provider_status', 32)->nullable();
    $table->timestampTz('next_billed_at')->nullable();
    $table->string('scheduled_change_action', 32)->nullable();
    $table->timestampTz('scheduled_change_at')->nullable();
});
```

### 4.3 `billing_events`

```php
Schema::create('billing_events', function (Blueprint $table) {
    $table->id();
    $table->string('provider', 32);
    $table->string('provider_event_id')->unique();
    $table->string('provider_notification_id')->nullable()->index();
    $table->string('event_type')->index();
    $table->timestampTz('occurred_at')->nullable()->index();
    $table->string('payload_hash', 64)->nullable();
    $table->string('processing_status', 32)->default('pending')->index();
    $table->unsignedInteger('attempts')->default(0);
    $table->timestampTz('processed_at')->nullable();
    $table->text('last_error')->nullable();
    $table->timestamps();
});
```

### 4.4 `billing_transactions`

```php
Schema::create('billing_transactions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
    $table->string('provider', 32);
    $table->string('provider_transaction_id')->unique();
    $table->string('provider_subscription_id')->nullable()->index();
    $table->string('provider_price_id')->nullable()->index();
    $table->string('kind', 32); // subscription|lifetime|credits|adjustment
    $table->string('status', 32)->index();
    $table->string('currency', 3)->nullable();
    $table->bigInteger('subtotal_minor')->nullable();
    $table->bigInteger('tax_minor')->nullable();
    $table->bigInteger('total_minor')->nullable();
    $table->string('invoice_number')->nullable();
    $table->timestampTz('occurred_at')->nullable();
    $table->timestampTz('completed_at')->nullable();
    $table->jsonb('summary_json')->nullable();
    $table->timestamps();
});
```

### 4.5 `entitlement_grants`

```php
Schema::create('entitlement_grants', function (Blueprint $table) {
    $table->id();
    $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
    $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
    $table->string('source', 64);
    $table->string('source_reference')->nullable()->index();
    $table->jsonb('features_json')->nullable();
    $table->jsonb('limits_json')->nullable();
    $table->timestampTz('starts_at');
    $table->timestampTz('ends_at')->nullable();
    $table->timestampTz('revoked_at')->nullable();
    $table->timestamps();

    $table->unique(['source', 'source_reference']);
});
```

The unique source reference prevents the same completed LTD transaction from granting twice.

## 5. Paddle client service

Suggested file:

```text
app/Services/Billing/PaddleClient.php
```

Skeleton:

```php
<?php

namespace App\Services\Billing;

use Paddle\SDK\Client;
use Paddle\SDK\Environment;
use Paddle\SDK\Options;

final class PaddleClient
{
    public function client(): Client
    {
        $environment = config('billing.paddle.environment') === 'sandbox'
            ? Environment::SANDBOX
            : Environment::PRODUCTION;

        return new Client(
            apiKey: (string) config('billing.paddle.api_key'),
            options: new Options($environment),
        );
    }
}
```

Avoid creating a new client repeatedly in high-volume loops if the SDK/client is safe to resolve as a container singleton in your runtime model.

## 6. Billing application services

Recommended:

```text
app/Domain/Billing/
├── EntitlementResolver.php
├── PaddleBillingService.php
├── PaddleEventProcessor.php
├── PaddleTransactionProjector.php
├── PaddleSubscriptionProjector.php
├── BillingReconciler.php
└── CreditLedger.php
```

### Responsibilities

**PaddleBillingService**
- fetch/update subscription
- customer portal session/deep links
- plan changes
- cancellation

**PaddleEventProcessor**
- route verified events to projectors
- converge safely on current state

**PaddleSubscriptionProjector**
- map Paddle subscription to local subscription
- resolve plan from provider price ID
- trigger entitlement/license refresh

**PaddleTransactionProjector**
- store completed transaction
- fulfill LTD/credit pack
- avoid duplicate fulfillment

## 7. Plan mapping

Never determine plan by amount or product name.

Correct:

```text
Paddle price_id -> plan_prices row -> internal plan_id + kind
```

For every transaction/subscription item, resolve price IDs against active local mappings.

If an unknown live price arrives:

- do not grant a guessed plan
- log/alert as unmapped billing item
- keep event available for operator repair/replay

## 8. Checkout configuration endpoint

Example:

```text
POST /api/billing/checkout-config
```

Request:

```json
{
  "plan": "growth",
  "kind": "monthly"
}
```

Server:

1. authorize owner
2. validate active plan
3. verify allowed transition
4. get `plan_prices` Paddle mapping
5. return safe price/customer metadata

Response:

```json
{
  "provider": "paddle",
  "price_id": "pri_...",
  "client_token": null,
  "customer": {
    "email": "owner@example.com"
  },
  "custom_data": {
    "organization_id": 123,
    "purchase_kind": "subscription",
    "plan_slug": "growth"
  }
}
```

The client-side token can be delivered through a public environment variable or a small configuration endpoint. It is not a secret.

## 9. Next.js Paddle initialization

Install:

```bash
npm install @paddle/paddle-js
```

Create one initialization module and do not initialize Paddle independently in every button component.

Illustrative TypeScript:

```ts
import { initializePaddle } from '@paddle/paddle-js';

let paddlePromise: ReturnType<typeof initializePaddle> | null = null;

export function getPaddle() {
  if (!paddlePromise) {
    paddlePromise = initializePaddle({
      token: process.env.NEXT_PUBLIC_PADDLE_CLIENT_TOKEN!,
      environment: process.env.NEXT_PUBLIC_PADDLE_ENV === 'sandbox'
        ? 'sandbox'
        : undefined,
    });
  }
  return paddlePromise;
}
```

Verify the exact wrapper API for the installed package version during implementation.

Checkout:

```ts
const paddle = await getPaddle();

paddle?.Checkout.open({
  items: [{ priceId: config.price_id, quantity: 1 }],
  customer: { email: config.customer.email },
  customData: config.custom_data,
  settings: {
    displayMode: 'overlay',
    variant: 'one-page',
    theme: 'light',
  },
});
```

Do not grant access inside Paddle.js completion callback. Refresh local billing status while the webhook projects the purchase.

## 10. Webhook route

Route must be public and exempt from user authentication/CSRF mechanisms that would block Paddle.

```php
Route::post('/billing/webhook/paddle', PaddleWebhookController::class);
```

Rate limiting should not accidentally block legitimate Paddle retries. Signature verification + event uniqueness are primary controls.

## 11. Webhook verification controller

Use the official PHP SDK verifier if practical.

Conceptual flow:

```php
public function __invoke(Request $request)
{
    // 1. verify raw request with Paddle SDK verifier + webhook secret
    // 2. parse event only after verification
    // 3. insert event_id with UNIQUE constraint
    // 4. queue processing job
    // 5. return 200 quickly
}
```

Paddle documentation emphasizes preserving the raw body for signature verification. Do not call JSON serialization logic that changes whitespace/order before verify.

## 12. Event processing order

Webhooks can be duplicated and may be observed in close succession. The local projector must converge rather than depend on one perfect order.

### `subscription.created`

- resolve price -> plan
- upsert subscription
- store Paddle customer/subscription IDs
- map status/billing dates
- recompute entitlements

### `subscription.updated`

- fetch/project complete subscription fields from event or API when required
- update status/items/next billed date/scheduled change
- recompute entitlement if price/status changed

### `transaction.completed`

- upsert transaction
- inspect price mapping
- if `lifetime`: create permanent grant once
- if `credit_pack`: credit ledger once
- if recurring transaction: record financial history; subscription events remain authoritative for access

### `transaction.payment_failed`

- record transaction failure
- do not immediately invent cancellation state
- subscription event/reconciliation decides current subscription status
- trigger dunning/in-app warning according to product policy

## 13. Billing state endpoint

Return local projected state only; do not call Paddle on every page view.

```json
{
  "plan": {...},
  "subscription": {
    "provider": "paddle",
    "status": "active",
    "next_billed_at": "...",
    "scheduled_change": null
  },
  "usage": {...},
  "lifetime_grant": null
}
```

Provider API calls happen on explicit manage actions or reconciliation jobs.

## 14. Customer portal

Generate portal session/deep link server-side using the current Paddle API. Return the temporary URL to the authorized owner and redirect immediately.

Do not persist temporary management URLs.

## 15. Cancellation endpoint

```text
POST /api/billing/cancel
```

Request:

```json
{
  "effective_from": "next_billing_period"
}
```

Server validates owner + Paddle subscription and calls Paddle cancel operation.

Local UI should show "scheduled to cancel" only after provider response/local projection is updated. Final canceled state is confirmed by webhook/reconciliation.

## 16. Upgrade/downgrade

Endpoint:

```text
POST /api/billing/change-plan
```

Steps:

1. resolve current subscription
2. resolve target Paddle price
3. show/compute preview if implemented
4. call Paddle update subscription with full desired items
5. specify proration billing mode explicitly
6. return provider update state
7. webhook refreshes durable local state

Do not assume Stripe `proration_behavior` values map 1:1 to Paddle.

## 17. LTD purchase flow

Endpoint can simply return checkout config for one-time price, or create transaction server-side.

Required correlation:

```text
organization_id
plan_slug
purchase_kind=lifetime
```

Webhook fulfillment must independently map actual completed `price_id` to the authorized LTD plan.

Never trust only `plan_slug` custom data.

## 18. Credit ledger

Recommended table:

```text
credit_ledger
id
organization_id
amount_signed
reason
source_type
source_reference
balance_after optional
created_at
```

Use database transaction/locking when applying a purchase.

Unique source reference ensures `txn_...` cannot credit twice.

## 19. Reconciliation command

```text
php artisan insightistic:billing-reconcile
```

Options:

```text
--organization=
--provider=paddle
--recent-transactions=7
```

Daily schedule.

Reconciliation should:

- fetch current subscription for active Paddle orgs
- compare provider/local status, price, period, scheduled changes
- repair differences
- check recent completed one-time transactions that remain unfulfilled
- log all repairs

## 20. Go-live checklist

### Paddle dashboard

- Live account approved
- live domain approved for checkout
- live products/prices created
- live client token created
- live API key created with minimum permissions
- webhook destination created
- webhook secret stored
- required events subscribed
- customer portal tested

### Application

- live/sandbox environment switching verified
- no sandbox price IDs in production mapping
- webhook endpoint HTTPS
- signature failures return 400
- duplicate event test passes
- reconciliation scheduled
- alerts on unmapped price/event failure
- production checkout complete on low-risk internal transaction before public launch

## Official implementation references

- PHP SDK: `https://developer.paddle.com/sdks/libraries/php/`
- PHP quickstart: `https://developer.paddle.com/get-started/quickstart/php/`
- SDK list: `https://developer.paddle.com/sdks/`
