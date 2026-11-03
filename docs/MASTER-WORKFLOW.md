# Insightistic — Master Workflow (App + Plugin, Public Production)

**Companion to:** `docs/PRODUCTION-AUDIT.md` (read it first — this document only plans work, the audit justifies it).
**Repos stay separate.** App-side work happens in this repo; plugin-side work happens in the `insightistic` plugin repo (its own copy of the plugin phase lives at `insightistic/docs/APP-CONNECT-WORKFLOW.md`). The only coupling is the API contract in §3, which both sides implement against.

---

## 0. Phase map

| Phase | Repo | Outcome | Depends on |
|---|---|---|---|
| **0 — Production hardening** | app | App deploys and runs clean; security gaps closed | — |
| **1 — License core** | app (API) | Keys generated, activated, validated, revoked; statuses live | 0 |
| **2 — Dashboard: Licenses + onboarding + conversion UI** | app (Next.js) | Licenses tab, wizard, trial banners, contextual prompts | 1 |
| **3 — Plugin: SaaS Connect layer** | plugin | License screen, feature gate, sync, grace period | 1 (API contract frozen) |
| **4 — Email automation** | app (API) | Full lifecycle email suite + email_logs + schedules | 1 |
| **5 — Marketing + docs pages** | app (theme) | Plugin landing page, docs page, pricing alignment | 1 (copy needs final plans) |
| **6 — Launch verification** | both | E2E checklist green on staging, then production | all |

Phases 2, 3, 4, 5 can run **in parallel** once Phase 1 freezes the API contract. Build order within each phase is top-to-bottom.

---

## 1. Phase 0 — Production hardening (app repo)

Small, unblocking, do first.

1. **Vendor the missing composer deps** so the API boots without manual steps:
   `composer require stripe/stripe-php barryvdh/laravel-dompdf laravel/socialite socialiteproviders/microsoft` — commit `composer.json`/`composer.lock`; update Docker build to `composer install --no-dev`.
2. **Rate limiting.** Define named limiters in `bootstrap/app.php` / `AppServiceProvider`:
   - `auth`: 10/min per IP on `auth/login`, `auth/register`, `auth/forgot-password`.
   - `license`: 30/min per IP + per-key on the new `plugin/*` routes (Phase 1) — activation/validation brute-force protection.
   - `connector`: 120/min per `connector_key_id`.
3. **Trial length → config.** `config/insightistic.php`: `'trial_days' => env('INSIGHTISTIC_TRIAL_DAYS', 7)`. Replace the hardcoded `addDays(14)` in `AccountProvisioner` (and DemoSeeder). Update copy: register page, pricing page, WelcomeMail ("14-day" → dynamic).
4. **Plan reseed.** Update `PlanSeeder`: active plans = `starter`, `growth` (flag `is_featured` → "Best Value"), `agency`; set `business`, `agency_pro` → `is_active = false`. Add `features_json` column to `plans` (Phase 1 migration) listing feature slugs per plan (see §3.4).
5. **Queue worker.** Switch `RebuildSiteSnapshots::dispatchAfterResponse()` → `dispatch()`; add `php artisan queue:work` (supervisor/docker service already sketched in HOSTINGER-VPS.md). All Phase 4 mail goes through the queue.
6. **Frontend runtime verification.** `npm ci && npm run build` in `insightistic-app/`; fix type/env errors; smoke-test login → dashboard → billing against a seeded API. (This was flagged "code-complete, not runtime-verified".)
7. **Finish or fence half-built features:** wire `report_schedules` into the scheduler (a `SendScheduledReports` command, hourly) **or** hide the UI affordance; same for `alerts` (add `DetectAlerts` job to the nightly schedule) and the dashboard `emails` page (keep the honest placeholder until Phase 4).

**Exit criteria:** fresh clone → `composer install`, migrate, seed, `npm run build`, docker-compose up → register → connect nothing → no errors; throttles return 429 under hammering.

---

## 2. Phase 1 — License core (API)

### 2.1 Migrations (new tables)

