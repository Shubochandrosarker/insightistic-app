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
- Direct integrations (GA4/GSC/Cloudflare): consent-URL state binding and
  single-use burn, server-side code exchange with no token in the redirect,
  access-revoked-mid-consent, provider syncs into `traffic_daily`/`seo_daily`,
  token refresh preserving the refresh token, revoked-grant handling,
  Cloudflare token verification, disconnect cleanup, cross-tenant 404s.
- Email analytics: encrypted-at-rest API keys never echoed, Mailchimp
  data-centre validation before any outbound call, derived delivered count,
  re-sync idempotency, connector-pushed campaigns, and the null-vs-zero rule
  for unreported counters.
- MFA: two-step enrolment, hashed recovery codes, TOTP replay refusal,
  two-phase login with a burn-on-first-attempt challenge, reauth bound to a
  single access token, and new-device login notifications.
- Stripe webhooks: forged and stale signatures rejected, redelivery
  processed exactly once, a duplicate cannot drag state backwards, and the
  Super Admin replay path still re-runs deliberately.
- Licensing: activation, idempotent re-activation with secret rotation,
  revoked keys, trial expiry, the payment grace window either side of its
  boundary, cancelled-until-period-end, entitlement override precedence, and
  production-only activation limits.

## Frontend

```bash
cd insightistic-app
npm ci
npx tsc --noEmit     # type check
npm test             # component tests (vitest + testing-library, jsdom)
npm run build        # production build
npm audit --omit=dev # dependency audit (CI fails on high/critical)
```

Component tests live in `insightistic-app/tests/` and render the real
components with the API layer stubbed per test — components are not
rewritten for testability. Next's router and search params are stubbed in
`tests/setup.ts`.

### What is covered

- **Emails page**: unreported metrics render as an em dash and never as
  `0`/`0%`; a genuinely reported zero stays a zero; both connection routes
  are offered when nothing is connected; an API failure shows the message
  and a retry rather than a blank page.
- **Login with MFA**: a correct password alone never navigates to the
  dashboard when two-factor is on; the code step exchanges for a session; an
  expired challenge returns to the password form while a merely wrong code
  keeps the code step open; recovery codes are mentioned so a lost device
  is not a dead end.

Playwright E2E is still outstanding — it needs the API, the app and
PostgreSQL running together, which is a harness rather than a test file.

## WordPress plugin

```bash
find insightistic -name '*.php' -exec php -l {} \;   # syntax
# CI also runs an ABSPATH-guard check and informational WPCS
# (WordPress-Extra) — see .github/workflows/ci.yml.
```

## CI

`.github/workflows/ci.yml` runs on every push/PR:

| Job | Covers |
|---|---|
| Frontend | typecheck, **component tests**, production build, npm audit (fails on high/critical in production deps) |
| Backend | Pest on pgvector PostgreSQL + Redis, migration up/rollback/up from an **empty** database, migration against an **already-populated** one, informational Pint |
| Plugin | PHP syntax, ABSPATH guards, informational WPCS |
| Docker | both images build |
| Secret scanning | gitleaks |
| Dependency review | flags known-vulnerable dependencies a PR introduces, across npm and composer |

GHCR image publishing (`docker-ghcr.yml`) is gated on this entire suite.

The populated-database migration step exists because that is the case that
actually happens on deploy; the empty-database path alone would miss a
migration that works on a fresh install but fails against real rows.

Pint is informational rather than blocking: the codebase deliberately aligns
`=>` in arrays and uses a brace style Pint's default Laravel preset
rewrites, so gating today would fail on ~180 pre-existing files. Adopting a
`pint.json` that matches house style, then flipping the step to blocking, is
tracked follow-up work.
