# Billing providers: Paddle + Stripe (dual provider)

Status: Stripe is **test mode only** (`feat/stripe-billing`). Paddle remains the
production processor; both processors can sell credit packs (Paddle via the
overlay, Stripe via `POST /billing/credits/checkout`). Reusable for Bridgistic:
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
  Credit packs on that endpoint → 422 `provider_not_supported`; packs have
  their own one-time endpoint, below.
- **Credit packs on Stripe** — `POST /billing/credits/checkout {pack}`
  (`credits_5000|credits_20000|credits_50000` from `config/commercial.php`)
  → `mode=payment` Checkout Session, metadata `organization_id`, `pack`,
  `credits`. Price: `STRIPE_PRICE_CREDITS_*` when set, else inline
  `price_data` from `price_minor` (no Stripe catalogue needed). Fulfilment on
  `checkout.session.completed` with `payment_status=paid` **and**
  `checkout.session.async_payment_succeeded`, granted via `CreditWallet` with
  reason/source `stripe_credit_pack` keyed on the session id — a replayed
  session credits exactly once. `GET /billing/credits/balance` serves the
  per-bucket balances, the plan's monthly allowance and the 20% / 5% / zero
  warning thresholds.
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


## Trial alignment (settled 2026-11-11: 7 days, no card)
- **One value:** `INSIGHTISTIC_TRIAL_DAYS` → `commercial.trial_days` (default 7).
- **Where it's used:**
  - TrialService (start-trial from Billing)
  - Stripe `subscription_data.trial_period_days` (only for eligible or mid-trial orgs)
  - `/public/plans.trial_days`
  - the `/billing` payload
  - the trial-started email heading (the org's real trial length)
  - Admin → System Health → `trial`
- **No card:** the regular-plan trial starts in the app without checkout. Stripe gets a trial period only if the customer chooses to subscribe early.
- **Paddle:** no Insightistic Paddle price has a `trial_period` (checked 2026-11-11), so Paddle can't add its own trial.
- **Site / app copy:** `insightistic-app/lib/trial.ts` (`NEXT_PUBLIC_TRIAL_DAYS`, default 7). It's used by the pricing cards, register and the auth stats. The billing page prefers the API value. insightistic.com already says 7 days everywhere.
- **Prod clean-up at release:** delete `INSIGHTISTIC_SIGNUP_TRIAL_DAYS=14` from `.env.ai`. Nothing reads it, and the live value is already `INSIGHTISTIC_TRIAL_DAYS=7`.
