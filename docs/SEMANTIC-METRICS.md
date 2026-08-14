# Semantic Metric Registry

Status: **P0 — implemented**. Part of the Insightistic VNext roadmap
(`P0 Semantic Metric Registry → P1 Safe WooCommerce Query Engine → ...`).
See `docs/CONVERSATIONAL-ANALYTICS-AUDIT.md` for the current-state audit
this was built against.

## What it is

One versioned, canonical catalog of metric metadata — `App\Metrics\
MetricRegistry`, backed by `App\Metrics\Catalog\*` — so "what does
`net_revenue` mean" has exactly one answer across the dashboard, Copilot,
client reports, and future automations. It is metadata, not computation:
the registry describes a metric (id, label, unit, source table/column,
formula, freshness, caveats); the existing services
(`MetricsAssembler`, `MetricSnapshotService`, `ContextAssembler`, ...)
still do the actual computing. P0 documents and disciplines what already
runs — it does not move or risk working query code.

## Why code, not a database table

Metric definitions are code (`app/Metrics/Catalog/*.php`), reviewed like
any other change, not editable at runtime through an admin screen. This is
deliberate: the typed semantic query planner (P1) needs a fixed allowlist
of metric ids → tables/columns to validate an AI-authored query plan
against, and a catalog that only changes via a reviewed commit is the
safest allowlist to reason about. If a future phase needs tenant-level
metric overrides, that's an explicit new concept layered on top of this
registry — not a reason to make the base catalog mutable.

## Core types (`app/Metrics/`)

- **`MetricDefinition`** — immutable value object: `id`, `version`, `label`,
  `description`, `source`, `aggregation`, `unit`, `table`, `column`,
  `freshness`, `allowedDimensions`, `allowedFilters`, `dependsOn`,
  `caveats`, `formula`.
- **`MetricRegistry`** — resolves a definition by id (+ optional version),
  lists all metrics or all metrics for a source, and lists a metric's full
  version history. Bound as a singleton in `AppServiceProvider`.
- **`MetricSource`** / **`MetricAggregation`** / **`MetricUnit`** /
  **`MetricFreshness`** — closed enums (as PHP class constants) a
  definition's fields are drawn from.
- **`MetricCatalog`** — aggregates `Catalog\CommerceMetrics`,
  `Catalog\TrafficMetrics`, `Catalog\SeoMetrics`, `Catalog\
  PerformanceMetrics` into the full list `MetricRegistry` loads.
- **`CommerceStatus`** — the canonical WooCommerce order-status
  classification every commerce metric/service now reads instead of
  hand-typing its own list (see "Two paid-status definitions" below).

## Versioning policy

- A metric id is never reused for a different meaning. `version` starts at
  1 and only increments when the formula, source, or unit changes in a way
  that would make a before/after comparison misleading (e.g. adding
  `refund_total` into `net_revenue` later would be a v2, not an in-place
  edit).
- `versionedId()` (`"net_revenue@v1"`) is the identifier the evidence ledger
  (P2) and Saved Insights (P5) should persist alongside a computed value —
  never just the bare metric id — so a re-run can prove which definition
  produced a historical number.
- `MetricRegistry::versions($id)` returns every historical version, oldest
  first, for exactly that purpose.

## Two paid-status definitions (read before adding a commerce metric)

Auditing every existing consumer of "is this order paid" found two
intentionally different, still-open definitions in production (see the
audit doc's §1 for the full list of affected files):

- **`CommerceStatus::REVENUE_PAID`** (`completed`, `processing`, `on-hold`)
  — "attributable revenue". Used by `MetricsAssembler`/
  `MetricSnapshotService` for headline revenue/order KPIs.
- **`CommerceStatus::CONFIRMED_PAID`** (`completed`, `processing`) —
  "payment captured". Used by `CustomerIntelligence`/`ProductIntelligence`/
  `AcquisitionIntelligence` for RFM/cohort/LTV/affinity/acquisition
  analytics, on the documented rationale that a payment-pending order
  shouldn't count until the payment is actually taken.

P0 preserved both behaviors exactly (see the drift-regression tests in
`tests/Unit/Metrics/MetricRegistryTest.php`) and only removed the
hand-copying. **This is flagged, not resolved** — whoever owns product
metric semantics should decide whether P1's query engine compiles
`net_revenue` against one unambiguous status set before that phase ships.

## Using the registry

```php
use App\Metrics\MetricRegistry;

$registry = app(MetricRegistry::class); // or inject via constructor

$netRevenue = $registry->get('net_revenue');       // latest version
$aovV1      = $registry->get('aov', 1);             // pinned version
$commerce   = $registry->forSource('woocommerce');  // MetricDefinition[]
$all        = $registry->all();                     // every metric, latest version
```

Unknown id/version throws `MetricRegistryException` — callers must not
silently fall back to a different metric.

### HTTP

```
GET /api/metrics             → { metrics: MetricDefinition[], sources: string[] }
GET /api/metrics?source=ga4  → filtered to one source (422 for an unknown source)
GET /api/metrics/{id}        → { metric: MetricDefinition, versions: MetricDefinition[] }
```

Authenticated tenant users only (`auth:sanctum` + `tenant`), no site scope
required — this is product metadata, not tenant data, and never returns a
live value.

### CLI

```
php artisan metrics:list [--source=woocommerce] [--json]
```

## Current catalog (19 metrics, P0)

| Source | Metrics |
|---|---|
| `woocommerce` | `net_revenue`, `orders`, `aov`*, `refund_count`, `refund_rate`*, `failed_orders`, `products_sold`, `product_revenue`, `new_customers`, `returning_customers`, `repeat_purchase_rate`* |
| `ga4` | `ga4_sessions`, `ga4_conversions`, `conversion_rate`* |
| `gsc` | `organic_clicks`, `organic_impressions`, `organic_ctr`*, `organic_avg_position` |
| `pagespeed` | `lcp`, `inp`, `cls` |

`*` = derived (has `dependsOn` + `formula`, no direct table/column read).

This is a starting catalog, not a ceiling — add a metric by adding a
`MetricDefinition` to the relevant `Catalog\*::all()` (or a new catalog
class registered in `MetricCatalog::all()`), never inline in a controller
or service. Every new derived metric must declare `dependsOn` (checked
against the registry by `MetricRegistryTest`) and a `formula`; every metric
whose computation has a known simplification must list it in `caveats` —
consumers are expected to surface those caveats, not hide them (per the
product's "evidence over model memory" invariant).

## What P0 deliberately does not do

- Does not move `MetricsAssembler`/`MetricSnapshotService`/`ContextAssembler`
  to read through the registry at runtime — they still compute directly
  against `metric_snapshots`/live tables. Wiring the registry into a real
  typed query planner is P1's job.
- Does not add tenant-level metric overrides, a metric-editing UI, or
  runtime-loaded definitions.
- Does not resolve the `REVENUE_PAID` vs `CONFIRMED_PAID` question above.
- Does not add an evidence ledger (query hash, run persistence, freshness-
  vs-confidence scoring) — that is P2.
