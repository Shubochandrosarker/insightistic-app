# Insightistic current status

**Last reviewed:** 2026-11-19 (Asia/Dhaka)  
**Status confidence:** high for the canonical source review, Stripe catalog cleanup, local frontend/build checks, and non-mutating live checks; the current uncommitted changes are not yet deployed, and authenticated credit charging, PDF download, and signed provider-event replay remain intentionally unperformed in production.

## Repository and organization

- **Verified:** The complete former local repository was moved into `D:\All Projects\WPISTIC-GALAXY-ECOSYSTEM\INSIGHTISTIC`.
- **Verified:** `.git`, frontend, Laravel API, Docker, Nginx, plugin, docs, temporary/project artifacts, modified files, and untracked files were preserved.
- **Verified:** Git still reports branch `main` behind `origin/main` by 44 commits and preserves the pre-migration dirty state. Do not reset, clean, merge, or push it without explicit approval.
- **Verified:** Existing destination material was preserved under `_archive/pre-migration-destination-20261117`.
- **Pending:** The old path is empty but Windows still holds the final directory because the Codex process started with that path as its working directory. The old project contents are gone; remove the empty directory after the Codex process releases the handle, without deleting any other path.

## Canonical Stripe-only commercial state

- **Verified on 2026-11-19:** The canonical D-drive `AGENTS.md`, `BUILD-STATUS.md`, `claude.md`, `README.md`, and `Memory/` were read before this status update. The D-drive checkout is the active Insightistic app source; the old `C:\Users\Qbits\insightistic-app` checkout was not used for the fixes.
- **Verified:** Paddle was not configured as the current payment gateway. The earlier statement that Paddle had been set up was incorrect and is superseded by this record.
- **Verified in active source/runtime:** Billing, routes, webhooks, feature flags, seeders, composer dependencies, CSP, legal copy, and app/website checkout surfaces are Stripe-only. Paddle billing classes, jobs, controllers, client code, scheduled reconciliation, and the old hosted Paddle page were removed from the active source tree.
- **Verified:** The public commercial catalog is the four recurring website plans (Starter, Growth, Agency, Agency Pro) plus AppSumo Tier 1–5 lifetime entitlements. AppSumo tiers are redemption entitlements, not Stripe checkout products. The inactive internal `free` trial row remains only for registration/trial compatibility and is not a public offer or active Stripe product.
- **Verified live on 2026-11-19:** Legacy Founding Angel prices/products and the old Insightistic Pro prices/product were deactivated in Stripe. The active Insightistic Stripe catalog now contains only the eight current recurring prices and three current Insight Credits add-ons; active current prices no longer carry stale Paddle metadata. Historical Stripe objects were retained as inactive records rather than destructively deleted.
- **Pending:** The local generated `vendor/` Paddle package files and generated Composer metadata can remain as stale ignored artifacts from the pre-cleanup install; they are not active dependencies and will disappear on a clean install. `composer.lock` is valid JSON and contains no Paddle package, but its content hash still needs regeneration with Composer before release packaging.
- **Pending:** Deploy the canonical changes, then run production login, billing, AppSumo entitlement, report download, uptime/SSL, crawler, PageSpeed, AI Insights, and JOLLY E2E checks with the required production recording evidence. No AppSumo resubmission has been made.

## Application architecture

- **Verified from repository documentation:** Next.js frontend in `insightistic-app/` communicates with Laravel API in `insightistic-api/`, normally through same-origin proxy mode.
- **Verified from repository documentation:** Production uses PostgreSQL/pgvector, Redis, Laravel workers/scheduler, Next.js app, and internal Nginx behind the shared Traefik network.
- **Historical:** Production domains are `app.insightistic.com` and `api.insightistic.com`; public WordPress marketing is `www.insightistic.com`.

## VPS deployment

- **Verified live on 2026-11-18:** Release `c658b742b4cb2530a78be752577f4725048208d5` was deployed to `/docker/insightistic-app` using immutable GHCR images for both API and app. API digest is `sha256:0b16672b5878ffe85f69228c19f0372b6d385849bcf0cf76c9c46ba359b35e80`; app digest is `sha256:f54284b055e11f78797198e3f302069695e46435a490af5cd0ce577fd3364513`.
- **Verified live:** API and app report the new release through `/api/version`; all seven compose services are running, and health-enabled services are healthy. `docker compose config -q` passed, the Laravel migration reported `Nothing to migrate`, and post-deploy error scans for API/app/queue/scheduler/nginx were clean.
- **Verified rollback material:** Pre-deploy environment, compose configuration, and compressed PostgreSQL backup are stored under `/docker/insightistic-app/backups/pre-deploy-20261118T005107Z.*`; the SQL gzip integrity check passed.
- **Verified release invariants (2026-11-18 release):** Stripe checkout/webhook routes and legacy AppSumo claim/webhook/OAuth routes remain present and their source files were not changed by that release. No live signed Stripe or AppSumo event was replayed during the AppSumo admin test window.
- **Verified locally:** Frontend tests passed (34 files, 222 tests), Next production build passed, PHP syntax checks passed, and both production Docker images built successfully. GitHub Actions could not start jobs (repeated zero-job `startup_failure`), so no passing hosted Pest/CI result is claimed.
- **Verified locally on 2026-11-19:** The canonical D-drive frontend passed `npm run test` (29 files, 171 tests) and `npm run build` (69 generated routes). Relevant changed PHP files and the active website provisioning file passed PHP syntax checks; `git diff --check` passed for the app and website trees.
- **Blocked locally on 2026-11-19:** The targeted Laravel feature suite could not reach its PostgreSQL test database at `127.0.0.1:5432` and therefore produced no application assertions. Composer was unavailable, so the Composer lock content hash remains pending regeneration.
- **Scope note:** The release reconciles plugin AI credit reservation/commit behavior, plugin report charging, WordPress/WooCommerce connector recording, and app credit summaries. Email/engagement connectors still require their respective plugin/add-on data to arrive; they are not falsely marked connected. The separate WordPress plugin Search Console service-account permission issue and real provider event replay remain external verification items.
- **Historical:** On 2026-11-17 the VPS was reported running seven Docker containers after a GHCR deployment.
- **Historical:** Frontend image release was commit/tag `a0c99907c067d6a46b8a6f3f92543c2dfc82a5be` with digest `sha256:6c5c72ced376bb1732a31f3f08b82570a5d47ec7d1d950d6115a08dc7151b6fc`.
- **Pending:** Recheck container health, image digest, logs, migrations, queues, scheduler, app login, API health, and HTTPS routing before the next release.
- **Security:** Server environment files and deployment credentials must remain outside this repository and outside memory files.

