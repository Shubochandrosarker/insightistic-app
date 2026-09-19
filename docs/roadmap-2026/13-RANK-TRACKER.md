# Rank Tracker

## Goal

Provide daily or scheduled keyword position tracking without pretending Insightistic owns a global SERP index.

## Build-vs-buy decision

Use a provider abstraction for SERP observations. Do not scrape search-engine result pages directly from application servers at scale.

Reasons:

- anti-bot instability
- proxy/geolocation complexity
- legal/terms considerations
- high maintenance
- inconsistent result localization

## Domain model

### Tracked keyword

```text
site_id
keyword
search_engine
country
language
device
location optional
active
frequency
priority
```

### Observation

```text
keyword_id
checked_at
position nullable
ranking_url nullable
serp_features
provider
provider_request_id
cost_units
```

## Scheduling

Group jobs by:

- provider
- location
- search engine
- device

Batch where provider supports batching.

## Quotas

Rank tracking is variable cost and must be metered.

Recommended plan limits:

- number of active keywords
- checks/month
- supported locations/devices
- refresh frequency

Never promise unlimited rank checks in LTD plans.

## UI

### Rank Tracker Overview

KPIs:

- tracked keywords
- top 3
- top 10
- top 20
- improved
- declined
- visibility score

### Keyword table

```text
keyword
current position
change
best position
ranking URL
search volume optional
country/device
last checked
SERP features
```

### History

Show position trend and ranking URL changes.

## GSC relationship

GSC average position and rank-tracker position are different products and must be labeled separately.

- GSC: aggregated observed position from actual Google impressions
- Rank tracker: controlled point-in-time SERP observation for a specified locale/device

Do not merge these into one unnamed "position" metric.

## Auto-suggestions

Suggest tracked keywords from GSC when they meet useful thresholds:

- high impressions
- commercial importance
- position near page 1
- user-marked priority

Require user confirmation before consuming rank-tracking quota.

## Alerts

- entered top 3/10/20
- dropped by configurable threshold
- ranking URL changed
- not found after previously ranking

Noise control:

- require minimum absolute movement
- suppress repeated same-state alerts
- batch daily notifications

## Acceptance criteria

- server enforces keyword/check limits
- provider failure does not overwrite last known position
- duplicate scheduled jobs do not duplicate observations
- UI identifies location/device/search engine
- every check records billable usage/cost units
