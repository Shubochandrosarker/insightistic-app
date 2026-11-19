# AppSumo pricing and listing revision

AppSumo submission 235204 and deal 267702 are in Needs revision and Testing. The partner draft contains the five one-time prices, weekly rank checks, separate standard AI requests, JOLLY messages and Insight Credits. Pricing validation in Edge passed. AppSumo blocked buyer preview with “Notes are required for the needs revision status.” The draft is awaiting the existing reviewer evidence and resubmission workflow.

- One-time prices are $49, $79, $149, $269 and $499. Website projects are 5, 10, 15, 30 and 50.
- Monthly report generation is 10, 50, unlimited, unlimited and unlimited. Active report schedules are 5, 25, 50, 100 and unlimited.
- Custom knowledge bases are 1, 3, 5, 10 and 20. The workspace retains a separate allowance of 50 total knowledge sources.
- Tier 1 supports five added teammates plus the owner. Tiers 2–5 have unrestricted team seat counts. A successful team addition consumes 50 Insight Credits once.
- New offers include white-label reports, priority support and Executive insights in Tiers 4 and 5. The release migration preserves white-label and priority support on active or claimable preexisting non-test Tier 3 licenses. The production read before release found three active Tier 3 license records, one linked to a workspace.
- Compared [Screpy](https://appsumo.com/products/screpy/) and [Screpy Plus Exclusive](https://appsumo.com/products/screpy-plus-exclusive/). The draft emphasizes Agent JOLLY citations, combined Google and Bing analytics, WooCommerce reporting, and project crawl budgets. Screpy advertises daily ranks and Screpy Plus Exclusive also advertises keyword research and map tracking.
- Replaced the partner hero and four story images with the actual Overview, Commerce, JOLLY, Site Audit, and Performance UI using labeled sample data. The new imagery removes product mockup claims about backlinks, malware, the absolute source count and broad encryption. The [review document](../../../docs/APPSUMO-REVIEW-2026-11-19.md) records named sources and confirmed credential encryption scope.
- Website project, knowledge, reporting, and team limits are enforced in the app catalog and migrations. Distinct active schedules can generate independent deliveries for the same project. The WordPress comparison source was edited in the separate website repository.

## Verification and handoff

- 46 focused Laravel tests passed with 285 assertions. Frontend TypeScript and the production frontend Docker build passed. The larger targeted test set also reproduced a preexisting unrelated automation-rule authorization failure on the previous commit.
- App, API, queue and scheduler were deployed as source 0a4c85959688081c1bb34f3de18e0d3045019c76 to srv1691974. The release used the existing pinned PHP runtime with the changed application, configuration and database source layers. API image digest sha256:8a30929beab7ac508c8842f57ca3455d377391e459b4e663490c5ff9f7b31781. Frontend digest sha256:432d8b4ed817c320350cc1b9c2737489394d53c25b3f604ef2fb41c3fadadd00.
- Both new migrations ran in batch 23. The separate app and API public catalogs returned all revised values. The entitlement resolver returned both preserved features for the existing claimed Tier 3 workspace. Three earlier non-test licenses were marked for preservation.
- The database dump was verified with gzip and saved next to the Compose rollback copy in backups/appsumo-offer-20261119T115308Z. Browser access to Hostinger ended before the separate WordPress template could be deployed, and the public Lifetime Deal page still returned older comparison content during the final check.
- The [recording script](../../../docs/APPSUMO-REVIEW-RECORDING-SCRIPT.md) covers the eight production clips and draft responses for items 9–11. Recording, reviewer access and submission remain pending. AppSumo’s preview validation requires the Needs revision notes, and no listing submission was made.