## Public marketing website

- **Verified live on 2026-11-17:** Hostinger website `insightistic.com` served HTTP 200 for `/`, `/features/`, `/pricing/`, `/agency/`, `/ltd/`, `/contact/`, `/about/`, `/security/`, `/legal-notices/`, `/privacy-policy/`, `/terms/`, and `/sitemap/`; none contained WordPress critical, fatal, or parse-error text.
- **Verified live:** The commercial `Product`/`SoftwareApplication` Offer schema was removed from the public marketing pages. No fake ratings, reviews, shipping, returns, GTIN, or merchant data were added. Product and Merchant listing reports must be re-crawled by Google before historical warnings change.
- **Verified live:** The approved full wordmark from `Graphic Assets/Insightistic Logo/Insightistic Gradient Eye Logo.png` is deployed as `insightistic-brand-logo.png`; SHA-256 is `A64F2DC0736D5E8B93F53A53A9480DE29E5CE45F3E1661F16B57D746B4A2D70`.
- **Verified live:** The public Company & Trust block was removed from the footer. Footer legal/company/social links remain available; machine-readable `llms.txt`, `llms-full.txt`, and MCP resources were not removed.
- **Verified live:** Contact now has one canonical contact form (plus the separate footer newsletter form), one visible “Send Us a Message” heading, and the customer-support card in the two-column layout.
- **Verified locally/live-source:** PHP syntax checks passed for the changed contact template, SEO override, and edge agent. Hostinger server/CDN cache clear was accepted. The optional LiteSpeed purge was not performed successfully because the server's WP-CLI did not register the `litespeed-purge` command.
- **Responsive QA:** A valid normal Edge session previously checked the live contact page at 320, 375, 390, 430, 768, 820, 1024, and 1440 CSS pixels with no horizontal overflow, broken images, or critical-error text. A fresh automated Playwright session was blocked by the hosting browser-check response (HTTP 403), so that result is not treated as a layout failure or as a replacement for real-browser verification.

## Performance

- **Historical:** User-provided PageSpeed screenshot showed 99 Performance, 97 Accessibility, 100 Best Practices, and 100 SEO.
- **Historical:** Screenshot findings included forced reflow, oversized/missing image dimensions, and low footer contrast; code work was reported to address them.
- **Pending:** Fresh PageSpeed result and Google Search Console validation. The user-provided PageSpeed result remains historical; no new score is claimed.

## Current open work

1. Request/monitor Google validation after the next crawl; Search Console warning counts will not change immediately after schema removal.
2. Recheck the separate WordPress plugin Search Console service-account property permission when a safe authenticated test session is available.
3. Perform one controlled authenticated AI/report test only with an approved test tenant or explicit production-test authorization; verify credit decrement and report entitlement without using an AppSumo admin account.
4. Remove the empty retired source directory after the active Codex process no longer holds it.
5. Keep this file and a dated work log current after each meaningful session.

## C: drive workspace audit

- **Verified:** The old `C:\Users\Qbits\insightistic-app` directory contains no project files and remains only as an OS-locked empty directory.
- **Verified:** The clean, older `C:\Users\Qbits\insightistic-website-inspect` snapshot was removed after confirming it was an ancestor of the active website repository and the active repository had no deleted files relative to it.
- **Verified:** The empty `C:\Users\Qbits\.wpistic-deploy` directory was removed.
- **Preserved:** `C:\Users\Qbits\insightistic`, `C:\Users\Qbits\insightistic-website`, `C:\Users\Qbits\Insightistic-Project-Docs`, deployment backups, release snapshots, animation assets, `.wpistic`, and `.wpistic.zip` because they contain active source, project documentation, credentials/configuration, rollback material, or potentially reusable product assets.
- **Pending:** Review old release/deployment folders individually before deleting them; their contents may be needed for rollback or release comparison.

