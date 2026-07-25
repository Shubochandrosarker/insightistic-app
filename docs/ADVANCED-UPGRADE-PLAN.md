# Insightistic — Advanced SaaS + AI Knowledge Base Upgrade Plan

_Last updated: 2026-07-25. This plan drives the `claude/insightistic-production-impl` work.
It records the **confirmed** current state (verified by reading the code, not the docs),
the phased implementation plan, the database migration plan, API changes, security risks
and the backward-compatibility strategy._

---

## 1. Confirmed current state

### 1.1 Repository layout

| Path | What it actually is |
|---|---|
| `insightistic-app/` | Next.js 15 (`^15.5.19`) + React 19 + TS 5.6 + Tailwind 3.4 + Recharts 3.8. 45 pages (20 dashboard, 14 admin, 6 auth, 3 marketing), 38 components. Builds clean (`npm run build` verified 2026-07-25). No tests, no ESLint config, no security headers, no `middleware.ts`. |
| `insightistic-api/` | Laravel 12 **overlay** — `app/`, `routes/`, `config/`, `database/`, `bootstrap/app.php` only. **No `composer.json`, no lockfile, no `tests/`, no `artisan`, no `public/`.** Docker builds run `composer create-project laravel/laravel "12.*"` at image build time (non-reproducible) and serve with `php artisan serve`. |
| `insightistic/` | WordPress plugin v1.0.0 (`Insightistic\Core`). License activation + HMAC client + daily **full** re-sync of orders/products/customers via Action Scheduler. No incremental sync, no cursors, no WooCommerce event hooks, no HPOS declaration. |
| `docker/`, `nginx/`, `docker-compose.*.yml` | php:8.3-cli + `artisan serve` (API), node:20-alpine (app), postgres:16. **No Redis.** Queue/cache/session all on Postgres. |
| `.github/workflows/docker-ghcr.yml` | Migration smoke test (fresh skeleton + overlay + `migrate`) then GHCR image push. No PHPUnit, no frontend build/lint in CI. |

### 1.2 Verified security findings (ordered by severity)

| # | Finding | Where | Status |
|---|---|---|---|
| S1 | **Public registration auto-promotes super admin** when email matches `SUPER_ADMIN_EMAIL`. Anyone who learns/guesses that email and registers it first gets platform-admin over all tenants. | `app/Http/Controllers/Api/AuthController.php:46-56`, `config/insightistic.php:59` | Fix in Phase 1 |
| S2 | **Same auto-promotion via OAuth signup** | `app/Http/Controllers/Api/OAuthController.php:78-86` | Fix in Phase 1 |
| S3 | **OAuth issues Sanctum tokens to disabled/archived users.** `callback()` never checks `status`; it also silently re-activates `invited` users and links a social identity to any account with a matching email. | `OAuthController.php:113-131` | Fix in Phase 1 |
| S4 | **Committed production secrets**: `APP_KEY` and `DB_PASSWORD`/`POSTGRES_PASSWORD` hardcoded in `docker-compose.hostinger-url.yml` (lines 6, 16, 44). The APP_KEY decrypts per-site connector HMAC secrets. | `docker-compose.hostinger-url.yml` | Remove from git + **rotation runbook** (operator must rotate; we cannot) |
| S5 | **Bearer token in `localStorage`** (`ins_token`, plus admin return token during impersonation) — XSS-exfiltratable; no HttpOnly cookie path; route protection is client-side only. | `insightistic-app/lib/api.ts:12-56` | Phase 1: cookie-session proxy (see §3.4) |
| S6 | **No security headers anywhere** (Next, Laravel, nginx): no CSP, HSTS, X-Content-Type-Options, Referrer-Policy, Permissions-Policy, frame-ancestors. | `next.config.mjs`, `nginx/*.conf` | Phase 1 |
| S7 | `npm audit`: 3 high (Next.js image chain `sharp`/libvips CVEs, `postcss` path traversal). Fix available via minor upgrades. | `insightistic-app/package-lock.json` | Phase 1 |
| S8 | OAuth flow is fully `stateless()` — no `state` parameter validation (CSRF on the OAuth callback), no PKCE. | `OAuthController.php:42,54` | Phase 1 |
| S9 | Connector secret stored plaintext in `wp_options` (autoloaded). SaaS URL accepted over `http://`. | `insightistic/includes/class-license.php:56-63` | Phase 3 (plugin v2), documented risk |
| S10 | `php artisan serve` as production runtime; root user containers; migrations run unattended on every container start. | `docker/api/Dockerfile`, `entrypoint.sh` | Phase 2 |

