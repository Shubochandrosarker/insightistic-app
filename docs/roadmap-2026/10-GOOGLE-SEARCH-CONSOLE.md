# Google Search Console Intelligence

## Current foundation

Current code already supports GSC metrics including clicks, impressions, CTR, average position, queries, pages and device breakdown. The SaaS also has a direct integration path.

## Goal

Turn GSC from a simple dashboard into the main first-party organic-search intelligence engine.

## Data ingestion

### Daily totals

Store by date:

- clicks
- impressions
- CTR
- average position

### Dimensions

Support data acquisition and exploration across:

- query
- page
- country
- device
- search appearance where available

Because GSC data is not an exhaustive raw log and returns top rows, product copy must not imply that stored queries equal every query Google observed.

## Recommended storage strategy

### Daily baseline

Always store daily totals for trend continuity.

### Query/page snapshots

Pull substantially more than the plugin's current small top-N snapshot. Use pagination/row limits supported by the API and plan-aware historical backfills.

### Combination strategy

Avoid exploding storage by capturing every possible dimension combination daily. Suggested tiers:

1. query aggregate
2. page aggregate
3. query + page for top/opportunity candidates
4. country/device aggregate
5. search-appearance aggregate

Deeper combinations can be fetched on-demand and cached where API constraints permit.

## Search Intelligence pages

### Overview

KPIs:

- clicks
- impressions
- CTR
- average position
- pages receiving clicks
- queries receiving clicks

Charts:

- clicks vs impressions
- position/CTR trend
- device mix
- country mix

### Queries

Columns:

```text
query
clicks
impressions
CTR
position
click change
impression change
position change
landing pages
intent/category if derived
```

### Pages

Columns:

```text
page
clicks
impressions
CTR
position
query count
change
crawl health
CWV state
revenue (WooCommerce where applicable)
```

## Opportunity algorithms

### Striking distance

High impressions + average position approximately 4–20, weighted by CTR gap and trend.

### CTR opportunity

Expected CTR should be relative to this site's own performance by position band where possible, rather than asserting universal CTR curves as truth.

### Content decay

Compare rolling windows such as recent 28 days vs preceding 28 days while controlling for low-volume noise.

### Winner/loser

Use minimum-impression thresholds and absolute + percentage changes.

### Cannibalization candidate

Flag queries with multiple landing pages receiving meaningful impressions/clicks. Label them as **candidates**, because GSC aggregates do not prove that the pages directly competed in the same result set.

## Business-impact correlation

For WooCommerce sites, enrich GSC page reports with:

- revenue attributed to landing pages where methodologically defensible
- product/category relationship
- conversion trends from GA4/Woo data

Label attribution assumptions.

## Alerts

Examples:

- clicks drop > threshold after minimum baseline
- high-value page disappears from top impressions
- sudden position loss
- CTR collapse without ranking loss
- rising impressions without clicks

Use noise floors; do not alert on 1-click changes.

## UI

Follow table-first design similar to search-console tools:

- fixed KPI row
- large trend chart
- tabs for Queries / Pages / Countries / Devices / Appearance / Dates
- filters and date compare
- export
- add query to rank tracker

## Acceptance criteria

- direct GSC OAuth works without exposing refresh token to browser
- historical sync survives provider delay and retries
- query/page records are deduplicated
- opportunity reports explain why an item was flagged
- user can jump from search page to related crawl/performance data
