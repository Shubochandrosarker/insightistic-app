# API Contracts & Conventions

## API goals

- stable internal contract between Next.js and Laravel
- stable HMAC connector contract for WordPress
- predictable errors
- idempotent ingestion
- explicit authorization
- versionable public/partner API later

## Route groups

Recommended:

```text
/api/auth/*
/api/organizations/*
/api/sites/*
/api/sites/{site}/analytics/*
/api/sites/{site}/search/*
/api/sites/{site}/crawl/*
/api/sites/{site}/rank/*
/api/sites/{site}/ai-visibility/*
/api/sites/{site}/performance/*
/api/sites/{site}/integrations/*
/api/reports/*
/api/billing/*
/api/licenses/*
/api/connector/v1/*
/api/plugin/*
/api/admin/*
```

## Response envelope

Do not force every endpoint into a verbose envelope, but use consistent error shape.

### Success

Resource/list payload can be direct JSON with pagination metadata where needed.

### Error

```json
{
  "message": "Tracked keyword limit reached.",
  "code": "tracked_keyword_limit_reached",
  "details": {
    "limit": 750,
    "used": 750
  }
}
```

HTTP semantics:

- `400` malformed business request
- `401` unauthenticated
- `403` authenticated but forbidden
- `404` resource hidden/not found
- `409` state conflict
- `422` validation
- `429` rate/quota throttling where appropriate
- `402` may be used for product entitlement/paid-limit cases if existing API convention already uses it, but keep behavior consistent
- `500/503` server/provider failure

## Validation

Use Laravel Form Requests for complex endpoints.

Never trust:

- site ID from frontend without tenant validation
- plan slug from checkout without active-plan validation
- organization ID inside Paddle custom data without mapping validation
- provider resource IDs without verifying access

## Pagination

Use cursor pagination for very large observation tables where possible.

Example:

```json
{
  "data": [...],
  "meta": {
    "next_cursor": "...",
    "has_more": true
  }
}
```

## Date ranges

Use one common query contract:

```text
period=last_30_days
```

or explicit:

```text
start=2026-08-01&end=2026-08-31
```

Comparison:

```text
compare=previous_period
```

Server resolves site timezone.

## Connector API

### Authentication headers

```text
X-INS-Key-Id
X-INS-Timestamp
X-INS-Nonce
X-INS-Signature
```

### Versioning

Keep `/api/connector/v1/` stable. Breaking payload changes require `/v2/`; additive fields can remain v1 when backward compatible.

## Ingestion errors

Return structured per-batch error summary. Do not expose stack traces to WordPress.

## Idempotency keys

For user-triggered expensive actions such as report generation or manual crawl, accept optional idempotency key and/or establish server lock to prevent double-click duplicate work.

## API rate limits

Suggested namespaces:

- auth
- human API
- connector
- billing webhook
- public/share links
- future public API keys

Provider webhooks should not share user rate-limit rules; signature/idempotency is the main protection.

## API documentation

Generate OpenAPI for stable public/internal endpoints where practical. At minimum document:

- method/path
- auth
- request schema
- response schema
- error codes
- entitlement requirement
- usage unit charged
