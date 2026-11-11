# Launch, GTM & Lifetime Deal Plan

## Launch objective

Use a capped lifetime-deal campaign to acquire early paid users, generate case studies, stress-test the platform and seed recurring growth — without creating unbounded infrastructure obligations.

## Pre-LTD minimum product

Do not launch LTD until these are production-stable:

- onboarding
- WordPress connection
- GSC
- Cloudflare
- universal overview
- technical crawler MVP
- usage metering
- Paddle one-time checkout/webhooks
- permanent entitlements
- support workflow
- status/monitoring

Rank tracker/AI visibility can be included only if cost controls are proven.

## Offer design

Three founder tiers are easier to communicate than complex stacking.

Example:

| Tier | Price hypothesis | Sites | Primary buyer |
|---|---:|---:|---|
| Founder 1 | $79 | 3 | site owner/freelancer |
| Founder 2 | $149 | 10 | consultant/small agency |
| Founder 3 | $249 | 30 | agency |

Limits are defined in `24-PRICING-ENTITLEMENTS-CREDITS-LTD.md`.

## Scarcity

Close on first condition reached:

- 60 days
- 500 customers
- $50,000 gross LTD revenue

Communicate the cap before launch.

## Launch sequence

### Stage 1 — private paid beta

Target 25–50 users.

Sources:

- existing network/clients
- WordPress community
- agency contacts

Objectives:

- support burden
- onboarding friction
- usage/cost telemetry
- testimonials

### Stage 2 — direct Founder LTD

Channels:

- product website
- Product Hunt
- WordPress communities
- relevant Reddit communities according to community rules
- X/LinkedIn
- Indie Hackers
- email list

### Stage 3 — LTD marketplace evaluation

Only after direct proof:

- AppSumo
- Dealify/PitchGround/SaaS-focused marketplaces as commercially suitable

Evaluate:

- revenue share
- refund window
- support expectations
- exclusivity/promotion commitments
- buyer behavior

Do not list on multiple platforms simultaneously without operational capacity.

### Stage 3 decision — AppSumo Option A (founder-locked, 2026-11-07)

AppSumo gets an exclusive three-tier campaign; the direct Founding Angel offer
($149/$299/$699, campaign `founding-angel-2026`) stays unchanged as the
direct-from-site deal.

| Tier | Price | Sites | AI allowance (plan-based) |
|---|---:|---:|---|
| AppSumo Solo | $79 | 3 | Growth-level standard AI requests (200/mo) |
| AppSumo Studio | $149 | 10 | Agency-level standard AI requests (600/mo) |
| AppSumo Agency | $269 | 25 | Agency-level standard AI requests (600/mo) |

Locked constraints:

- site-count entitlements only (3/10/25) — the site limit is the deal axis
- NO AI credits for LTD buyers: `monthly_ai_credits` is 0 on all three plans;
  the AI allowance is the plan-based `standard_ai_requests` (same mechanism as
  Growth/Agency), never credit grants
- implemented as the `appsumo_2026` campaign (`config/commercial.php`),
  disabled by default behind `FEATURE_LTD_APPSUMO=false`; Paddle price IDs map
  from env (`PADDLE_PRICE_LTD_APPSUMO_*`) at launch
- catalog creation, seeding and go-live steps: `docs/LTD-APPSUMO-2026-LAUNCH.md`

## Case studies

Build stories around outcomes:

- recovered search traffic
- identified high-impression technical issue
- reduced manual agency reporting
- caught outage/performance regression
- connected search opportunity to WooCommerce revenue

Avoid promising guaranteed traffic increases.

## Conversion after LTD closes

LTD remains grandfathered under purchased limits.

New users receive:

- monthly
- annual
- agency plans

Upsell LTD customers through legitimate recurring value:

- extra credits
- extra sites beyond LTD limit
- optional advanced data packs

Do not retroactively remove promised core LTD features to force subscription.

## Success metrics

- paid activation rate
- refund rate
- first-value time
- support tickets/customer
- cost/customer/month
- sites/customer
- connected sources/site
- weekly active organizations
- credit-pack attach rate
