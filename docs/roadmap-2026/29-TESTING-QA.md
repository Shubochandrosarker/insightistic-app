# Testing & QA Strategy

## Test pyramid

### Unit tests

Target:

- score calculations
- entitlement resolver
- provider normalizers
- issue rules
- usage meter
- webhook signature/parser wrappers
- commerce calculations
- opportunity algorithms

### Integration tests

Target:

- database tenancy
- API authorization
- connector HMAC
- ingestion idempotency
- Paddle event projection
- provider token refresh
- queue jobs

### Frontend tests

Target:

- loading/empty/error states
- entitlement locks
- table filters
- checkout launch configuration
- integration connect states

### E2E

Critical paths:

1. signup
2. add site
3. activate WP plugin
4. first sync
5. connect GSC
6. initial crawl
7. view overview
8. buy recurring plan with Paddle Sandbox
9. webhook grants access
10. run paid feature

## Paddle QA matrix

- sandbox/live config separation
- recurring monthly purchase
- yearly purchase
- duplicate event
- out-of-order event
- payment failure/past due
- cancel at period end
- cancellation effective
- plan change
- customer portal link
- one-time LTD
- credit pack
- refund/reversal policy
- invalid signature
- reconciliation repair

Use Paddle webhook simulator for lifecycle tests where possible.

## Connector QA

- timestamp skew
- nonce replay
- wrong signature
- revoked credential
- partial sync failure
- duplicate batch
- large store pagination
- WooCommerce inactive
- multisite

## Crawler QA

Fixtures/sites for:

- redirect loop
- robots block
- noindex
- canonical loop
- duplicate metadata
- broken links
- large sitemap
- parameter trap
- slow response
- compressed content
- malformed HTML

## Security tests

- cross-tenant ID access
- role violations
- SSRF/private IP
- webhook forged signature
- stored XSS in provider labels/site names
- CSRF/nonce on WP actions
- upload validation

## Release gates

### SaaS

- PHP tests
- TypeScript build
- frontend tests
- migration dry run
- lint/static checks
- Docker image build
- secret scan

### Plugin

Retain current release-validation philosophy:

- version consistency
- PHP lint
- tests
- asset freshness
- zip validation
- multisite/fresh install/upgrade checks

## Staging acceptance

Before production release:

- real test WordPress site
- test WooCommerce data
- live-ish GSC/GA4 test property if available
- Cloudflare test zone
- Paddle Sandbox full flow