### 1.3 What already works (preserve)

- HMAC connector contract: canonical string `METHOD\nPATH\nTIMESTAMP\nNONCE\nsha256(body)`,
  headers `X-INS-Key-Id/Timestamp/Nonce/Signature`, ±300 s skew, per-site nonce replay cache
  (`ConnectorAuth` middleware). **Do not change** — plugin parity verified.
- Tenancy: `Tenancy` request singleton + `BelongsToOrganization` global scope + auto-fill.
- License system: org-level `insightistic_<26 base32>` key (HMAC-SHA256 hash + last4 stored),
  sits in front of per-site connector credentials; plugin activation returns `key_id`+`secret`.
- Stripe: checkout/portal/cancel/reactivate + webhook as source of truth; super-admin subscription ops.
- Snapshots engine (tz-aware, idempotent), analytics API (overview/revenue/orders/products/
  customers/refunds/compare), traffic/SEO/performance read APIs, alerts, reports (dompdf),
  scheduled reports, CSV exports, white-label, team/invites, impersonation with audit.
- AI: `AiProvider` interface with `OpenAiProvider`, `WpisticGatewayProvider`, `RuleProvider`
  fallback; `ContextAssembler` already builds evidence bundles for 10 contextual types
  (executive/traffic/seo/performance/commerce/products/customers/content/alerts/report);
  `InsightController::generate` accepts all of them. Frontend only exposes legacy 3.
- Frontend: 19 of 20 dashboard pages wired to real endpoints (Emails page is the single
  deliberate placeholder), 14 admin pages wired, dark mode, mobile shell, white-label vars.

### 1.4 Current DB schema (25 migrations, ~30 tables)

Core: `users` (+saas cols, oauth cols, `is_super_admin`, `archived_at`), `organizations`,
`organization_users` (role pivot), `sites`, `site_user_access`, `plans`, `subscriptions`
(+`internal_override`), `usage_counters`, `billing_events`, `email_logs`, `notifications`,
`brand_settings`, `personal_access_tokens`.
Data: `wc_orders`, `wc_order_items`, `wc_products`, `wc_customers`, `metric_snapshots`,
`sync_logs` (+batch cols), `traffic_daily`, `traffic_dimension_snapshots`, `seo_daily`,
`seo_query_snapshots`, `seo_page_snapshots`, `performance_runs`, `performance_issues`,
`engagement_daily`, `broken_link_snapshots`, `data_source_connections`.
Licensing: `licenses`, `license_activations`, `license_events` (via license tables migration).
AI/reports: `ai_insights`, `client_reports`, `report_schedules`, `alerts`.
Admin: `admin_audit_logs`, `impersonation_sessions`.

---

## 2. Strategy and sequencing

Priorities applied in order: security → data accuracy → automation → scalability → UX → monetization.

The work is delivered as phases; each phase leaves the repo in a green, deployable state.

