# Reviewer workspace production recording

The dedicated AppSumo reviewer signed in to the live dashboard in Edge InPrivate, with the production address and the right-docked Console available. Keep log was enabled. The user operated the recorder. The saved video was outside the app verification.

The workspace already had the Tier 5 plan selected, but entitlement resolution returned no active source. Applied the existing manual admin-plan override to the exact reviewer-owned workspace and synchronized its local license. Billing then showed AppSumo Tier 5 Lifetime. This was an administrative reviewer entitlement. Existing AppSumo transactions and other workspaces were outside the change.

## Live demo results

- Added chatbotistic.com and ran Uptime Check now. The site redirected and the monitor showed Up and HTTP 200. The first probe completed without an SSL expiry. The application was capturing TLS session metadata without requesting the peer certificate. Commit 040d750103139b2aeb56b48c3db69532207b8996 enables peer-certificate capture. Both production version endpoints returned that release. A recheck of the same monitor returned Up, HTTP 200, and certificate expiry December 22, 2026.
- Generated a weekly report for INSIGHTISTIC-SUMO for November 13–19. PDF and HTML previews opened on the production HTTPS host and the standalone HTML report saved to the browser's download folder.
- Completed fresh Mobile and Desktop PageSpeed tests for the same project. Both scored 100 Performance, 97 Accessibility, 100 Best Practices and 100 SEO. The image-delivery opportunity opened official guidance.
- JOLLY Auto used the preceding crawl of 487 processed pages and 422 findings plus both fresh PageSpeed results. Opened Site Audit and PageSpeed citations in the browser. Generated both Performance and Executive insights, with Evidence only and the expected source links.
- Saved a 650-page monthly project cap and recorded a running crawl with the 163-page allowance. It completed with 163 processed, 317 discovered and 93 findings. Project usage became 650 and workspace usage increased from 1,593 to 1,756. In Pages, showed robots.txt HTTP 200 and HTTP 301 redirect rows. Stored robots data had text/plain content type. The run's redirect findings used the single redirect issue code.
- The final Tier 5 API counters included one JOLLY message used, two standard AI requests used and four websites. Lifetime billing was shown. The direct-subscription Stripe portal segment remains separate. The shown Agency Pro admin workspace had no Stripe subscription. The production subscription checkout source currently omits promotion-code collection.

## Deployment and handoff

- The SSL change used the previous pinned API runtime with the corrected inspector layer. API digest sha256:b35a2d39ca92fb066e345779a5a481b04eac40ccf9ffef3a3ef8ea4f7b4e1e09. The frontend retained its previously verified image contents. No migration ran.
- The configuration rollback is in backups/appsumo-review-ssl-20261119T155217Z on the production VPS. Refreshed only the reviewer monitor's certificate checkpoint before rechecking it under the new release.
- Record or retake any segment that starts after sign-in or contains visible app errors. The InPrivate Console still showed extension messages, including the WebMCP extension's request to /mcp. The final video files and any Stripe checkout were outside the verified results.
