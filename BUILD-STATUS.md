# Insightistic — Build Status

Two repos, built in your spec's order. Domains: `insightistic.com` (marketing),
`app.insightistic.com` (dashboard), API at `api.insightistic.com` (or `app.../api`).

```
insightistic-api/         Laravel 12 SaaS backend (multi-tenant)
insightistic-connector/   WordPress plugin (HMAC-signed sync)
```

## Done

| Layer | Spec ref | Status |
|---|---|---|
| Full Postgres schema (19 tables) | §8–§12 | ✅ |
| Multi-tenant isolation (ORM global scope) | §4, Mistake #3 | ✅ |
| Auth: register→org+owner+trial, login, me, logout | §13 Auth | ✅ |
| Organization + Site CRUD, plan `site_limit` | §13 Sites | ✅ |
| HMAC connector credentials (encrypted secret) | §13 Connector (upgraded) | ✅ |
| Connector handshake | §13, §20 W2 | ✅ |
| **Ingestion**: orders / products / customers / site-health / sync-complete (idempotent upsert) | §13, §20 W2 | ✅ |
| Sync logs from day one | Mistake #4 | ✅ |
| 5 plans seeded | §6 | ✅ |
| **WP plugin**: connect/test/disconnect, chunked Action Scheduler sync, settings, logs, status | §14 | ✅ |
| HMAC client↔server parity verified | — | ✅ |
| **Week 3 — metric snapshots engine** (tz-aware, idempotent) + nightly schedule + after-sync backfill | §10, §20 W3 | ✅ |
| **Analytics API** — overview / revenue / orders / products / customers / refunds / compare | §13 Analytics | ✅ |
| Analytics aggregation math verified (snapshot + overview + new/returning) | — | ✅ |
| Dev-preview dashboard (visualize the API now; production UI is Week 6) | §15 (partial) | ✅ |
| **Week 4 — AI insights** (swappable provider; OpenAI + $0 rule-based fallback) | §16, §20 W4 | ✅ |
| **Reports** — branded HTML + PDF (dompdf), generate/list/view/email | §17, §20 W4 | ✅ |
| **Usage metering** — AI insight + report limits enforced per plan/month | §6, §8 usage_counters | ✅ |
| RuleProvider logic + dompdf render verified | — | ✅ |
| **Week 5 — Stripe** checkout / portal / webhook (state source of truth) | §13 Billing, §20 W5 | ✅ |
| **Role enforcement** (`role:` middleware) — owner/admin/analyst/client_viewer | §4 | ✅ |
| **White-label** API — brand settings + logo upload (plan-gated) | §12, §20 W5 | ✅ |
| **Team** — list / invite (+ site grants, user-limit) / role / remove | §4, §20 W5 | ✅ |
| Plan limits enforced — sites, users, AI insights, reports | §6, §8 | ✅ |
| **Week 6 — auth email** (forgot/reset) + invite → set-password flow | §13, §20 W6 | ✅ (backend lint) |
| **Onboarding emails** — Welcome (register) + Invite mailables | §20 W6 | ✅ (backend lint) |
| **Demo seeder** — org/user/site + 30d Woo data + snapshots | §22 | ✅ (backend lint) |
| **Next.js frontend** — marketing + auth + full dashboard (10 pages) | §18, §19, §20 W6 | ⚠️ code-complete, not runtime-verified in sandbox |
| **Launch docs** — INSTALL.md + LAUNCH-CHECKLIST.md | §21 | ✅ |

## Status: all 6 spec weeks built. Remaining = your end-to-end testing.

The backend (71 PHP files) is lint-clean throughout with harness-verified crypto, snapshot
math, AI rules, and PDF rendering. The frontend is written but **not** build-verified here —
run `npm install && npm run build` in your environment to catch any env/type issues. Live
**Stripe test keys** and a **real WooCommerce store** are needed to verify billing + sync.

## What to verify first
1. API up + migrated + `PlanSeeder` + (optional) `DemoSeeder`; `storage:link`; queue + cron.
2. `npm run build` the app; log in with the demo account.
3. Install the connector on a test store → connect → first sync → watch Overview fill.
4. Generate an insight + a report; send the report by email.
5. Stripe: checkout → webhook flips plan/status → portal.

## Decisions locked
- **Metric snapshots**: nightly Laravel scheduled job + after-sync backfill (chosen).
- **AI provider (Week 4)**: defaulting to OpenAI `gpt-4o-mini` behind a swappable
  provider interface (Claude/Gemini/OpenRouter drop in). Override before Week 4 if needed.

## Week 3 — how to run it
- After a sync, snapshots backfill automatically (dispatched after sync-complete).
- Manual backfill: `php artisan insightistic:snapshots --site=1 --backfill`
- Nightly incremental: add cron `* * * * * php artisan schedule:run` (runs 02:00).
- See it: open `insightistic-api/dev-preview/dashboard.html`, paste API URL + a
  bearer token (from /api/auth/login) + site id.

## Week 4 — extra setup
- `composer require barryvdh/laravel-dompdf` (PDF rendering)
- `php artisan storage:link` (serve generated report files)
- Configure mail in `.env` (`MAIL_MAILER`, `MAIL_FROM_ADDRESS`, SMTP/SES creds)
- AI: leave `OPENAI_API_KEY` empty to use the **rule-based** provider (free, deterministic);
  set the key to switch to OpenAI `gpt-4o-mini`. No code change either way.

## Week 5 — extra setup
- `composer require stripe/stripe-php`
- `.env`: `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`, `INSIGHTISTIC_APP_URL`
- Put each plan's Stripe `price_...` ids into the `plans` table (`stripe_price_id_monthly`/`_yearly`).
- Register webhook in Stripe → `/api/billing/webhook/stripe`.
- Billing needs Stripe **test-mode keys** to verify end-to-end.

## Run order

1. `insightistic-api/README.md` → scaffold Laravel, copy files, migrate, seed.
2. Install `insightistic-connector/` on a WooCommerce test site (Guns 2 Ammo is your dogfood).
3. Dashboard → add site → copy connector_token → paste in plugin → Connect → Run Manual Sync.
4. Confirm `wc_orders` / `wc_products` / `wc_customers` fill in Postgres and `sync_logs` show success.
