# Cloudflare Edge Intelligence

## Product goal

Turn Cloudflare into a flagship differentiator: server/edge-level traffic, caching, security and crawler visibility that client-side analytics cannot provide.

## Current foundation

Existing code already includes Cloudflare GraphQL analytics paths and normalized metrics such as requests, cached requests, bytes, threats, page views, encrypted requests, country/status/TLS/browser breakdowns and firewall events.

## Product sections

### Traffic & Edge Overview

KPIs:

- requests
- edge pageviews where available
- unique visitors where available
- bandwidth
- cached requests
- cache hit ratio
- bandwidth saved
- encrypted request ratio
- threats
- 4xx
- 5xx

### Geographic traffic

- requests by country
- threats by country
- changes over time

### Cache intelligence

- cache hit ratio trend
- cached bytes vs total bytes
- top uncached paths where provider data permits
- sudden cache-ratio regression alert

### Security

- firewall actions
- threat trend
- response status spikes
- top attacking countries

### Crawler & bot intelligence

Where Cloudflare plan/dataset supports bot analytics, classify:

- Googlebot
- Bingbot
- GPTBot
- OAI-SearchBot
- ClaudeBot
- PerplexityBot
- AhrefsBot
- SemrushBot
- other verified/known crawlers

Provider availability varies by Cloudflare plan; the UI must degrade gracefully rather than pretending bot fields exist for every zone.

## AI Crawler Intelligence

Show:

- AI crawler requests
- crawler trend
- most crawled paths where data permits
- blocked/challenged/allowed status where derivable
- AI crawler share of bot traffic

Correlate with:

- AI visibility citations
- AI referral traffic

Do not claim a crawler visit caused an AI citation.

## Traffic Gap

Compare edge-level visibility to client-side analytics carefully.

Potential causes of a gap:

- bots/crawlers
- ad blockers
- JS disabled
- consent blocking
- analytics misconfiguration
- cached/edge requests that are not equivalent to pageviews

Label metrics precisely. Do not compare "requests" directly to GA4 "sessions" as if they are the same unit.

## Connection modes

### SaaS-hosted

Organization owner supplies a read-only scoped Cloudflare token in the SaaS and selects the zone.

### Plugin BYO

Plugin can query directly with locally encrypted credentials and optionally sync normalized results.

Preferred long-term source for agency workflows: SaaS-hosted connection.

## Security

- require least-privilege Cloudflare token
- encrypt token at rest
- never return token to frontend after save
- connection verification must not log token

## Sync cadence

- current-period edge overview: 15–60 min cache
- historical daily snapshots: nightly
- security events: plan/rate-limit aware

## Acceptance criteria

- connection can verify accessible zone
- unsupported datasets show "not available on this Cloudflare plan" rather than errors
- metrics preserve their native meaning
- bot/crawler insights are labeled by evidence source
- stale Cloudflare data is visible as stale
