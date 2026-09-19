# Sync & Ingestion Architecture

## Objectives

- near-real-time updates for important events
- resilient historical backfill
- idempotency
- low WordPress request overhead
- visible sync health
- no duplicate metrics from retries

## Ingestion classes

### 1. WordPress connector ingestion

Authenticated by HMAC.

Data families:

- site health
- WooCommerce orders/products/customers
- GA4 normalized rows where plugin-side integration is used
- GSC normalized rows where plugin-side integration is used
- engagement data
- broken links
- performance runs

### 2. Direct SaaS integrations

Server-to-provider APIs for:

- GSC
- GA4
- Cloudflare
- Bing
- PageSpeed

These are preferred for SaaS freshness because they do not depend on WordPress cron behavior.

### 3. Internal crawler ingestion

Crawler workers write observations through internal service contracts, not public connector endpoints.

## Idempotency strategy

Every ingestion payload must have a deterministic natural key.

Examples:

- order: `(site_id, external_order_id)`
- product: `(site_id, external_product_id)`
- GSC daily: `(site_id, date, source)`
- query snapshot: `(site_id, source, date, query, page?, country?, device?, search_appearance?)`
- rank observation: `(keyword_id, date, provider)`
- crawl run: internal run ID; issue fingerprint stable across runs

Use UPSERTs for retry-safe writes.

## Batch protocol

For expanded plugin sync:

1. `sync/start`
2. send one or more data families with `sync_batch_id`
3. report per-family success/failure
4. `sync-complete`
5. server schedules derived metric rebuilds

A batch is allowed to be partially degraded; individual source failures should not erase previous good data.

## High-water marks

Persist source-specific cursors:

```text
last_order_modified_at
last_product_modified_at
last_customer_sync_at
last_gsc_date
last_ga4_date
last_bing_date
```

Do not use one global "last synced" timestamp for all providers.

## Sync states

Normalized states:

```text
not_configured
connected
syncing
degraded
error
disabled
```

Store:

- last attempted
- last successful
- latest data date
- last error code
- last error message

The UI must distinguish "connected but provider data is delayed" from "integration broken."

## Retry policy

Recommended:

- network/429/5xx: exponential backoff + jitter
- auth/permission errors: stop automatic retry, mark action required
- validation errors: fail immediately, log structured field errors
- rate limit: use provider-specific retry-after headers when available

## Reconciliation jobs

Even with event-driven sync, run reconciliation because webhooks/hooks can be missed.

### WooCommerce

- event queue: near-real-time
- hourly lightweight reconciliation
- daily full or rolling reconciliation

### Billing

- webhooks are primary
- periodic Paddle reconciliation job repairs drift

### Integrations

- scheduled sync per provider
- manual "sync now" with cooldown

## Derived metrics

Do not compute every expensive metric during ingestion.

Flow:

```text
raw normalized observations
    -> queue derived computation
    -> snapshots/insights/alerts
```

This keeps ingestion fast and retry-safe.

## Sync observability

Each sync record should include:

```text
organization
site
source
batch/run id
started_at
completed_at
status
records_read
records_written
records_updated
records_skipped
attempts
error_code
error_summary
```

## Acceptance criteria

- replaying the same payload produces no duplicates
- a failed GSC sync does not block Cloudflare/GA4 sync
- WordPress frontend/admin response never waits for a complete store sync
- sync freshness is visible to users
- provider permission errors generate actionable UI states
