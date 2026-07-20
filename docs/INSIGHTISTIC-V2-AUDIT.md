# Insightistic V2 — Baseline Audit

Milestone 1 baseline per the Insightistic Complete App master prompt. Snapshot
as of the repo-separation + Milestone-1 work landing (see git log for exact
commits). This is a **point-in-time audit**, not a living document — re-run
the checks below before starting later milestones if meaningful time has
passed.

## 1. Repository map (post-separation)

Three repos, cleanly separated (see each repo's `README.md`):

| Repo | Contents | Version(s) |
|---|---|---|
| [`insightistic-app`](https://github.com/Shubochandrosarker/insightistic-app) | `insightistic-api/` (Laravel 12 API), `insightistic-app/` (Next.js 15 / React 19 dashboard) | API: unversioned overlay; App: `0.1.0` |
| [`insightistic-website`](https://github.com/Shubochandrosarker/insightistic-website) | `insightistic-marketing/` (theme), `formistic/` (lead-capture plugin), `insightistic-connector/` (legacy WP connector) | Theme `1.1.0`, Formistic `2.0.5`, Connector `0.1.0` |
| [`insightistic`](https://github.com/Shubochandrosarker/insightistic) | The one canonical, license-activated WordPress plugin | `4.4.0` |

The master prompt's "obsolete embedded plugin" concern (§1, gap #14) is
resolved: `insightistic-website` used to carry a stale, unrelated `insightistic/`
v1.0.0 stub (5 classes, WooCommerce-sync only — a different, much earlier
concept build that only shared a name with the real product) — deleted. The
real v4.4.0 plugin has never existed as a duplicate anywhere; it only ever
lived in the `insightistic` repo.

`insightistic-api/` has no `composer.json`/`vendor/` by design — it's an
overlay applied onto a fresh `laravel new` scaffold on the server (see its
Dockerfile). This means **no `composer validate` or `php artisan test` is
runnable from a plain checkout** — verification here is `php -l` plus manual
review against sibling code, noted per-PR throughout this project.

## 2. Route inventory (`insightistic-api/routes/api.php`)

- **Public auth**: register, login, forgot/reset password, OAuth (Google/Microsoft/GitHub) — `throttle:auth`.
- **Authenticated app** (`auth:sanctum` [+ `tenant`]): organization, team, billing (Stripe checkout/portal/cancel/reactivate), licenses, notifications, brand settings, sites, analytics (overview/revenue/orders/products/customers/refunds/compare), insights, reports.
- **Super Admin** (`auth:sanctum` + `super_admin`): overview, system-health, organizations, users, sites, subscriptions, sync-logs, reports, usage — **read/list only**, confirming gap #8 (no create/edit/archive/entitlement-override/impersonation yet — that's Milestone 6).
- **Plugin** (`connector.auth`, i.e. HMAC): `activate` (public+throttled), `validate`/`features`/`deactivate`, **`ai-insights`** and **`cloudflare-traffic`** (both added this project — closes gap #2, the plugin's dead routes).
- **Connector v1** (`connector.auth`): `ping`, `handshake`, `site-health`, `orders/bulk`, `products/bulk`, `customers/bulk`, `sync-complete`. **No v2 yet** — confirms gap #3/§7.2 is still open (Milestone 3).
- **Stripe webhook**: unauthenticated, signature-verified.
- **Reports**: `GET /reports/{report}/pdf` and `/html` — signed, unauthenticated (fixed this project; previously a raw public-disk URL, see PR history).

## 3. Database table inventory (14 migrations, 23 tables)

`personal_access_tokens` (Sanctum) · `plans` · `organizations` · `organization_users` · `sites` · `site_user_access` · `subscriptions` · `usage_counters` · `brand_settings` · `sync_logs` · `wc_orders` · `wc_order_items` · `wc_products` · `wc_customers` · `metric_snapshots` · `alerts` · `ai_insights` · `client_reports` · `report_schedules` · `licenses` · `license_activations` · `plugin_events` · `email_logs` · `notifications`.

Confirms gap #15: **no `traffic_daily`, `seo_daily`, `performance_runs`, `engagement_daily`, `broken_link_snapshots`, or `data_source_connections` tables exist** — the schema is WooCommerce-centric, exactly as the master prompt describes. `alerts` and `report_schedules` tables already exist (migrated but likely thin — worth confirming their column shape before Milestone 5 builds on them).

## 4. Frontend route inventory (`insightistic-app/app/`)

Existing dashboard routes (from `insightistic-app/app/dashboard/*`) include at minimum: overview, reports, licenses (per screenshots seen this session), billing, branding (white-label), team, settings, site health. **No dedicated Traffic, SEO, Performance, Integrations, Alerts, or Content pages exist yet** — confirms gap #5. Milestone 4 scope.

## 5. Plugin-to-API contract map

| Plugin call (`Insightistic_Saas_Client`) | Laravel route | Status |
|---|---|---|
| `activate()` | `POST /api/plugin/activate` | ✅ existing |
| `validate()` | `POST /api/plugin/validate` | ✅ existing |
| `features()` | `GET /api/plugin/features` | ✅ existing |
| `deactivate()` | `POST /api/plugin/deactivate` | ✅ existing |
| `ai_insights()` | `POST /api/plugin/ai-insights` | ✅ added this project |
| `cloudflare_traffic()` | `POST /api/plugin/cloudflare-traffic` | ✅ added this project |
| Connector v1 (`orders/bulk` etc.) | `/api/connector/v1/*` | ✅ existing |

**No dead client methods remain** as of this audit — gap #2 fully closed. §5.5 of the master prompt ("add a contract test that fails if a plugin method references a missing endpoint") is **not yet implemented** — no automated fixture exists tying the plugin's client methods to Laravel's route list. Deferred; flagged as a Milestone 1 follow-up (see §9 below).

## 6. AI provider architecture

- **`insightistic-api`**: single global, server-side `AiProvider` (`OpenAiProvider` / `WpisticGatewayProvider` / `RuleProvider`, bound once in `AppServiceProvider`). No per-organization BYOK, no per-call billing — only a monthly quota (`UsageService`). No OpenRouter/Groq support.
- **`insightistic` plugin**: the opposite — full BYOK already exists for five providers (OpenAI, Gemini, **OpenRouter**, Claude, **Groq**), each with an encrypted per-site key and model selector. This is *ahead* of the master prompt's assumption.
- Gap: no platform-hosted, usage-billed AI option, and no curated model marketplace UI on either side. Out of this project's milestone scope (was covered separately, see prior session's plugin/theme-audit + AI-marketplace priority discussion) — tracked independently of the master-prompt milestones.

## 7. Plugin/theme + data-source inventory

Confirmed: **no code anywhere inventories a connected site's installed WordPress plugins or active theme.** The closest existing pattern is capability-detection (`function_exists('wc_get_orders')` for WooCommerce, `Insightistic_Cloudflare::is_available()` for Cloudflare) — implicit auto-detection, not a real inventory or a user-facing "choose your data sources" toggle. This is a real gap, not covered by the master prompt's milestones directly (it maps most closely to §7.4's `data_source_connections` state table, which Milestone 3 should build toward).

## 8. Formistic shortcode contract (gap #10, #11 — fixed this project)

Confirmed and fixed: the marketing theme called a nonexistent `[formistic_form]` shortcode and treated Customizer-configured form "ids" as free-text slugs. Formistic v2.0.5 actually registers `wpistic_form` (numeric CPT post ID), `wpistic_contact_form` (fixed, no ID), and `wpistic_formistic_newsletter` (fixed, no ID). Fixed in `insightistic-website`: `insightistic-marketing/inc/helpers.php` now calls the real shortcodes, validates any builder-form ID against `get_post_type()`/`get_post_status()` before rendering, and the Customizer control for builder-form slugs (`agency`, `ltd`, `early_access`, `support`) is now a dropdown of real published Formistic forms instead of a free-text field. Setup-missing states show a technical message to `edit_theme_options` admins only; visitors see nothing broken-looking.

Not yet done (Milestone 7 scope): the §16.3 idempotent form-provisioning wizard that auto-creates Contact/Demo-request/Agency-inquiry/Newsletter forms on first activation. Today an admin must build those forms manually in Formistic and pick them in the Customizer.

## 9. Build/test status

- `php -l` across all `.php` files in all three repos: **clean, zero syntax errors** (re-verified this pass).
- `insightistic-api`: no `composer.json`/`vendor/` in the overlay repo — no `composer validate`/`phpunit` runnable from this checkout by design.
- `insightistic-app` (Next.js): **not built/linted this pass** — `node_modules` isn't installed in this environment and a fresh `npm install` + `next build`/`next lint` is a multi-minute operation deferred to Milestone 8's real CI setup rather than run ad hoc here. Flagging honestly rather than skipping silently.
- WordPress PHPCS: not run this pass for `insightistic-website`'s three plugins/theme (no `phpcs.xml`/ruleset found in that repo at audit time); the canonical `insightistic` plugin repo has its own PHPCS config and was previously verified clean (prior session).
- No automated plugin-to-Laravel contract test exists yet (see §5).

## 10. Confirmed defects (master prompt §3), current status

| # | Defect | Status |
|---|---|---|
| 1 | Separate legacy connector-token workflow still exposed | Open — Milestone 2 (de-emphasize from onboarding, not remove; backward compat) |
| 2 | Plugin calls `ai-insights`/`cloudflare-traffic`, no matching routes | **Fixed** (prior session) |
| 3 | Cloud sync only sends WooCommerce + basic site health | Open — Milestone 3 (connector v2) |
| 4 | GA4/GSC/PageSpeed/engagement/404/data-source-health not ingested by SaaS | Open — Milestone 3 |
| 5 | No dedicated Traffic/SEO/Performance dashboard areas | Open — Milestone 4 |
| 6 | AI generation not contextual per-page | Open — Milestone 5 |
| 7 | `InsightGenerator` is ecommerce-only, not multi-domain | Open — Milestone 5 |
| 8 | Super Admin is read/list-mostly | Confirmed this audit (§2) — Milestone 6 |
| 9 | Super Admin lacks CRUD/entitlement/impersonation/audit ops | Open — Milestone 6 |
| 10 | Marketing theme calls nonexistent Formistic shortcode | **Fixed** this project |
| 11 | Theme expects slug-like form IDs, not numeric post IDs | **Fixed** this project |
| 12 | Marketing CSS/theme.json palette inconsistency (dark tokens under a "light" shell) | Open — Milestone 7 |
| 13 | Pricing/plan naming differs across seeders/theme/UI | Partially fixed (real `free` plan added, prior session); full single-source-of-truth manifest still open — Milestone 7 / §13.7 |
| 14 | App repo can accidentally package the wrong plugin | **Fixed** (repo separation, prior session — stale stub deleted, duplicates removed) |
| 15 | WooCommerce-centric data model can't hold traffic/SEO/performance history | Confirmed this audit (§3) — Milestone 3 |
| 16 | Build/contract/migration/E2E verification not a release gate | Open — Milestone 8 |

## 11. Migration risks flagged for later milestones

- **Milestone 2** (single-key journey, license state machine): existing `licenses`/`license_activations` rows must not be touched destructively — any status-precedence rework must be additive/backward-compatible per the master prompt's §4.2. No migration should run in this project without a reversible `down()`.
- **Milestone 3** (new traffic/SEO/performance tables): high-cardinality dimension/query-snapshot tables need deliberate indexing and plan-aware retention pruning from day one — these are exactly the tables that silently become unqueryable at scale if indexes are an afterthought.
- **Milestone 6** (Super Admin destructive ops): archive/restore and the "last super admin" protection must exist *before* any admin CRUD ships — sequence matters here, not just presence.

## 12. Implementation checklist (carried into subsequent milestones)

- [x] Canonical plugin/release source decision + obsolete stub removed
- [x] Dead plugin routes repaired (`ai-insights`, `cloudflare-traffic`)
- [x] Real `free` plan added (single-source-of-truth manifest still incomplete)
- [x] Formistic shortcode/form-ID contract fixed
- [x] Report download security hardened (signed routes)
- [x] `php -l` clean across all three repos
- [ ] Plugin-to-Laravel contract test (fails CI if a client method has no matching route)
- [ ] Next.js build/lint verified in this environment or a real CI pipeline
- [ ] Milestones 2–8 (tracked separately; see project task list)

---
*Generated as part of Milestone 1. This file should be re-read, not
re-derived from memory, before starting Milestone 2+ work in a future
session — routes, tables, and defect status will have moved on.*
