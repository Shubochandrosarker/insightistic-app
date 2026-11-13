# Insightistic AppSumo 2026 pricing model

The canonical catalog lives in `insightistic-api/config/commercial.php` and is
seeded into the `plans` table by `PlanSeeder`. AppSumo owns the one-time charge;
the signed AppSumo webhook and OAuth claim flow own the lifetime entitlement.
These tiers are deliberately not Stripe subscription products.

| Tier | Price | Sites | Page crawl pages/mo | Browser minutes/mo | Weekly keywords | SERP depth | AI actions/mo | Included credits/mo | Reports/mo | Active schedules |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| 1 | $49 | 1 | 10,000 | 15 | 10 | 100 | 100 | 5,000 | 5 | 1 |
| 2 | $79 | 3 | 40,000 | 60 | 20 | 20 | 200 | 10,000 | 25 | 3 |
| 3 | $149 | 10 | 100,000 | 180 | 40 | 20 | 600 | 30,000 | 100 | 10 |
| 4 | $269 | 25 | 250,000 | 300 | 100 | 20 | 1,500 | 75,000 | 250 | 25 |
| 5 | $499 | 50 | 600,000 | 600 | 250 | 20 | 5,000 | 250,000 | 500 | 50 |

Tier 1 permits three added team members. Tiers 2–5 permit unlimited added team
members. Every added member costs 10 Insight Credits. Rank checks cost 10
credits for a standard market and 15 credits when a local market is supplied.
The monthly credit amounts are an explicit launch draft (50 credits per
included standard AI action) and can be changed in the catalog before listing
submission without changing the entitlement code. Reports/mo means generated
client reports; active schedules means recurring report schedules that may be
configured simultaneously. Page crawl limits are organization-wide and shared
across connected sites.

All tiers include GA4, Search Console, Cloudflare, PageSpeed, SEO analytics,
rank tracking, local SEO market targeting, AI insights/copilot, scheduled PDF
reports and a custom KnowledgeBase. Tiers 2–5 include WooCommerce analytics and
the Custom Workflow Builder. Tiers 3–5 include AI competitor visibility
insights, market insights, white-label reports and priority support. Tier 4–5
also include the white-label dashboard; Tier 5 adds a custom domain.

## Verification gates before launch

1. Run `PlanSeeder` on the target database and verify the five `appsumo_tier_*`
   rows and their `limits_json`.
2. Confirm the AppSumo webhook test returns HTTP 200 with `success: true`, and
   exercise OAuth callback validation in the partner portal.
3. Redeem a synthetic activate event and verify the claim page, organization
   plan, wallet grant, team-member charge and local rank check in staging.
4. Confirm the intended Stripe account before creating or changing any live
   recurring/credit-pack prices. Stripe remains for recurring subscriptions and
   Insight Credit packs; AppSumo remains the LTD entitlement source.
