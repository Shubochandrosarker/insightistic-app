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
| Migrations | 76 applied, none pending | same set | no migration in this release; seeders add 3 hidden `appsumo_*` plans (see below) |

#143 ported most of 9d7a845, but not these 13 files. This PR copies them byte-for-byte from the running containers. Deploying `main` as it stands would have regressed billing to Paddle first, put Paddle-MoR-only terms back, and sent a false "trial started" email to every signup.

## One tagged release: `v2026.11.1`

### Exact contents (final, 2026-11-11)

Decided (JOLLY, for Shuvo): `v2026.11.1` ships **everything below, including #150 and #151**.

| PR | Squash commit on `main` | Without new env | Status |
|---|---|---|---|
| #143 9d7a845 port | `1e4f5d3` | active | merged |
| #144 AppSumo LTD (flag off) | `5bd511c` | dormant (see risk 1) | merged |
| #145 Traefik labels | `d5b5d63` | active | merged |
| #146 AI jobs fix + watchdog | `129428c` | active (it is the fix) | merged |
| #148 convergence + this plan | `14a7ba5` | active (keeps live behavior) | merged |
| #147 shared brain (CF Access + MCP) | `e8dc102` | inert until the brain env is set (below) | merged |
| #150 rank adapter (DataForSEO) | `765c870` | inert (no `DATAFORSEO_*` in prod → checks skipped, $0) | merged |
| #151 AI cost metering | `580432f` | active, additive (tokens + `usage.cost`) | merged |
| #149 7-day no-card trial | (pending) | active | open, mergeable; tag after it merges |

Tag `v2026.11.1` on `main` once #149 is merged. The release candidate `d98a929` that was tested below had the same contents (merged locally in the same order).

### Migrations and seeders

- **No new migrations** in any of the six PRs. Prod `migrate:status --pending`: none (76 applied). The staging copy with the rc image: none.
- **Seeders do run.** Prod api/queue/scheduler have `RUN_MIGRATIONS=true`, `SEED_PLANS=true`, `SEED_BILLING_CATALOG=true`. The entrypoint runs `PlanSeeder` + `PaddleCatalogSeeder` on every container start. With the rc image this writes exactly:
  - `plans`: 13 → 16. Three new **hidden** rows (`appsumo_solo` $79, `appsumo_studio` $149, `appsumo_agency` $269, `is_active=false`, lifetime). They come from #144.
  - `plan_prices`: 39 → 39 (no `PADDLE_PRICE_LTD_APPSUMO_*` in prod, so no price rows).
  - `ltd_campaigns`: 1 → 1 (`appsumo_2026` is only created on the first AppSumo reservation, which the flag blocks).
  - Every existing plan row is byte-identical before and after (diffed on staging).
- Rollback for the seed: delete the three `appsumo_*` plan rows (nothing references them). The DB restore is not needed.

### Risks

