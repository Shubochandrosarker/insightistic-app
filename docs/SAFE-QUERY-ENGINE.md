# Safe WooCommerce Query Engine

Status: **P1 — implemented**, extended to GA4/GSC by **P4 Multi-source
Analytics — implemented**. Second step of the Insightistic VNext roadmap
(`P0 Semantic Metric Registry → P1 Safe WooCommerce Query Engine → P2
Analytical Evidence Ledger → P3 Ask Insightistic V2 → P4 Multi-source
Analytics → ...`). Builds directly on `docs/SEMANTIC-METRICS.md` — every
metric a plan can request must already be registered there. The engine
class keeps its original name (`App\Query\SafeWooQueryEngine`) even though
it now compiles three sources — see "Multi-source dispatch" below for why.

## What it is

A typed request → validated plan → compiled query → executed-read-only
pipeline that lets a caller (a human, `AskInsightisticService`'s
deterministic question-to-plan builder — see `docs/ASK-INSIGHTISTIC.md`)
ask for WooCommerce/GA4/GSC metrics **without ever writing SQL**. The
caller chooses metrics, an optional dimension, and a period from
allowlisted values; it can never name a table, column, or SQL fragment.

```
raw plan (array)
  → PlanValidator            validates against the Semantic Metric Registry
  → SemanticQueryPlan        typed, trusted
  → a source adapter         WooCommerceQueryCompiler / Ga4QueryCompiler /
                              GscQueryCompiler — compiles via Laravel's
                              query builder only, picked by $plan->source
  → ReadOnlySqlGuard         defense-in-depth check on the compiled SQL text
  → QueryConcurrencyLimiter  per-organization in-flight cap
  → read-only DB connection  a separate, least-privilege Postgres role
  → StatementTimeoutExecutor SET LOCAL statement_timeout, real Postgres cancel
  → QueryResult              normalized evidence (metrics/rows/caveats/hash)
```

Scoped to **WooCommerce, GA4, and GSC** as of P4. `pagespeed` metrics exist
in the registry but stay rejected by `PlanValidator::unsupportedSource()`
— see "Multi-source dispatch" below for why that one specifically stays
out.

## Multi-source dispatch (P4)

`SafeWooQueryEngine` dispatches by `$plan->source` to one of three source
adapters under `App\Query\Adapters`, each following the exact shape
`WooCommerceQueryCompiler` established in P1 — a hardcoded
`ALLOWED_TABLES` list and a hardcoded base-metric-id → column map, never
values read out of the registry at runtime:

| Source | Adapter | Table | Notes |
|---|---|---|---|
| `woocommerce` | `WooCommerceQueryCompiler` | `metric_snapshots`, `wc_orders`, `wc_order_items` | Aggregate + the one `product` dimension breakdown. |
| `ga4` | `Ga4QueryCompiler` | `traffic_daily` | Aggregate only. `traffic_daily` stores more than one `source` per (site, date) — a first-party `connector_engagement` fallback lives alongside GA4 rows — so every compiled query adds `WHERE source = 'ga4'` in addition to `site_id`, matching the caveat every GA4 metric declares in the registry. |
| `gsc` | `GscQueryCompiler` | `seo_daily` | Aggregate only. Mixes two SQL aggregate functions in one query: `SUM` for `organic_clicks`/`organic_impressions`, `AVG` for `organic_avg_position` (a ranking position, not something a period total means anything for). |

Neither GA4 nor GSC metrics declare an `allowedDimensions` entry in the
registry, so `PlanValidator` never accepts a dimension for them — the
product breakdown path (`SafeWooQueryEngine::runBreakdown()`) stays
WooCommerce-only without needing a source check of its own.

**Why `pagespeed` is still excluded**: `App\Metrics\Catalog\PerformanceMetrics`
(`lcp`, `inp`, `cls`) are "latest run" values — the most recent
measurement, not something you `SUM`/`AVG` over a date range the way every
metric above is. That's a genuinely different compilation shape (closest
to "give me the latest row", not "aggregate rows in a range") this engine
doesn't have an adapter for yet. Requesting a `pagespeed` metric still
throws `unsupported_source` — an honest, typed rejection, not a silent
wrong answer.

**Why the class wasn't renamed**: `SafeWooQueryEngine` is already the
tested, merged entry point every caller (`AnalyticsQueryController`,
`AskInsightisticService`) depends on by that name. A rename buys nothing
but risk and churn for a purely cosmetic change — the class's job
(validate → dispatch → guard → scope → budget → execute → normalize)
didn't change, only how many adapters it dispatches to.

## Why this is a clean slate, not a retrofit

`docs/CONVERSATIONAL-ANALYTICS-AUDIT.md` §2 found that before this PR, no
mechanism in the codebase could execute an AI- or user-composed query at
all — every existing analytics read is a hand-written, fixed Eloquent call.
This meant P1 didn't have to hardened an existing unsafe path; it only had
to make sure the new one is safe by construction from day one.

