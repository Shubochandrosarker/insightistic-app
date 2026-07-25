# Data Sources

Where every number in the dashboard comes from — and the honest state of
each integration.

| Source | State | Path |
|---|---|---|
| WooCommerce commerce | **Live** | Plugin HMAC sync (incremental daily + weekly reconciliation + realtime order/product events) → `wc_*` tables → snapshots → Analytics API |
| Site health | **Live** | Plugin `site-health` payloads |
| WordPress content → AI knowledge | **Live (opt-in)** | Plugin `content/bulk` → site-scoped knowledge base |
| Traffic (GA4) | **Live** | Server-side Google OAuth → `Ga4Client` → `traffic_daily` (source `ga4`) + landing-page snapshots. Also accepts connector `sync/traffic/*` payloads. |
| SEO (GSC) | **Live** | Server-side Google OAuth → `GscClient` → `seo_daily`, `seo_query_snapshots`, `seo_page_snapshots` (with previous-period deltas). Also accepts connector `sync/seo/*`. |
| Performance (PageSpeed) | **Ingest-ready** | `sync/performance/runs` + tables; SaaS-side scheduled Lighthouse runner remaining |
| Cloudflare traffic | **Live (BYO token)** | Per-site zone + API token (encrypted) → `CloudflareTrafficService`; connect/verify/zone-picker on `/dashboard/integrations`; daily rows into `traffic_daily` (source `cloudflare`); plugin proxy endpoint `plugin/cloudflare-traffic` |
| Email analytics | **Live** | Mailchimp/Klaviyo pulled by the SaaS via per-site API key; FluentCRM/AutomateWoo/WooCommerce pushed by the plugin → `email_campaigns` → `/dashboard/emails` |
| Alerts | **Live** | Deterministic `AlertDetector` rules, scheduled 6-hourly |
| AI insights / Copilot | **Live** | `ContextAssembler` + knowledge retrieval; provider-configurable |

Data-source connection state is tracked per site in
`data_source_connections` (state, last success, last error, freshness) and
surfaced on `/dashboard/integrations`.

## Direct connections (GA4, Search Console, Cloudflare)

GA4 and Search Console use **server-side** Google OAuth. The browser only
ever receives a consent URL; the authorization code is exchanged
server-to-server and the refresh token is stored encrypted in
`data_source_credentials` (`encrypted` cast, APP_KEY). No endpoint returns
token material, the model marks both token columns `$hidden`, and the
callback redirect carries only a coarse status — never a token. Cloudflare
uses a scoped API token instead of OAuth and reuses the existing encrypted
`sites.cloudflare_*` columns, so there is one source of truth shared with the
plugin's Advanced/BYO path.

Connection lifecycle, all under `/api/sites/{site}/integrations`:

| Step | Endpoint | Notes |
|---|---|---|
| Start OAuth | `POST {source}/authorize-url` | Mints a single-use `state` + PKCE S256 verifier bound to site, source and user |
| Finish OAuth | `GET /api/integrations/google/callback` | Public (Google redirects the browser); state-validated, throttled, re-checks org access |
| Connect Cloudflare | `POST cloudflare/connect` | Token verified against Cloudflare before it is stored |
| List properties/zones | `GET {source}/resources` | |
| Choose one | `POST {source}/select` | Marks the source connected and queues a first sync |
| Verify | `POST {source}/test` | Round-trips the provider without writing analytics rows |
| Manual resync | `POST {source}/resync` | Queues `SyncDataSource` (1–365 days) |
| Disconnect | `DELETE {source}` | Revokes at Google where possible, deletes the credential, resets state |

Reading integration status needs only site access; every mutation requires
**owner or admin**. A site in another organization returns 404, never 403.

Scheduled sync runs nightly at 01:20 via
`insightistic:sync-data-sources`, before the 02:00 snapshot build so
snapshots are computed over freshly pulled data. Only sources with both a
credential and a selected property are queued. `SyncDataSource` retries rate
limits and provider 5xx with exponential backoff (60s, 300s); a revoked
grant is terminal and surfaces on the Integrations page as
`reauth_required` rather than retrying forever.

Required configuration: `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` and
`INTEGRATIONS_GOOGLE_REDIRECT_URI` (must match the redirect URI registered in
the Google Cloud console). See `docs/ENVIRONMENT-VARIABLES.md`.

## Email analytics

Two ingestion paths land in the same `email_campaigns` table, so a site can
run several providers at once and the dashboard aggregates across them:

| Provider | Path | Credential |
|---|---|---|
| Mailchimp | SaaS pulls `/reports` (one call returns the whole funnel + ecommerce revenue) | Per-site API key; the data centre comes from the key's `-us14` suffix |
| Klaviyo | SaaS lists `/campaigns`, then a campaign-values report for statistics | Per-site private API key |
| FluentCRM, AutomateWoo, WooCommerce mail | Plugin pushes `POST connector/v1/sync/email/campaigns` over HMAC | None — these live inside WordPress and are not reachable from the SaaS |

API keys reuse `data_source_credentials` (source `email`), so they inherit
the same encrypted-at-rest, never-serialised handling as the Google and
Cloudflare grants. Both paths upsert on `(site_id, provider, external_id)`
through one shared `EmailAnalyticsService::store()`, so a retried batch or a
repeated sync converges instead of duplicating.

**Counters are nullable on purpose.** "This provider does not report opens"
and "nobody opened it" are different facts, and the dashboard has to be able
to tell them apart. Unreported metrics stay `null` end to end and render as
`—`, never as a confident `0%`. Aggregates follow the same rule: a total is
null unless at least one campaign reported that counter.

Endpoints under `/api/sites/{site}/emails`: `overview` and `campaigns` need
site access; `connect`, `sync` and `DELETE connection` require owner or
admin. Disconnecting removes the credential but keeps reporting history.

Rules of the platform: no mock analytics, no fabricated AI responses, no
invented citations — a source with no data shows an honest empty state.
