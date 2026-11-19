# Insightistic operations and verification runbook

## Safe local start

Read `README.md`, `docs/INSTALL.md`, `docs/ENVIRONMENT-VARIABLES.md`, and `docs/TESTING.md` first. Use local `.env` files created from examples; never copy production secrets into the repository.

Frontend checks:

```powershell
Set-Location 'D:\All Projects\WPISTIC-GALAXY-ECOSYSTEM\INSIGHTISTIC\insightistic-app'
npm ci
npm test
npx tsc --noEmit
npm run build
```

Backend checks:

```powershell
Set-Location 'D:\All Projects\WPISTIC-GALAXY-ECOSYSTEM\INSIGHTISTIC\insightistic-api'
composer install
php artisan test
```

Only run database migrations, seeders, queue operations, or billing tests against an explicitly selected environment. Confirm the target before using `--force`.

## Git safety

```powershell
Set-Location 'D:\All Projects\WPISTIC-GALAXY-ECOSYSTEM\INSIGHTISTIC'
git status --short --branch
git diff --stat
git diff --check
```

Do not clean, reset, checkout over, or commit unrelated user work. Before a production release, identify exactly which changes belong to the release.

## VPS deployment gates

1. Confirm the intended source commit and image tag.
2. Run frontend/backend tests and build checks.
3. Confirm the GHCR image exists and record its digest.
4. Back up the VPS compose/env references without exposing secrets.
5. Update only the intended deployment configuration.
6. Pull the selected image and restart the intended compose services.
7. Check all service statuses and recent logs.
8. Check API health and app HTTPS routes.
9. Exercise a safe, non-payment customer flow.
10. Record the release, evidence, and rollback reference in a dated work log.

Do not test real purchases, refunds, production emails, OAuth account creation, or destructive customer actions without explicit authorization and a safe test plan.

## WordPress/Hostinger checks

Use the approved Bridgistic connector or Hostinger workflow. Before writing:

- confirm the exact domain and WordPress installation;
- inspect active theme/plugin and current cache state;
- create or identify a recovery point;
- change only the intended theme/plugin/page/template;
- clear the correct page/object/CDN cache;
- verify the live URL, status code, rendered layout, console/network errors, and mobile layout;
- record the result without storing credentials.

The public site and app repository are separate systems. A local file change in this repository does not prove a WordPress site changed.

## Performance verification

For a speed task, record:

- URL, device profile, test location/tool, and timestamp;
- mobile and desktop results separately;
- LCP, CLS, INP/TBT, FCP, Speed Index where available;
- image dimensions/format, render-blocking, forced reflow, long tasks, and accessibility findings;
- whether the result is a fresh run or historical screenshot.

Do not call an optimization complete from a code diff alone. A fresh score may be blocked by rate limits; record that as blocked rather than inventing a result.

## Incident and rollback

When production breaks:

1. Stop further changes.
2. Capture status, failing URL, timestamp, recent deployment commit/tag/digest, and safe logs.
3. Check whether the failure is frontend, API, database, queue, routing, DNS, WordPress, or cache.
4. Use the documented rollback/snapshot path.
5. Recheck health and critical flows.
6. Record the cause, mitigation, and follow-up prevention work.