| Phase | Contents | State |
|---|---|---|
| P1 | Critical security fixes (S1-S8): super-admin bootstrap, OAuth hardening, dependency upgrades, cookie sessions, security headers, rate limiters, Dependabot | this branch |
| P2 | Reproducible Laravel app (committed `composer.json`/`lock`, full skeleton, Pest), PHP-FPM+nginx runtime, Redis, queue/scheduler containers, health checks, queue monitoring | this branch |
| P4 | Knowledge base: schema, ingestion pipeline, pgvector hybrid retrieval, SSRF-safe crawler, knowledge API + UI | this branch |
| P5 | Copilot: threads/messages/runs/citations schema + API, SSE streaming, context system, citations, /dashboard/copilot | this branch |
| P6 | Contextual insights surfaced in UI (10 types, filters, severity/confidence display) | this branch |
| P8 | Plan registry extension (knowledge/AI limits in `plans.limits_json`), server-side enforcement, structured upgrade errors | this branch |
| P3 | Connector v2 incremental sync (Woo CRUD hooks, cursors, checkpoints, idempotency keys), GA4/GSC/Cloudflare server-side OAuth, PageSpeed runner, email analytics adapters | partial in this branch (see §9 Honest scope) |
| P7 | Advanced BI (RFM, cohorts, affinity, attribution) | later branch |
| P9 | Full premium UI overhaul, onboarding wizard, command palette | partial (nav, knowledge/copilot UX) |
| P10 | Privacy/compliance workflows (export, deletion, retention) | partial (knowledge deletion + vector cleanup, consent flag) |

Rationale: security first (P1), then the platform must become testable/reproducible (P2) because
every subsequent phase requires running `php artisan test` in CI; the AI knowledge base (P4/P5)
is the headline product deliverable; P3's full plugin v2 is a large, independently-shippable
workstream that must not block the security + AI work.

## 3. Phase 1 design decisions

### 3.1 Super-admin bootstrap (replaces SUPER_ADMIN_EMAIL promotion)

- Registration and OAuth **never** set `is_super_admin`. The config key and env var are removed.
- New signed bootstrap flow:
  - `php artisan insightistic:super-admin:bootstrap {email}` — only succeeds when **zero**
    super admins exist (first-boot problem). Records to `admin_audit_logs`.
  - `php artisan insightistic:super-admin:issue-token {email} --ttl=15` — generates a
    single-use, HMAC-signed, expiring token (stored hashed in `cache`), printed once. The
    named user redeems it via `POST /api/auth/super-admin/redeem` while authenticated; only
    then are they promoted. Intended for break-glass without shell-into-prod-DB.
  - Existing `insightistic:make-super-admin` retained for operators but now audit-logs.
- Promotion/demotion of further super admins: only via `POST /api/admin/users/{id}/promote|demote`
  (already exists, already audit-logged, already has last-active-super-admin protection —
  verified in `Admin\UserController`).
- `SuperAdminSeeder` no longer promotes by email; it becomes a no-op unless
  `INSIGHTISTIC_ALLOW_SEED_SUPER_ADMIN=true` **and** zero super admins exist (fresh installs only).
- Tests: public register with the old env set never yields super admin; OAuth signup same;
  bootstrap command refuses when a super admin exists; redeem is single-use and expiring.

### 3.2 OAuth hardening

- Central `ensureLoginAllowed(User)` check: reject `status != active` (`invited`, `disabled`,
  `suspended`, `archived`, `pending_deletion`) and `archived_at != null`. Applied to OAuth
  callback **and** password login (login already checked status; OAuth did not).
- No auto-reactivation: `invited` users must accept their invite; OAuth callback redirects
  with `#error=account_not_active`.
- Email-match linking only when the provider asserts a **verified** email
  (Google: `email_verified`; GitHub: primary verified email; Microsoft: verified domains) and
  the account has no other provider bound. Otherwise: `#error=account_exists` with guidance
  to sign in and link from Settings.
- `state` parameter: server-generated random nonce persisted in cache (10 min TTL, single use)
  and validated on callback — works without cookie sessions. PKCE where the provider supports it.

### 3.3 Sessions: HttpOnly cookie architecture (SPA)

Chosen design (works with the existing same-origin proxy mode):

- The Next.js server becomes the session holder. New route handlers under
  `insightistic-app/app/api/session/*` exchange credentials with Laravel, then store the
  Sanctum token **encrypted in an HttpOnly, Secure, SameSite=Lax cookie** (`__Host-` prefixed
  where possible). The browser JS never sees the token.
- The `/api/[...path]` proxy becomes a route handler (replacing the blind rewrite) that
  injects `Authorization: Bearer <token from cookie>` server-side and enforces a
  double-submit CSRF header (`x-ins-csrf`) on mutating methods.
