# Insightistic AppSumo review recording script

Use this script for submission **235204** and deal **267702**. AppSumo requested production videos for items 1–8 and written confirmations for items 9–11. Read the actual values on screen wherever the narration uses brackets.

## Before recording

- Use Edge and a dedicated Tier 5 reviewer workspace with shareable production test data. Prepare a separate subscribed account for the Stripe segment. Use a redirecting site you control for clip 01 and a test mailbox for report delivery.
- Open [production login](https://app.insightistic.com/login) while signed out. Open DevTools, dock it to the right, select **Console**, and turn on **Preserve log**. Keep the production address bar and Console readable from the login through the result in every clip. Open DevTools in any preview tab before switching the recording to it.
- Use a password manager or the browser’s masked fields. Keep passwords, MFA codes, sensitive query parameters, real customer data and payment details out of the recording. Record the visible production login and the dashboard load.
- Make a dry run in each test workspace. Check your starting counters, ensure a crawl can finish within the recording, and have existing Site Audit and Mobile and Desktop PageSpeed results available for the JOLLY and AI insight clips. Show real job progress and wait for the fresh result. Repeat a clip if a result or related Console error invalidates the evidence.

## 01 · Uptime redirects and SSL

**Record.** Sign in. Click **Add Website** and enter the prepared URL that redirects to a working HTTPS destination. Select the new project and go to **Performance → Uptime**. Click **Check now** and keep the completed probe on screen. Show the target, **Up**, final HTTP 200, last checked time, response time, and SSL expiry. If the project appears in **Agency → Portfolio**, show that it no longer reports a false critical incident.

**Say.** “I’m adding [entered URL]. It redirects to [destination]. The uptime check follows the redirect to the final page. The monitor reports Up and HTTP 200 with a measured response of [time]. The certificate expires on [date], with [days] days remaining.”

## 02 · Report dates, PDF, HTML and download

**Record.** Sign in, select the project and open **Reports**. Choose weekly or monthly and click **Generate report**. Show the completed row and formatted date range. Click **View PDF**, then **View HTML**. Show the production HTTPS host and that both documents load successfully. Return to **Reports**, click **Download HTML**, and show the saved HTML file in Downloads. If covering report delivery, send the same generated report to the test mailbox and show the confirmation.

**Say.** “I generated a [weekly/monthly] report for [project], covering [start] through [end]. The dates are readable in the report list. The generated PDF and HTML links both open on the production HTTPS host using the standard HTTPS port. Download HTML saves the standalone report. [If tested: I sent this report to the test mailbox from the same row.]”

## 03 · Agent JOLLY Auto mode and citations

**Record.** Sign in. In **Site Audit**, show the latest completed crawl, its processed pages, and findings. In **Performance**, show the latest Mobile and Desktop scores. Open **AI Visibility → Agent JOLLY**, start a new chat, and show **Data Auto**. Send this prompt.

> What did my latest completed Site Audit and PageSpeed tests find? Give the processed pages, findings from that run, the top crawl issues, and the latest mobile and desktop Performance scores. Cite Site Audit and PageSpeed for the results.

**Say.** “Auto mode found both sources for this project. The latest completed crawl processed [N] pages and recorded [N] findings. The measured PageSpeed scores are [mobile] and [desktop]. I can open these citations to see the date, measured value, and source page.”

**Finish on.** The rendered answer and two open citation examples, one from each source. If the answer includes currently open issues as well as latest-run findings, name each count separately.

## 04 · Performance and Executive AI insights

**Record.** Sign in. Show the project’s measured PageSpeed scores and completed Site Audit. In **AI Visibility → AI Insights**, generate a fresh **Performance** insight, then a fresh **Executive** insight. Open the relevant source links and show the **Evidence only** provenance indicator. Use the Tier 5 reviewer workspace. An additional Tier 4 clip can document the expanded entitlement once a live Tier 4 account is available.

**Say.** “Performance uses this project’s PageSpeed evidence. It identified [actual metric or issue]. Executive uses the verified data available for the same project, including [crawl result] and [PageSpeed results]. Both results are generated from stored evidence, and the source links are visible here.”

## 05 · Tier 5 usage and entitlement reconciliation

**Record.** Sign in. Show the same workspace’s Sidebar, **Usage**, **Billing**, and **Licenses**. Note the starting standard requests, JOLLY messages, Insight Credits, connected websites, and crawl pages. Complete the following actions in sequence, refreshing Usage after each:

1. Send one fresh JOLLY prompt and wait for the complete response. Show the JOLLY counter move by one and compare the standard requests and credits with the baseline.
2. Generate an eligible Performance insight while standard requests remain. Show the standard requests increase by one and compare the other two AI counters.
3. Start a crawl. Show the per-run ceiling and crawl progress. After completion, show the actual processed pages. Compare the workspace usage delta with the processed count and show the remaining project and workspace amounts. Use a workspace without another simultaneous crawl.
4. Revisit the Sidebar, Usage, Billing, and Licenses. Show matching standard request usage where displayed, the AppSumo lifetime tier, and the same connected website count wherever the website metric is shown. If plugin activations appear, identify them as a separate metric.

**Say.** “Tier 5 includes 5,000 standard requests, 300 JOLLY messages, and 250,000 Insight Credits monthly. It supports up to 50 website projects. The JOLLY reply used one message and the Performance insight used one standard request. The crawl had a ceiling of [ceiling] and processed [N] URLs. Workspace usage increased by [N], which matches the completed crawl.”

## 06 · Lifetime billing and Stripe portal

**Record.** Sign in to the Tier 5 reviewer account. Open **Settings → Billing**, show the lifetime plan and active entitlement, and scroll through the entire billing view. Start a separate production login to a subscribed test account. Show its active subscription details, select **Manage billing** or the Stripe portal button, and show the portal opening. Keep private payment information outside the visible area.

**Say.** “This workspace is on AppSumo Tier 5 Lifetime. Billing shows its licensed tier and relevant details. This second workspace has a direct subscription. It shows its billing details and opens the Stripe Customer Portal. AppSumo handles the lifetime purchase and redemption.”

## 07 · Live Site Audit progress, reporting and classification

**Record.** Sign in and select the test site with several page links, a known redirect, and a sitemap, robots.txt, or llms.txt. Open **Site Audit** and show project and workspace usage and remaining pages. Set and save a reasonable project crawl cap. Start a crawl and keep the running status visible as discovery and processing advance. Show the completed row, final counts, and updated allowances. Use **Pages**, **Links**, or **Issues** to show the non-HTML resource and the redirect example.

**Say.** “The workspace has [remaining] crawl pages and this project has [remaining] under its cap. The crawler discovered and processed URLs while the run was active. The completed crawl processed [N] pages and recorded [N] findings. These non-HTML resources are classified separately, and the redirect has one corresponding finding.”

**Finish on.** In-progress evidence, final processed and discovered counts, issue totals, available pages, and the classification examples. Check that the selected non-HTML resource has no HTML title or metadata finding.

## 08 · Completed PageSpeed test and opportunity links

**Record.** Sign in and go to **Performance → Core Web Vitals**. Select **Mobile**, click **Run test**, and wait for the new run to complete and the timestamp to change. Repeat for **Desktop** when practical. Show the scores for Performance, Accessibility, Best Practices, SEO, and AI readiness when measured. Scroll to the measured vitals. Click a readable opportunity link and return to the result.

**Say.** “This is a newly completed [mobile/desktop] PageSpeed Insights test. The four measured scores are [values]. The vitals shown here have measured values for this result. Optional metrics appear when available. This opportunity opens its source guidance directly.”

**Finish on.** The selected strategy, fresh timestamp, scored categories, measured vitals and a working link. Use the actual measured INP and AI readiness values if this project supplies them.

## Written response for item 09 · Metering

> Standard AI requests use ai_insights_used. An eligible completed dashboard insight uses one available standard request. JOLLY uses ai_messages_used, with one message for each completed reply. Tier 5 includes 5,000 standard requests, 300 JOLLY messages and 250,000 Insight Credits monthly. Credits pay for eligible overage and credit-priced actions. Successful standard keyword checks use 10 credits, local checks use 15, and each teammate successfully added uses 50 once. A crawl uses actual processed URLs, with active runs reserving their outstanding capacity. The comparison lists each allowance separately.

Share the [detailed metering and offer table](APPSUMO-REVIEW-2026-11-19.md).

## Written response for item 10 · Listing and security claims

> The revised listing identifies five lifetime tiers, corrects the custom knowledge base wording, and states that rank checks run weekly on all tiers. Connected sources are Google Analytics 4, Google Search Console, Bing Webmaster Tools, Cloudflare, and WordPress and WooCommerce through the Insightistic connector. PageSpeed Insights, Site Audit, and Uptime provide additional measurements. The hero and gallery show product screens with labeled sample data. We removed the numerical source count and broad encryption badge. The application uses AES-256-CBC for specified stored credentials, including access and refresh tokens, WordPress connector secrets, and Cloudflare API tokens.

Attach the [cipher and encrypted field evidence](APPSUMO-REVIEW-2026-11-19.md#data-sources-and-encryption). Provide the cipher name and field names only.

## Written response for item 11 · Campaign and existing licenses

On the submission date, confirm the configured Founding Angel state, whether checkout accepts new purchases, successful and reserved sales, AppSumo deal status, and paid orders. Review the public [Lifetime Deal page](https://www.insightistic.com/ltd/) in the same session.

> AppSumo is a separate lifetime offer from Founding Angel. As of [date/time and time zone], Founding Angel is [verified catalog and checkout status], with [successful sales] completed and [reserved sales] reserved. The AppSumo deal is [status], with [paid orders] paid orders over [period]. Existing lifetime licenses retain their terms. AppSumo redemption applies its tier to the eligible workspace. Existing Tier 3 AppSumo licenses retain their previous white-label and priority-support access.

At the November 19 check, AppSumo showed Testing and zero paid orders in the checked period. Production also recorded active Tier 3 licenses. Paid orders and license records are separate measures. The Founding Angel API still reported active while the checkout products had been retired. Confirm both surfaces before sending a single campaign status to AppSumo.

## Before sending the review

- Export and label the eight MP4s 01–08 using the clip titles above. Watch the opening login, the completed result, and the Console in each file. Confirm the final video links open for the intended reviewer.
- Supply dedicated reviewer access through AppSumo’s approved credential-sharing mechanism and the production login link. Attach the eight video links and the item 09–11 written answers to the draft.
- Check the latest tier comparison and gallery against the applicable production release. The owner reviews the draft and sends the submission once the evidence is complete.
