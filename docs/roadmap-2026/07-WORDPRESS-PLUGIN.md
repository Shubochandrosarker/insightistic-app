# WordPress Plugin Architecture

## Role of the plugin

The plugin is the WordPress-native connector and local control surface. The SaaS is the primary deep intelligence workspace.

### Plugin should own

- site activation/linking
- secure connector credentials
- WordPress/WooCommerce environment data
- event-driven commerce sync
- optional local GA4/GSC/PageSpeed/Cloudflare views
- local broken-link/404 data collection
- safe local settings
- connection health and sync controls
- assisted-fix operations approved by the user

### Plugin should not duplicate

- full agency portfolio UI
- long historical cross-site analytics
- global AI visibility storage
- large crawl history
- billing state logic beyond displaying cached entitlement state

## Entitlement redesign

Current plugin messaging that most features are universally free conflicts with SaaS plan entitlements.

Target flow:

```text
Paddle purchase
  -> Laravel billing projection
  -> EntitlementResolver
  -> License payload
  -> plugin validation cache
  -> FeatureGate::can(feature)
```

The plugin does not hardcode the commercial plan matrix.

Example cached entitlement payload:

```json
{
  "status": "active",
  "plan": "growth",
  "features": [
    "search_console_advanced",
    "cloudflare_intelligence",
    "woocommerce_intelligence",
    "advanced_reports"
  ],
  "limits": {
    "sites": 10,
    "tracked_keywords": 750,
    "ai_prompts": 75
  },
  "validated_at": 1789850000,
  "grace_hours": 72
}
```

## Plugin source modularization

Maintain backward compatibility while migrating source classes to module folders.

Recommended components:

- `LicenseManager`
- `SaasClient`
- `ConnectorSigner`
- `SyncManager`
- `IntegrationRegistry`
- feature-specific integration services
- admin screens/controllers
- WordPress REST/AJAX handlers

## Sync model

### Event-driven WooCommerce sync

Listen to relevant WooCommerce hooks and enqueue small updates:

- new order
- order status/total changes
- refund
- product create/update
- stock change
- customer update where relevant

Do not send the event synchronously inside the checkout/admin request.

Queue via Action Scheduler when available.

### Reconciliation

- hourly incremental reconciliation where plan permits
- daily full reconciliation
- manual sync button
- cursor/high-water mark persisted

## Plugin-side scheduler

Use Action Scheduler for substantial tasks. WP-Cron remains a trigger/fallback, not the only workload executor.

## Credential handling

- never store raw SaaS license key after activation
- encrypt connector secret
- encrypt third-party API credentials
- do not expose secrets in localized JS
- support external encryption key via `wp-config.php` where currently available

## WordPress security

Every mutation must use:

- capability check (`manage_options` or more specific capability)
- nonce
- sanitization/validation
- escaped output

Remote callbacks must not trust values solely because they came from JS.

## Local data minimization

For customer data sent to the SaaS:

- send only what analytics requires
- hash email where direct identity is unnecessary
- avoid payment card/payment-token data entirely
- document exact fields in privacy documentation

## Admin UX

The local WordPress dashboard should be compact:

```text
Insightistic
├── Overview
├── Search
├── Performance
├── Commerce (if WooCommerce)
├── Connection
├── Add-ons / Automations
├── System Status
└── Settings
```

Link advanced analysis to the SaaS rather than recreating every SaaS table locally.

## Assisted fixes — future

Only implement fixes that are:

- deterministic
- reversible
- permission checked
- previewable
- logged

Examples:

- update missing alt text after user approval
- add/adjust meta fields through supported SEO-plugin APIs
- submit URL through IndexNow
- regenerate sitemap when supported

Never automatically rewrite production content without explicit approval.

## Release gates

Plugin release should fail if:

- version metadata mismatches
- assets are stale
- PHP syntax fails
- tests fail
- zip structure is wrong
- secrets/test keys are present
- required license/readme files are missing