1. **#144 AppSumo, Paddle prices (main risk).**
   - It ships dormant: prod has no `FEATURE_LTD_APPSUMO` and no `PADDLE_PRICE_LTD_APPSUMO_*`, so `reserve()` rejects `appsumo_*` (`invalid_ltd_plan`). Only the three hidden plan rows above appear, and admins can see them in the admin plan list.
   - **The design does not match how AppSumo sells.** AppSumo marketplace buyers pay on AppSumo and are fulfilled through the AppSumo Licensing API v2: `purchase`/`activate`/`upgrade`/`downgrade`/`deactivate` webhooks, then OAuth to fetch the `license_key` and link it to the new account. They never reach our Paddle checkout. Under #144 as written, a sale "pointed at the Paddle prices" would arrive without a local reservation and land in `manual_review`. It also contradicts the Stripe-only direction. **Do not activate #144 as-is.** It needs an AppSumo Licensing v2 adapter (webhook + OAuth redemption) that reuses the `appsumo_*` plans and the `appsumo_2026` counter. That is a separate PR and Shuvo's decision.
   - Pricing against Founding Angel (Shuvo's call):
     - AppSumo Solo is $79 for 3 sites; Angel Solo is $149 for 3 sites.
     - AppSumo Studio is $149 for 10 sites; Angel Studio is $299 for 7 sites.
     - AppSumo Agency is $269 for 25 sites; Angel Agency is $699 for 15 sites.
     - The four Founding Angel holders stay valid regardless.
   - AI allowance conflict: #144 gives AppSumo tiers `monthly_credits=0` and `standard_ai_requests` 200/600/600. The settled decision is a small monthly AI-credit allowance from measured cost (#151 draft). Reconcile this before activation.
   - Behavior notes:
     - Turning `FEATURE_LTD_APPSUMO` off mid-campaign also stops `confirmSale` from counting AppSumo sales. Keep it on until the campaign closes.
     - `maximum_sales=500` is read once, when the campaign row is created.
   - #144's own sale-counting test was broken: it sent `pri_pdl_lifetime` instead of the AppSumo price, so it failed on `main`. This is fixed in `ee6fa4e`. With the right price the code path passes, so the counting logic is sound.
2. **Seeders race on three containers.** api, queue and scheduler all run migrate + seed at start. Upserts are idempotent and `|| true` swallows a unique-slug race, but recreate **api first**, wait for healthy, then queue + scheduler.
3. **AI providers, not fixed by this release.** On staging, the WPistic gateway failed 21/21 insight calls and `openai/gpt-luna-latest` failed 1/1. The free `nemotron` fallback carried everything. #146 fixes the crash, not provider health.
4. **Prod AI output is still zero until this ships.** The watchdog on a fresh prod copy reports ZERO ROWS for both jobs since 2026-11-10.
5. **Env leftovers, fixed at release time:**
   - `.env.ai` `INSIGHTISTIC_SIGNUP_TRIAL_DAYS=14` is dead (System Health shows it as `legacy_signup_trial_days_env: "14"`). Delete it.
   - `.env.ai` `INSIGHTISTIC_RELEASE=b66a470` is stale. Delete it.
   - `.env` `INSIGHTISTIC_BUILD_TIME` overrides the image build arg, so System Health shows 2026-11-04. Update it or drop it.
6. **Legal copy:** terms and privacy stay on the live wording. Changing them is Shuvo's decision.
7. **Watchtower** labels stay `true` on api/queue/scheduler/app. Local `v2026.11.1` tags are not in a registry, so Watchtower cannot replace them. Leave as is.

### Staging test results (2026-11-11, Asia/Dhaka)

- **Pest, full suite** on the rc (`d98a929`), throwaway Postgres: **944 passed, 3 failed** (4 670 assertions). The 3 failures are the known environment-only baseline. `main` alone had 4; #144's AppSumo test now passes.
  - `ActionExecutorTest` / `ActionProposalServiceTest`: a 10s outbound-webhook timeout because the test network is `--internal`.
  - `OAuthRedirectConfigTest`: the test runner copies `.env.example` to `.env`, and its `GOOGLE_REDIRECT_URI` value wins over the "empty env" case the test expects.
- **Image:** `docker build` from the rc tree. `insightistic-api:rc-d98a929` labels = `{"traefik.enable":"false"}`, with no `traefik.http.*` labels.
- **Staging stack** (`insightistic-staging`, fresh `pg_dump` of prod, mail to log, billing secrets blank, no Traefik, no published ports, same `RUN_MIGRATIONS/SEED_*` as prod):
  - The container is healthy and `/up` returns 200. No pending migrations. The seed delta is exactly as listed above.
  - `insightistic:insights-generate`: 12 generated, 10 skipped (`no_data` 8, `ai_paused` 2).
  - `insightistic:ai-visibility`: 6 dispatched, 9 runs written after draining the queue.
  - Watchdog: ZERO ROWS → **OK** for both jobs.
  - `telemetry_ai_usage` now records tokens (#151), e.g. nemotron 10 calls, 3 260 in / 17 295 out. Cost is $0.000000 because the only successful model is free.
  - `insightistic:rank-provider-check`: "configured: no; checks skipped, cost nothing" (#150 inert).
  - System Health shows `trial` {days 7, card_required false}, `rank` {dataforseo, configured false}, and `ai_jobs`.
- **Frontend:** Vitest 195/195 and `tsc` clean on #149, which includes #148.
- **App image:** `docker build -f docker/app/Dockerfile` from the rc tree succeeds (`insightistic-app:rc-d98a929`). Its build args match live: `NEXT_PUBLIC_API_URL` is empty and `API_PROXY_TARGET=http://api:8000`.
- **Label safety:** both new images default to `traefik.enable=false` (#145). The live compose explicitly sets `traefik.enable=true` on `api` and `app` (verified with `docker compose config`), and compose labels override image labels, so swapping the image tags keeps both routed. queue and scheduler stay `false`.

### Build, deploy, rollback

1. Tag `v2026.11.1` on the final merge commit.
2. Build both images **from the tag** with the Dockerfiles. No `docker commit`, ever. GitHub Actions is locked, so build on the VPS from `git archive v2026.11.1`:
   - `insightistic-api:v2026.11.1`
   - `insightistic-app:v2026.11.1`
   
   Then check that `docker image inspect` shows no `traefik.http.*` labels.
3. Staging gate: repeat the staging run above with the tagged image.
4. Production (Shuvo's OK, about 2 minutes):
   1. Back up: `docker-compose.yml`, `.env`, `.env.ai`, and a pg_dump to `/docker/backups/insightistic-app/<ts>-pre-v2026.11.1`.
   2. In compose, set all four images to `v2026.11.1` and `INSIGHTISTIC_RELEASE=v2026.11.1`. Apply the "Env changes at release time" below.
   3. `docker compose config --quiet`.
   4. `docker compose up -d --no-deps api`, then wait for healthy.
   5. `docker compose up -d --no-deps queue scheduler app`.
   6. Verify:
      - 50× `api.insightistic.com/up` = 200
      - `app.insightistic.com/login` = 200
      - `scripts/check-traefik-labels.sh` passes
      - the queue and scheduler processes are up
      - `plans` has 16 rows
      - next morning: the 05:00 / 07:00 UTC jobs write rows, and the 09:30 UTC watchdog is OK
5. Rollback:
   1. Put the image tags back to `ai-pause-20261107` / `deploy-9d7a845`. Both images are kept, so do NOT prune them.
   2. Restore the backed-up compose and env, then run `up -d`.
   3. Optionally delete the three `appsumo_*` plan rows.
   
   The DB restore isn't needed.

## Env changes at release time (prod is not changed before the release)

In `/docker/insightistic-app/.env.ai` (this is the file the api/queue/scheduler containers load through `env_file`):
- Add `INSIGHTISTIC_OPS_ALERT_EMAIL=wordpressistic@gmail.com` (decided). This is the recipient for AI job crash and zero-rows watchdog alerts (#146).
- Delete `INSIGHTISTIC_SIGNUP_TRIAL_DAYS=14`. It is dead, and the trial is 7 days from `commercial.trial_days`.
- Delete the stale `INSIGHTISTIC_RELEASE=b66a470`.
- Keep `FEATURE_PADDLE=true` and `BILLING_CHECKOUT_PROVIDERS=stripe,paddle`. The Stripe-only switch is P1 #9, a separate decision.

In `/docker/insightistic-app/.env`:
- Update or remove the stale `INSIGHTISTIC_BUILD_TIME`.

Shared brain (#147). **Already done on 2026-11-11, inert until release:**
- Cloudflare Access service token **`insightistic-api-brain-2026-11`** was created. It expires **2027-11-11 08:41 UTC** (14:41 Asia/Dhaka); renew it before then.
- An app-scoped Service Auth policy, "insightistic-api service token" (non_identity, precedence 2), was added on the `insightistic-brain` Access app (brain.insightistic.com) only. The existing tripistic policy is unchanged.
- Verified:
  - with the token, Access passes the request to the origin;
  - without it, or with a wrong secret, the answer is 403;
  - the same token is refused (403) on brain.tripistic.com.
- `WPISTIC_KNOWLEDGE_AGENT_CF_ACCESS_CLIENT_ID` / `_CLIENT_SECRET` are stored only in `/docker/insightistic-app/.env` (chmod 600; backup `.env.bak-20261111T084155Z-pre-cf-access-token`).

At release, to turn the brain on:
1. `.env` is only read for compose `${VAR}` interpolation, so add these lines to the `environment:` of api, queue and scheduler in `docker-compose.yml`:

   ```yaml
   WPISTIC_KNOWLEDGE_AGENT_CF_ACCESS_CLIENT_ID: "${WPISTIC_KNOWLEDGE_AGENT_CF_ACCESS_CLIENT_ID}"
   WPISTIC_KNOWLEDGE_AGENT_CF_ACCESS_CLIENT_SECRET: "${WPISTIC_KNOWLEDGE_AGENT_CF_ACCESS_CLIENT_SECRET}"
   ```

2. Set the following, then check that `php artisan tinker` shows `config('insightistic.knowledge_agent.cf_access_client_id')` as non-empty (do not print it):
   - `WPISTIC_KNOWLEDGE_AGENT_ENABLED=true`
   - `WPISTIC_KNOWLEDGE_AGENT_URL=https://brain.insightistic.com`
   - `WPISTIC_KNOWLEDGE_AGENT_DRIVER=mcp`
   - `WPISTIC_KNOWLEDGE_AGENT_MCP_KINDS=project,system` (the default)
   - plus the memory-server side items still listed in #147 (`WPISTIC_MCP_ALLOWED_HOSTS` including `brain.insightistic.com`)
3. If the brain misbehaves, set `WPISTIC_KNOWLEDGE_AGENT_ENABLED=false`. The client already falls back to local knowledge after a timeout or cooldown.
