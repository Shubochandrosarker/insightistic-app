# Agent docs refresh + production rounds 1–2 verified — 2026-11-19

**Date:** 2026-11-19 (Asia/Dhaka)

## Scope

- Founder directive (12:45 IST): the restored agent entry files at repo root must be updated in place with today's verified state — not merely restored verbatim.
- Files updated: `Memory/current-status.md`, `Memory/work-log/2026-11-19/`, `CHANGELOG.md`, `README.md`, `claude.md` (Last-updated bump only). `BUILD-STATUS.md` was left untouched per its own SUPERSEDED banner. `AGENTS.md` needed no changes.
- No production deployment, no redeploy, no repo restructuring. Agent entry files were never moved to `ARCHIVE/`.

## Verified facts recorded

- Round 1 fixes deployed live: uptime redirect following (`Probe.php`, allow_redirects max 5 hops, final status judged; image `fix9-redirects-up`), PageSpeed categories as repeated query params (`PageSpeedClient.php`), openssl SSL inspector. Live probes verified up/200 in DB; SSL verified (insightistic.com expires 2027-01-26, www.wordpressistic.com 2027-01-21).
- Round 2 AppSumo items deployed live: lifetime-plan detection in `EntitlementResolver`, `ai_messages_used` metering in `UsageService`, robots.txt/sitemap HTML-check skip, redirect double-count fix (image `fix-redirects-entitlements-crawl-perf-aimsg`, included in fix8/fix9).
- Performance runs populate all categories (DB-verified: mobile 74/94/100/100, desktop 89/94/100/100 for www.wordpressistic.com).
- Plugin v4.7.3 deployed (sha256 `746c7859e6d209eda6988945274d01bcc8a647ab736715595bb62e3fb3838cca`); API compatibility verified (6 `/api/plugin/*` + 22 `/api/connector/v1/*` endpoints); app repo plugin copy synced (commit `923e1e6`); release "Insightistic 4.7.3" (tag `insightistic.4.7.3`) live in the org repo `Wordpressistic/insightistic`.
- Duplicate 4.7.3 release on the personal repo `Shubochandrosarker/insightistic`: deletion pending the founder's word.
- Repo hygiene: `ARCHIVE/` created (`2645eba`); agent entry files restored at root (`cfd1713`).
- Running images vs `main` (`cfd1713`): delta is docs/repo-structure and plugin-dir sync only — production code == main code.
- Founder rules recorded: (1) done = parent-verified; (2) founder's exact words are requirements; (3) agent entry files never archived.

## Pending

- Delete the duplicate 4.7.3 release on the personal repo only with the founder's explicit approval.
