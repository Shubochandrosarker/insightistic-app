# Insightistic API — Foundation (Week 1 + Connector Handshake)

Multi-tenant SaaS backend for Insightistic. This package is **drop-in files** for a
fresh Laravel 12 app — not a full Laravel install (that's ~100MB of vendor code you
generate locally). You scaffold Laravel, then copy these files over it.

## What's in this layer

- Full PostgreSQL schema (all 19 tables from the spec) as migrations
- Multi-tenant isolation baked into the ORM (global scope, not manual WHERE clauses)
- Auth: register (bootstraps org + owner + 14-day trial), login, me, logout
- Organization + Site management with plan `site_limit` enforcement
- One-time connector API key (sha256-hashed at rest)
- Connector handshake endpoint for the WordPress plugin
- 5 monthly plans seeded from the pricing table
- `sync_logs` from day one (spec Mistake #4)

## Not in this layer yet (next turns, in spec build order)

Woo sync ingestion (Week 2) · analytics/dashboard API (Week 3) · AI insights +
reports (Week 4) · Stripe billing + white-label + client_viewer team flows (Week 5)
· WordPress connector plugin · Next.js frontend. Route stubs for these are already
mapped (commented) in `routes/api.php`.

## Setup

```bash
# 1. Scaffold a fresh Laravel 12 app
composer create-project laravel/laravel insightistic-api
cd insightistic-api

# 2. Add Sanctum (token auth for the SPA + connector tokens)
composer require laravel/sanctum

# 3. Copy this package's files OVER the fresh app (merge folders)
#    - app/  database/  routes/api.php  bootstrap/app.php

# 4. Postgres in .env
#    DB_CONNECTION=pgsql
#    DB_HOST=127.0.0.1
#    DB_PORT=5432
#    DB_DATABASE=insightistic
#    DB_USERNAME=postgres
#    DB_PASSWORD=secret
#    APP_URL=https://api.insightistic.com

# 5. Migrate + seed plans
php artisan migrate
php artisan db:seed --class=Database\\Seeders\\PlanSeeder

# 6. Run
php artisan serve
```

> `routes/api.php` loads via `bootstrap/app.php` (already included). If `php artisan
> route:list` shows no `api/*` routes on a brand-new app, run
> `php artisan install:api` once — it wires Sanctum + the api route file.

## Smoke test

```bash
# Register → returns token + organization
curl -X POST http://localhost:8000/api/auth/register \
  -H "Content-Type: application/json" \
  -d '{"name":"Shuvo","email":"s@wordpressistic.com","password":"password123","password_confirmation":"password123","organization_name":"WordPressistic"}'

# Create a site (use the token from register)
curl -X POST http://localhost:8000/api/sites \
  -H "Authorization: Bearer <TOKEN>" -H "Content-Type: application/json" \
  -d '{"name":"Guns 2 Ammo","domain":"guns2ammo.com"}'
# → returns connector_token (shown once): ik_xxx.sk_xxx

# Connector endpoints are HMAC-signed (not a plain bearer token), so they are
# tested via the WordPress plugin, which signs each request locally. Paste the
# connector_token into Insightistic → Connection and click Connect Site → the
# handshake flips connection_status to "connected".
```

## Tenant isolation — how it works

`Site`, `Subscription`, `UsageCounter`, `BrandSettings`, `AiInsight`, etc. use the
`BelongsToOrganization` trait. Middleware (`tenant` for users, `connector.auth` for
the plugin) loads the active org into a request-scoped `Tenancy` singleton. A global
scope then filters **every** query to that org automatically, and auto-fills
`organization_id` on insert. A record from another org is invisible — not a 403, it
does not exist for the request. To bypass deliberately (Stripe webhook, console):
`Model::withoutGlobalScope('organization')`.

## Connector security (HMAC, upgraded from the spec)

The spec stored a hashed bearer key. We use **HMAC-signed requests** instead:
each site has a public `connector_key_id` + an AES-encrypted `connector_secret`.
The plugin signs every request — `HMAC-SHA256(secret, METHOD\nPATH\nTIMESTAMP\nNONCE\nsha256(body))`
— so the secret never travels on the wire, and a captured request can't be
replayed (5-min timestamp window + one-time nonce). This is the same model as
your Bridgistic system and is the right backbone for a long-lived product.

## Two corrections I made to the spec

1. **Idempotent Woo sync** — every `wc_*` table has `UNIQUE(site_id, external_*_id)`.
   The connector re-sends data each sync; without this, a re-sync duplicates every
   order. Ingestion (Week 2) will `upsert` on this key.
2. **Tenant isolation at the ORM layer**, not per-query. The spec's "filter every
   query by organization_id" is correct but fragile if done by hand — one forgotten
   `WHERE` leaks another tenant's data. The global scope makes it the default.

## Week 3 — Analytics

All under `GET /api/sites/{site}/analytics/*`, auth: `Bearer` token + tenant + site access.
Query params: `period` = today|yesterday|last_7_days|last_30_days|this_month|last_month|custom
(+ `from`/`to` as `YYYY-MM-DD` when `period=custom`). Ranges are resolved in the site's timezone.

| Endpoint | Returns |
|---|---|
| `/overview` | KPI metrics + previous-period values + % deltas (from snapshots) |
| `/revenue` | daily `{date, revenue, orders, refunds}` series |
| `/orders` | daily `{date, orders, failed}` series |
| `/products` | top products by revenue in range + low-stock list |
| `/customers` | new/returning counts, top customers, country breakdown |
| `/refunds` | refunded-order count, total, and list |
| `/compare` | current vs previous equal-length range + deltas |

Snapshots (spec §10) are precomputed for fast loading:
- **Auto**: after each sync, `RebuildSiteSnapshots` backfills the site (runs after the
  HTTP response — no queue worker needed for first run; switch to `::dispatch()` with Horizon in prod).
- **Nightly**: `insightistic:snapshots` (scheduled 02:00) rebuilds today + yesterday for all sites.
  Needs the system cron: `* * * * * php artisan schedule:run`.
- **Manual backfill**: `php artisan insightistic:snapshots --site=1 --backfill`

> Revenue/orders count statuses `completed, processing, on-hold` as paid; `failed, cancelled`
> as failed. A customer is "new" on the day of their first paid order, else "returning"
> (registered customers only; guests are not attributed).

**Dev preview**: `dev-preview/dashboard.html` — open it, paste the API URL + a bearer token
+ site id to see KPI cards, charts, and tables against the live API. (The production dashboard
is the Next.js app in Week 6.)

## Week 4 — AI Insights & Reports

**Extra setup**
```bash
composer require barryvdh/laravel-dompdf   # PDF rendering (pure PHP)
php artisan storage:link                   # serve generated report files
```
`.env`:
```
# AI — leave OPENAI_API_KEY empty to use the free rule-based provider
INSIGHTISTIC_AI_PROVIDER=openai
INSIGHTISTIC_AI_MODEL=gpt-4o-mini
OPENAI_API_KEY=

# Mail (for emailing reports)
MAIL_MAILER=smtp
MAIL_FROM_ADDRESS=reports@insightistic.com
MAIL_FROM_NAME=Insightistic
```

**AI insights** (`Bearer` + tenant + site access). Usage is metered against the plan's
`ai_insight_limit` per month; over-limit returns `402 ai_limit_reached`. A provider
failure returns `502` and is **not** charged.

| Endpoint | Purpose |
|---|---|
| `GET  /sites/{site}/insights` | list recent insights |
| `POST /sites/{site}/insights/generate` | body `{type: daily|weekly|monthly}` → generates + stores |
| `GET  /sites/{site}/insights/{insight}` | view one |
| `POST /sites/{site}/insights/{insight}/mark-read` | mark read |

Structured AI output (stored per spec §16): `title, summary, possible_reason,
recommendation, severity(low|medium|high), priority_score(1-10)`. The rule-based
provider produces the same structure from real metrics only (no invented data).

**Reports** — branded HTML + PDF (logo, colors, footer from `brand_settings`),
metered against `report_limit`.

| Endpoint | Purpose |
|---|---|
| `GET  /sites/{site}/reports` | list |
| `POST /sites/{site}/reports/generate` | body `{type: weekly|monthly}` → builds HTML+PDF |
| `GET  /reports/{report}` | view metadata + `pdf_link`/`html_link` |
| `POST /reports/{report}/send-email` | body `{recipients: ["a@b.com"]}` → emails the PDF |

> The report's executive summary uses the AI provider inline but is **not** double-charged —
> the report itself is the metered unit. Switching `OPENAI_API_KEY` on/off needs no code change.

## Week 5 — Billing, White-label & Team

**Extra setup**
```bash
composer require stripe/stripe-php
```
`.env`:
```
INSIGHTISTIC_APP_URL=https://app.insightistic.com
STRIPE_SECRET=sk_test_xxx
STRIPE_WEBHOOK_SECRET=whsec_xxx
```
Then set each plan's Stripe price IDs (create products/prices in Stripe, copy the
`price_...` ids into the `plans` table columns `stripe_price_id_monthly` / `_yearly`).
Register the webhook in Stripe → `https://api.insightistic.com/api/billing/webhook/stripe`,
events: `checkout.session.completed`, `customer.subscription.created|updated|deleted`,
`invoice.payment_failed`.

