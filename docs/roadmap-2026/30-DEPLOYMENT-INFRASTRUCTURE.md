# Deployment & Infrastructure

## Production services

Recommended minimum:

```text
Next.js frontend
Laravel API/PHP-FPM
nginx/reverse proxy
PostgreSQL 16+
Redis
queue workers
scheduler
crawler workers
object storage
```

## Domains

```text
insightistic.com       marketing
app.insightistic.com   dashboard
api.insightistic.com   API
```

Same-origin proxy mode for browser API calls may be retained where it reduces CORS complexity.

## Container strategy

Use one API image for:

- web API
- queue worker
- scheduler

with different commands/process roles.

Crawler may start in the same codebase/container family but should have a dedicated queue/worker pool.

## Environment separation

### Local

- local DB/Redis
- Paddle Sandbox
- sandbox/test provider credentials

### Staging

- production-like infrastructure
- Paddle Sandbox
- separate OAuth callback URLs
- test domains

### Production

- Paddle Live
- production OAuth/provider credentials
- secrets only in environment/secret store

Do not share Paddle sandbox/live catalog IDs.

## Database migrations

Rules:

- backward-compatible expansion before code switch
- long table migrations planned carefully
- never drop Stripe columns in same release that introduces provider-neutral columns
- backup before destructive migration

## Worker sizing

Keep critical queues isolated from crawler/AI spikes.

Example:

```text
api web            autoscale/light
critical workers   always-on
integration        small pool
crawler            separately scalable
ai/rank            cost/rate limited
reports            burstable
```

## PostgreSQL

- automated backups
- point-in-time recovery if available
- connection pooling when needed
- indexes reviewed for high-volume observation tables

## Redis

Redis loss should not destroy durable billing or analytics state. Queued jobs may need replay/reconciliation.

## Object storage

Store generated files outside container filesystem.

Use signed URLs for private exports.

## Deployment flow

1. CI tests
2. build frontend/API images
3. push image
4. database backup/check
5. run migrations
6. deploy web
7. deploy workers/scheduler
8. smoke tests
9. monitor errors/queues

## Rollback

A release is not production-ready without rollback plan.

- previous container image retained
- migrations designed to allow application rollback where possible
- feature flags for large subsystems
