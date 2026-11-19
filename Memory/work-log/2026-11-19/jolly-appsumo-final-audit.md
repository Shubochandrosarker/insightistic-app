# Agent JOLLY production review

November 19, 2026 · Asia/Dhaka

## Plan

- Compare JOLLY behavior and usage with AppSumo review items 3, 4, 5 and 9.
- Verify an Auto answer against the selected Tier 5 site's actual crawl and Mobile and Desktop PageSpeed results.
- Check source links, Markdown, provider provenance and the three AI meters. Exercise Performance and Executive insights separately.
- Fix observed gaps and repeat the production workflow.

## Findings and changes

- The original Auto answer on production retrieved both datasets and rendered Markdown. Its citations omitted the desktop result and showed unrelated sources. It also described the crawl ceiling as intended work. The source index and stored citations now use the same server-resolved references. The context distinguishes measured crawl pages, current open issues, and findings reported by the run. The Site Audit Analyst has Site Audit and PageSpeed sources.
- The live Tier 5 Executive action returned HTTP 403 because it inherited a report entitlement. The live Performance action returned HTTP 500 during a model-gateway connection failure. Tier 5 Executive now resolves its dashboard entitlement independently of advanced reports. Performance and Executive use type-aware evidence-only generation with source links. JOLLY chat retains its normal configured provider chain.
- JOLLY shows the workspace message allowance before the first prompt using the same payload as Usage. Usage identifies the AppSumo lifetime plan.

## Production verification

- Signed-in AppSumo Tier 5, project `insightistic.com`, Data Auto. The final answer reported 486 processed URLs, 412 findings from the latest completed crawl, 412 currently open issues, mobile Performance 98, and desktop 99. The Agent JOLLY server trace recorded a completed WPistic Gateway run with model `glm-4.5-flash` and six verified citations across the two required sources. The UI rendered headings and bullets, and the desktop citation drawer showed 99, the result date and a link to Performance. The later run used the currently completed crawl instead of the historical AppSumo issue count.
- On the final chat, JOLLY usage rose from 21 to 22 of 300. Standard AI requests stayed at 104 of 5,000 and Insight Credits stayed at 247,400 remaining of 250,000 included. Before that chat, the fresh Performance insight moved standard requests from 103 to 104. The subsequent Executive insight moved none of the three counters.
- Fresh Performance used PageSpeed only and reported mobile 98. Fresh Executive included the same 486-page crawl and both PageSpeed scores. Both displayed Evidence only and stored `rule-based-v1` provenance. Dashboard links to their sources were present.
- Sidebar, Usage and Billing agreed on 104 of 5,000 standard requests. Usage, Billing and Licenses agreed on 11 of 50 workspace websites. Billing showed AppSumo Tier 5 Lifetime. The post-release browser console check returned zero related errors for the tested routes.
- 77 targeted Laravel tests, 218 frontend tests, TypeScript and both Docker builds passed. Both version endpoints returned `94e990ff9fd737b7f7c9910ab7d080fd5ca93743`. API health and dashboard routes returned HTTP 200.
- VPS stack `/docker/insightistic-app`. API digest `sha256:ead85e2d7b687b7f74d23ca698de21ce886a312c6ecdaf9d3cf8164e3d012361`. App digest `sha256:5136e64f99d5385a91c34d74cb6a738ec9a6f1c7bf588c727c1f66f395305d15`. The rollback copy and verified PostgreSQL dump are in `backups/jolly-audit-20261119T103102Z`. No migration ran.

## AppSumo evidence still required

- AppSumo deal 267702 reports Testing and submission 235204 reports Needs revision. The listing has a separate 300-message JOLLY allowance and Tier 5's 5,000 actions and 250,000 credits. The test account fields contain a marketing-site login URL and no username or password.
- This audit ran in an already-authenticated Tier 5 owner session. AppSumo's login-through recording with a visible production URL and developer console remains pending for a dedicated reviewer workspace. Brother Tours' AI pause was preserved.