- Migration path: the client keeps working if a legacy `localStorage` token exists — the
  proxy passes through an explicit `Authorization` header when provided. The auth context
  migrates the legacy token into the cookie on first load, then deletes it.
- Sanctum tokens get an expiry (`sanctum.expiration`) so sessions are short-lived; the
  active-sessions API lists and revokes tokens (backend endpoints; UI in Settings).
- MFA (TOTP + recovery codes) is scoped for the follow-up branch — schema (`user_mfa`) and
  reauth middleware land now; enrollment UI ships with P9 (documented in §9).

### 3.4 Security headers & rate limits

- Next.js `headers()`: CSP (self + data: images, no inline script except the theme snippet via
  hash/'unsafe-inline' style only), HSTS, X-Content-Type-Options, Referrer-Policy,
  Permissions-Policy, frame-ancestors 'none'.
- Laravel `SecurityHeaders` middleware for API responses; body-size limits documented in nginx.
- Named rate limiters (already present: `auth`, `public`, `license`, `connector`, `plugin-ai`)
  extended with: `ai-chat`, `knowledge-upload`, `knowledge-crawl`, `exports`.

## 4. Phase 2 design decisions

- `insightistic-api/` becomes a complete Laravel 12 application: committed `composer.json`
  + `composer.lock`, `artisan`, `public/`, `tests/` (Pest), `phpunit.xml`, `config/*` full set.
  Existing overlay files are preserved byte-for-byte where possible (they are the app).
- Platform requirements: PHP ^8.3, ext-pdo_pgsql, ext-redis (via predis fallback), ext-gd.
- Docker: multi-stage `docker/api/Dockerfile` — composer install (no-dev) → php:8.3-fpm-alpine
  runtime + nginx sidecar (or FPM pool exposed to the existing nginx service), OPcache enabled,
  non-root `www-data`, `/up` health endpoint, `SIGTERM`-graceful.
- Redis service added to all compose files: cache, queue, rate-limiter, locks. Postgres keeps
  sessions-less API (token auth) — `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`.
- Dedicated `queue` (with `--tries=3 --backoff=10,60,300`), `scheduler` containers unchanged in
  role; failed jobs table + `queue:prune-failed`; queue health surfaced via
  `/api/admin/system-health` (jobs pending/failed counts) — Horizon optional, documented.
- Migrations no longer run on every start: `RUN_MIGRATIONS` defaults to `false` in prod compose;
  a one-shot `migrate` service/profile is provided.
- S3-compatible storage: `league/flysystem-aws-s3-v3` committed; `FILESYSTEM_DISK` env-driven
  with local fallback; knowledge uploads and reports use it.
- Observability: Sentry SDK integration point (`SENTRY_LARAVEL_DSN` optional), structured
  AI telemetry (`ai_runs` stores provider/model/latency/tokens/cost), sync/ingestion failures
  logged to `sync_logs`/`knowledge_ingestion_jobs` with alerting hooks.

## 5. Phase 4 — Knowledge base architecture

### 5.1 Schema (all tenant-scoped, all reversible)

- `knowledge_bases`: id, organization_id FK, site_id nullable FK, name, description,
  scope enum(organization|site|global), status, settings_json, timestamps.
  Indexes: (organization_id, status), (site_id).
- `knowledge_sources`: id, organization_id, site_id nullable, knowledge_base_id FK,
  type enum(file|url|sitemap|wordpress_post|product|manual|integration), title, source_url,
  storage_path, mime_type, checksum (sha256), status enum(pending|processing|indexed|failed|disabled),
  error_code, error_message, metadata_json, last_indexed_at, timestamps.
  Indexes: (knowledge_base_id, status), (organization_id, checksum) for dedup, (site_id).
- `knowledge_chunks`: id, organization_id, site_id nullable, knowledge_source_id FK,
  chunk_index, content text, content_hash, token_count, metadata_json, embedding_model,
  `embedding vector(1536)` (pgvector; nullable), tsvector generated column for FTS, timestamps.
  Indexes: HNSW on embedding (cosine), GIN on tsvector, (knowledge_source_id, chunk_index) unique,
  (organization_id).
