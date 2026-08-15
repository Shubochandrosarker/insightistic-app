# Changelog

All notable changes to the Insightistic platform (app + API + plugin).

## [Unreleased] — Advanced SaaS + AI Knowledge Base upgrade (2026-07)

### Added — Semantic Metric Registry (Insightistic VNext P0)
- **`App\Metrics\MetricRegistry`** — one versioned, canonical catalog of 19
  metric definitions (id, label, unit, source, table/column, formula,
  freshness, caveats) covering revenue, orders, AOV, refunds, products,
  customers, GA4, GSC and Core Web Vitals. Metadata only — computation stays
  in the existing `MetricsAssembler`/`MetricSnapshotService`/
  `ContextAssembler` services. See `docs/SEMANTIC-METRICS.md`.
- `GET /api/metrics` (+ `?source=`) and `GET /api/metrics/{id}` — read-only,
  authenticated-tenant access to the catalog for the dashboard/Copilot.
- `php artisan metrics:list` for manual/CI verification.
- **Fixed order-status classification drift**: `MetricsAssembler`,
  `MetricSnapshotService`, `CustomerIntelligence`, `ProductIntelligence` and
  `AcquisitionIntelligence` each hand-typed their own paid/failed status
  array; two of the five silently disagreed (on-hold orders included in
  revenue KPIs but excluded from customer/product intelligence). All five
  now read from `App\Metrics\CommerceStatus::REVENUE_PAID` /
  `::CONFIRMED_PAID` / `::FAILED`. No metric value changed — see
  `docs/CONVERSATIONAL-ANALYTICS-AUDIT.md` for the full before/after table
  and the still-open product decision this surfaced.
- `docs/CONVERSATIONAL-ANALYTICS-AUDIT.md` — Phase 0 current-state audit for
  the VNext roadmap (analytics/AI/tenant-isolation/automation surfaces).

### Added — Safe WooCommerce Query Engine (Insightistic VNext P1)
- **`App\Query\SafeWooQueryEngine`** — typed plan → validated →
  compiled-via-query-builder-only → defense-in-depth-guarded →
  tenant-scoped → concurrency/timeout-budgeted → executed read-only →
  normalized evidence. No plan-supplied string is ever concatenated into
  SQL; table/column names are hardcoded literals in
  `WooCommerceQueryCompiler`, never runtime-derived. See
  `docs/SAFE-QUERY-ENGINE.md`.
- `POST /api/sites/{site}/analytics/query` — executes a plan requesting up
  to 5 registered WooCommerce metrics, an optional `product` dimension
  breakdown, and an optional previous-period comparison. Behind the
  existing `site.access` tenant-scope middleware.
- **Read-only Postgres execution identity**: a genuinely separate,
  SELECT-only role (`php artisan db:provision-readonly-role`), verified in
  CI by directly attempting INSERT/UPDATE/DELETE/ALTER and reads of
  out-of-allowlist tables against the real role and confirming Postgres
  itself rejects them — not just an application-layer check. Fails closed
  in production if unconfigured; falls back to the default connection with
  a logged warning in local/dev.
- Query budgets: 8s statement timeout (real Postgres `SET LOCAL
  statement_timeout`, proven to cancel and to never leak onto a later
  query), a 500-row breakdown cap, and a per-organization 3-in-flight
  concurrency limit (429 when exceeded).
- `docs/SECURITY-TEST-MATRIX.md` — the full adversarial SQL / tenant-
  isolation / concurrency test matrix, PASS or explicitly NOT VERIFIED per
  row, nothing hidden.
- Tests: 97 new (unit + feature), full existing suite (363 tests) green,
  zero regressions.