## The primary control: architecture, not string filtering

Per `docs/04-LOCAL-QUERY-SECURITY.md`'s "never rely on keyword regex as the
primary SQL safety control": this engine's primary defense is that **no
plan-supplied string is ever concatenated into SQL text.**

- `WooCommerceQueryCompiler::BASE_METRIC_COLUMNS` and `::ALLOWED_TABLES`
  are hardcoded PHP literals in that one file — not values read out of the
  registry at runtime, so even a corrupted `MetricDefinition` entry could
  never make the compiler touch a different table/column.
- A plan can only select *which* of those hardcoded columns to include
  (by requesting a registered metric id) — never name one directly.
- Every value (site id, date range bounds) is passed as a **bound
  parameter**, never interpolated into the SQL string.
- `ReadOnlySqlGuard` (regex-based) runs on every compiled query as
  **defense-in-depth against a future compiler bug** — not as the thing
  standing between untrusted input and the database. See its docblock.

## Plan schema

```json
{
  "metrics": ["net_revenue", "orders"],
  "dimension": null,
  "filters": [],
  "period": {"preset": "last_7_days"},
  "comparison": false,
  "limit": null
}
```

| Field | Rule |
|---|---|
| `metrics` | 1–5 registered ids, all the same `MetricDefinition::$source` — `woocommerce`, `ga4`, or `gsc` as of P4 (`pagespeed` still rejected, see "Multi-source dispatch" above). |
| `dimension` | `null`, or a dimension every requested metric's `allowedDimensions` includes. Only `"product"` exists today (`product_revenue` only, `woocommerce`-only) — requesting it restricts `metrics` to just that one id. |
| `filters` | Must be `[]` — no registered metric declares a filter beyond `date_range`, which `period` already covers. Kept in the wire schema so a future phase can add real filters without a breaking change. |
| `period` | `preset` from `App\Support\DateRange`'s vocabulary (`today`, `yesterday`, `last_7_days`, `last_30_days`, `this_month`, `last_month`, `custom`). `custom` requires `from`/`to` as `YYYY-MM-DD`. |
| `comparison` | `true` adds the previous equal-length period + deltas. Only valid without a `dimension`. |
| `limit` | Row cap for a dimension breakdown (1–100, default 10, further clamped by `query_engine.chart_max_rows`). Ignored (must be absent) for an aggregate plan — those always return exactly one row. |

Unknown/invalid input throws `QueryEngineException` with a stable
`errorCode` (`unknown_metric`, `disallowed_dimension`, `invalid_period`,
...) — never a generic 500.

## Compiled query shapes (exactly two, across all three sources)

1. **Aggregate** (`dimension: null`) — one query against the source's own
   table (`metric_snapshots` for woocommerce, `traffic_daily` for ga4,
   `seo_daily` for gsc) for every base metric column the requested metrics
   need (following one level of `dependsOn` for derived metrics like
   `aov`/`refund_rate`/`repeat_purchase_rate`/`conversion_rate`/
   `organic_ctr`), computed in one round trip — `SUM(...)` for every base
   column except `organic_avg_position`, which is `AVG(...)`. Derived
   metrics are then computed in PHP by `DerivedMetricCalculator` from
   hardcoded formulas — never by evaluating `MetricDefinition::$formula`,
   which is a documentation string only.
2. **Product breakdown** (`dimension: "product"`, woocommerce only) —
   `wc_order_items` joined to `wc_orders`, scoped to
   `CommerceStatus::REVENUE_PAID`, grouped by product, ordered by revenue,
   limited.

Both are always scoped by `Site` and organization id passed in by the
caller (`AnalyticsQueryController` / `SafeWooQueryEngine::run()`'s
constructor-style arguments) — **never by anything in the plan itself**. A
plan cannot widen its own scope.

## Read-only execution identity

The engine executes every compiled query through `DB::connection('readonly')`
— a genuinely separate Postgres role from the one migrations/writes use,
with `SELECT`-only grants on exactly `ReadOnlyRoleProvisioner::allowedTables()`
— the union of every registered source adapter's `ALLOWED_TABLES`
(`metric_snapshots`, `wc_orders`, `wc_order_items` from WooCommerce;
`traffic_daily` from GA4; `seo_daily` from GSC, added by P4). This is a
real database-level guarantee, verified in CI by
`tests/Feature/Query/ReadOnlyIdentityTest.php`, which connects as that role
directly and asserts Postgres itself rejects INSERT/UPDATE/DELETE/ALTER
and reads of any table outside the allowlist.

### Provisioning

```bash
php artisan db:provision-readonly-role --role=insightistic_readonly --password='...'
# or rely on READONLY_DB_USERNAME / READONLY_DB_PASSWORD env vars
```

