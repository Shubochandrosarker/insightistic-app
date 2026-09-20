# Performance, Core Web Vitals & Uptime

## Goal

Make performance monitoring historical and actionable rather than a one-time PageSpeed screenshot.

## Performance sources

- PageSpeed Insights lab data
- CrUX/field data where available
- crawler response time
- Cloudflare edge/network indicators

## PageSpeed runs

Store:

```text
site/page
strategy mobile|desktop
run_at
performance score
LCP
INP where available in source
CLS
FCP
TBT
Speed Index
TTFB
opportunities/diagnostics summary
```

## Page selection

Monitor:

- homepage
- top GSC landing pages
- top revenue pages/products
- user-pinned URLs

Do not run PageSpeed against every crawled URL every day; it is expensive and unnecessary.

## Core Web Vitals dashboard

Show:

- monitored URLs passing/failing
- metric distributions
- trend
- regressions
- page templates/categories affected

Separate field and lab data clearly.

## Regression detection

Trigger when:

- performance score drops significantly from baseline
- LCP/INP/CLS crosses threshold
- key page response time changes materially

Require repeated confirmation for noisy lab metrics before alerting unless change is extreme.

## Uptime model

### Monitors

```text
site
URL
method
expected status
interval
regions optional
timeout
active
```

### Checks

Store compact checks and aggregate older history.

### Incident lifecycle

```text
healthy -> suspected -> confirmed incident -> recovered
```

Avoid alerting on a single failed probe. Confirm with a second attempt/region where possible.

## Uptime intervals by plan

Example:

- Starter: 10 min
- Growth: 5 min
- Agency: 1–5 min depending tier

High-frequency checks are recurring infrastructure cost and must be plan limited.

## Additional checks

- SSL expiry
- domain/DNS resolution
- redirect anomalies
- unexpected status

## UI

```text
Performance
├── Overview
├── Core Web Vitals
├── PageSpeed
├── Uptime
└── Incidents
```

## Acceptance criteria

- field and lab metrics are never merged without labels
- uptime incident requires confirmation rule
- PageSpeed jobs obey per-plan budgets
- history can identify regression date
- alerts link to affected page and recent crawl/change context