- `knowledge_ingestion_jobs`: id, organization_id, knowledge_base_id, knowledge_source_id,
  status enum(queued|processing|completed|failed), source_count, chunk_count, token_count,
  duration_ms, error_code, error_message, started_at, finished_at, timestamps.
- `ai_threads` (org, site nullable, user, title, context_json, archived_at),
  `ai_messages` (thread, role, content, status, error), `ai_runs` (message, provider, model,
  prompt_version, latency_ms, tokens_in/out, cost_usd, status), `ai_citations` (message,
  knowledge_chunk_id nullable, source_type, title, url, snippet, score, data_date,
  last_indexed_at), `ai_feedback` (message, user, rating, comment).
- pgvector guarded: migration checks `CREATE EXTENSION IF NOT EXISTS vector` and degrades to
  a JSON embedding column + brute-force cosine when unavailable (dev fallback, documented).
  Canonical dimension **1536** (text-embedding-3-small compatible); configurable provider.

### 5.2 Ingestion pipeline (queued)

`IngestKnowledgeSource` job chain: fetch/extract → clean → chunk (~800 tokens, 15% overlap,
heading-aware) → hash/dedup → embed (batched) → upsert chunks → mark indexed.
Extractors: PDF (smalot/pdfparser), DOCX (phpoffice/phpword), TXT/MD/CSV/JSON native, HTML
(DOM + boilerplate strip). URL/sitemap crawler: SSRF guard (deny private/reserved IPs by
resolving **and** pinning, block redirects to private ranges, https+http only, port allowlist
80/443), robots.txt + noindex awareness, depth/page caps, per-org crawl rate limit.
WordPress import: reuses connector — new plugin endpoint pushes selected pages/posts/products
(Phase 3 dependency: shipped as `content/bulk` ingestion endpoint server-side; plugin-side
exporter is part of connector v2).
Embeddings: `EmbeddingProvider` interface — `OpenAiEmbeddingProvider` (any OpenAI-compatible
base URL incl. the WordPressistic gateway) + `NullEmbeddingProvider` (deterministic hash-based,
dev/test only, clearly labeled — retrieval falls back to FTS-only ranking so results stay real).
Every failure recorded on the source + ingestion job; retry with exponential backoff (3 tries).

### 5.3 Retrieval

`KnowledgeRetriever::search(org, query, opts)` — **always** filters by organization_id at SQL
level (and site/kb/source/date filters), never trusts client-supplied org. Hybrid: pgvector
cosine top-K ∪ Postgres `websearch_to_tsquery` top-K, reciprocal-rank-fusion merge, optional
LLM rerank behind config. Returns chunks + provenance for citations.

## 6. Phase 5 — Copilot

- `POST /api/copilot/threads`, `GET /threads`, `GET/PATCH/DELETE /threads/{id}`,
  `POST /threads/{id}/messages` → SSE stream (`text/event-stream`): events
  `run.started`, `delta`, `citations`, `run.completed`, `run.failed`; stop via client abort +
  `POST /runs/{id}/cancel`. Feedback: `POST /messages/{id}/feedback`.
- Context: request carries site_id/date range/source toggles/kb ids (all validated against
  tenancy); server assembles authorized context via existing `ContextAssembler` + knowledge
  retrieval; retrieved documents wrapped in a delimited untrusted-content envelope with an
  anti-injection system preamble; the model never sees other tenants' data because retrieval
  is org-scoped at SQL level.
- Answer contract (direct answer, key finding, metrics, why it matters, actions, confidence,
  limitations, citations, date range, freshness) enforced via prompt + response post-processor;
  citations only from actually-retrieved chunks/evidence — the server matches citation ids
  against the retrieval set and drops anything else (no invented citations).
- Plan limits: monthly AI message quota via extended `usage_counters` + `plans.limits_json`;
  structured 402 `{code:"ai_limit_reached"}`.
- Streaming provider: OpenAI-compatible streaming; gateway/rule providers degrade to
  single-chunk SSE (still honest — no fake typing).

## 7. Phase 8 — Plans/entitlements

