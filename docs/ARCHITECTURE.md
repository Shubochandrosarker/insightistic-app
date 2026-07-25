# Insightistic Architecture

Multi-tenant WordPress/WooCommerce intelligence platform.

```
WordPress site ── insightistic plugin (HMAC) ──▶ api.insightistic.com (Laravel 12)
                                                   │  PostgreSQL 16 + pgvector
Browser ── app.insightistic.com (Next.js 15) ──▶   │  Redis (cache/queue/limits)
              (same-origin /api proxy)             │  queue worker + scheduler
                                                   └─ Stripe / OAuth / AI providers
```

## Components

- **insightistic-app/** — Next.js 15 App Router SPA. Proxy mode by default:
  the browser only calls its own origin; Next forwards `/api/*` to Laravel.
  Security headers (CSP/HSTS/…) in `next.config.mjs`.
- **insightistic-api/** — complete Laravel 12 application (committed
  composer.json/lock, artisan, tests). Runs as PHP-FPM 8.4 + nginx +
  OPcache (supervisord) in Docker; separate queue and scheduler containers
  share the image.
- **insightistic/** — WordPress plugin: license activation → HMAC connector
  credentials → incremental WooCommerce sync + optional AI-knowledge
  content sharing.

## Tenancy

Request-scoped `Tenancy` singleton, set by `ResolveOrganization` (user
requests, `X-Organization-Id` header validated against membership) or
`ConnectorAuth` (plugin requests, from the site's org). The
`BelongsToOrganization` trait adds a global scope: foreign rows are
invisible (404, never 403) and `organization_id` auto-fills on create.
Site access adds `EnsureSiteAccess` (+ per-site grants for client_viewer);
roles via `EnsureRole` (owner/admin/analyst/client_viewer); platform admin
via `SuperAdmin` (`is_super_admin`, never tenant-scoped).

## Authentication

- SPA: Sanctum bearer tokens (expiring, default 12 h) with session
  list/revoke endpoints. Registration bootstraps org + license + usage
  counter in one transaction.
- OAuth (Google/Microsoft/GitHub): cache-backed single-use state nonce,
  account-state enforcement identical to password login, verified-email
  linking policy. Never grants super admin.
- Super admin: artisan bootstrap or audited promotion by an existing super
  admin; single-use expiring bootstrap tokens for break-glass.
- Connector: per-site `ik_…`/`sk_…` HMAC credentials (secret encrypted at
  rest). Canonical string `METHOD\nPATH\nTIMESTAMP\nNONCE\nsha256(body)`,
  ±300 s skew, per-site nonce replay cache, constant-time compare.

## Data plane

Plugin syncs orders/products/customers (idempotent upserts on natural
keys) → nightly `metric_snapshots` (tz-aware) → analytics API reads
snapshots + live tables through one `MetricsAssembler`. Traffic/SEO/
performance/engagement tables are fed by connector v2 endpoints.
`sync_logs` records every run (batch id, cursor, attempts).

## AI plane

- `ContextAssembler` — authorized evidence bundles for 10 contextual types.
- Knowledge base — see docs/AI-KNOWLEDGE-BASE.md (queued ingestion,
  pgvector + FTS hybrid retrieval, SSRF-guarded crawling).
- Copilot — SSE streaming chat grounded in analytics evidence + knowledge
  retrieval, answer contract + server-owned citations, run telemetry.
  Providers: OpenAI-compatible (streaming), WordPressistic AI Gateway,
  deterministic rule fallback. See docs/AI-SECURITY.md.

## Billing & entitlements

Stripe webhooks are the source of truth for subscriptions; the license
system projects entitlements to the plugin (same key activates plugin and
connector). Plan limits live in `plans` columns + `limits_json` and are
enforced only server-side (`UsageService`), returning structured 402s.

## Operations

Compose stacks: `docker-compose.prod.yml` (GHCR + Traefik; db=pgvector,
redis, api, queue, scheduler, app, nginx, one-shot `migrate` service under
the `ops` profile), plus two Hostinger variants. CI
(`.github/workflows/ci.yml`) gates GHCR publishing. Queue: Redis with
retries/backoff + `failed_jobs`; scheduler runs snapshots (02:00), trial
automation (hourly), alert scans (6 h), report delivery (hourly). Health:
`/up`, container healthchecks, `/api/admin/system-health`.
