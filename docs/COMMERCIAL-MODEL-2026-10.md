# Insightistic commercial model (2026-10)

This document is the release reference for the pricing, wallet, Founding Angel
and Paddle changes implemented in the application. It does not authorize a
production application deployment or a production database migration.

## Pricing registry

The authoritative application catalog is insightistic-api/config/commercial.php,
seeded idempotently by PlanSeeder. The database keeps historical Founder plan
rows and Paddle price mappings for replay and entitlement compatibility; those
rows are inactive and are not new offers.

| Tier | Monthly | Annual | Sites | Seats | Standard AI/mo | Monthly credits | Reports/mo |
|---|---:|---:|---:|---:|---:|---:|---:|
| Free Connected | $0 | $0 | 1 | 1 | 10 basic credit-funded insights | 1,000 | 0 |
| Starter | $29 | $290 | 1 | 1 | 50 | 5,000 | 10 |
| Growth | $69 | $690 | 3 | 3 | 200 | 15,000 | 40 |
| Agency | $149 | $1,490 | 10 | 10 | 600 | 40,000 | 150 |
| Agency Pro | $299 | $2,990 | 30 | 25 | 1,500 | 100,000 | 500 |
| Angel Solo | $149 one-time | — | 3 | 1 | 25 | 3,000 | 5 |
| Angel Studio | $299 one-time | — | 7 | 3 | 100 | 8,000 | 25 |
| Angel Agency | $699 one-time | — | 15 | 10 | 250 | 20,000 | 100 |

Free Connected keeps the visible ten-insight promise but charges each basic
insight at 100 credits from its 1,000 monthly wallet. Paid and Angel eligible
standard actions use the standard-request allowance first and do not also debit
credits.

## AI action tariffs

The server owns this table and never accepts a client-supplied debit amount:

| Action | Credits |
|---|---:|
| Basic WordPress dashboard insight | 100 |
| SEO opportunity analysis | 200 |
| WooCommerce business analysis | 300 |
| Advanced multi-source AI report | 500 |
| Comprehensive AI business audit | 1,000 |

Interactive insights, the WordPress cloud-AI endpoint, proactive insight
generation and premium AI report summaries now preflight the effective
entitlement and wallet, then settle one idempotent charge after a successful
operation. Report-count usage is recorded separately, including scheduled
reports. Starter/Angel Solo report builds omit the premium AI summary because
their published feature family does not include advanced reports. A failed
provider response does not charge. Existing legacy plans without the new wallet
limits retain the previous usage-counter behavior until their catalog migration
is applied.

## Wallet rules

The additive commercial-wallet migration adds bucket, period, expiry and
metadata fields to the append-only credit ledger. Monthly included credits are
UTC-calendar-month grants and expire at the next month boundary. Purchased
credits are kept in a separate non-expiring bucket. Monthly grants and
operation deductions are serialized on the organization row and keyed by
business references so retries cannot double grant or double charge.

The billing subscription and dedicated billing/credits endpoints expose monthly
remaining, purchased remaining, total usable, next reset and transaction data.
The WordPress license entitlement payload includes the effective limits and
wallet summary without exposing provider secrets.

## Founding Angel inventory

The founding-angel-2026 campaign is backed by ltd_campaigns and
ltd_checkout_reservations. Checkout configuration reserves inventory before
Paddle checkout opens. Reservation expiry, row locking, idempotency and verified
transaction fulfillment prevent successful sales plus active reservations from
exceeding 100.

Historical completed lifetime transactions and active paddle_lifetime grants are
reconciled into the campaign counter. The union of provider transaction
references and grant references prevents duplicate counting. A completed Angel
webhook without a local reservation is held for manual billing reconciliation
and does not receive an entitlement.

Once the cap is reached, the campaign closes and new reservations fail. Refund
or chargeback events revoke the lifetime grant. Credit-pack refunds reverse
available purchased balance without allowing a negative wallet and record the
consumed-before-refund amount for operator review.

## Paddle configuration

Existing provider price identifiers remain in PaddleCatalogSeeder. New Angel
price identifiers are deployment-specific and must be supplied through:

- PADDLE_PRICE_ANGEL_SOLO
- PADDLE_PRICE_ANGEL_STUDIO
- PADDLE_PRICE_ANGEL_AGENCY

No new provider identifiers are invented in source. The three values must be
created and verified in the intended Paddle environment before enabling the
corresponding new checkout prices. The Docker examples and Compose services
forward these variables to the API.

The customer-facing Paddle default payment link is `https://pay.insightistic.com`.
Insightistic opens Paddle Checkout as an overlay popup using the selected plan's
API-issued `price_id`; the frontend never composes a price or redirects buyers
to a different payment host. The success redirect returns to the app only for
post-checkout status display. Entitlement, subscription, lifetime access and
credit fulfillment remain webhook-driven.

The active Insightistic Paddle notification destination must subscribe to
`transaction.completed`, subscription lifecycle events, customer events, and
`adjustment.created` plus `adjustment.updated` for refund/chargeback projection.

## Live Paddle catalog verification (2026-10-14)

The live catalog was reconciled against the application registry using the
authorized vendor credential. The new Founding Angel product is active with
these one-time prices:

| Plan | Live price ID | Amount | Sites | Monthly credits |
|---|---|---:|---:|---:|
| Angel Solo | `pri_01m4wn26nhwwr3xpn4tdgfdss3` | $149 | 3 | 3,000 |
| Angel Studio | `pri_01m4wn2791zs00kbhw2w3w36ma` | $299 | 7 | 8,000 |
| Angel Agency | `pri_01m4wn27vn7hrbfxbhk336nnw9` | $699 | 15 | 20,000 |

The approved checkout domain is `pay.insightistic.com`, with Apple Pay
verified. The active webhook remains pointed at the Insightistic API and now
contains both adjustment events. These identifiers are safe catalog metadata;
the Paddle API credential is never stored in the repository. The API runtime
still needs the three `PADDLE_PRICE_ANGEL_*` variables populated from the
deployment secret store before Angel checkout is enabled in that environment.

## Deployment gates

Before production:

1. Back up the database and the current Compose/environment configuration.
2. Apply the additive migration and verify rollback in a non-production copy.
3. Seed plans and the historical catalog; inspect existing subscriptions,
   lifetime grants and purchased credits.
4. Create and verify the three Angel prices and credit-pack price mappings in
   Paddle; set the environment variables without committing secrets.
5. Deploy API and app, clear only the intended application caches, and confirm
   the catalog version.
6. Verify signed webhook delivery, replay behavior, late-checkout manual
   review, refund projection and sold-out behavior in Paddle sandbox first.
7. Run authenticated smoke tests for regular checkout, Angel checkout, credit
   purchase, subscription payload, wallet balance and WordPress entitlement
   sync.
8. Monitor failed billing events, adjustment events, reservation expiry,
   wallet deductions and provider latency.

Rollback is application-version rollback plus the documented database backup
restore procedure. Do not delete the new tables or rewrite historical price
mappings as an emergency response.
