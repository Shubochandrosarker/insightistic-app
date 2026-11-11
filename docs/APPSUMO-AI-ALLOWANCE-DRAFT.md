# AppSumo tiers: monthly AI-credit allowance (DRAFT, measured cost only)

Settled: AppSumo tiers get a *small* monthly AI-credit allowance, sized from **measured** provider cost. Stripe Tax is on hold. This draft does not change any plan numbers. `commercial.appsumo_plans.*.monthly_credits` stays `0` until Shuvo approves the numbers.

## What production has measured (read-only, 2026-11-11 Asia/Dhaka)

| Source | Volume | Recorded provider cost |
|---|---|---|
| `telemetry_ai_usage` (every insight / visibility / report call) | 1,772 calls in the last 30 days, 9 orgs | **$0.00**, with 0 tokens recorded |
| `ai_runs` (Copilot answers) | 49 runs | `cost_usd` **null on every row** |
| `ai_visibility_runs` | 271 successful runs | `cost_units` **0** |
| `credit_ledger` debits | 23 debits ever (19 × advanced report = 9,500 credits, 4 × basic insight = 400 credits) | n/a |

Why it's $0:
1. **Every successful call ran on a free route.** That's free OpenRouter models, the WPistic gateway (which returns no price) or rule-based.
2. **Cost was never captured.** OpenRouter returns `usage.cost` on every completion, but no code read it. `MeteredAiProvider` deliberately recorded no tokens or cost.

So no honest dollar figure exists yet. Any allowance number written today would be made up.

Measured *usage*, Copilot tokens per answer by model (`ai_runs`):

| Model | Avg in | Avg out |
|---|---|---|
| nemotron-3.5-super-vl:free | 1,011 | 1,242 |
| ling-3.0-flash:free | 662–2,074 | 1,006–1,455 |
| glm-5.3 (gateway) | 1,019–1,264 | 505–1,065 |
| deepseek-v4.1-flash | 1,848 | 841 |

## Problems found while measuring (please fix before sizing allowances)
- **The paid route is broken.** `openrouter.paid_model = openai/gpt-luna-latest` failed **161 of 161** calls (Oct 17 to Nov 6). Paid-tier requests fall through to the gateway, the fallback model or rule-based answers.
- **The gateway fails 90% of insight calls:** `wpistic-gateway:glm-5.3`, 772 of 854.
- Measuring cost on today's routing would measure fallbacks, not the product we sell. Pick a working paid model first. That's Shuvo's call.

## What this PR changes (code, no prices)
- `ReportsUsage` interface:
  - OpenRouter records `usage.prompt_tokens`, `completion_tokens` and `cost`.
  - The gateway records tokens; its cost stays null because the gateway returns no price.
- `MeteredAiProvider` writes those values into `telemetry_ai_usage`. AI visibility writes them into `ai_visibility_runs.tokens_used / cost_units`. Copilot writes `ai_runs.cost_usd` from the streamed `usage.cost`.
- Nothing is estimated. If a provider reports nothing, the value stays 0 or null.

## How the allowance gets set (formula, filled in after measurement)
```
c_credit  = Σ cost_usd(paid-route AI actions, 14 days) / Σ credits debited for those actions
allowance = floor( B_tier / c_credit )   rounded down to a clean number
```
- `B_tier` is the monthly AI budget per AppSumo license. That's Shuvo's number (for example a share of the tier's net AppSumo revenue spread over the expected license life).
- Today's credit prices per action (`commercial.ai_action_costs`):
  - basic insight: 100
  - SEO analysis: 200
  - Woo analysis: 300
  - advanced report: 500
  - full audit: 1,000
- Revenue per credit from the packs, for reference only:
  - 5k for $15 = $0.0030
  - 20k for $49 = $0.00245
  - 50k for $99 = $0.00198
- **Guardrail:** `c_credit` must stay below the cheapest pack's revenue per credit, or the packs lose money too.

| AppSumo tier | Sites | Standard AI requests / mo (current config) | Monthly AI credits (draft) |
|---|---|---|---|
| appsumo_solo ($79) | 3 | 200 | **TBD = floor(B_solo / c_credit)** |
| appsumo_studio ($149) | 10 | 600 | **TBD** |
| appsumo_agency ($269) | 25 | 600 | **TBD** |

## Measurement plan (needs Shuvo's OK: it spends a little real OpenRouter money)
1. Shuvo picks a working paid model (replacing `gpt-luna-latest`).
2. Deploy this PR with the release (P0 #4). From then on every paid call records its real cost.
3. Optionally, to speed it up: a capped staging sample on the prod-copy DB.
   - 50 insights, 20 AI-visibility prompts and 20 Copilot questions on the chosen model.
   - Read `telemetry_ai_usage` and `ai_runs.cost_usd`, then compute `c_credit`.
4. Fill in the table above. Shuvo approves. Then the `monthly_credits` values go in a small config PR.
