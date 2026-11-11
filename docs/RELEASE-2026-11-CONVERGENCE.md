# Release plan: converge production with `main` (P0 #4, Nov 2026)

## What is running in production (read-only check, 2026-11-11 13:34 Asia/Dhaka)

| Service | Image | Image ID | Source |
|---|---|---|---|
| api | `insightistic-api:ai-pause-20261107` | `bec73ee04cd2` | `docker commit` of the live container, 2026-11-06 18:44 UTC. Release `9d7a845` plus the uncommitted ai-pause hotfix. |
| queue | same image `bec73ee04cd2` | | Recreated 2026-11-11 07:30 UTC with `traefik.enable=false` (P0 #1 live fix). |
| scheduler | same image `bec73ee04cd2` | | Same as queue. |
| app (Next.js) | `insightistic-app:deploy-9d7a845` | `80c3c8535642` | Built 2026-11-04 15:19 UTC from `9d7a845`. |
| db | `postgres:16-alpine` | `0cefaabd882e` | Not part of the release. |

- `9d7a845` **is not a commit in this repository.** It was built from an untracked working tree. `b66a470` does exist.
- Release markers disagree:
  - compose `INSIGHTISTIC_RELEASE=9d7a845` (this is the value the container sees)
  - `.env` has `9d7a845`
  - `.env.ai` still has `INSIGHTISTIC_RELEASE=b66a470` (stale)
- Live code equals the `9d7a845` source tree plus the ai-pause hotfix, byte for byte. This was verified by exporting `app config routes database resources` from the running api container.

## Drift: live versus `main` (`5bd511c`)

| Area | Live (9d7a845) | `main` | After this PR |
|---|---|---|---|
| ai-pause hotfix (13 files) | yes, with the `$availability` crash | missing | **#146** ports it and fixes the crash |
| Traefik labels | fixed live in compose | missing | **#145** |
| AppSumo LTD Option A (#144) | missing | yes, flag off | unchanged, flag stays off |
| Signup flow | free workspace; the 7-day trial starts from Billing; `trial_started` email only when the trial really starts | sends a `trial_started` email at signup, but no trial starts there (`trial_ends_at` stays null) | **live behavior** |
| Welcome email | "free workspace… start a trial from Billing, no card" | "You're on a 7-day trial" (not true at signup) | **live** |
| Stripe LTD purchase email | `lifetime_purchased` receipt with a details table | generic `subscription_started` | **live** |
| Stripe checkout return | `plan` + `kind` in the success/cancel URL; the billing page shows the result | bare URL | **live** |
| Default checkout order | `stripe,paddle` (Stripe first) | `paddle,stripe` | **live** (prod env already sets `stripe,paddle`) |
| Register / billing / pricing pages | Stripe first, LTD-aware register copy | Paddle first, "Start your free trial" | **live** |
| Terms / privacy | "Paddle or Stripe"; Paddle MoR "where applicable" | "Paddle as Merchant of Record" only | **live** text (this is what customers see today; legal wording is Shuvo's decision) |
| Migrations | 2026_* set | same | no migration diff |

#143 ported most of 9d7a845, but not these 13 files. This PR copies them byte-for-byte from the running containers. Deploying `main` as it stands would have regressed billing to Paddle first, put Paddle-MoR-only terms back, and sent a false "trial started" email to every signup.

## One tagged release

1. Merge order (Shuvo's OK for each):
   1. #145 (Traefik)
   2. #146 (AI jobs + hotfix port)
   3. **this PR** (convergence)
   4. #147 (brain, inert until env is set)
   5. the trial-alignment PR (stacked on this PR)
   
   They merge cleanly in that order (checked locally).
2. Tag `v2026.11.1` on the merge commit.
3. Build both images **from the tag** with the Dockerfiles. No `docker commit`, ever. GitHub Actions is locked, so build on the VPS from `git archive v2026.11.1`:
   - `insightistic-api:v2026.11.1`
   - `insightistic-app:v2026.11.1`
   
   Then check that `docker image inspect` shows no `traefik.http.*` labels.
4. Staging gate: run the `insightistic-staging` compose project on a fresh pg_dump.
   - Pest is green apart from the 4 known baseline failures.
   - `insights-generate` and `ai-visibility` write rows.
   - The watchdog is OK.
   - Next.js test + build pass.
5. Production (Shuvo's OK, maintenance-light, about 2 minutes):
   1. Back up: `docker-compose.yml`, `.env`, `.env.ai`, and a pg_dump to `/docker/backups/insightistic-app/<ts>-pre-v2026.11.1`.
   2. In compose, set all four images to `v2026.11.1` and `INSIGHTISTIC_RELEASE=v2026.11.1`. **Delete** `INSIGHTISTIC_RELEASE` from `.env.ai`.
   3. `docker compose config --quiet`, then `docker compose up -d --no-deps api queue scheduler app`. `RUN_MIGRATIONS` is a no-op because there's no new migration.
   4. Verify:
      - 50× `api.insightistic.com/up` = 200
      - `app.insightistic.com/login` = 200
      - `scripts/check-traefik-labels.sh` passes
      - the scheduler and queue processes are up
      - next morning: the 05:00 / 07:00 UTC jobs write rows, and the 09:30 UTC watchdog is OK
6. Rollback: put the image tags back to `ai-pause-20261107` / `deploy-9d7a845`. Both images are kept, so do NOT prune them. Then restore the backed-up compose and run `up -d`. The DB restore isn't needed because there's no migration.

## Env decisions that ride with the release (not changed by this PR)
- `INSIGHTISTIC_SIGNUP_TRIAL_DAYS=14` in prod `.env.ai`. The settled decision is 7 days. The trial PR changes the default and the copy, and the env value changes at release time.
- `FEATURE_PADDLE=true`, `BILLING_CHECKOUT_PROVIDERS=stripe,paddle`. The Stripe-only switch is P1 #9, a separate decision.
- `INSIGHTISTIC_OPS_ALERT_EMAIL` (new, #146) needs an address from Shuvo.
