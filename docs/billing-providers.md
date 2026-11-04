# Billing providers: Paddle + Stripe (dual provider)

Status: Stripe is **test mode only** (`feat/stripe-billing`). Paddle remains the
production processor and the only one for credit packs. Reusable for Bridgistic:
every piece below is provider-agnostic except the two adapters.

## Model

| Layer | Source of truth |
|---|---|
| Which processor bills an org | `organizations.billing_provider` (`paddle` / `stripe` / null) |
| Provider customer | `organizations.paddle_customer_id`, `organizations.stripe_customer_id` |
| Subscription state | `subscriptions` row (`billing_provider`, `provider_*`, `status`, periods) projected **only from webhooks** |
| Price ↔ plan mapping | `plan_prices` (`provider`, `kind` monthly/yearly/lifetime/credit_pack, `provider_price_id`) |
| Entitlements | `EntitlementResolver` (plan + grants); lifetime grants via `EntitlementGrant::LIFETIME_SOURCES` (`paddle_lifetime`, `stripe_lifetime`) |
| Trials | **App-side `TrialService` only** (7 days, one per org). Stripe gets `trial_period_days` / `trial_end` only when TrialService says eligible or the org is mid-trial (≥48h left). Paddle prices must carry **no** trial (would double the trial). |
| LTD cap | `LtdCampaignService` reservation → `confirmSale()`; both providers |
| Invoices | `billing_transactions` (Stripe hosted invoice URL; Paddle invoices via its portal) |

Migration `2026_11_04_000002_add_billing_provider_to_organizations` adds the org
columns and backfills (Paddle `sub_`/`ctm_` ids → paddle, `stripe_subscription_id` → stripe).
`down()` reverses only rows it backfilled.

## Flows

- **Checkout** — `GET /billing/providers` lists processors (`BILLING_CHECKOUT_PROVIDERS`).
  Paddle: `POST /billing/checkout-config` → Paddle.js overlay. Stripe:
  `POST /billing/stripe/checkout {plan, kind}` → hosted Checkout Session URL
  (`automatic_tax`, billing address + tax id collection, metadata
  `organization_id`, `plan_slug`, `purchase_kind`, `ltd_reservation_id`).
  Credit packs on Stripe → 422 `provider_not_supported`.
- **LTD on Stripe** — `mode=payment`; a slot is reserved before the session,
  session `expires_at` 30 min (reservation 31 min), released on
  `checkout.session.expired` or error. Fulfilment on `checkout.session.completed`
  with `payment_status=paid` only.
- **Webhooks** (`/webhooks/stripe`, signature verified, idempotent on event id):
  `checkout.session.completed|expired`, `customer.subscription.*`,
  `invoice.paid`, `invoice.payment_failed` (past_due), `charge.refunded`
  (full refund revokes the lifetime grant; partial only marks the txn).
- **Portal** — `POST /billing/portal` picks the org's provider. Stripe uses
  portal configuration `STRIPE_PORTAL_CONFIGURATION`; Paddle mints a customer
  portal session (public Paddle portal link kept on the page for Paddle orgs).
- **Summary** — `GET /billing/summary`: provider, plan, status, next renewal,
  cancel-at-period-end, trial end, portal kind, invoices.
- **Admin** — subscriptions list shows provider + customer, `?provider=` filter.
  System Health shows per provider: configured, mode, API reachability (cached
  5 min), webhook secret set (never the value), last webhook and staleness (72h),
  offered at checkout.

## Safety rails

- `StripeService` refuses `sk_live_` keys unless `STRIPE_ALLOW_LIVE=true`.
- `StripeCatalogSeeder` picks `stripe_catalog.<mode>.json` by key mode, checks
  `livemode` of the file, and validates every amount against `config/commercial.php`
  before upserting `plan_prices`. There is no live catalog file yet.

## Test catalog (Stripe test mode, acct …lkDP)

See `insightistic-api/database/seeders/data/stripe_catalog.test.json`. Portal
configuration `bpc_1UXtaSBUynykllDPng6hlqJF`.

## Go-live checklist (not done)

1. Create the live catalog (same lookup keys) → `stripe_catalog.live.json`.
2. Live webhook endpoint + secret; live portal configuration.
3. Stripe Tax registrations / origin address (required by `automatic_tax`).
4. Set `STRIPE_ALLOW_LIVE=true`, run the seeder, verify System Health.

## Reusing for Bridgistic

Copy: the org columns + backfill migration, `plan_prices` provider mapping,
`StripeBillingService`, `StripeWebhookProcessor`, `BillingProviderHealth`, the
providers/summary endpoints and the catalog seeder. Keep the trial and cap
logic app-side so processors stay interchangeable.
