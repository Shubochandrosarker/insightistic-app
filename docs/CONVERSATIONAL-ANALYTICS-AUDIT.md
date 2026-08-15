# Conversational Analytics — Current-State Audit

Phase 0 audit for the Insightistic VNext roadmap (Semantic Metric Registry →
Safe WooCommerce Query Engine → Analytical Evidence Ledger → Ask Insightistic
V2 → …). Snapshot as of the P0 (Semantic Metric Registry) PR. This is a
**point-in-time audit** — re-run the relevant sections before starting each
later phase (P1 Safe Query Engine especially: several findings here are load-
bearing for how that phase must be designed).

Classification per section: **IMPLEMENTED** / **PARTIAL** / **MISSING** /
**UNSAFE-TO-REUSE**, per `docs/12-INGESTION-MANIFEST.md`'s retrieval rule —
this file records current repo/code facts, not the proposed architecture.

## 1. Analytics/metrics surface — PARTIAL

- `MetricsAssembler` (`insightistic-api/app/Services/MetricsAssembler.php`)
  is the existing single source of truth for commerce KPIs — explicitly
  shared by `AnalyticsController`, `InsightGenerator`, and `ReportBuilder`.
  Computes revenue, orders, refunds (count), products_sold, new/returning
  customers, failed_orders, AOV (derived), top products, top customers,
  daily series.
- `MetricSnapshotService` builds the daily `metric_snapshots` rollup
  `MetricsAssembler` reads.
- `EngagementDaily`, `SeoDaily`, `TrafficDaily` are separate daily-grain
  tables (first-party events, GSC, GA4) with their own models — no shared
  metric-definition layer with commerce before this PR.
- Core Web Vitals live in `PerformanceRun`/`PerformanceIssue`, surfaced via
  `ContextAssembler::performance()` — not covered by `MetricsAssembler`.
- `ContextAssembler` is a second, AI-context-specific aggregation layer that
  independently re-sums some of the same underlying data (e.g. traffic
  sessions) rather than reusing a shared metric helper.
- **Before this PR: no metric registry, no metric versioning, no metric
  metadata (units/format/freshness/caveats) model existed anywhere.** Metric
  meaning was implicit in code (array keys, `selectRaw` aliases).
- **This PR adds** `App\Metrics\MetricRegistry` + `App\Metrics\Catalog\*` —
  a versioned catalog of 19 metric definitions across all 4 connected
  sources (woocommerce/ga4/gsc/pagespeed), exposed at `GET /api/metrics`.
  It documents existing computations; it does not yet replace them (see §2).

### Order-status classification drift — found and fixed

Auditing every consumer of "is this order paid" surfaced that the same
classification concept had been hand-typed into **five** separate places,
and two of them disagreed with the other three:

| File | Old list | Included on-hold? |
|---|---|---|
| `MetricsAssembler::PAID_STATUSES` | `['completed','processing','on-hold']` | ✅ |
| `MetricSnapshotService::$paidStatuses` | `['completed','processing','on-hold']` | ✅ |
| `CustomerIntelligence::PAID_STATUSES` | `['completed','processing']` | ❌ |
| `ProductIntelligence::PAID_STATUSES` | `['completed','processing']` | ❌ |
| `AcquisitionIntelligence::performanceRevenueCorrelation()` (inline) | `['completed','processing']` | ❌ |