```php
// licenses
id; organization_id FK; plan_id FK; subscription_id FK nullable;
license_key_hash (char 64, unique);   // HMAC-SHA256(app_key, raw)
license_key_last4 (char 4);
status enum: active | trialing | expired | cancelled | suspended | payment_failed | revoked;
activation_limit (int);               // copied from plan->site_limit on issue/plan change
expires_at nullable; last_validated_at nullable; revoked_at nullable;
timestamps; index (organization_id, status)

// license_activations
id; license_id FK; site_id FK nullable;   // linked once Site row is created
site_url; normalized_site_url; site_hash (sha256 of normalized url, unique per license);
wp_version; php_version; plugin_version; woocommerce_active bool;
environment enum: production | staging | local;   // staging/local do NOT count against limit
status enum: active | deactivated | blocked;
activated_at; last_seen_at; deactivated_at; timestamps;
unique (license_id, site_hash)

// plugin_events
id; license_id FK; activation_id FK nullable; event_type; payload_json jsonb; created_at
// event types: activated, deactivated, validation_ok, validation_denied,
//              limit_reached, key_regenerated, revoked, heartbeat_missed

// email_logs (Phase 4 uses it; create now)
id; user_id FK nullable; organization_id FK; email_type; recipient; subject;
status enum: queued|sent|failed|skipped; provider_message_id nullable; sent_at; created_at

// notifications (dashboard bell + conversion prompts)
id; user_id FK; type; title; message; action_url nullable;
status enum: unread|read|dismissed; created_at; read_at
```

Plus: `plans.features_json` (jsonb), `plans.is_featured` (bool), `organizations.trial_reminder_sent_at` markers (or track via `email_logs`).

### 2.2 `LicenseService` (single owner of all license logic)

- `issue(Organization $org): string` — generate `insightistic_` + 26 chars Crockford-base32 from `random_bytes(16)`; store hash + last4; return raw **once**. Called from `AccountProvisioner::bootstrapOrganization()` (every new org gets a license automatically) and from a backfill command `insightistic:licenses-backfill` for existing orgs.
- `regenerate(License)` — new key, same row (old hash overwritten → old key dead everywhere); log `key_regenerated`; existing activations stay valid (they authenticate by HMAC creds, not the raw key) but the next daily validation returns the new status.
- `resolve(string $rawKey): ?License` — constant-time hash lookup.
- `computeStatus(License): string` — pure projection: revoked > suspended > subscription status (payment_failed/cancelled) > trial state (trialing/expired) > active. Called by a `SyncLicenseStatus` listener on every Stripe webhook + trial transitions; persisted so the plugin sees one authoritative field.
- `entitlements(License): array` — `{ status, plan, features[], activation_limit, activations_used, expires_at, validate_interval: 86400, grace_hours: 72 }` from `plan.features_json` + `UsageService`.
- Site-URL normalization: lowercase host, strip scheme/`www.`/trailing slash/ports 80/443; classify `environment` (host is `localhost`/`*.local`/`*.test`/IP → `local`; contains `staging.`/`dev.` → `staging`). Staging/local activations don't consume the limit (spec edge case).

### 2.3 New routes

```
// Dashboard (auth:sanctum + tenant)
GET    /api/licenses                       LicenseController@index      // masked, statuses, usage
POST   /api/licenses/{license}/regenerate  @regenerate   role:owner    // returns raw key once
GET    /api/licenses/{license}/activations @activations
DELETE /api/activations/{activation}       @deactivate                 // "Deactivate website"

// Plugin (public + throttle:license — authenticated BY the license key itself)
POST   /api/plugin/activate      PluginLicenseController@activate
POST   /api/plugin/validate      @validate        // also the daily-cron endpoint
POST   /api/plugin/deactivate    @deactivate
GET    /api/plugin/features      @features        // entitlement payload only

// Billing additions (auth + role:owner)
POST   /api/billing/cancel       BillingController@cancel      // cancel_at_period_end
POST   /api/billing/reactivate   @reactivate
```

`POST /api/plugin/activate` — request/response contract (frozen; the plugin repo builds against this):

