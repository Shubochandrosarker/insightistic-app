# Environment Variables

Canonical reference. Examples: `insightistic-api/.env.example` (local),
`.env.production.example` (GHCR/Traefik VPS), `.env.docker.example`
(Hostinger Docker Manager). **Never commit real values.**

## Laravel API — core

| Variable | Required | Notes |
|---|---|---|
| `APP_KEY` | ✅ prod | Encrypts connector secrets + Cloudflare tokens and peppers license-key hashes. **Rotating it invalidates every license key and encrypted column** — plan a re-issue. |
| `APP_ENV` / `APP_DEBUG` / `APP_URL` | ✅ | `production` / `false` / `https://api.insightistic.com` |
| `INSIGHTISTIC_APP_URL` | ✅ | SPA base for redirects/invites (`https://app.insightistic.com`) |
| `DB_*` | ✅ | PostgreSQL 16 with the **pgvector** extension (image `pgvector/pgvector:pg16`) |
| `REDIS_HOST/PORT/PASSWORD` | ✅ prod | Cache, queues, rate limiting |
| `QUEUE_CONNECTION` / `CACHE_STORE` | ✅ | `redis` in production (`database` works for tiny installs) |
| `SANCTUM_EXPIRATION_MINUTES` | — | API-token lifetime, default 720 (12 h); 0 disables (not recommended) |
| `CORS_ALLOWED_ORIGINS` | direct mode | Comma list; unused in proxy mode |
| `MAIL_*` | ✅ prod | SMTP/SES/Postmark for auth + report mail |
| `RUN_MIGRATIONS` / `SEED_PLANS` | — | Default `false`; use the one-shot `migrate` compose service |

## Billing

`STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET` (+ per-plan `stripe_price_id_*`
rows in the `plans` table). Webhook endpoint: `POST /api/billing/webhook/stripe`.

## OAuth (all optional — buttons hide when unset)

`GOOGLE_CLIENT_ID/SECRET/REDIRECT_URI`, `MICROSOFT_CLIENT_ID/SECRET/
TENANT/REDIRECT_URI`, `GITHUB_CLIENT_ID/SECRET/REDIRECT_URI`,
`OAUTH_REDIRECT_BASE`. Redirect URIs point at
`{API}/api/auth/oauth/{provider}/callback`.

> `SUPER_ADMIN_EMAIL` was **removed** (security fix). First super admin:
> `php artisan insightistic:make-super-admin owner@example.com`.

## Direct data-source integrations (GA4, Search Console, Cloudflare)

| Variable | Required | Notes |
|---|---|---|
| `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` | for GA4 + GSC | Same OAuth client as social login; the Integrations page shows a "not configured" notice while unset |
| `INTEGRATIONS_GOOGLE_REDIRECT_URI` | for GA4 + GSC | Defaults to `{APP_URL}/api/integrations/google/callback`. Register this **exact** URI in the Google Cloud console — it is separate from the login callback so analytics scopes are never granted by a plain sign-in |
| `INTEGRATIONS_HTTP_TIMEOUT` | — | Provider HTTP timeout in seconds, default 20 |
| `INTEGRATIONS_STALE_AFTER_HOURS` | — | Hours before a source's newest data is labelled stale, default 48 |
| `CLOUDFLARE_API_BASE` | — | Default `https://api.cloudflare.com/client/v4` |

Cloudflare needs **no server-level credential**: each site stores its own
scoped API token (Zone → Analytics → Read), entered on
`/dashboard/integrations` and encrypted with `APP_KEY`.

## Email analytics

| Variable | Notes |
|---|---|
| `EMAIL_ANALYTICS_TIMEOUT` | Provider HTTP timeout in seconds, default 20 |
| `EMAIL_ANALYTICS_CAMPAIGN_LIMIT` | Campaigns pulled per sync, default 50 |

Like Cloudflare, email providers need **no server-level credential**:
Mailchimp and Klaviyo keys are entered per site on `/dashboard/emails` and
stored encrypted. FluentCRM, AutomateWoo and WooCommerce mail need no key at
all — the plugin pushes those over the existing HMAC connector contract.

Google scopes requested are read-only —
`analytics.readonly` for GA4 and `webmasters.readonly` for Search Console.
Consent is requested with `access_type=offline` so scheduled sync keeps
working; without a refresh token the connect step fails loudly rather than
dying silently an hour later.

## AI insights / Copilot

