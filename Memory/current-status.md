# Insightistic current status

**Last reviewed:** 2026-11-19 (Asia/Dhaka)  
**Status confidence:** production release and dashboard changes verified on VPS `srv1691974` and in signed-in browser sessions. Earlier rollout records remain under their respective sections.

## Repository and organization

- **Verified (2026-11-19):** The active app repository is `Shubochandrosarker/insightistic-app` on GitHub; `main` is at `cfd1713`.
- **Verified:** Repo hygiene commits landed: `2645eba` created the `ARCHIVE/` folder and cleaned the root structure; `cfd1713` restored the agent entry files (`AGENTS.md`, `claude.md`, `Memory/`, `README`, `BUILD-STATUS`, `CHANGELOG`) at repo root after they were mistakenly archived.
- **Verified:** Agent entry files are mandatory at repo root and must never be archived or moved. They are now being updated in place to reflect the current state (this refresh).
- **Verified:** Plugin releases are published from the **org repo** `Wordpressistic/insightistic` (releases page), not the personal repo. The app repo's `insightistic/` plugin directory is synced to deployed plugin v4.7.3 (commit `923e1e6`).

## Production rounds — deployed and verified live (2026-11-19)

- **Verified live:** Round 1 fixes are deployed: uptime redirect following (`Probe.php` uses `allow_redirects`, max 5 hops, final status judged; deployed image `fix9-redirects-up`), PageSpeed categories as repeated query params (`PageSpeedClient.php`), and the openssl SSL inspector method.
- **Verified in DB:** Live uptime probes report `up`/200. SSL checks verified: `insightistic.com` expires 2027-01-26; `www.wordpressistic.com` expires 2027-01-21.
- **Verified live:** Round 2 AppSumo items are deployed: lifetime-plan detection in `EntitlementResolver`, `ai_messages_used` metering in `UsageService`, robots.txt/sitemap HTML-check skip, and the redirect double-count fix. These shipped in image `fix-redirects-entitlements-crawl-perf-aimsg` and are included in the superseding `fix8`/`fix9` images.
- **Verified in DB:** Performance runs now populate all four categories: mobile 74/94/100/100 and desktop 89/94/100/100 for `www.wordpressistic.com`.

## Plugin v4.7.3

- **Verified:** Deployed plugin v4.7.3 (sha256 `746c7859e6d209eda6988945274d01bcc8a647ab736715595bb62e3fb3838cca`).
- **Verified:** API compatibility confirmed: 6 `/api/plugin/*` endpoints + 22 `/api/connector/v1/*` endpoints.
- **Verified:** App repo plugin copy synced to 4.7.3 (commit `923e1e6`).
- **Verified:** Release "Insightistic 4.7.3" (tag `insightistic.4.7.3`) is live in the org repo `https://github.com/Wordpressistic/insightistic/releases`, created 2026-11-19.
- **Pending:** The personal repo `Shubochandrosarker/insightistic` also received a duplicate 4.7.3 release by mistake. The founder was informed; deletion is pending his word. Do not delete without explicit approval.

## VPS deployment state

- **Verified live:** Release 040d750103139b2aeb56b48c3db69532207b8996 fixed peer certificate capture for new uptime monitors. The reviewer workspace was activated with its intended Tier 5 admin override. The user signed in and operated the recorder while the production walkthrough covered Uptime, Reports, PageSpeed, JOLLY, AI Insights, Site Audit, Usage, Lifetime Billing, and Licenses. The Stripe portal segment and saved recording review remain pending. [Reviewer recording work log](work-log/2026-11-19/appsumo-reviewer-production-recording.md).
- **Verified live:** Release 0a4c85959688081c1bb34f3de18e0d3045019c76 updates all five AppSumo tiers, applies the report and team allowances, and enables Executive insights on Tiers 4 and 5. Public catalogs returned revised values on both production hosts. The earlier Tier 3 licenses retain their existing features. The AppSumo offer edits are saved in the reviewer draft, and the revised public WordPress template is pending deployment. [Pricing and listing work log](work-log/2026-11-19/appsumo-pricing-and-listing.md).
- **Prepared:** [AppSumo recording script](../docs/APPSUMO-REVIEW-RECORDING-SCRIPT.md) for review items 1–8 and the written answers to items 9–11. The reviewer recordings and submission remain pending.

- **Prior verified release:** App, API, queue and scheduler previously ran `94e990ff9fd737b7f7c9910ab7d080fd5ca93743` from `codex/appsumo-production-review-fixes`, under `/docker/insightistic-app`. It superseded `75a547f`.
- **Verified live:** Tier 5 JOLLY Auto answered with the completed Insightistic crawl (486 pages, 412 findings and open issues) and PageSpeed (98 mobile, 99 desktop). The source drawer showed the dated desktop result. JOLLY used one message with no change to standard requests or Insight Credits. Fresh Performance and Executive insights used stored evidence, and Tier 5 lifetime and 11 of 50 websites agreed across Usage, Billing and Licenses. [JOLLY audit and remaining recording requirement](work-log/2026-11-19/jolly-appsumo-final-audit.md).
- **Cloudflare:** Four projects returned crawler matches after switching to Cloudflare's `userAgent` field. The signed-in `www.insightistic.com` project kept its historical edge charts and hid the AI crawlers card after Cloudflare rejected its saved credential.
- **Performance Tracking:** Signed-in Mobile and Desktop views for `www.brothertours.com` omit the unmeasured INP tile. Regression tests cover the measured case.
- Cloudflare and INP details are in [the dated work log](work-log/2026-11-19/cloudflare-crawlers-and-inp.md).

## Founder rules (recorded 2026-11-19)

1. Done means parent-verified. Never report unverified work as done.
2. The founder's exact words are requirements.
3. Agent entry files (`AGENTS.md`, `claude.md`, `Memory/`, `README`, `BUILD-STATUS`, `CHANGELOG`) are mandatory at repo root and are never archived or moved.

## Current open work

1. Delete the duplicate 4.7.3 release on the personal repo once the founder approves (Pending his word).
2. Keep `BUILD-STATUS.md` untouched — it is a superseded historical record by its own banner.
3. Keep this file and a dated work-log record current after each meaningful session (append-only for work logs).

## Security

- Server environment files, deployment credentials, and tokens stay outside this repository and outside memory files. Never record them here.
