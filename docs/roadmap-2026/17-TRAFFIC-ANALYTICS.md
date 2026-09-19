# Traffic Analytics

## Goal

Unify behavioral analytics with edge traffic and search landing-page performance while preserving metric definitions.

## Sources

- GA4
- Cloudflare
- WordPress engagement events
- referral detection for AI sources

## GA4 module

Target dimensions/measures:

- users
- sessions
- views
- engaged sessions
- engagement rate
- conversions/key events as configured
- revenue where applicable
- channel/source/medium
- landing page
- country
- device

## UI

### Overview

- users
- sessions
- pageviews/views
- engagement
- conversions
- revenue if available

### Acquisition

- default channel group
- source/medium
- organic/social/referral/email/paid

### Landing Pages

Enrich with:

- GSC clicks/impressions
- crawl health
- performance status
- revenue where relevant

### Geography / Devices

Provide supporting context rather than overwhelming the main overview.

## AI referral traffic

Maintain a normalized domain/source registry for AI referral sources.

Examples may change over time; keep this configuration server-managed.

Report:

- sessions/users from recognized AI referrals
- landing pages
- conversions/revenue if configured
- trend

Do not assume all AI influence produces referral traffic; many AI answer interactions have no outbound click.

## Edge/client reconciliation

Cloudflare and GA4 measure different layers. The product should explicitly explain the difference.

Good comparison:

> "Cloudflare observed 2.6× more edge page-view-like requests than GA4 client views. This can reflect bots, blocked scripts, consent behavior, and measurement differences."

Bad comparison:

> "GA4 missed exactly 1,932 users."

## Privacy

- do not ingest unnecessary GA4 user-level identifiers
- keep reports aggregate by default
- document retention

## Acceptance criteria

- GA4 data source freshness is visible
- source/medium reports do not double-count compared periods
- landing pages can deep-link into Search/Audit/Performance modules
- AI referrals use an updateable classification registry
