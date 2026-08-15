# Anomaly Engine

Status: **P7 — implemented**. Sixth step of the Insightistic VNext roadmap
(`... P6 Automation Studio → P7 Anomaly Engine → P8 WPAgentistic
Investigations → ...`).

## What it is

Generalizes the median/MAD anomaly math
`App\Services\Intelligence\AcquisitionIntelligence::trafficAnomalies()`
already shipped for GA4 sessions specifically, to a small fixed set of
metrics across all three query-engine sources (`woocommerce`, `ga4`,
`gsc`), and persists findings as first-class `Anomaly` rows that feed
Automation Studio's (P6) `anomaly_detected` trigger.

```
GET  /api/sites/{site}/anomalies?status=open|acknowledged|all&metric_id=
POST /api/sites/{site}/anomalies/scan                 run now
POST /api/sites/{site}/anomalies/{id}/acknowledge
```

## Why median/MAD, not mean/standard-deviation

Same reasoning `AcquisitionIntelligence` already documents: a mean and a
standard deviation are both dragged toward an outlier by the outlier
itself — the more extreme a spike, the more it inflates the very threshold
meant to catch it, and a naive z-score detector can go quiet right after
the event you most wanted it to notice. A median and a **median absolute
deviation** (MAD, scaled by 1.4826 so the result is comparable to a
standard-deviation z-score on normally distributed data) are far less
sensitive to a single extreme day.

**Deliberately not shared code with `AcquisitionIntelligence`** — that
class's `trafficAnomalies()` is a shipped, tested feature with its own
output shape; this engine must not risk regressing it. Instead
`App\Services\Anomaly\AnomalyDetector` independently re-implements the
same ~15 lines of median/MAD math. This mirrors an existing convention
already in this codebase: `WooCommerceQueryCompiler`, `Ga4QueryCompiler`
and `GscQueryCompiler` each hardcode their own table/column maps rather
than importing a shared one — a small independent copy over a risky shared
abstraction, for core logic that different callers can't be allowed to
silently drag out of sync with each other.

## Supported metrics

`AnomalyDetector::supportedMetrics()` — a small, hardcoded, fixed set
(never derived from the Semantic Metric Registry at runtime, same boundary
every query-engine source adapter enforces):

| Metric | Source | Table.Column |
|---|---|---|
| `net_revenue` | woocommerce | `metric_snapshots.revenue` |
| `orders` | woocommerce | `metric_snapshots.orders` |
| `refund_count` | woocommerce | `metric_snapshots.refunds` |
| `ga4_sessions` | ga4 | `traffic_daily.sessions` (scoped to `source = 'ga4'`) |
| `ga4_conversions` | ga4 | `traffic_daily.conversions` (scoped to `source = 'ga4'`) |
| `organic_clicks` | gsc | `seo_daily.clicks` |
| `organic_impressions` | gsc | `seo_daily.impressions` |

Requesting a metric outside this list throws `InvalidArgumentException` —
never a silent no-op.

## Scanning

`App\Services\Anomaly\AnomalyScanner::scan()` mirrors `AlertDetector::scan()`'s
shape: run every supported metric's detector over a rolling 30-day window
(comfortably above the 14-day floor `AnomalyDetector::MIN_HISTORY_DAYS`
requires for a median to describe "normal" at all), and persist only
**genuinely new** findings. `anomalies`' `unique(site_id, metric_id, date)`
constraint enforces the dedup at the database level, not just in
application code — re-scanning the same window twice never creates a
duplicate row, and `scan()`'s return value only ever contains the newly
created rows.

`php artisan insightistic:anomalies-scan [--site=]`, scheduled every 6
hours (same cadence as the alert scan it complements — the underlying
data is daily-granularity, so more-frequent runs surface nothing new
within a day).

## Feeds Automation Studio (P6)

Every genuinely new `Anomaly` fires the `anomaly_detected` trigger via
`AutomationDispatcher`, with `$context = ['metric_id' => ..., 'anomaly_id'
=> ...]` — an `AutomationRule` can optionally filter to one metric via
`trigger_config_json = {"metric_id": "net_revenue"}`, or omit it to match
every supported metric. See `docs/AUTOMATION-STUDIO.md`.

## Severity

`AnomalyScanner` classifies severity from `|score|` relative to the
default detection threshold (3.0): `>= 6.0` → `high`, `>= 4.0` → `medium`,
else `low`. This is a simple magnitude band, not a business-impact
assessment — a large sessions swing and a large revenue swing get the same
severity label purely from how far outside the metric's own normal range
the day fell.

## What P7 deliberately does not do

- **No dimension breakdowns** — anomaly detection runs on the same
  site-level aggregate series the woocommerce/ga4/gsc source adapters
  compute for an aggregate plan; there is no per-product or per-page
  anomaly detection here.
- **No PageSpeed metrics** — same "latest run, not a date-range series"
  reasoning `docs/SAFE-QUERY-ENGINE.md` documents for why `pagespeed` isn't
  a supported query-engine source yet.
- **Does not re-derive `AcquisitionIntelligence`'s existing anomaly
  feature** — that dashboard-facing report keeps its own independent
  implementation and output shape; this engine is a separate, persisted,
  cross-metric system feeding Automation Studio, not a replacement for it.
- **No UI.** This is the API surface a future "Anomalies" panel reads from.
