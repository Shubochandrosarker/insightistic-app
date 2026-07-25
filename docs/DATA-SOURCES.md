# Data Sources

Where every number in the dashboard comes from — and the honest state of
each integration.

| Source | State | Path |
|---|---|---|
| WooCommerce commerce | **Live** | Plugin HMAC sync (incremental daily + weekly reconciliation + realtime order/product events) → `wc_*` tables → snapshots → Analytics API |
| Site health | **Live** | Plugin `site-health` payloads |
| WordPress content → AI knowledge | **Live (opt-in)** | Plugin `content/bulk` → site-scoped knowledge base |
| Traffic (GA4-shaped) | **Ingest-ready** | `connector/v1/sync/traffic/*` endpoints + `traffic_daily`/`traffic_dimension_snapshots` tables exist; dashboards read them; a first-party GA4 OAuth sync worker is remaining work (docs/ADVANCED-UPGRADE-PLAN.md §2 P3) |
| SEO (GSC-shaped) | **Ingest-ready** | `sync/seo/*` endpoints + tables; GSC OAuth worker remaining |
| Performance (PageSpeed) | **Ingest-ready** | `sync/performance/runs` + tables; SaaS-side scheduled Lighthouse runner remaining |
| Cloudflare traffic | **Live (BYO token)** | Per-site zone + API token (encrypted) → `CloudflareTrafficService`; plugin proxy endpoint `plugin/cloudflare-traffic` |
| Email analytics | **Honest empty state** | `/dashboard/emails` shows the integration setup state; Mailchimp/Klaviyo/FluentCRM adapters are remaining work |
| Alerts | **Live** | Deterministic `AlertDetector` rules, scheduled 6-hourly |
| AI insights / Copilot | **Live** | `ContextAssembler` + knowledge retrieval; provider-configurable |

Data-source connection state is tracked per site in
`data_source_connections` (state, last success, last error, freshness) and
surfaced on `/dashboard/integrations`.

Rules of the platform: no mock analytics, no fabricated AI responses, no
invented citations — a source with no data shows an honest empty state.
