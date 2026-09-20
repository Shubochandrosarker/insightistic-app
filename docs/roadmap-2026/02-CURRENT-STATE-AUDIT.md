# Current-State Audit

## Snapshot

This document describes the repository state reviewed on 2026-09-20. It is not the target architecture.

## SaaS repository

Repository: `Shubochandrosarker/insightistic-app`

### Confirmed foundation

- Laravel 12 API
- PHP 8.3+ / production platform configuration targeting PHP 8.4
- Next.js frontend (current package manifest uses Next 16.x and React 19.x)
- PostgreSQL-oriented backend
- Redis for queues/cache/limits in documented architecture
- Sanctum authentication
- social login support
- MFA/security work
- multi-tenant organization/site structure
- roles and site access controls
- Stripe billing implementation
- plans and feature/limit JSON
- licenses and WordPress connector activation
- HMAC connector security
- WooCommerce sync and analytics
- GA4 direct integration
- Google Search Console direct integration
- Cloudflare direct integration
- PageSpeed support
- reports
- alerts
- AI insights
- Copilot / knowledge capabilities
- white-label configuration
- admin/observability work
- Docker and CI/deployment documentation

### Current plan seed state

The current `PlanSeeder` includes:

- Free
- Starter
- Growth
- Business (inactive)
- Agency
- Agency Pro (inactive)

It currently stores prices, feature slugs, site/user/AI/report limits and knowledge/Copilot limits.

The existing public paid prices are currently much lower than the proposed future positioning and should not be treated as the final commercial model.

## Existing billing state

The SaaS currently uses Stripe-specific implementation:

- `StripeService`
- `StripeWebhookProcessor`
- Stripe customer/subscription IDs
- Stripe Checkout
- Stripe portal
- cancel-at-period-end
- reactivation
- webhook verification
- Stripe event idempotency
- Stripe PHP SDK dependency

**Target decision:** replace this with Paddle Billing. See `22-PADDLE-BILLING.md` and `23-STRIPE-TO-PADDLE-MIGRATION.md`.

## WordPress plugin repository

Repository: `Wordpressistic/insightistic`

Current plugin release metadata observed: `4.4.2`.

### Confirmed capabilities

- GA4 analytics
- Google Search Console
- PageSpeed
- Cloudflare Traffic Insights
- WooCommerce intelligence
- engagement tracking
- 404/broken-link monitoring
- AI provider support
- Insightistic Cloud AI path
- email automations
- account/license connection
- encrypted connector secret
- HMAC SaaS requests
- entitlement cache and offline grace
- chunked data sync
- test/release validation scripts

### Strong engineering decisions already present

- raw license key is exchanged and not permanently stored
- connector secret is encrypted at rest
- request signatures use a canonical HMAC string
- nonce + timestamp replay defenses exist on connector flow
- customer email is hashed before SaaS sync in the WooCommerce mapper
- sync is chunked rather than loading all orders/products into memory
- plugin has release/asset validation tooling

## Major gaps and contradictions

### 1. Product information architecture is too flat

The SaaS dashboard currently exposes many top-level destinations. The product needs grouped navigation and module hierarchy.

### 2. Overview is too commerce-heavy

Current overview emphasizes revenue/orders. A universal search/health/visibility overview is needed so non-WooCommerce sites see value immediately.

### 3. No confirmed Bing Webmaster integration

Neither reviewed repository showed a production Bing Webmaster implementation. This is a P0 integration gap.

### 4. No mature SaaS crawler

The product has broken-link/site-health pieces, but not the full crawl system required for pages, links, images, metadata, canonical/indexability, issue history and change monitoring.

### 5. No complete rank-tracking subsystem

A dedicated keyword/location/device SERP tracking architecture needs to be introduced with strict usage metering.

### 6. AI Visibility is not yet the complete first-class product envisioned

The existing AI functionality is mainly insights/Copilot. Prompt-level monitoring, provider observations, citations, share of voice and competitor tracking require a distinct data model.

### 7. Plugin commercial messaging conflicts with SaaS entitlements

The plugin's current feature gate describes the plugin as fully free apart from cloud-dependent flows, while the SaaS has paid plans and entitlement infrastructure.

**Resolution:** Laravel plan entitlements become the single source of truth. Plugin features consume server-provided feature slugs and limits.

### 8. Plugin front-end architecture will become difficult to scale

The plugin still has a large centralized `admin.js` build. New modules should be separated at source level and bundled into page-specific or feature-specific assets.

### 9. Sync cadence needs improvement

Daily reconciliation is useful but insufficient for near-real-time commerce intelligence. Event-driven incremental sync should complement scheduled reconciliation.

### 10. Historical documentation drift

The SaaS repo contains older audit/workflow documents whose statements have been superseded by later implementation. New documentation must label current-state and target-state clearly and avoid treating historical plans as present facts.

## High-level readiness assessment

| Area | State | Next action |
|---|---|---|
| SaaS core | Strong foundation | reorganize and harden |
| WordPress connector | Strong foundation | modularize + entitlement alignment |
| GSC | functional foundation | deeper dimensions/history/opportunity engine |
| GA4 | functional | integrate into unified traffic model |
| Cloudflare | strong early differentiator | build flagship edge/bot intelligence UI |
| Bing | missing | implement REST + IndexNow |
| Technical crawler | insufficient | build new crawler service |
| Rank tracker | insufficient | build metered provider abstraction |
| AI visibility | partial | build dedicated subsystem |
| Agency reporting | existing foundation | extend portfolio/client UX |
| Billing | Stripe-specific | migrate to Paddle |
| LTD support | not complete | add one-time plan entitlements + credits |
