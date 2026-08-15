# Analytical Evidence Ledger

Status: **P2 — implemented**, now also written to by **P3 Ask Insightistic
V2** (see `docs/ASK-INSIGHTISTIC.md`). Third step of the Insightistic VNext
roadmap (`P0 Semantic Metric Registry → P1 Safe WooCommerce Query Engine →
P2 Analytical Evidence Ledger → P3 Ask Insightistic V2 → ...`).

## What it is

Every Safe Query Engine execution — success or failure — is persisted as
one `EvidenceRun` row: what was asked, which metric *versions* produced the
numbers, a reproducibility hash, timing, freshness, and outcome. This is
the record P3 (Ask), P5 (Saved Insights) and eventually P6 (Automation
Studio) all point back to when they need to say "here's exactly what ran
and when."

## Why a new table, not `ai_runs`

`docs/CONVERSATIONAL-ANALYTICS-AUDIT.md` §6 noted `ai_runs` already tracks
provider/latency/cost for Copilot chat calls. It was **not** reused here:
`ai_runs.ai_message_id` is a `NOT NULL` foreign key into `ai_messages`
specifically, and `UsageService` counts its rows for AI-usage quota
metering. Most query-engine executions involve no AI call and no chat
message at all — repurposing `ai_runs` for them would corrupt that
accounting. `evidence_runs` is a separate, dedicated table (spec: "one
system of record" per surface, not one overloaded table doing two jobs).

## Schema (`evidence_runs`)

| Column | Purpose |
|---|---|
| `organization_id`, `site_id` | Tenant scope. Stamped explicitly from `$site->organization_id` by `EvidenceLedger` — not left to `BelongsToOrganization`'s ambient-`Tenancy` auto-fill, since this service may run outside an HTTP request (a queued job, a console command) where no request-bound tenant context exists. |
| `requested_by_user_id` | Nullable — null for system-triggered runs (P6 Automation Studio). |
| `source`, `run_type` | `source` = `MetricSource::*` (`woocommerce` today). `run_type` = `query` (direct `POST .../analytics/query` call) or `ask` (P3). |
| `validator_version` | `PlanValidator::CONTRACT_VERSION` — bumped whenever the plan schema/validation rules change in a way that could make a stored plan un-replayable against the current validator. A stored plan is always interpreted against the rules in force when it actually ran. |
| `semantic_plan_json` | The validated plan, as `SemanticQueryPlan::toArray()`. |
| `metric_versions_json` | `{"net_revenue": 1, "orders": 1, ...}` — each requested metric's **resolved** registry version at run time, frozen even if the catalog later bumps a version. |
| `query_hash` | `QueryResult::$queryHash` — `sha256(site_id \| compiled SQL \| bindings)`. Two runs with the same hash ran the identical query. |
| `row_count`, `duration_ms`, `read_only_identity_active` | Straight from `QueryResult`. |
| `freshness_json` | `{"data_as_of": "2026-08-10", "days_stale": 1}` — the site's own sync recency (latest `metric_snapshots` date vs today), independent of the query's requested period. `null`/`null` when the site has never synced — never a fabricated date. |
| `status`, `error_code`, `error_message` | `error_message` is always `QueryEngineException`'s own safe, public-facing text — never a raw SQL fragment or stack trace (see `tests/Feature/Evidence/EvidenceLedgerTest.php`'s explicit assertion against that). |
| `ai_used`, `ai_model` | Whether this run's narration went through an AI rephrase step and which model produced it — `false`/`null` for every P2 `query` run (the engine itself never calls AI) and for every P3 `ask` run whose organization has external AI disabled or whose AI call failed/was skipped. Populated by `AskInsightisticService` (see `docs/ASK-INSIGHTISTIC.md`); no cost/token accounting yet (`ai_cost_usd` remains deferred — see below). |

## Who writes to it

`SafeWooQueryEngine` itself stays a pure computation with **no storage side
effect** — this preserves P1's documented boundary and keeps the engine
trivially reusable by callers that don't want ledger writes (e.g. a future
dry-run preview). `EvidenceLedger::recordSuccess()` /
`recordFailure()` are called explicitly by each caller right after
`SafeWooQueryEngine::run()` returns or throws:

- `AnalyticsQueryController::query()` — every direct query, success or
  failure, `run_type = "query"`. The response includes `evidence_run_id`.
- `AskInsightisticService` (P3) — `run_type = "ask"`, with `ai_used`/
  `ai_model` populated from whether the narration step actually called an
  AI provider. See `docs/ASK-INSIGHTISTIC.md`.
- P5's saved-insight "run now" — reuses the same `run_type = "query"` path.

## HTTP surface (the "Evidence drawer")

```
GET /api/sites/{site}/evidence              list, newest first, ?status=, ?run_type=, ?limit= (max 200)
GET /api/sites/{site}/evidence/{run}        one run's full record
```

Same tenant-scope pattern as every other site-scoped route: `site.access`
middleware plus an explicit `site_id` match in the query (defense in
depth). A run belonging to a different site or organization 404s — it does
not exist for this request, matching `EnsureSiteAccess`'s own convention
elsewhere in the app.

## What P2 deliberately does not do

- Does not add a UI. This is the API surface a future "Evidence drawer"
  component (spec: premium responsive surfaces) reads from.
- Does not prune/retain runs — no TTL or archival job yet. Follow-up work,
  analogous to `docs/PRIVACY-AND-RETENTION.md`'s existing analytics
  retention enforcement.
- Does not record AI **cost** (token counts, `$` spend). `ai_used`/
  `ai_model` exist and are populated by P3, but there is no `ai_cost_usd`
  column yet — that stays a follow-up addition for when cost tracking is
  actually needed, not something added speculatively ahead of a real
  consumer.
