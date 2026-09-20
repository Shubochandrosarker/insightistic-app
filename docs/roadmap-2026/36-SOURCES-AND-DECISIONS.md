# Sources, Assumptions & Locked Decisions

## Repository sources reviewed

### SaaS

- `Shubochandrosarker/insightistic-app`
- README / build status / architecture / data-source documentation
- dashboard navigation/overview/integrations code
- PlanSeeder
- BillingController
- StripeService
- StripeWebhookProcessor
- package manifests

### WordPress plugin

- `Wordpressistic/insightistic`
- plugin readme/release metadata
- license manager
- feature gate
- SaaS client
- sync manager
- GSC / Cloudflare integration code observed during audit

## Audit-plan source

The initial project direction came from the repository + market audit prepared before this documentation set, including the recommendation to position Insightistic as a WordPress-first Search + AI Visibility + Performance Intelligence platform.

## Paddle official references used

- `https://developer.paddle.com/`
- `https://developer.paddle.com/api-reference/about/authentication/`
- `https://developer.paddle.com/api-reference/about/permissions/`
- `https://developer.paddle.com/paddle-js/about/`
- `https://developer.paddle.com/paddle-js/methods/paddle-checkout-open/`
- `https://developer.paddle.com/build/transactions/create-transaction/`
- `https://developer.paddle.com/webhooks/`
- `https://developer.paddle.com/webhooks/about/signature-verification/`
- `https://developer.paddle.com/webhooks/about/respond-to-webhooks/`
- `https://developer.paddle.com/build/subscriptions/provision-access-webhooks/`
- `https://developer.paddle.com/build/subscriptions/cancel-subscriptions/`
- `https://developer.paddle.com/build/subscriptions/pause-subscriptions/`
- `https://developer.paddle.com/concepts/sell/customer-portal/`
- `https://developer.paddle.com/sdks/sandbox/`
- `https://developer.paddle.com/get-started/how-paddle-works/`

## Other official references from the market/technical audit

- Google Search Console API documentation
- Microsoft Bing Webmaster API documentation
- Cloudflare GraphQL Analytics documentation

These should be rechecked immediately before implementation of provider-specific calls because external APIs change.

## Locked product decisions in this documentation

### Positioning

Build a WordPress-first intelligence platform, not a general Ahrefs/Semrush replacement.

### Billing

Target Paddle Billing. Stripe becomes transitional legacy for existing subscribers if any.

### Entitlements

Laravel is the single source of truth. Plugin consumes entitlements.

### LTD

Lifetime plans have fixed recurring resource allowances. No unlimited AI/rank/crawl promises.

### Crawler

Focused first-party technical crawler; no attempt to crawl/index the global web.

### Rank data

Use a provider abstraction instead of self-hosted mass SERP scraping.

### AI

Evidence-grounded insights/Copilot; AI Visibility is a separate observation product.

## Assumptions requiring validation

- final Paddle account approval and available payment methods for the business
- final recurring/LTD pricing
- actual infrastructure cost per crawl page/rank check/AI prompt
- selected SERP provider
- exact AI visibility surfaces/APIs available at launch
- actual active Stripe subscriber migration requirements

## Change-control rule

When a locked decision changes, update:

1. this file
2. the relevant architecture document
3. implementation phase plan
4. acceptance criteria if affected

Do not let code silently contradict the documentation again.
