# Integration Framework

## Supported target sources

### P0 / core

- WordPress plugin
- WooCommerce
- Google Search Console
- Google Analytics 4
- Cloudflare
- Bing Webmaster
- PageSpeed Insights / CrUX where available
- Paddle

### P1

- IndexNow
- SERP provider
- AI answer providers/surfaces
- email platforms already supported or planned

## Integration lifecycle

Every integration follows the same state machine:

```text
not_configured
  -> connecting
  -> connected
  -> syncing
  -> connected
  -> degraded/error
  -> reconnect
  -> disconnected
```

## Common connection record

Use `data_source_connections` for shared metadata and state.

Fields:

- source
- auth type
- encrypted credentials/reference
- account/resource labels
- scopes
- last success
- latest data timestamp/date
- error code/message
- metadata

## Provider adapters

Each direct provider implements a stable internal interface such as:

```php
interface DataSourceAdapter
{
    public function listResources(Connection $connection): array;
    public function verify(Connection $connection): VerificationResult;
    public function sync(Site $site, SyncWindow $window): SyncResult;
    public function disconnect(Connection $connection): void;
}
```

OAuth handling is provider-specific, but state validation and encrypted token storage should use shared infrastructure.

## Google

Use server-side OAuth for the SaaS connection. Keep login OAuth scopes separate from analytics/search scopes.

Do not silently upgrade "Sign in with Google" into GSC/GA4 access.

## Cloudflare

Target connection modes:

1. SaaS-hosted per-site Cloudflare token (recommended)
2. plugin BYO token for users who prefer local credential configuration

Normalize both paths to the same data shape.

## Bing

Implement API-key/OAuth mechanism according to current Bing Webmaster API requirements and isolate credentials in the SaaS. Add IndexNow key management separately.

## Provider data normalization

UI modules should consume normalized internal DTOs, not raw provider payloads.

Example search metric DTO:

```json
{
  "date": "2026-09-19",
  "source": "gsc",
  "clicks": 91,
  "impressions": 4800,
  "ctr": 0.0189,
  "position": 17.4
}
```

## Integration settings UI

Each card displays:

- provider
- state
- account/resource
- last successful sync
- data freshness
- permissions warning if needed
- connect/reconnect/disconnect
- sync now

Avoid hiding error details behind generic "Something went wrong." Provide provider-specific remediation.

## Data ownership rule

Disconnecting an integration should stop future sync. It should not necessarily delete historical analytics immediately. Users need a separate delete-data action with clear consequences.
