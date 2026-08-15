# Saved Insights

Status: **P5 — implemented**. Final phase of this roadmap slice
(`P0 Semantic Metric Registry → P1 Safe WooCommerce Query Engine → P2
Analytical Evidence Ledger → P3 Ask Insightistic V2 → P4 Multi-source
Analytics → P5 Saved Insights`).

## What it is

A saved insight is a **named, reusable `SemanticQueryPlan`** — "Monthly
Revenue", "Top Products This Quarter" — that a user saves once and re-runs
on demand instead of re-composing the same plan (by hand or by asking)
every time. It is not a second query engine: creating and running a saved
insight both go through the exact same `PlanValidator` → `SafeWooQueryEngine`
→ `EvidenceLedger` pipeline every other analytical surface uses.

```
POST   /api/sites/{site}/saved-insights            create
GET    /api/sites/{site}/saved-insights             list
GET    /api/sites/{site}/saved-insights/{id}         show
PATCH  /api/sites/{site}/saved-insights/{id}         update
DELETE /api/sites/{site}/saved-insights/{id}         delete
POST   /api/sites/{site}/saved-insights/{id}/run     re-execute now
```

## Schema (`saved_insights`)

| Column | Purpose |
|---|---|
| `organization_id`, `site_id` | Tenant scope, stamped explicitly from `$site` (same reasoning as `evidence_runs` — this may run outside an HTTP request in a future scheduled context). |
| `owner_user_id` | Who created it. Nullable for consistency with `evidence_runs.requested_by_user_id`, though every current write path sets it. |
| `name`, `description` | User-facing label. |
| `semantic_plan_json` | The validated plan, as `SemanticQueryPlan::toArray()` — identical shape to `evidence_runs.semantic_plan_json`. |
| `metric_versions_json` | Each metric's resolved registry version **at save time**, via the same `EvidenceLedger::metricVersionsFor()` an evidence run uses. Frozen even if the catalog later bumps a version — a saved insight's definition doesn't silently change meaning underneath the user. |
| `chart_spec_json` | **Structural only** — `{"type": "kpi"}` or `{"type": "bar", "dimension": "product"}`. Deliberately never stores computed values: a cached number would go stale the instant underlying data changes, which would violate "never present stale data as current." Fresh numbers only ever come from `POST .../run`. |
| `scope` | `"organization"` (default — visible to the whole org, this is a small-team product with no finer-grained sharing model) or `"private"` (visible only to `owner_user_id`). |
| `thresholds_json` | **Reserved, not evaluated by anything yet.** See "What P5 deliberately does not do." |
| `version` | Increments only when the stored **plan** changes via `PATCH`. Renaming or changing scope does not bump it — this tracks definition changes, not metadata edits. |
| `last_run_at` | Set on every successful `POST .../run`. `null` until first run. |

## Create / update validate through the real pipeline

`SavedInsightController::store()`/`update()` call `PlanValidator::validate()`
on the submitted plan directly — the identical validator
`AnalyticsQueryController` and `AskInsightisticService` use. A saved
insight can never be created with a plan that `POST .../analytics/query`
would reject; a bad plan gets the same `422` and the same `code` (e.g.
`unknown_metric`, `disallowed_dimension`) a direct query call would return.

Validation does **not** execute the plan against the database — creating a
saved insight is cheap and doesn't touch `metric_snapshots`/`traffic_daily`/
`seo_daily` at all. `chart_spec_json`'s structural type is derived purely
from `SemanticQueryPlan::isBreakdown()`/`$dimension` (see
`ChartSpecBuilder::typeFor()`), which needs no query result.

## Running

`POST .../saved-insights/{id}/run` re-executes the **stored**
`semantic_plan_json` through `SafeWooQueryEngine::run()` — re-validated by
`PlanValidator` again inside the engine, same as any other call — and
records the outcome via `EvidenceLedger` with `run_type = "query"` (the
same run type a direct `analytics/query` call uses; a saved insight isn't
a distinct kind of evidence, just a named entry point into the same one).
On success, `last_run_at` is updated and the response includes a freshly
computed `chart` (this time with real values, via `ChartSpecBuilder::build()`)
and `evidence_run_id`.

If a metric the plan references has since been removed from the registry
(an edge case — the catalog is append-only in practice, but nothing
enforces that), the run fails honestly with `unknown_metric`, recorded as
an error evidence run — never a silently wrong or partial result.

## Visibility and tenant isolation

- `index()` returns rows where `scope = "organization"` OR
  (`scope = "private"` AND `owner_user_id` = the caller).
- Fetching, updating, deleting, or running someone else's private insight
  returns **404**, not 403 — consistent with `EvidenceController`'s
  cross-org convention: a request never gets to distinguish "exists but
  not yours" from "doesn't exist."
- A saved insight from a different site or organization 404s the same way
  — `site.access` middleware plus an explicit `site_id` match, same
  defense-in-depth pattern as every other site-scoped route.

## What P5 deliberately does not do

- **`thresholds_json` is inert.** The column exists (forward-compatible
  schema, matching P2's `ai_used`/`ai_model` precedent) but nothing reads
  or evaluates it — there is no scheduled job checking a saved insight's
  latest value against a threshold and no notification path. That's
  P6 Automation Studio territory.
- **No scheduled auto-run.** `last_run_at` only updates from an explicit
  `POST .../run` call; nothing runs saved insights on a cron.
- **No dashboard/UI.** This is the API surface a future "Saved Insights"
  panel reads from and writes to.
- **No sharing finer than org-wide vs. private-to-owner** — no per-user
  or per-role ACL on an individual saved insight.
- **`chart_spec_json` never stores data** — see the schema table above.
  This is a deliberate constraint, not a missing feature: a "run" is
  always a real query, never a cache read.
