# Cloudflare crawler analytics and missing INP

## Plan

- Confirm the Cloudflare GraphQL failure against connected production zones and test the documented replacement.
- Update crawler collection and hide the card when the selected range has no usable AI crawler rows. Hide the INP tile when the selected PageSpeed result has no measurement.
- Run frontend and API checks, deploy pinned images with a rollback copy, sync Cloudflare and verify the two dashboard views.

## Findings and changes

- Cloudflare reported `unknown field "clientRequestUserAgent"` for valid linked credentials. The API now queries `userAgent`, using the documented `userAgent_like` filters and real client requests. The dashboard shows positive AI user-agent matches when available. The unsupported blocked-count column and empty/raw-error card were removed.
- Performance Tracking shows INP only when the active device result includes a finite measurement. LCP and CLS fill the available columns when INP is missing.

## Production and verification

- Deployed source `75a547f9f33b028cee2bc6f4fd9b1f20012a7fe1` from `codex/appsumo-production-review-fixes`. API digest `sha256:50951c70d6fd044c5b697aa0a0acefde33e03e0f396be96449f8e79f7d6494fe`. App digest `sha256:44e414f3fd68612de402b0ef333824975334c737516217e667383d63c5edb881`.
- VPS stack `/docker/insightistic-app`. Rollback and gzip-verified Postgres dump at `backups/cloudflare-crawlers-20261119T100130Z`. Updated the pinned image tag and release metadata. No database migration. Both public version endpoints, the API health check and both dashboard routes passed.
- Refreshed the Cloudflare crawler dataset for the 30-day window through the deployed service. Four projects returned positive AI crawler rows. The credential saved on the specific `www.insightistic.com` project returned HTTP 401. Its signed-in Cloudflare dashboard displayed historical edge traffic and the Traffic gap section with the AI crawlers card omitted.
- In the signed-in Brother Tours project, Mobile and Desktop retained LCP and CLS with the missing INP tile omitted. The frontend regression test confirmed a measured INP still appears and disappears when switching back to the device with no measurement.
- 218 frontend tests, 13 targeted Laravel tests and TypeScript passed. Both production Docker images built.
