# Background Jobs, Scheduler, Locks & Caching

## Queue strategy

Split workloads by urgency and resource profile.

Recommended queues:

```text
critical        # account/billing state projection
connector       # WordPress ingestion follow-up
integrations    # GSC/GA4/Bing/Cloudflare
crawler         # HTTP crawl
rank            # SERP provider
ai              # insights/copilot background work
reports         # PDF/export
notifications   # email/webhooks
low             # cleanup/backfill
```

## Job rules

Every job must define:

- tenant/site context
- idempotency behavior
- retry count
- timeout
- backoff
- failure handling
- usage/metering behavior

## Scheduling examples

### Nightly chain

Possible order:

1. direct source sync kickoff
2. metric snapshots
3. opportunity/anomaly detection
4. proactive insights
5. report generation if scheduled

Do not force unrelated sources into one blocking chain; queue independently and gate derived jobs on freshness where required.

### Suggested cadence

```text
Billing reconciliation      daily
GSC                         daily
Bing                        daily
GA4                         hourly/daily by plan
Cloudflare                  hourly + daily aggregates
Crawler                     weekly/daily by plan
Rank tracking               daily by plan
AI visibility               weekly/daily by plan
PageSpeed                   daily/pinned pages
Uptime                      1–10 min by plan
Alerts/anomalies             1–6 hourly depending type
```

## Locks

Use Redis locks for:

- one active crawl per site/config class
- one provider sync per site/source/window
- one report generation per report schedule occurrence
- AI double-click/cost guard
- billing reconciliation per organization

Locks need expiry so crashes do not deadlock forever.

## Caching

### Good cache candidates

- overview aggregates
- integration resource lists
- plan/entitlement response
- provider metadata
- data-table counts

### Bad cache candidates

- billing state without durable DB projection
- license revocation status with no TTL/refresh
- security authorization decisions across users

## Stale-while-revalidate UX

For analytics:

- show last successful data quickly
- mark stale
- refresh in background

This is better than blanking the dashboard during provider sync.

## Usage reservation

Expensive jobs should use a two-step usage model:

```text
reserve -> execute -> finalize
```

On safe failure, release reservation. On provider-billed failure, record consumed cost if it actually occurred.

## Failed jobs

Admin tools need:

- failed job list
- retry selected
- inspect safe error summary
- source/site context

Do not display secrets or raw sensitive payloads.
