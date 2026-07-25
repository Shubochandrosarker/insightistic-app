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
| **Organization deletion** | Two-stage. `POST /api/privacy/deletion` schedules a purge `deletion_grace_days` out (default 7) and can be cancelled until then; `insightistic:privacy-maintenance` carries it out. Foreign-key cascades clear the rows; `OrganizationPurger` additionally deletes the files a cascade cannot reach (knowledge uploads, generated reports, export archives) and removes member accounts that belong to **no other** organization. Super admins are never removed as a side effect. |

Verified by tests (`KnowledgeBaseTest`, `CopilotTest`, `ConnectorHmacTest`,
`PrivacyTest`).

## Data export

`POST /api/privacy/exports` queues a build; the archive is a zip with one
newline-delimited JSON file per table plus a `manifest.json` listing row
counts and what was withheld.

**Credentials are never included** — connector secrets, Cloudflare and
provider API tokens, OAuth refresh tokens, MFA secrets and recovery codes
are all excluded. An export is a file customers will move around; it must
not be a way to walk out with live keys. A test asserts the archive contains
the site's data but none of its secrets.

Tenant scoping is read from the live schema (`organization_id`, else
`site_id`) rather than a hand-maintained list, so a renamed column shows up
as an empty table in the manifest instead of failing mid-export, and a table
that has neither column is skipped rather than exported unscoped.

Archives expire after `PRIVACY_EXPORT_TTL_DAYS` (default 7) and the nightly
maintenance command deletes both the row and the file — a full tenant dump
sitting on disk indefinitely is a liability.

Export, deletion and the AI-processing toggle are **owner-only and behind a
fresh password confirmation** (`reauth`); reading the overview needs
owner or admin.

## Retention

`plans.limits_json.data_retention_days` records each plan's retention
entitlement, and `insightistic:privacy-maintenance` (nightly at 03:30, after
the 02:00 snapshot build so a purge never races the job reading the same
tables) enforces it.

**Retention applies to time-series analytics only** — `traffic_daily`,
`seo_daily`, `engagement_daily`, the dimension/query/page snapshots,
performance runs, broken-link snapshots and sync logs. Orders, customers,
licenses and subscriptions are commercial records the customer still needs;
silently deleting a two-year-old order because a plan says 90 days would
destroy data nobody expected to lose. Retention means "how far back the
dashboards keep detail", which is what the plan advertises. A test asserts
an out-of-window order survives a purge.

A missing or zero `data_retention_days` means unlimited. The window is read
from the plan row, so changing a plan in the database changes behaviour with
no deploy.

Run `insightistic:privacy-maintenance --dry-run` to see what would be
removed without changing anything.

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
  `wc_customers`/`wc_orders` by site. Self-serve organization export and
  erasure are available to owners at `/dashboard/privacy`.
- Restore: an export archive is NDJSON per table and is intended for
  portability and audit, not as a backup format. Point-in-time recovery
  comes from the nightly `pg_dump` plus the knowledge/report disks — see
  docs/DEPLOYMENT.md.