| Variable | Notes |
|---|---|
| `INSIGHTISTIC_AI_PROVIDER` | Retained for older integrations; dashboard AI uses the plan-aware provider chain. |
| `INSIGHTISTIC_AI_MODEL` / `INSIGHTISTIC_AI_TIMEOUT` | Chat model + timeout |
| `OPENAI_API_KEY` / `OPENAI_BASE_URL` | Any OpenAI-compatible endpoint |
| `OPENROUTER_API_KEY` and `OPENROUTER_*_MODEL(S)` | Server-side free/paid model routes and fallbacks |
| `WPISTIC_GATEWAY_URL` / `WPISTIC_GATEWAY_CHAT_PATH` | Defaults to `https://ai.wpistic.com` and `/compat/chat/completions` |
| `WPISTIC_GATEWAY_PRODUCT_KEY` / `WPISTIC_GATEWAY_PRODUCT_ID` | Product authentication; default product ID is `insightistic` |
| `WPISTIC_GATEWAY_MODEL` / `WPISTIC_GATEWAY_TIMEOUT` | Gateway model and request timeout |

## AI Knowledge Base

| Variable | Notes |
|---|---|
| `INSIGHTISTIC_EMBEDDINGS_PROVIDER` | `none` (FTS-only retrieval) \| `openai` |
| `INSIGHTISTIC_EMBEDDINGS_MODEL` | default `text-embedding-3-small` |
| `INSIGHTISTIC_EMBEDDINGS_DIMENSION` | canonical `1536` — must match the vector column |
| `INSIGHTISTIC_EMBEDDINGS_API_KEY` / `_BASE_URL` / `_TIMEOUT` | falls back to `OPENAI_API_KEY` |
| `INSIGHTISTIC_KNOWLEDGE_DISK` | `local` or an S3 disk (see below) |
| `INSIGHTISTIC_KNOWLEDGE_MAX_FILE_MB` | upload cap, default 20 |
| `INSIGHTISTIC_KNOWLEDGE_MAX_CONTENT_BYTES` | extracted-text cap, default 2 000 000 |
| `INSIGHTISTIC_KNOWLEDGE_CRAWL_TIMEOUT` / `_CRAWL_MAX_BYTES` | crawler caps |

## Shared knowledge brain (optional enrichment)

| Variable | Default | Notes |
|---|---|---|
| `WPISTIC_KNOWLEDGE_AGENT_ENABLED` / `_URL` / `_TOKEN` / `_SLUG` / `_CATEGORY` | off | Existing. Admin → AI configuration can override enabled/url/slug/category (stored in `platform_settings.ai_routing`, never secrets). |
| `WPISTIC_KNOWLEDGE_AGENT_DRIVER` | `rest` | `rest` = brain-api Worker (`/api/agents` …). `mcp` = WPistic memory MCP server (`tools/call memory_search`), e.g. `https://brain.insightistic.com`. |
| `WPISTIC_KNOWLEDGE_AGENT_MCP_PATH` | `/mcp` | MCP endpoint path. |
| `WPISTIC_KNOWLEDGE_AGENT_MCP_KINDS` | `project,system` | Allowlist of memory card kinds that may reach a customer prompt. Never add `client`, `person`, `decision`, `incident`, `run`. |
| `WPISTIC_KNOWLEDGE_AGENT_CF_ACCESS_CLIENT_ID` | empty | Cloudflare Access service-token Client ID (secret store only). |
| `WPISTIC_KNOWLEDGE_AGENT_CF_ACCESS_CLIENT_SECRET` | empty | Cloudflare Access service-token Client Secret (secret store only). |
| `WPISTIC_KNOWLEDGE_AGENT_CONNECT_TIMEOUT` | `2` | Seconds. |
| `WPISTIC_KNOWLEDGE_AGENT_TIMEOUT` | `4` | Seconds per request (capped by the budget). |
| `WPISTIC_KNOWLEDGE_AGENT_BUDGET_MS` | `5000` | Total time for one lookup across all calls. |
| `WPISTIC_KNOWLEDGE_AGENT_COOLDOWN` | `120` | Seconds the brain is skipped after a failure (timeout, 3xx login redirect, 401/403, bad payload). One warning is logged per trip with a reason code; answers use local knowledge only. |

## Object storage (S3-compatible, optional)

`AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`,
`AWS_BUCKET`, `AWS_ENDPOINT` (MinIO/R2), `AWS_USE_PATH_STYLE_ENDPOINT`.
Point `INSIGHTISTIC_KNOWLEDGE_DISK=s3` / `INSIGHTISTIC_REPORTS_DISK=s3`.
Local disks remain the development default.

## Licensing / trials

