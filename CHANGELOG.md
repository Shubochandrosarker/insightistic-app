# Changelog

All notable changes to the Insightistic platform (app + API + plugin).

## [Unreleased] — Advanced SaaS + AI Knowledge Base upgrade (2026-07)

### Fixed — build/runtime blockers found in post-merge audit
- **`IngestionFailure` was unloadable**, taking the whole knowledge ingestion
  pipeline with it: it promoted a `public readonly string $code`, and
  redeclaring `Exception::$code` (inherited, non-readonly) as readonly is a
  fatal error. Every ingestion path throws this class, so no source could ever
  be indexed. The property is now `errorCode`.
- **Knowledge migration aborted without pgvector.** `CREATE EXTENSION` was
  wrapped in a `try/catch`, but migrations run inside a transaction and a
  failed statement poisons it — every later statement died with SQLSTATE
  25P02, so the documented "FTS-only degradation" never worked and migrating
  onto a plain PostgreSQL image failed outright. It now probes
  `pg_available_extensions` first and guards the `CREATE` with a savepoint.
- **Frontend build was broken by two unvalidated major bumps.** Tailwind was on
  4.3.3 while every file still used v3 (`@tailwind` directives +
  `tailwind.config.ts`), and TypeScript 7.0.2 is not recognised by Next 16's
  TypeScript integration. Pinned back to `tailwindcss@3.4.17` and
  `typescript@5.9.3`; `tsconfig.json` picks up the `jsx: react-jsx` Next 16
  requires. A deliberate Tailwind v4 migration remains open follow-up work.
- API runtime image moved from `php:8.5-fpm-alpine` to `php:8.4-fpm-alpine` so
  production matches CI and `composer.json`'s `config.platform.php` (8.4.1).
- The `report` contextual insight type was supported by `ContextAssembler` but
  missing from the dashboard's type list — all 10 are now selectable.

### Security (breaking where noted)
- **Removed `SUPER_ADMIN_EMAIL` auto-promotion** from registration, OAuth
  signup, the seeder and the Docker entrypoint. Public signup can never mint
  a platform admin. Create the first super admin with
  `php artisan insightistic:make-super-admin <email>`; further admins are
  promoted by an existing super admin (audited) or via a single-use expiring
  bootstrap token (`insightistic:super-admin:issue-token` +
  `POST /api/auth/super-admin/redeem`).
- **OAuth hardening**: no tokens for invited/disabled/suspended/archived
  accounts (no silent reactivation); email-linking requires a
  provider-verified email and no competing provider binding; cache-backed
  single-use `state` nonce (anti-CSRF) on the callback; redirect/callback
  now rate-limited.
- `is_super_admin` is no longer mass-assignable.
- **Sanctum tokens expire** (default 12 h, `SANCTUM_EXPIRATION_MINUTES`).
  BREAKING: long-lived sessions issued earlier will lapse; users sign in
  again. Active-session list / revoke-one / revoke-others endpoints added.
- Security headers everywhere: CSP, HSTS, nosniff, Referrer-Policy,
  Permissions-Policy, frame-ancestors on both the Next.js app and the API.
- New rate limiters: `ai-chat`, `knowledge-upload`, `knowledge-crawl`, `exports`.
- `npm audit`: 0 vulnerabilities (Next 16.2.11; postcss + sharp overrides).
- **Removed committed production secrets** from
  `docker-compose.hostinger-url.yml`. The old `APP_KEY` and `DB_PASSWORD`
  must be treated as compromised — see docs/DEPLOYMENT.md → Rotation.
- Dependabot enabled (npm, composer, docker, actions); CI fails on
  high/critical production npm findings; gitleaks secret scanning in CI.

### Platform
- `insightistic-api/` is now a **complete, reproducible Laravel 12 app**:
  committed `composer.json` + `composer.lock`, `artisan`, `public/`,
  `tests/` (Pest on PostgreSQL), full config. Docker builds from the
  lockfile — `composer create-project` at build time is gone.
- Production runtime: **PHP-FPM 8.4 + nginx + OPcache** (supervisord)
  replaces `php artisan serve`; non-root FPM workers; `/up` health checks;
  config/route/view caching at boot.
- **Redis** for cache, queues and rate limiting (compose service included);
  queue worker runs with retries + exponential backoff; dedicated one-shot
  `migrate` compose service (migrations no longer run on every container
  start).
- Database image switched to `pgvector/pgvector:pg16` across every compose file
  (production and both Hostinger variants). Without the `vector` extension the
  schema still migrates, but retrieval degrades to full-text search only.
- CI pipeline (`.github/workflows/ci.yml`): frontend typecheck/build/audit,
  backend Pest with migrate up/rollback/up, plugin syntax + WPCS checks,
  docker builds, secret scan. GHCR publishing is gated on the full suite.

### AI Knowledge Base (new)
- Tenant-isolated RAG platform: `knowledge_bases`, `knowledge_sources`,
  `knowledge_chunks` (pgvector 1536-dim + HNSW when available, PostgreSQL
  FTS always), `knowledge_ingestion_jobs` — reversible migrations.
- Queued ingestion: PDF/DOCX/TXT/MD/CSV/JSON/HTML extraction, boilerplate
  removal, heading-aware chunking (~800 tokens, 15 % overlap), checksum
  dedup, batched embeddings, atomic re-index.
- SSRF-hardened crawler (public-IP-only DNS screening, pinned connections,
  re-validated redirects, port/scheme allowlists, size/time caps), sitemap
  crawl with page caps + honest skip reporting, robots `noindex` respected.
- Hybrid retrieval with reciprocal-rank fusion; organization filtering is
  structural on every SQL path; honest FTS-only degradation when embeddings
  are not configured.
- Knowledge management API + `/dashboard/knowledge` UI: upload / URL /
  sitemap / manual sources, processing status, retry, re-index, disable,
  delete with verified chunk cleanup, test-retrieval panel, usage meters.

### AI Copilot (new)
- `/dashboard/copilot`: threads, SSE streaming answers (real token
  streaming on OpenAI-compatible providers), stop/regenerate/copy/feedback,
  citation drawer, context chips (site, date range, analytics sources,
  knowledge bases), suggested questions.
- Answer contract: direct answer, key finding, supporting metrics, why it
  matters, recommended actions, confidence, data limitations, date range —
  stored per message; citations only ever come from the server's own
  retrieval set.
- Run telemetry (`ai_runs`): provider, model, prompt version, latency,
  tokens, cost. Organizations can disable external AI processing
  (`allow_external_ai`); basic PII redaction before external calls.

### Plans & entitlements
- `plans.limits_json`: knowledge bases, sources, storage MB, indexed
  chunks, monthly embeddings, monthly AI messages, scheduled reports, data
  retention days — enforced server-side with structured
  `402 {code:"limit_reached"}` responses; usage meters in the UI.
- Existing subscriptions, licenses and the plugin activation contract are
  unchanged.

### WordPress plugin (1.1.0)
- Incremental daily sync with per-entity cursors/checkpoints; weekly full
  reconciliation (no more daily full-history sync).
- Real-time WooCommerce order/product events via Action Scheduler with
  debounced batched flushes; retry with backoff; sync batch ids.
- HPOS compatibility declaration.
- Opt-in (off by default) sharing of published pages/posts/products with
  the AI knowledge base via the new HMAC `connector/v1/content/bulk`
  endpoint; per-post exclusion honored.
- Manual resync by date range.

### Contextual insights
- The 10 contextual insight types the API already supported (executive,
  traffic, seo, performance, commerce, products, customers, content,
  alerts, report) are now generatable and filterable from the dashboard.
