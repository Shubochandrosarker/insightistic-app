# Insightistic production release — 2026-11-18

## Outcome

- Deployed commit `c658b742b4cb2530a78be752577f4725048208d5` to the Hostinger VPS stack at `/docker/insightistic-app`.
- Updated API and app images were pulled by immutable commit tag; `latest` was not changed.
- Pre-deploy PostgreSQL, compose, and environment backups were created and verified before recreation.

## Release scope

- AI plugin insight and report charging now reserve/commit credits through the wallet flow, return live credit summaries, and enforce the free monthly report allowance without changing paid plan tariffs.
- Added the authenticated plugin AI-report charge route.
- Reconciled WordPress/WooCommerce connector state during plugin handshake and sync.
- Added app AI credit summaries to usage and license views.
- Pinned Next.js to `16.4.6` and limited build CPUs for stable production builds.
- Stripe checkout/webhooks and legacy AppSumo routes were preserved; no Paddle checkout path was introduced or made active.

## Verification

- Frontend: `npm test` passed with 34 files and 222 tests; production build passed.
- PHP: changed files passed syntax checks locally and inside the built API image.
- Docker: API and app production images built successfully and were pushed to GHCR.
- VPS: API/app/db/nginx/redis health checks passed; queue and scheduler were running; migrations reported `Nothing to migrate`.
- HTTPS smoke checks returned 200 for app pages and API health/version endpoints. Protected plugin AI endpoints rejected unauthenticated requests with 401.
- Live route inventory still includes Stripe checkout/webhooks and AppSumo claim/webhook/OAuth routes. Post-deploy logs were clean.

## Limitations and safety decisions

- GitHub Actions repeatedly failed before job startup with zero-job `startup_failure`; this is reported as an infrastructure limitation, not as passing hosted CI.
- No authenticated AI/report request was issued against an AppSumo admin or production customer tenant, because that would consume credits and create a billing/report mutation. No signed Stripe or AppSumo event was replayed.
- The separate WordPress plugin Search Console service-account permission error and optional email/engagement connector activation require provider/plugin-side authenticated testing; the app was not altered to fake those statuses.