Idempotent — safe to re-run after adding/removing a table from
`ALLOWED_TABLES` (it revokes all privileges first, then re-grants exactly
the current list). Run once per environment by whoever administers the
database; the app never provisions this role itself from a web request.

### Fallback behavior

- **Configured** (`READONLY_DB_USERNAME` set): every query runs through
  the `readonly` connection. `QueryResult::$readOnlyIdentityActive` is `true`.
- **Unconfigured, non-production**: falls back to the default connection
  with a logged warning. `$readOnlyIdentityActive` is `false` — visible in
  every API response's `query.read_only_identity_active` field.
- **Unconfigured, production**: refuses to execute at all
  (`QueryEngineException::readOnlyIdentityNotConfigured()`, HTTP 503).
  Production must never silently run analytical queries through the
  write-capable connection.

### A testing subtlety worth knowing

`RefreshDatabase` wraps every test in an uncommitted transaction on the
default connection. A genuinely separate connection (the real `readonly`
role) runs under Postgres's own read-committed isolation and **cannot see
those uncommitted rows** — so `tests/Feature/Query/SafeWooQueryEngineTest.php`
and `AnalyticsQueryEndpointTest.php` (which need to see Eloquent-seeded
data) deliberately unset `READONLY_DB_USERNAME` for themselves and exercise
the default-connection fallback path instead. `ReadOnlyIdentityTest.php`
doesn't have this problem — it only asserts on Postgres's own permission
behavior, not on seeing specific rows. See `tests/Pest.php` and
`tests/Feature/Query/SafeWooQueryEngineTest.php` docblocks for the full
mechanics of *why* provisioning has to happen through a raw, un-wrapped
PDO connection in the test suite in the first place.

## Query budgets

Configured in `config/insightistic.php`'s `query_engine` key
(`QUERY_ENGINE_*` env vars):

| Budget | Default | Mechanism |
|---|---|---|
| Statement timeout | 8000ms | `SET LOCAL statement_timeout` inside a transaction (`StatementTimeoutExecutor`) — reverts automatically at commit, can't leak onto a later query on a pooled connection. Postgres cancellation (SQLSTATE 57014) maps to `QueryEngineException::timeout()` (HTTP 504). |
| Row cap (breakdown) | 500 (`chart_max_rows`) | Applied as `->limit()`; `QueryResult::$truncated` is `true` when the cap was hit. |
| Per-org concurrency | 3 in-flight | `QueryConcurrencyLimiter`, `Cache::lock()`-guarded counter. Exceeding it returns 429 (`concurrency_limit_exceeded`) without running the query. Best-effort under a non-atomic cache driver, self-heals via a TTL if a slot leaks — see the class docblock. |
| Request rate | 30/min per user | `throttle:query-engine` named rate limiter, on top of (not instead of) the concurrency cap — rate bounds request frequency, concurrency bounds simultaneous execution. |

## HTTP surface

```
POST /api/sites/{site}/analytics/query
Body: {"plan": {...see schema above...}}
```

Behind the existing `auth:sanctum + tenant + site.access` middleware stack
— site scope is enforced by the same mechanism every other analytics route
uses, not reimplemented here. Error responses: `{"message": ..., "code": ...}`
with `code` matching `QueryEngineException::$errorCode`, status mapped by
`AnalyticsQueryController::statusFor()` (422 default, 429 concurrency, 503
misconfigured, 504 timeout).

## What this engine deliberately does not do

- Does not translate natural language into a plan itself — that's
  `App\Services\Ask\QuestionPlanBuilder` (P3)'s job, which produces this
  same raw-plan-array shape and hands it to this engine unchanged. See
  `docs/ASK-INSIGHTISTIC.md`.
- Does not implement a PageSpeed adapter — `PlanValidator` rejects
  `pagespeed` specifically because its metrics are a "latest run" shape
  this engine's SUM/AVG-over-range compilers can't express (see
  "Multi-source dispatch" above), not because multi-source support itself
  is missing (GA4/GSC landed in P4).
- Does not persist runs, query hashes, or evidence — `QueryResult` computes
  everything P2 (Analytical Evidence Ledger) needs to persist (`queryHash`,
  duration, row count, freshness-relevant range) but does not write it
  anywhere itself.
- Does not resolve the `REVENUE_PAID` vs `CONFIRMED_PAID` open question
  from `docs/SEMANTIC-METRICS.md` — `net_revenue`/`orders`/etc. compile
  against `REVENUE_PAID`, matching `MetricsAssembler`'s existing dashboard
  behavior, consistent with how the registry documents that metric.
- Does not support multiple simultaneous dimensions, ad-hoc filters beyond
  `date_range`, dimension breakdowns for GA4/GSC, or CTEs/subqueries — the
  compiled shapes documented above are this engine's complete scope.
  Extending any of them is a reviewed change to the relevant adapter, never
  a runtime capability.
