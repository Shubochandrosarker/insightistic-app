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

## AI insights / Copilot

| Variable | Notes |
|---|---|
| `INSIGHTISTIC_AI_PROVIDER` | `rule` (default, $0, local) \| `openai` \| `wpistic_gateway` |
| `INSIGHTISTIC_AI_MODEL` / `INSIGHTISTIC_AI_TIMEOUT` | Chat model + timeout |
| `OPENAI_API_KEY` / `OPENAI_BASE_URL` | Any OpenAI-compatible endpoint |
| `WPISTIC_GATEWAY_URL/SHARED_SECRET/AGENT/TIER/TIMEOUT` | WordPressistic AI Gateway |

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

## Monitoring (optional integration points)

`SENTRY_LARAVEL_DSN` (exception tracking; add `sentry/sentry-laravel` when
enabling), `LOG_CHANNEL=stderr` for container log drivers. Never send
secrets or raw tokens to monitoring.
