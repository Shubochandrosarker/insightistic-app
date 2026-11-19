# Insightistic AppSumo review

November 19, 2026 · Asia/Dhaka

## AppSumo tier comparison

Allowances belong to the workspace. Rank tracking checks run weekly on each AppSumo tier.

| Monthly allowance | Tier 1 | Tier 2 | Tier 3 | Tier 4 | Tier 5 |
| --- | ---: | ---: | ---: | ---: | ---: |
| Workspace websites | 1 | 3 | 10 | 25 | 50 |
| Standard AI requests | 100 | 200 | 600 | 1,500 | 5,000 |
| Agent JOLLY messages | 300 | 300 | 300 | 300 | 300 |
| Included Insight Credits | 5,000 | 10,000 | 30,000 | 75,000 | 250,000 |
| Crawl pages | 10,000 | 40,000 | 100,000 | 250,000 | 600,000 |
| Reports generated | 5 | 25 | 100 | 250 | 500 |
| Active report schedules | 1 | 3 | 10 | 25 | 50 |
| Tracked keywords | 10 | 20 | 40 | 100 | 250 |

### How the counters move

- **JOLLY messages** use **ai_messages_used**. Each completed chat response consumes one message. Failed turns do not consume a message. JOLLY uses its own allowance, independent of the standard AI request meter and Insight Credit balance.
- **Standard AI requests** use **ai_insights_used**. An eligible completed dashboard insight or plugin insight uses one available standard request. The included wallet remains unchanged for that request. Legacy plans that predate the wallet use the same counter for their monthly insight quota.
- **Insight Credits** use the credit ledger and wallet. The monthly grant and purchased balances are tracked separately. Eligible AI overage and credit-priced actions debit the applicable tariff when the provider call qualifies for charging. An evidence-only fallback does not create a paid provider charge. Standard dashboard and paid plugin insights use a 100-credit overage tariff, SEO analysis 200, commerce analysis 300, advanced AI reports and Executive insights 500, and comprehensive AI audits 1,000. Free Connected plugin insights and plugin AI reports use 50 credits per successful action. Successful standard and local rank checks cost 10 and 15 credits per keyword respectively. Adding a team member on AppSumo costs 10 credits.
- **Crawl pages** count frontier URLs the crawler processes. A finished run with a 5,000-page reservation and 486 processed URLs uses 486 pages. A failed run counts only the pages processed before failure. Pending and running jobs reserve their outstanding capacity, which returns to the workspace when the run finishes. Crawl usage is grouped by run creation month.
- **Project crawl limits** are optional monthly caps for a single website. Owners and admins set them in Site Audit. A blank field shares the available workspace balance. Zero pauses new crawls for that project. Both manual and scheduled jobs use the remaining project and workspace balances.
- **Workspace websites** count tracked projects. **WordPress plugin activations** count license activations separately. A platform-neutral site can use one website slot without a WordPress plugin activation.
- **Reports generated** and **active report schedules** are independent. A qualifying advanced AI summary can also use credits. Viewing, downloading or emailing an existing report does not generate a second report.

Owning source files include [UsageService](../insightistic-api/app/Services/UsageService.php), [CrawlBudget](../insightistic-api/app/Services/Crawler/CrawlBudget.php), [AiCreditService](../insightistic-api/app/Domain/Billing/AiCreditService.php) and the [commercial catalog](../insightistic-api/config/commercial.php).

## Data sources and encryption

The supported external connections for the comparison are Google Analytics 4, Google Search Console, Bing Webmaster Tools and Cloudflare, plus WordPress and WooCommerce through the Insightistic connector. PageSpeed Insights, Site Audit and Uptime generate their own measurements. Engagement and email analytics depend on the relevant integration being configured and delivering data.

I verified that production Laravel reports AES-256-CBC as its application cipher. Model-level encrypted fields include [OAuth tokens](../insightistic-api/app/Models/ProviderAccount.php) and [WordPress connector secrets and Cloudflare API tokens](../insightistic-api/app/Models/Site.php). This establishes encryption for those credential fields at rest. It does not establish database-volume or backup encryption.

## Campaign status

- AppSumo submission 235204, deal 267702, is in Needs revision with the deal status Testing. AppSumo Partner Analytics reported zero paid orders in the November 1–19 window and zero lifetime invoice orders.
- The live public catalog reported zero completed or reserved Founding Angel sales and still returned state active. Prior catalog work retired those Stripe offers. The checkout state and catalog state need to agree before the campaign can be described as operationally closed on every surface. Existing lifetime grants must remain in place.
- The AppSumo draft now names five lifetime tiers, weekly rank checks, separate JOLLY messages and documented credential encryption. Its main image uses the official Insightistic logo and names supported capabilities.

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
