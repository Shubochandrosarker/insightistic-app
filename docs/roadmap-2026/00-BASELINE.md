# Phase 0 Baseline — Verified Current State (2026-09-20)

This is the frozen, code-verified baseline for the roadmap in this directory.
Everything below was confirmed by a direct code audit on 2026-09-20, not copied
from older status documents. Where older docs (BUILD-STATUS.md, docs/AUDIT-*)
contradict this file, **this file wins**.

## Repos

| Repo | What it is | State at baseline |
|---|---|---|
| `Shubochandrosarker/insightistic-app` (this repo) | Monorepo: `insightistic-api/` (Laravel 12) + `insightistic-app/` (Next.js 16) + docker/nginx/CI | main @ 3174cbe |
| `Wordpressistic/insightistic` | The WordPress plugin (canonical home), v4.4.2 | main @ 8231662, PR #2 (WPistic platform bridge) merged |
| `Shubochandrosarker/insightistic-website` | Marketing theme + legacy `insightistic-connector` v0.1.0 (absorbed into the plugin) | reference only |

The `insightistic/` directory inside this monorepo is an **old v1.1.0 connector
snapshot** kept in-tree for the CI PHP lint job. It is NOT the live plugin.

## Verified local baseline (Phase 0 gate)

- Frontend: `npm test` → **51/51 vitest pass**; `npm run build` → **success**.
  Node 22.18.0 / npm 10.9.3. `npm run lint` is broken (`next lint` removed in
  Next 16, no ESLint config) — CI compensates with typecheck + build.
- Backend: `composer install` succeeds (requires PHP ^8.3; local PHP 8.2.31
  needs `--ignore-platform-req=php`); app boots (`php artisan --version` →
  Laravel 12.65.0). **The Pest suite requires PostgreSQL** (`phpunit.xml`
  pins `DB_CONNECTION=pgsql`; schema uses jsonb/pgvector) — the suite runs in
  CI (PHP 8.4 + Postgres 16 + Redis), not on this workstation.
- Plugin repo: `php tests/run.php` → 5/5 suites pass; lint + release
  validation pass (PHP 8.2.31 available locally; composer/PHPCS not).

## Backend inventory (`insightistic-api/`)

269 app PHP files (~28k LOC), 46 migrations creating **67 tables**,
**283 API routes** (`routes/api.php`), 65 test files (~440 Pest cases).

Module status against the target roadmap:

| Module | Status | Evidence |
|---|---|---|
| Tenancy / auth / RBAC | COMPLETE | `app/Support/Tenancy.php`, `ResolveOrganization`, `EnsureRole`, `EnsureSiteAccess`; 29 org-scoped models |
| Stripe billing (legacy) | COMPLETE | `StripeService`, `StripeWebhookProcessor` (raw-body verify, idempotent by `stripe_event_id`), `billing/webhook/stripe` |
| Paddle billing (target) | **MISSING** | No plan_prices / billing_events / billing_transactions / entitlement_grants; zero Paddle code |
| Entitlements / limits / metering | COMPLETE (no credits) | `UsageService`, `usage_counters`, `plans.limits_json`, 402 `limit_reached` convention |
| WP HMAC connector | COMPLETE | `ConnectorAuth` (canonical HMAC, nonce replay cache, ±300s skew), connector v1+v2 routes, idempotent upserts |
| GSC / GA4 / Cloudflare / PageSpeed | NEAR COMPLETE | `app/Services/Integrations/*`, PKCE OAuth, CrUX-preferring PageSpeed, quota guards |
| Bing Webmaster / IndexNow | **MISSING** | — |
| Technical crawler | **MISSING** | Only KB sitemap fetch (50-page cap); broken links come from the plugin |
| Rank tracker | **MISSING** | — |
| AI visibility (prompts/mentions/citations) | **MISSING** | `AiCitation` is Copilot-internal, not AI-search |
| AI insights / Copilot | COMPLETE | `app/Services/Ai/*` swappable providers, metered; `app/Services/Copilot/CopilotChat.php` |
| Performance (PageSpeed/CWV) | PARTIAL | Runs + regression alert exist; **no uptime monitors/incidents** |
| Traffic analytics | COMPLETE | `traffic_daily` + dimensions + `AcquisitionIntelligence` |
| WooCommerce intelligence | COMPLETE | `AnalyticsController`, 14 intelligence endpoints, `SafeWooQueryEngine` readonly guard |
| Metric snapshots | COMPLETE | tz-aware, idempotent, nightly + backfill |
| Reports / white-label | COMPLETE | dompdf PDF, schedules, signed share URLs, brand settings |
| Agency | PARTIAL | `agency/overview` rollup only; no portfolio/client grouping |
| Automation / alerts | COMPLETE | `AutomationDispatcher`, `AlertDetector` (9 rules), SSRF-guarded webhooks |

## Frontend inventory (`insightistic-app/`)

Next.js 16.2.12, React 19, TS strict, Tailwind, App Router. **50 routes**
(25 dashboard + 15 admin + 10 top-level) — BUILD-STATUS.md's "10 pages" is
wrong. All pages are API-driven; no fake data except the Settings page sync
toggles (cosmetic, no persistence).

Gaps vs target IA:

- Sidebar is **flat** (24 items), not grouped; no custom date range (4 presets).
- **No AI Visibility pages, no Site Audit pages** (crawl/links/images/issues).
- Search Intelligence = GSC-only page; Performance = PageSpeed-only.
- Billing UI is Stripe-only with **hardcoded plan lineups that disagree**
  (billing page: 3 plans; marketing pricing: 5 plans; backend seeds 6).
- ~8 read-heavy pages (overview, traffic, seo, revenue, products, customers,
  orders, site-health) swallow API errors and render them as empty states.
- Dead code: `components/dashboard/DataTable.tsx`, two `app-shell` components.

## Plugin inventory (`Wordpressistic/insightistic` v4.4.2)

All claimed modules exist and are security-sound (nonce+capability on every
mutation, versioned encrypt-then-MAC secret storage, raw license key never
stored). Known items:

- `class-insightistic-platform-bridge.php` (PR #2) is **dormant scaffolding** —
  zero runtime consumers, the `WPistic\Sdk\Licensing` SDK is not bundled.
- `sync/engagement/daily` client method is defined but never called.
- No client-side idempotency keys or chunk retries in sync (server dedup only).
- GSC client uses legacy `www.googleapis.com/webmasters/v3/` base.
- Stale nested clone inside the plugin repo ships a 4.4.2 zip built without
  PR #2 — cosmetic, but should be removed to avoid confusion.

## Phase 0 conclusions

1. No unknown dependency blocks the billing refactor: Stripe is isolated
   behind `StripeService` + `StripeWebhookProcessor`; adding Paddle is
   additive (Phase 1 never drops Stripe columns — see 23-STRIPE-TO-PADDLE).
2. The authoritative test gate is CI. Feature PRs must keep the CI matrix
   green (Pest on PG16, frontend vitest + build, plugin lint).
3. `BUILD-STATUS.md` is superseded by this file (banner added).
4. Feature flags introduced in `config/feature-flags.php`: `paddle`,
   `crawler`, `rank_tracker`, `ai_visibility`, `uptime` — all default off.