`INSIGHTISTIC_TRIAL_DAYS` (7), `INSIGHTISTIC_LICENSE_VALIDATE_INTERVAL`
(86400), `INSIGHTISTIC_LICENSE_GRACE_HOURS` (72),
`INSIGHTISTIC_PAYMENT_GRACE_DAYS` (7), `INSIGHTISTIC_REPORTS_DISK` (public).

## Next.js app

| Variable | Notes |
|---|---|
| `NEXT_PUBLIC_API_URL` | empty = proxy mode (recommended); set = direct mode (also lands in the CSP `connect-src`) |
| `API_PROXY_TARGET` | server-side proxy target (`http://api:8000` in compose) |

## Deploy-stack extras (`.env.production.example`)

`IMAGE_NAMESPACE`, `IMAGE_TAG`, `TRAEFIK_NETWORK/ENTRYPOINT/CERT_RESOLVER`,
`APP_HOST`, `API_HOST`, `WATCHTOWER_SCOPE`, `COMPOSE_PROJECT_NAME`.

## Observability (spec Phase 2.3)

Everything here is off by default. A fresh install sends nothing to any third
party until a DSN or collector endpoint is deliberately set.

### Error tracking

| Variable | Default | Notes |
| --- | --- | --- |
| `SENTRY_LARAVEL_DSN` | *(empty)* | Empty disables Sentry entirely. |
| `SENTRY_ENVIRONMENT` | `APP_ENV` | Groups events per deployment. |
| `SENTRY_RELEASE` | *(empty)* | Set to the image tag or commit SHA to tie errors to a deploy. |
| `SENTRY_TRACES_SAMPLE_RATE` | `0.0` | Sentry performance traces. OpenTelemetry is the primary tracing path. |
| `SENTRY_PROFILES_SAMPLE_RATE` | `0.0` | Requires the Excimer extension; leave at 0 without it. |
| `SENTRY_TRACE_QUEUE_ENABLED` | `false` | One transaction per queued job — noisy on a busy queue. |

Payloads are scrubbed by `App\Services\Observability\EventScrubber` before
they leave the process: request bodies and cookies are dropped wholesale,
headers and nested keys matching secret/PII name fragments are redacted, and
URLs keep their shape but lose every query value. This matters because an
exception carries the request that caused it, and for this API that can be a
Google refresh token, a Cloudflare API token, a license key or a customer
record. `send_default_pii` is forced off and SQL bindings are excluded from
breadcrumbs — the statement shape is enough to debug, the values are the data.

### Tracing

| Variable | Default | Notes |
| --- | --- | --- |
| `OTEL_ENABLED` | `false` | Must be true *and* an endpoint set before anything exports. |
| `OTEL_EXPORTER_OTLP_ENDPOINT` | *(empty)* | Full traces URL, e.g. `http://collector:4318/v1/traces`. |
| `OTEL_EXPORTER_OTLP_HEADERS` | *(empty)* | `key=value,key2=value2` — where a vendor API key goes. |
| `OTEL_SERVICE_NAME` | `insightistic-api` | |
| `OTEL_SERVICE_VERSION` | `SENTRY_RELEASE` or `dev` | |
| `OTEL_SAMPLE_RATE` | `0.05` | Head sampling. 1.0 is fine on a quiet install, ruinous on a busy one. |
| `OTEL_EXPORTER_TIMEOUT` | `5` | Seconds. |

OTLP over HTTP/protobuf, which every collector accepts (Tempo, Honeycomb,
Jaeger, Datadog, Sentry). gRPC would need `ext-grpc`, which the runtime image
does not carry. The auto-instrumentation package is deliberately *not* used —
it needs `ext-opentelemetry`, so it would work on a laptop and silently do
nothing in production.

### Metrics and retention

| Variable | Default | Notes |
| --- | --- | --- |
| `OTEL_REQUEST_METRICS` | `true` | Hourly per-route buckets in `telemetry_requests`; two integer writes per request. |
| `OTEL_RETENTION_DAYS` | `30` | Pruned nightly at 03:50 by `insightistic:telemetry-prune`. |

Request timings and AI spend are stored pre-aggregated into hourly buckets
per route/model rather than one row per request — a busy site would write
millions of rows a day, and the questions this data answers are all bucket
questions. Per-request detail belongs in traces and errors, which are sampled
and expire.

Queue health (`/api/admin/observability/queue`) reads whichever driver is
configured. Horizon is not used: the Hostinger compose stacks run
`QUEUE_CONNECTION=database` while the main production stack runs Redis, and a
dashboard that only works under one of those would leave the deployments most
likely to be neglected uncovered.

`LOG_CHANNEL=stderr` remains the right choice for container log drivers.
