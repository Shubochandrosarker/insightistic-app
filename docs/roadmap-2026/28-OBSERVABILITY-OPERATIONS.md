# Observability & Operations

## Goals

Operators must know:

- is the API healthy?
- are queues backing up?
- which providers are failing?
- is billing projection current?
- which organizations are affected?
- what is AI/SERP/crawl cost doing?

## Logs

Structured fields:

```text
request_id
job_id
organization_id
site_id
source
operation
duration_ms
status
error_code
```

Never log secrets.

## Metrics

### API

- request count
- error rate
- p50/p95 latency
- route family

### Queue

- queued
- processing
- failed
- oldest job age
- runtime by queue

### Integrations

- successful syncs
- failed syncs
- stale connections
- provider error codes

### Crawler

- pages/min
- average response time
- failures
- pages per run

### Billing

- webhook received/verified/duplicate/failed
- billing projection drift
- past-due organizations
- unfulfilled completed transactions

### Cost

- AI cost units
- SERP cost units
- PageSpeed/API volume where relevant
- cost per organization/plan

## Tracing

Existing OpenTelemetry architecture can be used selectively for high-value server spans.

Trace:

- provider sync
- crawl run coordination
- report generation
- AI run
- billing event projection

Do not attach sensitive payloads to traces.

## Sentry/error tracking

Keep request/body scrubbing. Provider tokens and PII must not leak into exception events.

## Super-admin operations

Required operational screens:

- system health
- queue status
- integrations failing
- billing events/reconciliation
- organizations/sites
- usage/cost
- failed reports
- sync logs
- audit logs

Mutations must be audited.

## Runbook examples

### GSC outage

1. identify provider-wide error pattern
2. mark source degraded, not disconnected
3. stop noisy retries
4. show stale data with freshness notice
5. resume queue when healthy

### Billing webhook backlog

1. verify Paddle destination logs
2. confirm webhook signature endpoint health
3. inspect queue
4. replay notifications if needed
5. run billing reconciliation

### Crawler overload

1. reduce worker concurrency
2. pause low-priority crawls
3. protect critical/API queues
4. investigate runaway site/url patterns
