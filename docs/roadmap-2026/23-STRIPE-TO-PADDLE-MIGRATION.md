# Stripe-to-Paddle Migration Plan

## Current state

The SaaS currently contains Stripe-specific billing code and data:

- `StripeService`
- `StripeWebhookProcessor`
- Stripe Checkout
- Stripe customer portal
- Stripe subscription IDs/customer IDs
- Stripe price IDs on plans
- Stripe webhook event processing
- `stripe/stripe-php` dependency

## Migration objective

Move all new sales and lifecycle management to Paddle while preserving existing customer access and avoiding sudden billing-state changes.

## Critical product decision

Choose one migration policy before coding production migration:

### Option A — Paddle for all new customers; grandfather Stripe subscriptions

Lowest risk.

- existing Stripe subscriptions continue until manually migrated/canceled
- new checkout uses Paddle
- entitlement resolver supports both providers temporarily

### Option B — active migration of existing subscribers

Requires customer communication and provider-specific migration workflows. Do not assume payment methods can simply be moved automatically.

**Recommended:** Option A first, then selectively migrate willing subscribers.

## Phase 1 — provider-neutral schema

Before removing Stripe, add:

- `plan_prices`
- provider-neutral subscription columns
- `billing_events`
- `billing_transactions`
- `entitlement_grants`

Backfill existing Stripe rows:

```text
billing_provider = stripe
provider_customer_id = stripe_customer_id
provider_subscription_id = stripe_subscription_id
```

Keep legacy columns during transition.

## Phase 2 — billing interfaces

Introduce:

```php
interface BillingProvider
{
    public function getSubscription(...);
    public function cancel(...);
    public function createPortalSession(...);
    public function changePlan(...);
}
```

Do not over-generalize checkout UI if provider flows differ. The interface should protect entitlement/business logic, not force identical provider semantics.

Implement `PaddleBillingService`.

## Phase 3 — Paddle catalog mapping

Create products/prices in Sandbox and Live.

Populate `plan_prices` for:

- Starter monthly/yearly
- Growth monthly/yearly
- Agency monthly/yearly
- Agency Pro monthly/yearly if launched
- LTD tiers
- credit packs

Do not hardcode Paddle price IDs in React components.

## Phase 4 — Paddle frontend

Replace `/billing/checkout -> Stripe URL` UI with Paddle.js checkout launch.

Backend still validates requested plan and returns the allowed price mapping.

## Phase 5 — Paddle webhooks

Create new endpoint:

```text
POST /api/billing/webhook/paddle
```

Keep Stripe webhook endpoint active while any Stripe subscription remains.

Both project into the same normalized local billing model and entitlement resolver.

## Phase 6 — cancellation semantics

Current Stripe code supports toggling `cancel_at_period_end` and reactivating it.

Paddle cancellation semantics differ. Refactor UI/business wording away from Stripe-specific assumptions:

```text
scheduled_change_action
scheduled_change_at
```

rather than only:

```text
cancel_at_period_end boolean
```

If a Paddle cancellation takes effect, treat it as canceled and require a new checkout to return.

## Phase 7 — remove Stripe from new sales

Feature flag:

```dotenv
BILLING_PROVIDER=paddle
```

New organizations see Paddle only.

Existing Stripe orgs can still open legacy Stripe management until migrated.

## Phase 8 — customer migration campaign

For remaining Stripe customers:

1. communicate benefits/required action
2. offer migration discount if needed
3. create Paddle checkout for equivalent plan
4. after Paddle subscription confirmed, schedule Stripe cancellation
5. ensure no double billing
6. verify entitlement continuity

Never cancel Stripe before Paddle payment/subscription is confirmed.

## Phase 9 — Stripe retirement

Only after no active Stripe subscriptions remain:

- remove Stripe checkout routes
- remove portal routes
- archive/remove Stripe secrets
- remove Stripe webhook destination
- remove `stripe/stripe-php`
- remove provider-specific legacy columns after safe migration window

Keep historical transaction references if needed for records/support.

## Data migration example

Old:

```text
stripe_customer_id
stripe_subscription_id
stripe_price_id_monthly
stripe_price_id_yearly
```

New:

```text
subscriptions.provider = paddle|stripe
subscriptions.provider_customer_id
subscriptions.provider_subscription_id
plan_prices.provider
plan_prices.provider_price_id
```

## Test matrix

| Scenario | Expected |
|---|---|
| existing Stripe org logs in | current access unchanged |
| new org upgrades | Paddle checkout only |
| Paddle webhook delayed | no premature access; UI shows processing |
| duplicate Paddle webhook | one projection |
| Stripe org migrates | no access gap |
| Stripe and Paddle both active accidentally | alert/admin reconciliation |
| canceled Paddle customer returns | new checkout |
