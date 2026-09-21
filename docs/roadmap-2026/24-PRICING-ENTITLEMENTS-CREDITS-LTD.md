# Pricing, Entitlements, Credits & Lifetime Deals

## Pricing principles

- price around business value, not feature count alone
- protect recurring infrastructure cost
- keep plans understandable
- make agency expansion profitable
- do not promise unlimited AI/crawling/rank tracking

## Proposed recurring pricing

Initial target for validation:

| Plan | Monthly | Sites |
|---|---:|---:|
| Starter | $29 | 3 |
| Growth | $69 | 10 |
| Agency | $149 | 30 |
| Agency Pro | $299 | 100 |

Annual discount should be implemented as dedicated Paddle yearly prices, not frontend arithmetic.

## Example limit matrix

| Limit | Starter | Growth | Agency | Agency Pro |
|---|---:|---:|---:|---:|
| Sites | 3 | 10 | 30 | 100 |
| Crawl pages/mo | 10k | 50k | 200k | 1M |
| Tracked keywords | 150 | 750 | 3k | 10k |
| AI prompts | 15 | 75 | 250 | 1k |
| Team members | 1 | 3 | 10 | 30+ |
| Reports/mo | 4 | 20 | 100+ | high/fair-use |
| White label | No | No/optional | Yes | Yes |
| API | No | limited | Yes | advanced |

Exact limits should be validated with cost telemetry before launch.

## Entitlement resolver

Inputs:

1. active recurring subscription plan
2. permanent lifetime grant
3. promotional/manual grant
4. admin override
5. account status/suspension

Output:

```json
{
  "plan": "growth",
  "features": ["technical_crawler", "bing_webmaster", "ai_visibility"],
  "limits": {
    "sites": 10,
    "crawl_pages_monthly": 50000,
    "tracked_keywords": 750,
    "ai_prompt_runs_monthly": 75
  }
}
```

The resolver is the single authority used by:

- API middleware/services
- dashboard
- plugin license response
- job dispatch

## Usage counters

Recommended separate usage dimensions:

```text
crawl_pages
rank_checks
ai_visibility_runs
ai_messages
ai_insights
embeddings
reports
uptime_checks optional
```

Do not collapse all compute into one opaque credit system internally. Track native units for cost analysis.

## Customer-facing Insight Credits

Use credits only for variable-cost add-ons where flexibility is useful.

Example packs:

- 5,000 credits — $15
- 20,000 — $49
- 50,000 — $99

The actual conversion from an operation to credits must be stable and published before sale.

Recommended use cases:

- extra AI visibility runs
- extra AI inference
- extra SERP checks
- premium external data calls

Core first-party data sync should not consume confusing per-request credits.

## Lifetime deal structure

### Angel Tier 1 — $79

- 3 sites
- 10k crawl pages/month
- 150 tracked keywords
- 10 AI prompts or equivalent limited allowance
- basic reports

### Angel Tier 2 — $149

- 10 sites
- 40k crawl pages/month
- 500 tracked keywords
- 40 AI prompts
- scheduled reports

### Angel Tier 3 — $249

- 30 sites
- 100k crawl pages/month
- 1,500 tracked keywords
- 100 AI prompts
- white-label reports
- 5 team members

The launch offer is limited to the first 100 Angel members and a separately announced campaign window. Publish the final limits and fair-use terms before accepting payment.

## LTD billing implementation

Each LTD tier has a **one-time Paddle price** mapped to a permanent `entitlement_grant`.

Do not implement LTD as a subscription with a fake 100-year duration.

## LTD fairness rules

Define in sales copy and terms:

- what "lifetime" means
- recurring monthly allowances
- whether future entirely-new products are included
- fair-use policy
- credit purchase rules
- refund/revocation consequences

Recommended promise:

> Lifetime access to the purchased Insightistic plan and its included feature family, with the published recurring usage limits for as long as Insightistic operates the service.

Avoid promising all future premium modules forever.

## Closing the LTD

Recommended campaign limit:

- 60 days, or
- 500 customers, or
- $50,000 gross LTD revenue

whichever happens first.

After closure, move to recurring plans and optional annual discounts.

## Abuse controls

- no account sharing across unrelated agencies if plan forbids it
- site activation limit enforced server-side
- rate limits
- credit/usage ledger immutable enough for audit
- manual admin override logged

## Acceptance criteria

- feature gate produces same result for SaaS and plugin
- one-time transaction cannot be replayed to add credits twice
- LTD grants survive absence of a subscription
- plan upgrades update plugin entitlement on next validation/refresh
- limits are enforced in backend jobs, not just displayed in UI
