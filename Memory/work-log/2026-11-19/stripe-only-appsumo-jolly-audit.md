# Stripe-only commercial cleanup and AppSumo audit

**Date:** 2026-11-19 (Asia/Dhaka)

## Scope

- Used the canonical app project at `D:\All Projects\WPISTIC-GALAXY-ECOSYSTEM\INSIGHTISTIC`.
- Read the canonical `AGENTS.md`, `BUILD-STATUS.md`, `claude.md`, `README.md`, and `Memory/` files before making the status record.
- Preserved the dirty working tree and existing user changes. No reset, clean, merge, commit, push, deployment, or AppSumo resubmission was performed.

## Verified source changes

- Removed active Paddle billing configuration, routes, webhooks, client/classes, jobs, scheduled reconciliation, seeder call, Composer package entry, CSP/legal references, and the old hosted Paddle page.
- Kept Stripe checkout and webhook handling, plus AppSumo claim, OAuth, and webhook flows.
- Reduced the public catalog to Starter, Growth, Agency, Agency Pro, and AppSumo Tier 1–5. AppSumo tiers remain redemption-only lifetime entitlements; the inactive internal free-trial row is not public pricing.
- Repaired the AppSumo review paths for redirect-aware uptime and SSL checks, canonical signed report URLs/date formatting, JOLLY source assembly and formatted markdown, insight-type-specific fallbacks, entitlement/counter handling, lifetime billing display, crawler classification/deduplication/progress, and PageSpeed metric/link rendering.
- Added a bounded, SSRF-hardened JOLLY live-web evidence path that reads the connected site homepage, robots.txt, sitemap, and a small same-host page sample. It is read-only and evidence-first; it is not presented as an unrestricted browser agent.

## Verified live Stripe cleanup

- Deactivated the three legacy Founding Angel lifetime prices/products.
- Deactivated the old Insightistic Pro monthly/annual prices and product.
- Verified that the active Insightistic Stripe catalog contains only the eight current recurring plan prices and three current Insight Credits add-ons.
- Removed stale Paddle price metadata from active current Stripe prices.
- Retained historical Stripe objects as inactive records for auditability and safer rollback; no destructive deletion was used.

## Verification

- Frontend tests: 29 files, 171 tests passed.
- Next production build: passed; 69 routes generated.
- Relevant PHP syntax checks: passed.
- App and website `git diff --check`: passed.
- Targeted Laravel tests: blocked before assertions because local PostgreSQL test database `insightistic_test` was unavailable on `127.0.0.1:5432`.
- Composer lock content hash: pending regeneration because Composer was unavailable. No Paddle package remains in `composer.lock`; stale ignored vendor/generated metadata may remain until a clean install.

## Remaining release gates

- Deploy the canonical D-drive changes through the normal release process.
- Re-run authenticated production E2E checks for billing, AppSumo entitlement, reports, uptime/SSL, crawl progress/classification, PageSpeed, AI Insights, and JOLLY.
- Capture the production recordings required by the AppSumo review team, with the production URL and developer console visible and no related console errors.
- Run the Laravel suite with PostgreSQL available and regenerate the lock hash with Composer before packaging.