**Billing** (the webhook is the source of truth for subscription state)

| Endpoint | Role | Purpose |
|---|---|---|
| `GET  /billing/subscription` | owner, admin | plan + subscription + usage |
| `POST /billing/checkout` | owner | `{plan, interval}` → Stripe Checkout URL |
| `POST /billing/portal` | owner | Stripe customer portal URL |
| `POST /billing/webhook/stripe` | public (signed) | syncs subscription → org status + plan |

**White-label** (writes require a plan with `white_label_enabled`, else `402`)

| Endpoint | Role | Purpose |
|---|---|---|
| `GET   /brand-settings` | owner, admin | current branding |
| `PATCH /brand-settings` | owner | colors, footer, email-from, custom domain |
| `POST  /brand-settings/logo` | owner | upload logo (image ≤2MB) |

**Team** (roles: owner, admin, analyst, client_viewer)

| Endpoint | Role | Notes |
|---|---|---|
| `GET    /organizations/users` | owner, admin | list members + viewer site grants |
| `POST   /organizations/users/invite` | owner, admin | only owner can grant `admin`; `client_viewer` needs `site_ids`; enforces plan `user_limit` |
| `PATCH  /organizations/users/{user}/role` | owner | can't change the org owner |
| `DELETE /organizations/users/{user}` | owner | can't remove the org owner |

> Invited users are created in an `invited` state; password setup / invite email is
> wired in Week 6 with the auth email flow. All write routes are role-gated by the new
> `role:` middleware; plan limits are enforced on sites, users, AI insights, and reports.