```jsonc
// request
{ "license_key": "insightistic_4X7…", "site_url": "https://shop.example.com",
  "site_name": "Example Shop", "wp_version": "6.8", "php_version": "8.2",
  "plugin_version": "4.0.0", "woocommerce_active": true }

// 200 response
{ "ok": true,
  "license": { "status": "trialing", "plan": "growth", "expires_at": null,
               "trial_ends_at": "2026-07-12T00:00:00Z",
               "features": ["basic_analytics","woocommerce_analytics","seo_analysis",
                             "email_audit_automation","ai_insights","scheduled_reports"],
               "activation_limit": 3, "activations_used": 1,
               "validate_interval": 86400, "grace_hours": 72 },
  "activation_id": "act_9f2…",
  "connector": { "key_id": "ik_…", "secret": "sk_…", "api_base": "https://api.insightistic.com" },
  "site_id": 42 }

// errors (400/402/403/409/429): invalid_key, key_revoked, license_expired,
// activation_limit_reached (with upgrade_url), site_already_active (idempotent →
// return existing activation + fresh entitlements instead of an error when the
// same site re-activates), payment_required
```

Activation flow inside the controller: resolve key → status check → normalize URL → find-or-create `license_activations` (idempotent on `site_hash`) → enforce limit (production sites only) → **create Site row + issue HMAC connector credentials** (reuse `Site::issueConnectorToken()`) → log `plugin_events` → fire `SiteConnected` event (Phase 4 email) → return payload. `validate` is the same minus site creation: bump `last_seen_at`/`last_validated_at`, return fresh entitlements — this is how upgrades/downgrades/cancellations propagate to plugins within 24 h.

### 2.4 Feature slugs (single vocabulary — API, dashboard, and plugin all use these)

```
basic_analytics            // always on, even unlicensed (free plugin)
woocommerce_analytics      // growth+
seo_analysis               // starter: overview, growth+: full
email_audit_automation     // growth+
ai_insights                // starter: basic, growth+: business insights
advanced_reports           // growth+
scheduled_reports          // growth+
multi_site                 // growth (3), agency (10+)
agency_white_label         // agency
team_access                // agency
```

Stored per-plan in `plans.features_json`; `LicenseService::entitlements()` merges plan features with license status (non-active status → only `basic_analytics` survives).

### 2.5 Trial + status automation (scheduler)

New commands on the Laravel schedule:

- `insightistic:trials` (hourly) — orgs with `status=trialing` past `trial_ends_at` and no active subscription: set license `status=expired` (org row keeps its history), fire `TrialExpired` event (Phase 4 email + dashboard notification). The same command fires `TrialEndingSoon` at T-2 days and T-1 day (deduped via `email_logs`).
- `insightistic:licenses-sync` (hourly, cheap) — recompute `computeStatus()` for licenses whose subscription/org changed since last run (or do it purely event-driven from the Stripe webhook listener + trial command; keep the command as a reconciliation sweep).
- Payment-failed grace: on `invoice.payment_failed` set license `payment_failed`; entitlements keep full features for `grace_days` (config, default 7); after grace with no recovery → `suspended`.

**Exit criteria (Phase 1):** feature test suite covering — signup issues key; activate happy path creates site + creds; limit reached blocks 4th production site on growth; staging site bypasses limit; regenerate kills old key; Stripe `customer.subscription.updated` (plan change) reflected in next `validate` response; trial expiry flips status + locks features; all `plugin/*` routes throttled.

---

## 3. Phase 2 — Dashboard: Licenses tab, onboarding, conversion UI (Next.js)

### 3.1 Licenses page (`app/dashboard/licenses/page.tsx`) — new nav item

- Key card: `insightistic_ ••••••••••••••••` + last4, **Copy** button (copies masked? no — the raw key is only available at issue/regenerate time; the copy button copies the raw key returned at signup and cached client-side only during that session; afterwards the card offers **Regenerate** with a confirm dialog "Your old key stops working on all sites").
  - Simpler and spec-compliant alternative (recommended): store nothing client-side; the page shows masked key + Copy is enabled only right after generation/regeneration; otherwise the CTA is Regenerate.
