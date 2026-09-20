# Repository & Module Structure

## Repository strategy

Keep the SaaS and WordPress plugin versioned independently.

### SaaS repository

```text
insightistic-app/
├── insightistic-app/        # Next.js frontend
├── insightistic-api/        # Laravel API
├── docs/                    # project-specific runbooks
├── docker/
└── .github/
```

### Plugin repository

```text
insightistic/
├── insightistic.php
├── src/                     # target source modules
├── includes/                # compatibility/bootstrap classes during migration
├── assets/
├── templates/
├── tests/
├── scripts/
└── languages/
```

## Laravel target structure

Do not turn every feature into one giant controller or service. Use domain folders while keeping Laravel conventions recognizable.

```text
app/
├── Domain/
│   ├── Billing/
│   ├── Entitlements/
│   ├── Search/
│   ├── Crawler/
│   ├── RankTracking/
│   ├── AiVisibility/
│   ├── Traffic/
│   ├── Performance/
│   ├── Commerce/
│   └── Automations/
├── Http/
│   ├── Controllers/Api/
│   ├── Middleware/
│   └── Requests/
├── Jobs/
├── Models/
├── Policies/
├── Services/
└── Support/
```

If a full Domain structure creates too much churn immediately, use it for new major subsystems first and migrate older code opportunistically.

## Next.js target structure

```text
app/
├── dashboard/
│   ├── page.tsx
│   ├── search/
│   │   ├── performance/
│   │   ├── rank-tracker/
│   │   ├── index/
│   │   └── opportunities/
│   ├── ai-visibility/
│   ├── audit/
│   ├── performance/
│   ├── traffic/
│   ├── commerce/
│   ├── intelligence/
│   ├── reports/
│   └── settings/
components/
├── app-shell/
├── charts/
├── data-table/
├── dashboard/
├── forms/
├── states/
└── ui/
lib/
├── api/
├── auth/
├── dashboard/
├── entitlements/
└── format/
```

## WordPress plugin target structure

The plugin should stop accumulating all UI logic in one source file.

```text
src/
├── Core/
│   ├── Bootstrap.php
│   ├── Capabilities.php
│   ├── Encryption.php
│   └── Settings.php
├── Cloud/
│   ├── LicenseManager.php
│   ├── SaasClient.php
│   ├── ConnectorSigner.php
│   └── SyncManager.php
├── Integrations/
│   ├── GA4/
│   ├── GSC/
│   ├── Cloudflare/
│   ├── PageSpeed/
│   └── Bing/
├── Commerce/
├── Engagement/
├── Admin/
└── Rest/
```

Front-end source:

```text
assets-src/
├── admin/
│   ├── core.js
│   ├── overview.js
│   ├── search.js
│   ├── cloudflare.js
│   ├── commerce.js
│   └── settings.js
└── tracking/
```

Build output can remain under `assets/js` and `assets/css`.

## Module ownership rule

A module owns:

- its provider adapter/client
- normalization logic
- data model
- jobs
- API endpoints
- UI pages/components
- tests

Shared code is extracted only when two real modules need it. Avoid creating speculative abstraction layers.

## Naming rules

### Internal source names

Use stable slugs:

```text
gsc
bing
cloudflare
ga4
pagespeed
wordpress
woocommerce
crawler
rank
ai_visibility
```

### Feature entitlement slugs

Use explicit names instead of ambiguous bundles:

```text
search_console_advanced
bing_webmaster
technical_crawler
rank_tracking
ai_visibility
cloudflare_intelligence
woocommerce_intelligence
advanced_reports
scheduled_reports
agency_white_label
team_access
api_access
automations
```

## Migration policy

Avoid a giant refactor before shipping features.

Recommended approach:

1. establish new boundaries
2. route new work through them
3. add compatibility wrappers for old classes
4. migrate old modules when touched
5. remove wrappers only after release coverage proves safe
