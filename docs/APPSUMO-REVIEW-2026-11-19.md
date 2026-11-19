# Insightistic AppSumo review

November 19, 2026 · Asia/Dhaka

## AppSumo tier comparison

Allowances belong to the workspace. Rank tracking checks run weekly on each AppSumo tier. The following comparison reflects the revised partner draft and the app release prepared for it.

| Price or allowance | Tier 1 | Tier 2 | Tier 3 | Tier 4 | Tier 5 |
| --- | ---: | ---: | ---: | ---: | ---: |
| One-time price | $49 | $79 | $149 | $269 | $499 |
| Workspace websites | 5 | 10 | 15 | 30 | 50 |
| Standard AI requests | 100 | 200 | 600 | 1,500 | 5,000 |
| Agent JOLLY messages | 300 | 300 | 300 | 300 | 300 |
| Included Insight Credits | 5,000 | 10,000 | 30,000 | 75,000 | 250,000 |
| Crawl pages | 10,000 | 40,000 | 100,000 | 250,000 | 600,000 |
| Reports generated per month | 10 | 50 | Unlimited | Unlimited | Unlimited |
| Active report schedules | 5 | 25 | 50 | 100 | Unlimited |
| Added team members | 5 | Unlimited | Unlimited | Unlimited | Unlimited |
| Custom knowledge bases | 1 | 3 | 5 | 10 | 20 |
| White-label reports and priority support | — | — | — | Included | Included |
| Executive insights | — | — | — | Included | Included |
| Tracked keywords | 10 | 20 | 40 | 100 | 250 |

Every tier supports up to 50 knowledge sources per workspace. Each added teammate uses 50 Insight Credits once. The Tier 1 five-member allowance is in addition to the workspace owner. Existing Tier 3 lifetime licenses retain the white-label reporting and priority-support access granted before this revision.

### How the counters move

- **JOLLY messages** use **ai_messages_used**. Each completed chat response consumes one message. Failed turns do not consume a message. JOLLY uses its own allowance, independent of the standard AI request meter and Insight Credit balance.
- **Standard AI requests** use **ai_insights_used**. An eligible completed dashboard insight or plugin insight uses one available standard request. The included wallet remains unchanged for that request. Legacy plans that predate the wallet use the same counter for their monthly insight quota.
- **Insight Credits** use the credit ledger and wallet. The monthly grant and purchased balances are tracked separately. Eligible AI overage and credit-priced actions debit the applicable tariff when the provider call qualifies for charging. Evidence-only generation does not create a paid provider charge. Standard dashboard and paid plugin insights use a 100-credit overage tariff, SEO analysis 200, commerce analysis 300, advanced AI reports 500, and comprehensive AI audits 1,000. Free Connected plugin insights and plugin AI reports use 50 credits per successful action. Successful standard and local rank checks cost 10 and 15 credits per keyword respectively. Each successful team addition on AppSumo costs 50 Insight Credits once.
- **Performance and Executive insights** use stored evidence. Performance uses PageSpeed and consumes one standard AI request when the workspace has an available slot. Executive on Tiers 4 and 5 summarizes verified sources, including completed Site Audit and PageSpeed results where available, and uses no Insight Credits for evidence-only generation.
- **Crawl pages** count frontier URLs the crawler processes. A finished run with a 5,000-page reservation and 486 processed URLs uses 486 pages. A failed run counts only the pages processed before failure. Pending and running jobs reserve their outstanding capacity, which returns to the workspace when the run finishes. Crawl usage is grouped by run creation month.
- **Project crawl limits** are optional monthly caps for a single website. Owners and admins set them in Site Audit. A blank field shares the available workspace balance. Zero pauses new crawls for that project. Both manual and scheduled jobs use the remaining project and workspace balances.
- **Workspace websites** count tracked projects. **WordPress plugin activations** count license activations separately. A platform-neutral site can use one website slot without a WordPress plugin activation.
- **Reports generated** count both on-demand and scheduled reports. **Active report schedules** count recurring configurations across the workspace. Each schedule can deliver independently, and Tiers 1–2 share one monthly generation allowance across their schedules and on-demand requests. A qualifying advanced AI summary can use credits. Viewing, downloading or emailing an existing report does not generate a second report.

Owning source files include [UsageService](../insightistic-api/app/Services/UsageService.php), [CrawlBudget](../insightistic-api/app/Services/Crawler/CrawlBudget.php), [AiCreditService](../insightistic-api/app/Domain/Billing/AiCreditService.php) and the [commercial catalog](../insightistic-api/config/commercial.php).

## Data sources and encryption

The supported external connections for the comparison are Google Analytics 4, Google Search Console, Bing Webmaster Tools and Cloudflare, plus WordPress and WooCommerce through the Insightistic connector. PageSpeed Insights, Site Audit and Uptime generate their own measurements. Engagement and email analytics depend on the relevant integration being configured and delivering data.

Production Laravel reports AES-256-CBC as its application cipher. Model-level encrypted fields include access and refresh tokens in [ProviderAccount](../insightistic-api/app/Models/ProviderAccount.php) and [DataSourceCredential](../insightistic-api/app/Models/DataSourceCredential.php), plus [WordPress connector secrets and Cloudflare API tokens](../insightistic-api/app/Models/Site.php). This verification covers those credential fields at rest.

## Campaign status

- AppSumo submission 235204, deal 267702, is in Needs revision with the deal status Testing. AppSumo Partner Analytics reported zero paid orders in the November 1–19 window and zero lifetime invoice orders.
- The live public catalog reported zero completed or reserved Founding Angel sales and still returned state active. Prior catalog work retired those Stripe offers. The checkout state and catalog state need to agree before the campaign can be described as operationally closed on every surface. Existing lifetime grants must remain in place.
- The AppSumo draft specifies five lifetime tiers, weekly rank checks, separate JOLLY messages and credential encryption. Its hero and four gallery images show actual application screens with labeled sample data.

## Production evidence to attach

AppSumo requested eight production recordings, each starting with login and including the production URL and developer console. Capture these in a dedicated reviewer workspace with test website data that can be shared.

1. Redirecting website addition, final uptime HTTP 200 and SSL expiry.
2. Report generation, formatted dates, working PDF and HTML previews, and HTML attachment.
3. Agent JOLLY Auto mode answering with both Site Audit and PageSpeed evidence.
4. Performance and Executive insight generation, including source and model provenance.
5. Tier 5 before-and-after counters across Sidebar, Usage, Billing and Licenses, with a completed crawl.
6. Lifetime billing for Tier 5 and subscription billing with Stripe Customer Portal on a subscribed account.
7. Project cap, in-progress discovery, finished crawl counts and resource classification.
8. Completed PageSpeed runs, measured scores and clickable opportunity links.
