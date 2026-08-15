# Agency Intelligence

Status: **P10 — implemented**. Ninth step of the Insightistic VNext
roadmap (`... P9 Bridgistic Approved Actions → P10 Agency Intelligence →
P11 Outcome Verification`).

## What it is

A cross-site rollup for an agency-style organization managing several
client sites — "how are all my clients doing right now, in one view" —
instead of switching between each site's own dashboard one at a time.

```
GET /api/agency/overview?period=last_30_days
```

**Deliberately not a `sites/{site}/...` route.** This is the one
analytical surface in the app that spans every site an organization owns
at once, so it sits in the same outer `auth:sanctum + tenant` route group
`GET /api/sites` (the site list itself) and `GET /api/metrics` already
use — no `site.access` middleware applies because there is no single
`{site}` to scope to.

## Response shape

```json
{
  "organization_id": 1,
  "period": "last_30_days",
  "site_count": 2,
  "totals": {"net_revenue": 1000.00, "orders": 10},
  "sites": [
    {
      "site_id": 5, "site_name": "Client A", "status": "ok",
      "net_revenue": 300.00, "orders": 3,
      "net_revenue_delta_pct": 12.5, "health": "healthy"
    },
    {
      "site_id": 6, "site_name": "Client B", "status": "error", "error": "read_only_identity_not_configured"
    }
  ]
}
```

## How it works — no new query path

`App\Services\Agency\AgencyRollupService::rollup()` calls
`SafeWooQueryEngine::run()` once per site the organization owns (`Site::where('organization_id',
$org->id)`), requesting `net_revenue` + `orders` with a previous-period
comparison — the exact same plan shape `analytics/query` accepts. There is
no new tenant-scope risk: every site queried is already filtered to the
caller's own organization before the query engine is ever touched, and the
engine's own `Site`-scoped execution is a second, independent scope check
on top of that.

A site whose query fails for any reason (most commonly: no synced data
yet) is surfaced as its own row with `"status": "error"` and the real
`errorCode` — **never silently dropped from the rollup, and never counted
as zero revenue as if that were a real measurement.** `totals` only sums
the sites that actually returned a value.

## `health`

Classified from `net_revenue_delta_pct` using the **identical** threshold
`AlertDetector::REVENUE_DROP_THRESHOLD` already uses for its
`revenue_drop` alert rule (`-20.0%`) — a site the rollup calls `at_risk`
is, by construction, a site that would also trip (or has already tripped)
an actual alert. `unknown` when no comparison delta is available (no prior
period data). This is a magnitude classification, not a prediction — it
describes what already happened, not what will happen next.

| `health` | Meaning |
|---|---|
| `at_risk` | Revenue down 20% or more vs. the previous equal-length period. |
| `watch` | Revenue down, but by less than 20%. |
| `healthy` | Revenue flat or up. |
| `unknown` | No comparison data available. |

## What P10 deliberately does not do

- **Only `net_revenue`/`orders`** — no GA4/GSC rollup, no per-product
  breakdown across sites. A future version could add more metrics to the
  per-site plan; this is a deliberately small first slice, not a ceiling.
- **No pagination** — every site the organization owns is queried and
  returned in one response. For an organization with a very large number
  of sites this could become slow; not addressed here.
- **No caching** — every call re-runs the query engine for every site,
  live. No cached/precomputed rollup snapshot exists yet.
- **No UI.** This is the API surface a future "Agency" dashboard panel
  reads from.