- Status pill (7 statuses, color-coded), plan badge, trial countdown, activation meter ("1 of 3 websites connected"), created/renewal dates.
- Connected-sites table: domain, environment tag, WP/plugin versions, last seen, **Deactivate** button.
- Connection steps block (the spec's 5 steps: install plugin → Insightistic → License → paste → Activate → site appears here) + "Use this key inside your WordPress plugin to unlock advanced Insightistic features."
- Upgrade CTA when `activations_used == activation_limit`.

### 3.2 Onboarding wizard (`app/onboarding/`)

Post-register redirect (skippable, resumable; progress in `localStorage` + org meta):
1. Goal (WooCommerce sales / SEO / automate reports / monitor clients / AI insights) → 2. Site type → 3. **Your license key** (the one show-once raw display, big copy button) → 4. Install plugin (link + one-liner) → 5. Activate (poll `GET /api/licenses` every 5 s; auto-advance when an activation appears) → 6. First scan (sync status from `sync_logs`) → 7. Recommended features (driven by goal + `woocommerce_active`).

### 3.3 Conversion system

- **Global trial banner** in the dashboard shell: driven by `GET /api/dashboard/overview` additions (`trial_days_left`, `license_status`). States: trialing (≤3 days → amber countdown), expired (violet "Reactivate"), payment_failed (red + grace deadline), cancelled-still-active ("ends <date> — Reactivate").
- **Notifications API** (`GET/PATCH /api/notifications`) → bell + inline promo cards. Server-side rules (a small `PromotionService`, evaluated on dashboard load):
  - no activation yet → "Connect your first WordPress site"
  - `woocommerce_active` on any site + plan < growth → "WooCommerce detected. Unlock revenue analytics with Growth."
  - locked-feature click (`POST /api/plugin/events` proxied / frontend event) → feature-specific prompt
  - trial T-2 → "Keep advanced analytics active — choose a plan"
  - sites at limit → upgrade prompt
- Billing page: add **Cancel** and **Reactivate** flows (new endpoints), plan-change preview, "Best Value" badge on Growth.

**Exit criteria:** all 8 spec UI states render correctly with seeded fixtures (new user, trial, free, paid, expired trial, payment failed, cancelled, agency multi-site); wizard completes end-to-end against staging API.

---

## 4. Phase 3 — Plugin: SaaS Connect layer (plugin repo — summary only)

Owned by the `insightistic` repo; full task list in `insightistic/docs/APP-CONNECT-WORKFLOW.md`. Contract: §2.3 payloads above. In one paragraph: add `Insightistic_License_Manager`, `Insightistic_Saas_Client`, `Insightistic_Feature_Gate`, a License admin page, daily WP-Cron validation with 72 h cached-status grace, gate WooCommerce Pro / AI business insights / add-ons / email automations behind entitlements, and port the connector's HMAC client + chunked sync as an `Insightistic_Sync` module activated by the license handshake. The `insightistic-connector` plugin in this repo is thereafter **internal reference only — do not ship it publicly**.

---

## 5. Phase 4 — Email automation (API)

Queue-backed (Phase 0.5), every send recorded in `email_logs` (dedupe key: `email_type + org + period`), all templates on one branded Blade layout (logo, footer, unsubscribe for non-transactional). Driver: whatever `MAIL_MAILER` is configured (SES/SMTP/Resend).

### 5.1 Email matrix (type → trigger → contains)

| # | email_type | Trigger | CTA |
|---|---|---|---|
| 1 | welcome | register (exists — refresh copy, 7-day trial) | Go to dashboard |
| 2 | verify_email | register (enable Laravel `MustVerifyEmail`) | Verify |
| 3 | trial_started + license_generated | org bootstrap (one combined email: "Your trial is live — here's your license key setup") | Copy key / connect plugin |
| 4 | plugin_install_guide | +1 day, if no activation | Install guide |
| 5 | trial_day3_features | trial day 3 | Explore features |
| 6 | trial_day5_value | day 5, personalized (revenue synced? "You tracked $X this week") | Dashboard |
| 7 | trial_ending_2d / trial_ending_today | T-2 / T-0 | Choose plan |
| 8 | trial_expired | expiry job | Reactivate |
| 9 | trial_winback | +7 days after expiry, still unconverted | Restart |
| 10 | license_activated_on_site | first activation per site | View site |
| 11 | license_deactivated / regenerated / suspended / revoked / limit_reached | respective events | Licenses tab |
| 12 | subscription_started / payment_succeeded / invoice_available | Stripe webhooks | Invoice |
| 13 | payment_failed / payment_retry | `invoice.payment_failed` / retry schedule | Update card (portal link) |
| 14 | plan_upgraded / plan_downgraded | subscription.updated diff | What changed |
| 15 | subscription_cancelled / reactivated / renewal_reminder (T-7 yearly) | webhooks + scheduler | — |
| 16 | site_connected / site_disconnected / plugin_inactive_warning (no heartbeat 7 d) | events + daily sweep | — |
| 17 | weekly_audit / monthly_report / wc_revenue_report / seo_issue_report | `report_schedules` job (Phase 0.7) | View report |
| 18 | critical_issue | alerts job severity=critical | View alert |

Implementation: one `LifecycleMailer` service + thin Mailable per template; events (`TrialExpired`, `SiteConnected`, `LicenseRegenerated`, …) → queued listeners; scheduled sweeps for time-based ones (`insightistic:trials`, `insightistic:email-sweeps` daily). Copy: premium/calm tone per spec — draft all subjects/bodies in `resources/views/emails/` during this phase; review pass before launch.

**Exit criteria:** Mailtrap/staging run showing every email fires exactly once per trigger (dedupe proven by re-running jobs), `email_logs` rows written, failed provider sends marked `failed` and retried by the queue.

---

## 6. Phase 5 — Marketing site + docs (WP theme)

1. **Plugin landing page** (`template-plugin.php`): plugin hero → free features → locked advanced grid → how activation works (5 steps) → screenshots → pricing comparison strip → FAQ → "Create free account" CTA. Reuse existing section partials.
2. **Docs page(s)**: installation, license activation, plugin connection, WooCommerce analytics, SEO analytics, email automation, troubleshooting, FAQ. Simplest robust option: a `docs` CPT + archive template (the theme already has `inc/cpt` scaffolding).
3. **Pricing page alignment:** 4 tiers (Free plugin / Starter / Growth "Best Value" / Agency), monthly-yearly toggle already exists; numbers must match `PlanSeeder`; trial copy "7-day free trial, no card required".
4. Home page: add the license-activation workflow section + "Download free plugin" CTA next to trial CTA (both already exist as sections — verify copy against spec messaging: "Turn your WordPress data into business intelligence.").
5. Retire/redirect the Lifetime Deal page or keep as lead-gen (decision: keep, it's harmless lead-gen).

---

## 7. Phase 6 — Launch verification (both repos)

Staging first, then production. The full E2E script:

1. Fresh signup → verify email → onboarding wizard → license key shown once.
2. Install plugin (from the plugin repo build) on a test WooCommerce site → paste key → Activate → site appears in dashboard ≤5 s; connector creds issued; first sync completes; Overview fills.
3. Feature gate: on Starter, WooCommerce Analytics locked in plugin with upgrade prompt; upgrade to Growth via Stripe test checkout → webhook → `plugin validate` (manual Refresh License) → module unlocks without reinstall.
4. Trial: time-travel org `trial_ends_at` → T-2 email + banner; expiry → license `expired`, plugin locks advanced modules, basic analytics still works, dashboard shows Reactivate.
5. Payment failed (Stripe test card) → `payment_failed` status, grace features intact, dunning email; recovery → active.
6. Regenerate key → old key rejected on a second site's activation attempt; existing site keeps syncing (HMAC) and picks up status on next validate.
7. Activation limit: 4th production site on Growth → 402 + upgrade prompt; staging URL activates without consuming a slot.
8. Kill the API (maintenance mode) → plugin keeps advanced features from cache for 72 h, shows "reconnecting" notice, never fatals.
9. Cancel at period end → access until period end → locked after; Reactivate flow restores.
10. Rate limits: hammer `plugin/validate` with bad keys → 429 + no timing oracle.
11. `email_logs` reviewed: every lifecycle email fired once.
12. Load `docker-compose.hostinger.yml` stack from scratch on the VPS: migrations, seeder, queue worker, scheduler cron, `storage:link`, Stripe webhook registered, DNS/SSL for the three domains.

---

## 8. Suggested delivery order & sizing

| Order | Work | Est. size |
|---|---|---|
| 1 | Phase 0 (hardening) | S — 1–2 days |
| 2 | Phase 1 (license core + tests) | L — 4–6 days |
| 3 | Phase 3 (plugin SaaS layer — parallel with 4/5) | L — 4–6 days |
| 4 | Phase 2 (dashboard UI) | M — 3–4 days |
| 5 | Phase 4 (emails) | M — 3–4 days |
| 6 | Phase 5 (marketing/docs) | S–M — 2–3 days |
| 7 | Phase 6 (verification) | M — 2–3 days |

Decisions locked by this plan (flag if you want them changed): 7-day trial; 4 public plans with Growth featured; license key in front of HMAC creds; one public plugin (connector retired from distribution); 72 h plugin grace; 7-day payment-failed grace.
