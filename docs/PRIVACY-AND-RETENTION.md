# Privacy & Data Retention

## What Insightistic stores per tenant

- **Commerce**: orders/items/products aggregates; customers with **hashed
  emails** (sha256, plugin-side — raw addresses never reach the platform),
  first/last name, city, country, totals.
- **Traffic/SEO/performance**: aggregate daily rows and snapshots.
- **Knowledge**: documents the organization explicitly uploads, crawls or
  syncs (WordPress content sharing is **opt-in, default OFF** in the
  plugin; per-post exclusion via `_insightistic_exclude` meta).
- **AI conversations**: threads/messages/citations/run telemetry.

## Consent & external AI processing

- `organizations.allow_external_ai` — when disabled, all AI features run on
  the local deterministic provider; no content leaves the platform.
- Basic PII redaction (emails, long digit runs) is applied to knowledge
  text before any external model call.
- AI answers only ever cite the organization's own data.

## Deletion guarantees

| Action | Effect |
|---|---|
| Delete knowledge source | Chunks + embeddings removed (DB cascade); API response reports the verified remaining chunk count (always 0); stored file deleted from the knowledge disk. |
| Delete knowledge base | All sources, chunks, embeddings and ingestion jobs cascade; stored files deleted. |
| Delete Copilot thread | Messages, runs, citations and feedback cascade. |
| Plugin content-delete event | `connector/v1/content/bulk` with `deleted:true` removes the source + chunks. |
| Site disconnect (admin) | Connector credentials revoked; site data retained until deletion workflows run. |

Verified by tests (`KnowledgeBaseTest`, `CopilotTest`, `ConnectorHmacTest`).

## Retention

`plans.limits_json.data_retention_days` records each plan's retention
entitlement (180–1825 days seeded). Automatic pruning jobs are **not yet
implemented** — retention is currently a plan attribute surfaced for
enforcement in a follow-up (see ADVANCED-UPGRADE-PLAN.md §2 P10). Manual
pruning can use standard SQL against the dated tables; all data tables are
indexed by (site_id, date).

## Operator guidance

- Do not use production customer data in development fixtures — use
  `DemoSeeder`.
- Backups: `pg_dump` the `pgdata` volume nightly before deploys (see
  docs/DEPLOYMENT.md); knowledge files live on the knowledge disk (S3
  versioning recommended in production).
- Audit history: super-admin mutations, impersonation, super-admin
  promotions/demotions and bootstrap-token events are recorded in
  `admin_audit_logs`.
- GDPR erasure requests for store customers should be executed against
  `wc_customers`/`wc_orders` by site; a self-serve organization
  data-export/erasure workflow is tracked as remaining work.
