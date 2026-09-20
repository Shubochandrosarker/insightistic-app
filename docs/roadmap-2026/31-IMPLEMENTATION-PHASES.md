# Detailed Implementation Phases

## Execution principle

Do not build all 20 product updates simultaneously. Each phase must leave the product deployable.

---

## Phase 0 — Documentation & baseline

### Objectives

- freeze current state
- run full tests/builds
- inventory production schema
- inventory active customers and billing
- create feature flags

### Tasks

- archive old contradictory docs as historical
- add this documentation set to `/docs/roadmap-2026/` or equivalent
- run Laravel test suite
- run Next.js build/tests
- run plugin release checks
- capture DB schema
- capture current Stripe customer/subscription counts

### Exit criteria

No unknown production dependency blocks billing/UI refactor.

---

## Phase 1 — Provider-neutral billing + Paddle foundation

### Objectives

Build Paddle without breaking Stripe customers.

### Backend

- `plan_prices`
- provider-neutral subscriptions
- `billing_events`
- `billing_transactions`
- `entitlement_grants`
- `PaddleBillingService`
- Paddle webhook verifier/processor
- billing reconciliation job

### Frontend

- Paddle.js initialization
- new billing page states
- checkout launch
- processing state after checkout

### Admin

- billing provider visibility
- replay/reconcile tools

### Exit criteria

Sandbox recurring purchase and one-time LTD purchase both provision correctly through webhooks.

---

## Phase 2 — Entitlement unification

### Objectives

Make Laravel the single feature/limit authority.

### Tasks

- define final feature slug vocabulary
- update plan/limit resolver
- plugin consumes returned features/limits
- remove commercial assumptions from WP feature gate
- usage service expanded for crawl/rank/AI visibility

### Exit criteria

Same account returns identical entitlement decisions in SaaS API and plugin.

---

## Phase 3 — New app shell and design system

### Objectives

Reorganize the existing product into the target information architecture.

### Tasks

- grouped sidebar
- project selector
- global date range
- consistent PageHeader
- robust DataTable
- universal state components
- new Overview skeleton

### Exit criteria

All existing pages still reachable; no regression in auth/site context.

---

## Phase 4 — Universal Overview

### Tasks

- health score service
- search KPI block
- crawl summary placeholder/data
- AI visibility placeholder/data
- performance
- uptime
- Cloudflare summary
- conditional commerce section
- priority actions

### Exit criteria

A non-Woo site receives a useful dashboard.

---

## Phase 5 — Search Intelligence expansion

### Tasks

- GSC deeper storage/backfill
- queries/pages explorers
- winners/losers
- striking distance
- CTR opportunities
- content decay
- cannibalization candidates
- filters/export

### Exit criteria

Search Intelligence is usable as a standalone GSC analysis product.

---

## Phase 6 — Bing Webmaster + IndexNow

### Tasks

- connection
- site mapping
- scheduled sync
- normalized search metrics
- Bing screens/source filters
- IndexNow key/submission flow
- WordPress publish hooks

### Exit criteria

Bing data refreshes daily and IndexNow submission is logged/retry-safe.

---

## Phase 7 — Technical crawler MVP

### Tasks

- crawl run tables
- URL frontier
- sitemap discovery
- HTML parser
- page/link/image checks
- issue engine
- run history
- Pages/Links/Images/Issues UI

### Exit criteria

Crawler handles 10k-page test site within configured budget without runaway loops.

---

## Phase 8 — Crawl intelligence + change monitoring

### Tasks

- issue lifecycle new/fixed/ignored
- change fingerprints
- GSC-impact weighting
- health score integration
- critical change alerts

### Exit criteria

Issues are prioritized by impact, not only raw count.

---

## Phase 9 — Cloudflare flagship module

### Tasks

- dedicated performance/edge screens
- cache/security breakdown
- status/TLS/country
- bot intelligence where available
- AI crawler classification
- traffic-gap explanation

### Exit criteria

Cloudflare becomes a meaningful standalone value reason to connect the integration.

---

## Phase 10 — Rank Tracker

### Tasks

- provider selection/contract
- keyword CRUD
- usage meter
- scheduled checks
- history
- alerts
- GSC keyword suggestions

### Exit criteria

Cost per active keyword is measurable and plan enforcement is reliable.

---

## Phase 11 — AI Visibility

### Tasks

- prompt management
- provider adapters
- scheduled runs
- mentions/citations/competitors
- visibility metrics
- quota/credits

### Exit criteria

Metric methodology is documented and repeatable over stored observations.

---

## Phase 12 — Performance & uptime

### Tasks

- historical PageSpeed
- CWV regression engine
- uptime monitors/incidents
- SSL/DNS checks
- alerts

### Exit criteria

One failed probe does not create false outage alert; history is visible.

---

## Phase 13 — Agency/reporting expansion

### Tasks

- portfolio dashboard
- client grouping
- report composer/templates
- share links
- white label
- scheduled reports

### Exit criteria

Agency can manage 30+ sites without entering each project daily.

---

## Phase 14 — Automation Studio

### Tasks

- trigger/action model
- rule UI
- execution log
- webhook/email/report actions
- limits

### Exit criteria

Rules are idempotent/rate-limited and auditable.

---

## Phase 15 — LTD launch hardening

### Tasks

- final Paddle catalog
- LTD one-time checkout
- permanent grants
- credit packs
- usage dashboard
- terms/copy
- onboarding
- support/runbooks
- load/cost tests

### Exit criteria

A Tier 1/2/3 purchase provisions automatically and can never accidentally gain unlimited metered usage.
