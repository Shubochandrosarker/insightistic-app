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
- **Two-factor (TOTP)**: enrolment is two-step — a secret is written on
  `POST /auth/mfa/setup`, and only a working code on `/auth/mfa/confirm`
  sets `mfa_confirmed_at` and switches MFA on. Enabling on first write would
  lock out anyone whose authenticator failed to scan. Secret and recovery
  codes are encrypted at rest and `$hidden` on the model; recovery codes are
  additionally stored **hashed**, so a database dump cannot be turned into a
  working second factor. A used TOTP code is burned for its window
  (`verifyKeyNewer` + a cached last-used timestamp), so an observed code
  cannot be replayed for the rest of its 30-second step.
- **MFA login is two-phase**: the password alone never mints a token. When
  MFA is on, `/auth/login` returns a single-use 5-minute `challenge`;
  `/auth/mfa/challenge` exchanges it plus a TOTP or recovery code for a
  session. The challenge is burned on the first attempt regardless of
  outcome, so it cannot be used to brute-force codes.
- **Reauthentication** (`reauth` middleware, 428 Precondition Required):
  billing checkout/cancel, license regeneration, super-admin
  promotion/demotion, impersonation start, and every MFA setting change
  require a password confirmation within the last 10 minutes. The mark is
  bound to the **specific access token** that confirmed, not to the user —
  confirming on your laptop must not unlock a token someone else stole.
- **Login notifications**: a sign-in from an unseen device emails the
  account. The device fingerprint is an APP_KEY-salted HMAC of (IP, user
  agent) — the raw values are never stored, so "is this new?" is answerable
  without keeping a log of where people sign in from. The first tracked
  login records silently, so shipping this does not alert every user at once.
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
- Insight lifecycle — `saved_at` / `dismissed_at` / `actioned_at` /
  `expires_at` are columns on `ai_insights`, not `status` values: an insight
  can be read *and* saved, and dismissing ("not now") is not completing.
  Dismissal hides, never deletes. `expires_at` is mirrored out of the
  generator's contract so freshness is queryable — inside JSON nothing could
  filter or sort on it — and past-window insights render as stale rather than
  disappearing. `POST .../insights/{id}/action` returns task-ready markdown;
  Insightistic exports into whatever tracker the team already uses instead of
  growing its own.
- Proactive generation — `insightistic:insights-generate` (05:00, last in the
  nightly chain so it reads freshly synced data) produces executive, traffic,
  seo, commerce and alerts insights unprompted. Three guards: plan limits are
  checked per organization before each generation and the run abandons that
  org once its allowance is gone, a type is skipped when a live non-dismissed
  insight of that type is still inside its freshness window, and context is
  assembled before metering so a site with no evidence costs no quota.
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
retries/backoff + `failed_jobs`; scheduler runs data-source sync (01:20),
snapshots (02:00), privacy maintenance (03:30), PageSpeed (04:10), proactive
insights (05:00), trial automation (hourly), alert scans (6 h) and report
delivery (hourly). Health:
`/up`, container healthchecks, `/api/admin/system-health`.