`plans.limits_json` (additive, reversible migration) with keys: `knowledge_bases`,
`knowledge_sources`, `storage_mb`, `indexed_chunks`, `monthly_embeddings`,
`monthly_ai_messages`, `scheduled_reports`, `data_retention_days`, plus existing columns kept
authoritative for sites/users/insights/reports. `PlanSeeder` updated; admin plan editor
gains limit fields; `UsageService` extended (`canUseKnowledgeSource`, `canSendAiMessage`, …)
returning structured errors `{code:"limit_reached", limit, used, upgrade_url}`.
Existing subscriptions/licenses untouched; same license activates plugin + connector (no change
to that contract).

## 8. API changes summary (all additive)

- `POST /api/auth/super-admin/redeem` (new, authed)
- `GET/POST /api/knowledge-bases`, `GET/PATCH/DELETE /api/knowledge-bases/{id}`
- `POST /api/knowledge-bases/{id}/sources` (multipart upload | url | sitemap | manual)
- `GET /api/knowledge-bases/{id}/sources`, `GET /sources/{id}`,
  `POST /sources/{id}/retry|reindex|disable`, `DELETE /sources/{id}` (removes chunks+vectors)
- `POST /api/knowledge-bases/{id}/test-retrieval`
- `GET /api/knowledge/usage`
- Copilot endpoints (§6)
- `GET /api/auth/sessions`, `DELETE /api/auth/sessions/{id}`, `POST /api/auth/sessions/revoke-others`
- Existing endpoints: **no breaking changes**. Connector v1/v2 HMAC contract unchanged.

## 9. Honest scope + backward compatibility

- **Backward compatibility**: existing WP plugin v1.0.0 keeps working — no connector-contract
  changes; bearer-token clients keep working during the cookie migration window; all
  migrations reversible (`down()` implemented); no data backfills required for existing tables.
- **Deferred to follow-up branches** (tracked in §2 table): full plugin connector v2
  (incremental Woo sync + content push), GA4/GSC/Cloudflare OAuth sync workers (server-side
  scaffolding + connection model land now; Google/Cloudflare API wiring needs credentials),
  PageSpeed scheduled runner, email analytics provider adapters beyond the honest setup state,
  advanced BI (P7), MFA enrollment UI, full onboarding wizard, Playwright E2E suite.
- Anything not implemented stays an **honest empty/setup state** in the UI — never fabricated
  data.

## 10. Migration plan & rollback

1. All new tables ship in `2026_07_25_*` migrations with complete `down()`.
2. pgvector: `CREATE EXTENSION IF NOT EXISTS vector` inside the migration, guarded; if the
   DB user lacks privileges the migration aborts with a clear message (documented in
   DEPLOYMENT.md; extension must be installed by the operator).
3. `plans.limits_json` added nullable — null = legacy defaults resolved in code; rollback drops
   the column.
4. No destructive changes to existing tables. `SUPER_ADMIN_EMAIL` removal is config-only.
5. Rollback: `php artisan migrate:rollback --step=N` reverses cleanly; frontend/back-end are
   deployable independently (cookie auth proxy passes through legacy bearer headers).

## 11. Test plan

- Backend (Pest, Postgres + Redis in CI): registration cannot create super admin (env set);
  OAuth disabled/archived/invited users get no token; state validation; tenant isolation
  (org A cannot read org B's sites/knowledge/threads/citations); site access; role
  enforcement; HMAC signature + nonce replay; license activation; Stripe webhook signature +
  idempotency; plan limits (sites/users/insights/knowledge/AI messages); knowledge upload
  authz + MIME/size validation; SSRF guard unit tests (private IPs, redirects, DNS pinning);
  source deletion removes chunks; retrieval org-scoping; citation ownership; queue jobs.
- Frontend: `tsc --noEmit`, ESLint, production build, Vitest component tests for critical
  UI (auth form, knowledge upload state machine, copilot stream reducer, error boundary).
- Plugin: `php -l`, PHPCS (WordPress-Extra subset), HMAC parity test vector shared with Pest.
- CI: single `ci.yml` running all of the above + `npm audit --omit=dev` (fail on high/critical)
  + docker builds; GHCR publish gated on the full suite.