### Added — Analytical Evidence Ledger (Insightistic VNext P2)
- **`App\Query\EvidenceLedger`** persists every Safe Query Engine execution
  — success or failure — as one `evidence_runs` row: the validated plan,
  each metric's *resolved* registry version, a reproducibility hash
  (`sha256(site_id | compiled SQL | bindings)`), timing, the site's own
  data-freshness (independent of the query's requested period), and
  outcome. A separate table from the existing `ai_runs` on purpose — that
  table's `ai_message_id` is a `NOT NULL` FK into Copilot chat messages and
  its rows feed `UsageService`'s AI-usage quota metering; most query-engine
  runs involve no AI call and no chat message at all. See
  `docs/ANALYTICAL-EVIDENCE.md`.
- `SafeWooQueryEngine` itself stays a pure computation with no storage side
  effect (P1's documented boundary preserved); `AnalyticsQueryController`
  now calls `EvidenceLedger::recordSuccess()`/`recordFailure()` explicitly
  after every run and returns `evidence_run_id` in the response.
- `GET /api/sites/{site}/evidence` (`?status=`, `?run_type=`, `?limit=`) and
  `GET /api/sites/{site}/evidence/{run}` — the "Evidence drawer" read
  surface. Same tenant-scope pattern as every other site-scoped route; a
  run belonging to a different site or organization 404s.
- **Fixed a consent gap flagged by the P0 audit**: `InsightGenerator`
  (legacy insight summarization + the nightly proactive-insights job) called
  its injected `AiProvider` regardless of an organization's
  `allow_external_ai` setting — only `CopilotChat`'s chat path actually
  respected that consent flag. Extracted the check into
  `App\Services\Ai\ProviderResolver` (forces the deterministic `$0`
  `RuleProvider` when consent is off) so both `InsightGenerator` and P3's
  `AskInsightisticService` enforce the identical rule.
- Tests: `EvidenceLedgerTest`, `EvidenceEndpointTest`,
  `InsightGeneratorConsentTest`, plus a permanent regression case added to
  `NestedRouteBindingTest` for `EvidenceController::show()` (a positional
  route-parameter bug caught only by a full-suite run — see that test
  file's docblock).

### Added — Ask Insightistic V2 (Insightistic VNext P3)
- `POST /api/sites/{site}/ask` — a free-text question in, a cited,
  confidence-scored answer out. Runs through the exact same
  `PlanValidator`/`SafeWooQueryEngine`/`EvidenceLedger` pipeline as a
  hand-authored `analytics/query` plan; a question is only a different way
  of producing that plan's raw-array shape, never a parallel query path.
  See `docs/ASK-INSIGHTISTIC.md`.
- **`App\Services\Ask\QuestionPlanBuilder`** — deterministic, keyword-based
  question → plan mapping against `MetricRegistry` (no AI involved in
  understanding the question). A question matching no known metric keyword
  gets an honest `422 question_not_understood`, not a guess.
- **`App\Services\Ask\ConfidenceScorer`** — `high`/`medium`/`low` from data
  freshness, keyword-match ambiguity, and result shape. Never from a
  model's own self-reported confidence, which is not evidence.
- **`App\Services\Ask\AnswerSynthesizer`** — a fully deterministic
  answer contract (mirroring `CopilotChat`'s existing shape) built only
  from `QueryResult`'s real numbers; an optional AI rephrase step may touch
  narrative text only (`direct_answer`, `key_finding`, `why_it_matters`,
  `recommended_actions`). `confidence`, `supporting_metrics` and
  `citation_refs` are never read from the AI response under any
  circumstance — `tests/Unit/Ask/AnswerSynthesizerTest.php` proves this
  against a provider that tries to smuggle a fabricated number and a fake
  confidence through. Skipped entirely (not just discarded) when
  `ProviderResolver` resolves to the rule-based fallback, so no request is
  even sent when external AI is off for the organization.
- **`App\Services\Ask\ChartSpecBuilder`** — reshapes `QueryResult` into a
  `kpi` (aggregate) or `bar` (breakdown) chart spec; computes nothing,
  copies numbers already produced by the query engine.
- `evidence_runs`' `ai_used`/`ai_model` columns (added ahead of use in P2's
  migration) are now actually populated, by Ask's runs (`run_type = "ask"`)
  — always `false`/`null` for a plain `query` run, which never calls AI.
- Tests: `QuestionPlanBuilderTest`, `AnswerSynthesizerTest`,
  `AskInsightisticEndpointTest` (tenant isolation, no-match handling,
  breakdown routing, AI-consent-off behavior).

### Added — Multi-source Analytics (Insightistic VNext P4)
- **`App\Query\Adapters\Ga4QueryCompiler`** and **`GscQueryCompiler`** —
  two new source adapters mirroring `WooCommerceQueryCompiler`'s shape
  exactly: hardcoded `ALLOWED_TABLES` and base-metric-id → column maps,
  every SELECT built via the query builder only. GA4 reads `traffic_daily`
  scoped to `source = 'ga4'` (that table also stores a first-party
  `connector_engagement` fallback per day — never summed into a GA4-scoped
  metric). GSC reads `seo_daily`, mixing `SUM` (clicks/impressions) and
  `AVG` (`organic_avg_position` — a ranking position, not a period total)
  in one compiled query.
- `PlanValidator` now accepts `ga4`/`gsc` alongside `woocommerce`.
  `pagespeed` stays rejected on purpose: its metrics are "latest run"
  values, a different compilation shape this engine doesn't support yet —
  an honest `unsupported_source`, not a silent wrong answer.
- `DerivedMetricCalculator` gains `conversion_rate` (`ga4_conversions /
  ga4_sessions`) and `organic_ctr` (`organic_clicks /
  organic_impressions`).
- **`SafeWooQueryEngine`** keeps its P1 name (already the tested entry
  point every caller depends on) but now dispatches internally by
  `$plan->source` to the matching adapter. A dimension breakdown stays
  WooCommerce/`product`-only, since neither GA4 nor GSC metrics declare an
  `allowedDimensions` entry.
- The read-only Postgres role now grants `SELECT` on the union of every
  registered adapter's `ALLOWED_TABLES` (`ReadOnlyRoleProvisioner::allowedTables()`)
  — `traffic_daily` and `seo_daily` join the P1 WooCommerce tables.
- Tests: `Ga4QueryCompilerTest`, `GscQueryCompilerTest`, new
  `SafeWooQueryEngineTest` cases (ga4 aggregate scoped to `source = 'ga4'`,
  gsc SUM+AVG aggregate, cross-tenant isolation for both, `pagespeed`
  rejection), a `ReadOnlyIdentityTest` case proving the role can actually
  read the two new tables. Updated `docs/SAFE-QUERY-ENGINE.md` and
  `docs/SECURITY-TEST-MATRIX.md`.

### Added — Saved Insights (Insightistic VNext P5)
- **`App\Models\SavedInsight`** — a named, reusable `SemanticQueryPlan` a
  user saves once and re-runs on demand. `POST/GET/PATCH/DELETE
  /api/sites/{site}/saved-insights[/{id}]` plus `POST .../{id}/run`. See
  `docs/SAVED-INSIGHTS.md`.
- Create/update validate the submitted plan through the exact same
  `PlanValidator` `analytics/query` and Ask use — a saved insight can never
  store a plan that direct query wouldn't already accept, and gets the
  identical error codes when it doesn't.
- `metric_versions_json` is frozen at save time via
  `EvidenceLedger::metricVersionsFor()` (now public, shared with
  `recordSuccess()`'s own use of it). `chart_spec_json` stores only the
  **structural** chart type (`{"type":"kpi"}` / `{"type":"bar",...}`) via
  the new `ChartSpecBuilder::typeFor()` — never computed values, which
  would go stale the instant underlying data changes.
- `POST .../run` re-executes the stored plan through `SafeWooQueryEngine`
  and records to the Evidence Ledger with `run_type = "query"` — the same
  path a direct query call uses — and updates `last_run_at`. Never returns
  a cached number; every run is a real query.
- `scope`: `"organization"` (default, org-wide visible) or `"private"`
  (owner only). Fetching/updating/deleting/running someone else's private
  insight 404s, matching `EvidenceController`'s cross-org convention —
  never a 403 that would confirm something exists.
- `thresholds_json` column added but **inert** — reserved schema for a
  future alerting feature, not evaluated by anything in this PR (documented
  explicitly, not silently implied as live).
- Tests: `SavedInsightEndpointTest` (create/validate/list-visibility/
  update-versioning/delete/run/run-failure/tenant-isolation), plus a
  `NestedRouteBindingTest` regression case for `SavedInsightController::update()`.

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
- **Insight lifecycle.** Insights can be saved, dismissed and restored, and
  exported as task-ready markdown. Saved and dismissed are timestamps rather
  than `status` values, because they are orthogonal to how far through an
  insight you are — a thing can be read *and* saved, and "not now" is not
  "done". Dismissal hides without deleting, so nothing is unrecoverable.
- **Freshness is queryable.** `expires_at` was already being written into the
  generator's JSON contract but nothing could filter or sort on it there; it
  is now a real column, backfilled from the existing JSON, and insights past
  their window render as stale instead of quietly looking current.
- **Proactive generation** (`insightistic:insights-generate`, 05:00 daily).
  Insights previously only existed when someone pressed the button, so the
  feature meant to tell you something changed only spoke once you already
  suspected it had. Guarded three ways: plan limits are checked before every
  generation and the run stops at the limit rather than borrowing against the
  quota a user wants for their own questions; a type is skipped while a live
  insight of that type is still fresh; and evidence is assembled before
  metering, so a site with nothing to say costs nothing. A dismissed insight
  does not suppress the next cycle — the user said "not now", not "never".

### Observability (new)
Error tracking, distributed tracing, request/AI metrics and queue health,
surfaced at **Admin → Observability**. All of it is off by default: a fresh
install sends nothing to any third party until a DSN or collector endpoint is
deliberately configured.

- **Errors** — Sentry (`sentry/sentry-laravel`), inert without a DSN. Every
  payload passes through `EventScrubber` first: request bodies and cookies
  dropped wholesale, header and nested keys matching secret or PII fragments
  redacted, URLs keeping their shape but losing every query value. Error
  tracking is a data-exfiltration path nobody thinks of as one — an exception
  carries the request that caused it, and here that can be a Google refresh
  token, a Cloudflare API token, a license key or a customer record.
- **Tracing** — OpenTelemetry over OTLP/HTTP, requiring both `OTEL_ENABLED`
  and an endpoint, since an exporter with nowhere to send is just latency.
  Built lazily by hand rather than through the auto-instrumentation package,
  which needs `ext-opentelemetry` — that would trace on a developer laptop
  and silently do nothing in production.
- **Metrics** — request timings and AI spend pre-aggregated into hourly
  buckets per route and model. A per-request table would write millions of
  rows a day, and the questions this data answers are all bucket questions.
- **AI cost** — a decorator wraps whichever provider is bound, so insight
  generation and the rule-based fallback are measured too, not just Copilot.
  The provider recorded is the one that actually ran: a missing API key falls
  back to the rule provider, and a dashboard still claiming OpenAI would make
  "the AI got worse" impossible to diagnose.
- **Queue health** — works on both `database` and `redis`, because the
  Hostinger stacks and the production stack differ and Horizon covers only
  one of them. The signal is *oldest pending age*, not depth: 5,000 jobs that
  drain in a minute is healthy, three that have waited an hour is not, and
  depth alone cannot tell them apart. A silent worker with an empty queue is
  a warning rather than a page — idle and dead look identical.

Buckets are pruned nightly at 03:50, so observability data cannot become the
performance problem it was installed to detect.

Two bugs were found while building it:

- **A telemetry write could poison the caller's transaction.** These writes
  run inside whatever transaction the request is already in, and in
  PostgreSQL a failed statement aborts the entire transaction — so a
  telemetry error made every subsequent query fail with SQLSTATE 25P02, the
  measurement killing the thing it measured while reporting the wrong error.
  Writes are now wrapped in a savepoint, and `telemetry_ai_usage.organization_id`
  is deliberately not a foreign key (a failing FK check was the trigger, and
  `nullOnDelete` would have merged a departed customer's buckets into the
  platform-level rows rather than removing them).
- **`telemetry-prune --days=0` silently used the default.** `?:` treats the
  string `"0"` as falsy, so a nonsense window fell through to 30 days instead
  of being rejected.

### Advanced business intelligence (new)
Fourteen reports under `/sites/{site}/intelligence/*` and a new **Intelligence**
dashboard section, computed from data already synced — no new sources and no
stored derivations, so nothing can go stale against the orders it describes.

- **Customers** — RFM segmentation, monthly acquisition cohorts, lifetime value
  and repeat behaviour, and churn risk.
- **Products** — frequently-bought-together, stock cover and stockout dates,
  discount impact, and declining products.
- **Search** — CTR opportunities, content decay, cannibalization candidates.
- **Acquisition** — channel performance, traffic anomalies, and the
  speed/revenue relationship.

Four rules hold across all of them:

- **Recomputed, not trusted.** Commerce figures come from `wc_orders`, not the
  store's `total_sales` / `total_spent` counters — those never decrease on
  refund and count unpaid orders. Only `completed` and `processing` are
  revenue, so a refund actually moves every number on the page.
- **Relative to the site, not an industry.** RFM scores quintiles of this
  store's own customers; CTR gaps compare against this site's median CTR per
  position band; churn is measured against each customer's own median gap
  between orders. A fixed "90 days = churned" rule labels every customer of a
  slow-cycle store as lost, and a published CTR curve manufactures
  opportunities that are not there.
- **Floors before findings.** RFM needs 20 customers, anomaly detection 14
  days of history, correlation 10 paired days, product affinity 3
  co-occurrences. Below a floor the report answers `has_data: false` with a
  machine-readable `reason` and the UI says which kind of empty it is — "not
  enough customers yet" and "no orders at all" send people to different places.
- **Stated limits.** Channels are labelled last-click. Cannibalization is
  labelled candidates, because Search Console aggregates do not pair queries
  with pages. Speed-vs-revenue is labelled correlation, with the caveat kept
  on screen. Anomaly detection uses median absolute deviation rather than
  mean and standard deviation, so a launch-day spike cannot inflate the
  baseline that is meant to catch it.

Cohort rows are only as wide as the months each cohort has actually lived
through. Padding them to a uniform width would print 0% for months that have
not happened, and a reader cannot tell that apart from a cohort that never
came back.

Churn risk is the only report naming individual customers, so it is restricted
to owner/admin/analyst — a `client_viewer` gets 403 from the route, not a
hidden button.

### Fixed — nested route parameters bound the wrong record
- Controllers on `sites/{site}/…/{id}` routes read the Site off the request
  and declared only the second id. Laravel injects route parameters
  positionally, so the **site** id was landing in the record id: saving,
  dismissing or actioning an insight, resolving or reopening an alert, and
  updating or deleting a report schedule all operated on whichever record
  happened to share the site's id. It is invisible on a single-site tenant
  whose first record is id 1 — the shape of most dev data — which is why it
  survived. All three controllers now declare `{site}` first, and
  `NestedRouteBindingTest` pins each endpoint against a target whose id is
  guaranteed to differ from its site's.