The two intelligence-side omissions are **not** an accident — both classes'
own docblocks explain a deliberate "money actually taken" rationale for
excluding payment-pending on-hold orders from RFM/cohort/LTV/affinity
scoring. This PR does not silently pick a winner. It introduces
`App\Metrics\CommerceStatus` with two named, documented constants —
`REVENUE_PAID` (includes on-hold, used by the two revenue-KPI services) and
`CONFIRMED_PAID` (excludes on-hold, used by the three intelligence
services) — and repoints all five call sites at it. **Every metric value
produced by these five services is unchanged** (see
`tests/Unit/Metrics/MetricRegistryTest.php`'s drift-regression tests); what
changed is that the two definitions can no longer silently diverge further
without a reviewed, deliberate edit to `CommerceStatus`.

**Open product decision (not resolved by this PR):** should revenue KPIs and
customer/product intelligence agree on one paid-status definition going
forward? Flagging for whoever owns that call before P1 builds a query
engine that needs one canonical answer.

Also noted, not fixed (out of scope for P0, flagged for follow-up):
`AcquisitionIntelligence::performanceRevenueCorrelation()` computes revenue
as `total - refund_total` (net of refunds), while `MetricsAssembler`'s
`net_revenue` does not subtract `refund_total` at all. A third, more subtle
revenue-definition disagreement — worth resolving in the same pass as the
status-list decision above.

## 2. WooCommerce data access — IMPLEMENTED (safe today), no query engine exists yet

- Sync path: WordPress plugin → HMAC-signed `connector/v1/*` routes →
  `SyncController`/`SyncV2Controller` → validated (`$request->validate()`) →
  keyed `upsert()`/`insert()` via Eloquent. **No raw SQL, no user/AI-supplied
  SQL anywhere in the ingestion path.**
- All `DB::raw`/`selectRaw`/`whereRaw` usage in analytics/intelligence
  services is hardcoded, developer-authored SQL — never built from user or
  AI input.
- `KnowledgeRetriever` is the one place raw SQL touches user-controlled
  input (the knowledge search query string) — fully parameterized with `?`
  bindings, never string-concatenated.
- **No whitelisted-table list, no query-builder abstraction, and no
  semantic-query-plan concept exists anywhere in this codebase today.**
  This is a clean slate for P1 (Safe WooCommerce Query Engine), not a
  retrofit of an unsafe existing mechanism — there is nothing today that
  could execute an AI-composed query, safely or otherwise.
- This PR's `MetricDefinition::$table`/`$column` fields exist specifically
  to seed P1's allowlist: every metric already declares the one table/column
  it's allowed to read.

## 3. AI provider abstraction — IMPLEMENTED

- `AiProvider` interface: `generate(system, context, identityId): ?array` +
  `model(): string`.
- Three implementations: `OpenAiProvider`, `WpisticGatewayProvider` (exists,
  HMAC-signed calls to the `wpistic-ai-gateway` Worker's `/chat` endpoint,
  `analytics-pro` agent by default), `RuleProvider` ($0 deterministic
  fallback).
- Provider selection is config-driven in `AppServiceProvider::register()`,
  wrapped by `MeteredAiProvider` for latency/cost telemetry — reports the
  provider that **actually ran**, not the configured one.
- `ContextAssembler` builds authorized, server-side evidence bundles;
  `CopilotContextBuilder` composes evidence + org-scoped knowledge retrieval
  into a prompt with an explicit prompt-injection-defense envelope and PII
  redaction before anything reaches an external model.
- **Consent gating exists but has a gap.** `organizations.allow_external_ai`
  is read by `CopilotChat::mode()` — if `false`, Copilot is forced to the
  local `RuleProvider`. `InsightGenerator`/`GenerateProactiveInsights` call
  `AiProvider` directly via DI and do **not** appear to check
  `allow_external_ai` — the consent gate covers the Copilot chat path only,
  not insight generation. **Flag for P3 (Ask Insightistic V2):** any new
  AI-driven query/synthesis path must check this flag explicitly; do not
  assume it's inherited for free.

## 4. Tenant isolation — IMPLEMENTED, two parallel mechanisms

- `BelongsToOrganization` trait: global Eloquent scope on `organization_id`,
  set from `Tenancy` (per-request singleton, populated by
  `ResolveOrganization` middleware from `X-Organization-Id` or the user's
  first org). Used by AI/knowledge/site/subscription/license models (27
  files).
- `MetricSnapshot`, `WcOrder/Product/Customer`, `TrafficDaily/SeoDaily/
  EngagementDaily` are **not** org-scoped via the trait — scoped manually by
  `site_id` in every query, on the documented rationale that a site belongs
  to exactly one tenant. This is a second, parallel convention with no
  global-scope safety net — **P1's query engine must enforce site/org scope
  server-side itself, not assume a model-level scope exists for every
  table it reads.**
- `EnsureSiteAccess` middleware enforces per-request site access (404, not
  403, for out-of-org sites), including `client_viewer`-role restrictions.
- `KnowledgeRetriever` re-asserts tenancy at the SQL layer as defense in
  depth — the pattern P1's query engine should follow, not just middleware.

## 5. Insights/reports — IMPLEMENTED

- `InsightGenerator` has legacy (`generate()`, flat contract) and contextual
  (`generateContextual()`, `ContextAssembler::TYPES`-driven, richer
  evidence/confidence contract) paths.
- `AiInsight` lifecycle: `saved_at`/`dismissed_at`/`expires_at`/
  `actioned_at`/`status`. No explicit `schema_version` field on the insight
  itself — worth adding when P5 (Saved Insights) formalizes versioned
  definitions.
- `GenerateProactiveInsights` runs nightly, quota- and freshness-gated.

## 6. Knowledge/citations — IMPLEMENTED

- `AiCitation` already models both knowledge-doc citations and live-
  analytics-evidence citations (`source_type` includes
  `woocommerce|ga4|gsc|pagespeed|cloudflare|alerts|reports`) under one
  table — a useful existing shape for P2 (Analytical Evidence Ledger) to
  extend rather than replace.
- `KnowledgeRetriever`: hybrid FTS + pgvector retrieval (degrades gracefully
  to FTS-only without pgvector), every query's first predicate is
  `organization_id = ?` — the de-facto RAG namespace today is one per
  organization.
- Retrieved content is treated as untrusted and wrapped in a delimited,
  prompt-injection-defended envelope before reaching a model.

## 7. Queues/automation — PARTIAL

- Solid job/schedule infrastructure exists (`RebuildSiteSnapshots`,
  `RunPageSpeedTest`, `SyncDataSource`, `IngestKnowledgeSource`,
  `BuildOrganizationExport`), all `withoutOverlapping()`, retry/backoff
  policies per job, terminal-vs-retryable failure classification.
- HMAC signing exists **inbound only** (`connector.auth`) and for the
  outbound AI-gateway call. **No outbound webhook-delivery mechanism**
  (Insightistic calling a customer-supplied URL with a signed payload)
  exists anywhere — P6 (Automation Studio) is a genuinely new subsystem, not
  a retrofit.
- No automation-studio concept (trigger→condition→action chains) exists.
  Closest analog: `AlertDetector` (threshold rule engine) and
  `ReportScheduleController` (fixed-cadence report delivery only, no
  branching).

## 8. Routes — IMPLEMENTED

All analytics/copilot/insight routes sit behind
`auth:sanctum + tenant + restrict.impersonation`, with `site.access`
wrapping every `sites/{site}/...` route and `role:...` gating on
sensitive/mutating endpoints. Connector (plugin→API) and the AI-gateway path
each use their own HMAC auth, separate from Sanctum. See
`insightistic-api/routes/api.php`.

## 9. Existing tests — PARTIAL

Solid Feature-test coverage for connector HMAC, Copilot, knowledge tenant
isolation, privacy, licensing, OAuth security, Stripe webhooks. **Gaps found
and addressed by this PR:**

- No `MetricsAssembler`/general analytics-controller test existed —
  `tests/Unit/Metrics/MetricRegistryTest.php` now covers the registry
  itself and the status-list drift regression across all 5 affected
  services.
- No general cross-tenant analytics-isolation test beyond
  `KnowledgeTenantIsolationTest.php` (knowledge-only) — **still open**, not
  addressed by this PR; recommended before P1 ships a query engine.
- No test proving `InsightGenerator`/proactive insights honor
  `allow_external_ai` — **still open**, flagged in §3 for P3.

## Summary for P1 (Safe WooCommerce Query Engine)

P1 is building on a genuinely clean slate for query execution (no existing
unsafe mechanism to harden), but must independently implement:

1. Server-side org/site scope enforcement (no global-scope safety net for
   the analytics tables — see §4).
2. A read-only DB identity, regardless of what the app-layer validator does.
3. The table/column allowlist — this PR's `MetricDefinition::$table`/
   `$column`/`$allowedDimensions`/`$allowedFilters` fields are designed to
   seed exactly this allowlist directly from the registry.
4. A resolved position on the `REVENUE_PAID` vs `CONFIRMED_PAID` (and net-
   vs-gross revenue) question from §1, so a query plan referencing
   `net_revenue` has one unambiguous status/refund definition to compile
   against.
