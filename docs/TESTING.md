# Testing

## Backend (Pest on PostgreSQL)

The schema uses `jsonb`, generated tsvector columns and pgvector, so tests
run against real PostgreSQL — not sqlite.

One-time local setup:

```bash
sudo -u postgres psql -c "CREATE USER insightistic WITH PASSWORD 'insightistic' CREATEDB;"
sudo -u postgres createdb -O insightistic insightistic_test
sudo -u postgres psql -d insightistic_test -c 'CREATE EXTENSION IF NOT EXISTS vector;'  # optional but recommended
```

Run:

```bash
cd insightistic-api
composer install
./vendor/bin/pest            # or: php artisan test
```

Connection settings live in `phpunit.xml` (127.0.0.1 /
`insightistic_test` / `insightistic`/`insightistic`). The queue runs sync
and mail is arrayed in tests.

### What is covered

- Registration can never create a super admin (incl. legacy env set and
  mass-assignment smuggling); disabled/invited login blocked.
- OAuth: state validation + replay, no tokens for disabled/suspended/
  archived/invited users, no auto-reactivation, verified-email linking
  policy, no super-admin grant.
- Super-admin bootstrap tokens: single use, wrong-user rejection + burn,
  command guards, audit entries.
- Session management: list/revoke-one/revoke-others, cross-user 404.
- Connector HMAC: plugin-parity signing accepted; bad signature, stale
  timestamp and nonce replay rejected; WordPress content ingestion round
  trip incl. deletion cleanup.
- Knowledge: CRUD, plan limits (structured 402s), MIME rejection, manual +
  file ingestion end-to-end, failed-ingestion honesty (SSRF-blocked URL),
  deletion with verified chunk cleanup, role enforcement.
- Tenant isolation: knowledge bases/sources/chunks/retrieval (incl. forged
  filters), sites.
- Copilot: SSE stream events, real citations (org-owned chunks only), run
  telemetry, quota 402 + metering-on-success-only, thread isolation
  (cross-org and cross-user), cascade deletion, feedback upsert.
- SSRF unit matrix: private/loopback/link-local/CGN/metadata IPs, IPv6,
  internal hostnames, schemes, ports, credentials — all offline.

## Frontend

```bash
cd insightistic-app
npm ci
npx tsc --noEmit     # type check
npm run build        # production build
npm audit --omit=dev # dependency audit (CI fails on high/critical)
```

Component/E2E test frameworks are not yet installed — see
docs/ADVANCED-UPGRADE-PLAN.md §11 for the planned Vitest + Playwright
suites (tracked as remaining work).

## WordPress plugin

```bash
find insightistic -name '*.php' -exec php -l {} \;   # syntax
# CI also runs an ABSPATH-guard check and informational WPCS
# (WordPress-Extra) — see .github/workflows/ci.yml.
```

## CI

`.github/workflows/ci.yml` runs on every push/PR: frontend typecheck +
build + audit, backend Pest with a migration up/rollback/up pass from an
empty pgvector database + Redis, plugin checks, both Docker builds, and
gitleaks secret scanning. GHCR image publishing
(`docker-ghcr.yml`) is gated on this entire suite.
