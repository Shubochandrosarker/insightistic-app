# Insightistic — Production Audit & Gap Analysis

**Date:** 2026-07-05
**Scope:** `insightistic-app` repo (Laravel API + Next.js app + connector plugin + marketing theme) and the standalone `insightistic` WordPress plugin repo, audited against the Insightistic product specification (free plugin → license key → SaaS unlock, 7-day trial, automated lifecycle emails, conversion-focused dashboard).

> **Superseded, partially.** The gap table below (§1) predates the license-key
> system, feature gate, and auth rate limiting shipped afterward — all three
> now exist in `insightistic-api` (`LicenseService`, `PluginLicenseController`,
> `AppServiceProvider`'s `RateLimiter::for('auth'|'license'|'connector')`).
> Treat this document as a historical planning snapshot, not a current gap
> list; verify against the actual code before re-building anything it marks
> "missing."

---

## 1. Executive summary

| Area | State | Verdict |
|---|---|---|
| Laravel API core (auth, multi-tenant, sites, analytics, insights, reports, team, white-label, super-admin) | Built, lint-clean | ✅ Solid foundation |
| Stripe billing (checkout / portal / webhook → status) | Built | ✅ Needs cancel/reactivate endpoints + dunning emails |
| WP connector sync (HMAC, chunked Action Scheduler sync) | Built (v0.1.0, inside this repo) | ✅ Working — becomes the transport under the license layer |
| Next.js dashboard (15 dashboard pages + 12 admin pages) | Code-complete, **not runtime-verified** | ⚠️ Must be build/e2e verified |
| **License-key system** (generation, activation, validation, statuses, Licenses tab) | **Does not exist — anywhere** | ❌ Build from scratch (Phase 1 of master workflow) |
| **Trial system** (7-day, reminders, expiry enforcement) | 14-day trial recorded; **never enforced**, no reminder emails | ❌ Enforcement + emails missing |
| **Email automation** (~35 lifecycle emails) | 3 mailables exist (Welcome, Invite, ClientReport) | ❌ ~90% missing |
| **Onboarding wizard** | None (empty-state hints only) | ❌ Missing |
| **Smart conversion prompts / trial banners** | Minimal (billing page only) | ❌ Mostly missing |
| Rate limiting on auth + connector endpoints | **None** | ❌ Security gap |
| Standalone plugin ↔ SaaS connection | **Zero SaaS code in the plugin repo** | ❌ Build (Phase 3, plugin repo) |
| Marketing site (WP theme) | Home/pricing/features/etc. built | ⚠️ Missing plugin landing page + docs page |

**Bottom line:** the data platform is ~80% done; the *commercial layer* (licenses, trial lifecycle, email automation, conversion UI) is the missing 20% that blocks public launch. The master workflow in `docs/MASTER-WORKFLOW.md` sequences exactly that.

---

## 2. What exists today (verified inventory)

### 2.1 API (`insightistic-api`, Laravel 12)

- **Auth:** Sanctum bearer tokens; register/login/forgot/reset; Socialite OAuth (Google/Microsoft/GitHub). Register → `AccountProvisioner::bootstrapOrganization()` → org + owner + **7-day trial** + starter plan + usage counter.
- **Multi-tenancy:** `tenant` middleware + global org scope (`BelongsToOrganization`); `site.access`, `role`, `super_admin` middleware all present.
- **Tables (19):** users, plans, organizations, organization_users, sites, site_user_access, subscriptions, usage_counters, brand_settings, sync_logs, wc_orders, wc_order_items, wc_products, wc_customers, metric_snapshots, alerts, ai_insights, client_reports, report_schedules (+ personal_access_tokens).
- **Plans seeded:** starter $9/$90 (1 site), growth $29/$290 (3), business $79/$790 (10), agency $199/$1990 (30, white-label), agency_pro $399/$3990 (100, white-label + custom domain).
- **Plan enforcement:** site limit (402 `site_limit_reached`), AI insight limit, report limit, user limit, white-label gate — all via `UsageService` + controllers. This is the entitlement engine the license layer will reuse.
- **Stripe:** `StripeService` + webhook handling `checkout.session.completed`, `customer.subscription.created/updated/deleted`, `invoice.payment_failed` → org status. Webhook is source of truth. ✔
- **Connector auth:** per-site `connector_key_id` (`ik_…`, public) + encrypted `connector_secret` (`sk_…`); one-time token `ik_x.sk_y`; HMAC-SHA256 over `METHOD\nPATH\nTIMESTAMP\nNONCE\nsha256(body)`; ±300s skew; nonce replay cache; `hash_equals`. Strong design — **keep it** as the transport layer.
- **Emails:** WelcomeMail, InviteMail, ClientReportMail + framework password reset. Nothing else.
- **Jobs/scheduler:** `RebuildSiteSnapshots` (after sync; `dispatchAfterResponse`), nightly `insightistic:snapshots` at 02:00. That's the whole scheduler.

### 2.2 Next.js app (`insightistic-app`)

- Public: `/`, `/pricing`, `/privacy`, `/terms`; Auth: login/register/forgot/reset/accept-invite/oauth-callback.
- Dashboard (15 pages): overview, revenue, orders, products, customers, insights, reports, alerts, site-health, emails (placeholder), billing, branding, team, settings.
- Admin (12 pages): overview, organizations(+detail), users, sites(+detail), subscriptions, sync-logs, usage, reports, system-health, settings.
- API client `lib/api.ts` (Bearer; proxy or direct mode via `NEXT_PUBLIC_API_URL` / `API_PROXY_TARGET`).
- **No Licenses page. No onboarding wizard. No trial countdown banner. No contextual upgrade prompts** beyond billing/branding.

### 2.3 Connector plugin (`insightistic-connector`, v0.1.0)

- Classes: Settings, Client (HMAC signer), Sync (chunked orders/products/customers/site-health via Action Scheduler, daily), Admin (Connection/Sync/Logs/Status tabs).
- Customer emails SHA-256-hashed client-side before sending. ✔
- **No license concept, no feature gating** — it reads plan limits from handshake but enforces nothing.

### 2.4 Marketing theme (`insightistic-marketing`)

- Front page (13 sections) + templates: About, Agency, AI Insights, Contact, Features, Integrations, Lifetime Deal (lead-gen form only), Pricing, Privacy, Reports, Terms, WooCommerce Analytics.
- **Missing:** dedicated free-plugin landing page, docs/knowledge-base page, license-activation explainer.

### 2.5 Standalone plugin repo (`insightistic`, v3.3.0) — summary

Full detail lives in that repo's `docs/APP-CONNECT-WORKFLOW.md`. Key facts:

- GA4 + GSC + PageSpeed + first-party engagement tracker + BYO-key AI insights (5 providers) + email digest automations + add-on reports (SEO Opportunities, Anomaly Alerts, Content Lab, WooCommerce Intelligence Pro).
- Security is strong: nonces on all 15 AJAX handlers, `manage_options` everywhere, authenticated AES-256-CBC+HMAC encryption for credentials, clean uninstall.
- **Zero SaaS/licensing code:** no license manager, no `app.insightistic.com` client, no feature gate. "WooCommerce Pro" is a free on/off toggle. Every remote call is Google or an AI provider.
- No custom DB tables; no REST routes (all admin-ajax).

---

## 3. Gap matrix (spec → repo)

### 3.1 SaaS app gaps

| # | Spec requirement | Current state | Gap severity |
|---|---|---|---|
| G1 | License auto-generation on signup (`insightistic_…` key, hash + last4 stored, show-once) | Nothing | **Blocker** |
| G2 | License endpoints: activate / validate / deactivate / regenerate / list activations | Nothing | **Blocker** |
| G3 | `licenses`, `license_activations`, `plugin_events`, `email_logs`, `notifications` tables | None of them | **Blocker** |
| G4 | License statuses (active, trialing, expired, cancelled, suspended, payment_failed, revoked) synced from Stripe + trial | Org status only (trialing/active/past_due/canceled) | **Blocker** |
| G5 | 7-day trial | 14-day hardcoded in `AccountProvisioner` | Config change + decision |
| G6 | Trial expiry **enforcement** (lock advanced features, keep free) | `trial_ends_at` recorded, never enforced | **Blocker** |
| G7 | Trial reminder emails (day 3, day 6/2-days-left, expired) | None | High |
| G8 | Lifecycle email suite (signup ×5, trial ×7, license ×7, billing ×10, plugin ×8) + `email_logs` | 3 mailables | High |
| G9 | Licenses tab in dashboard (key masked, copy, regenerate, activations, deactivate site, statuses) | No page | **Blocker** |
| G10 | Onboarding wizard (goal → site type → license → install → activate → scan → recommend) | None | High |
| G11 | Trial countdown banner + contextual conversion prompts (8 user states) | Billing page only | High |
| G12 | Billing cancel / reactivate endpoints + dunning emails + payment-failed grace period | Portal only; `past_due` flips silently | High |
| G13 | Rate limiting (auth, license activate/validate, connector) + brute-force lockout | Only password-reset broker throttle | **High (security)** |
| G14 | Plan lineup Free / Starter / Growth ("Best Value") / Agency | starter/growth/business/agency/agency_pro | Decision + seeder update |
| G15 | Plugin landing page + docs page on marketing site | Missing | Medium |
| G16 | Scheduled reports (`report_schedules` table has no job/controller) | Half-finished | Medium |
| G17 | Alert generation job (table exists, nothing writes) | Half-finished | Medium |
| G18 | Dashboard "emails" page wired to real data | Placeholder | Medium |
| G19 | White-label email sender (`ClientReportMail` deferred) | Deferred | Low |
| G20 | Composer deps not vendored (`stripe/stripe-php`, `barryvdh/laravel-dompdf`, `laravel/socialite` + Microsoft) — fatals until installed | Documented in INSTALL.md | Deploy blocker |
| G21 | Next.js frontend runtime verification (`npm run build`, e2e login → dashboard) | Never run | Deploy blocker |
| G22 | Queue worker (jobs use `dispatchAfterResponse`) | Temporary pattern | Medium |

### 3.2 Standalone plugin gaps (tracked in the plugin repo)

| # | Spec requirement | Current state | Gap severity |
|---|---|---|---|
| P1 | License activation screen (paste key → activate) | None | **Blocker** |
| P2 | `Insightistic_License_Manager` (storage, status cache, grace period) | None | **Blocker** |
| P3 | `Insightistic_Saas_Client` (activate/validate/heartbeat/deactivate against api.insightistic.com) | None — only Google/AI calls exist | **Blocker** |
| P4 | `Insightistic_Feature_Gate` (central `can( 'feature' )`) | None — free toggles only | **Blocker** |
| P5 | Daily WP-Cron license validation + manual refresh | None | **Blocker** |
| P6 | Grace period when SaaS API unreachable | None | High |
| P7 | Gate WooCommerce Pro / AI / add-ons behind plan entitlements + upgrade prompts | All free | High |
| P8 | WooCommerce data sync to SaaS (port from connector) | Exists only in connector plugin | High |
| P9 | README/docs drift (README says v3.0.0 / PHP 7.4+; actual 3.3.0 / PHP 8.0) | Stale | Low |

---

## 4. Key architecture decision (recommended, and assumed by the master workflow)

**One key for the user; two credentials under the hood.**

The existing HMAC connector-credential system is good — don't replace it. Instead, put the license key *in front* of it:

1. SaaS auto-generates an **org-level license key** `insightistic_<26 base32 chars>` at signup. DB stores only `hash` (HMAC-SHA256 with app key) + `last4`. Shown once + copyable from the Licenses tab (masked thereafter, regenerate available).
2. In the WordPress plugin the user pastes **only the license key**. The plugin calls `POST /api/plugin/activate` with key + site URL + env info.
3. The API validates key → checks plan/site limit → **creates the Site row + issues the per-site HMAC connector credentials in the same response**, plus the entitlement payload (plan, features[], status, expires_at, validation interval, grace hours).
4. All subsequent sync/heartbeat traffic uses the existing HMAC signing. License *validation* (daily cron) refreshes entitlements; HMAC remains the transport auth.

This gives the spec's UX (one key, activation limits, statuses, revocation) while reusing every line of the proven connector auth/sync code.

**Second decision — one public plugin.** The standalone `insightistic` plugin absorbs the connector's HMAC client + sync module as its "SaaS Connect" layer. The `insightistic-connector` plugin in this repo becomes internal reference code (not shipped to the public). Users install exactly one plugin.

**Trial: standardize on 7 days** (spec) via `config/insightistic.php` → `trial_days` env, replacing the hardcoded 14 in `AccountProvisioner` (and updating marketing copy that currently says 14).

**Plans: rename/reseed to Free (implicit — no license), Starter, Growth (Best Value), Agency**, keeping `business` and `agency_pro` rows inactive (hidden) rather than deleted, so existing Stripe price IDs and FKs survive.

---

## 5. What NOT to change

- HMAC canonical string, skew window, nonce replay cache — proven and mirrored in the connector; the plugin port must copy it byte-for-byte.
- Multi-tenant global scope and middleware stack.
- Snapshot engine, analytics endpoints, `UsageService` — the license entitlement payload is derived from these, not duplicated.
- Stripe webhook as billing source of truth — license status becomes a *projection* of subscription + trial state, recalculated in one service.
- The standalone plugin's encryption, nonce, and capability patterns — new license code must match them.

---

*Next: `docs/MASTER-WORKFLOW.md` — the phased implementation plan for both repos.*
