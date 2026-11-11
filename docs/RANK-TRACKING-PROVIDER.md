# Rank tracking provider adapter (DataForSEO default)

## How it plugs in
- `App\Services\Rank\RankProvider` is the interface: `checkBatch()`, `available()`, `name()`.
- `RankProviderFactory` picks the adapter from `RANK_PROVIDER`. The default is `dataforseo`.
  - It's bound in `AppServiceProvider`.
  - `RankChecker`, `insightistic:rank-check` and System Health only ever see the interface.
- Adding a vendor means one class plus one line in `RankProviderFactory::PROVIDERS`.
- Every observation stores `provider = $provider->name()` and the provider's **own reported cost** (`cost_units`, USD).

## What was wrong in the old DataForSEO adapter (it had never run: no credentials, 0 observations)
| Problem | Effect | Fix |
|---|---|---|
| It expected envelope `status_code` **10000**. DataForSEO's "Ok." is **20000**. | Every successful call would have thrown "rejected the batch: Ok." | Checks for 20000. There's a regression test. |
| It sent up to 100 tasks in one **live** POST | Live accepts one task per call | One POST per keyword, sent in parallel (`DATAFORSEO_CONCURRENCY`, default 5) |
| No `depth` | DataForSEO's default depth is 10, so anything on page 2+ was stored as "not found in top 100" and could fire false `rank_not_found` alerts | `depth` = `DATAFORSEO_DEPTH` (default 100, clamped 10..200) |
| No `device` | Mobile keywords were checked as desktop | Sends `device` from the keyword |
| Answers matched by keyword text (FIFO) | Fragile with duplicate phrases | Sends `tag` = keyword id and matches on it |
| Batch cost split evenly | Not the real per-keyword cost | Each task's own `cost` |

## Failure rules
- If every keyword fails, an `IntegrationFailure` is thrown and nothing is written. That's the same as before.
- In a mixed batch, the failed keywords are left out. They get no observation and stay due. One warning is logged.
- A failed call never becomes a "not ranking" row.

## Cost (to measure, not guess)
- After the credentials are set, run `php artisan insightistic:rank-provider-check`. It's free (it uses `appendix/user_data`). It shows:
  - whether the credentials work
  - the balance
  - the active keyword count
  - the published-price estimate
  - **measured cost per check over the last 30 days** from `rank_observations.cost_units`
- Admin → System Health → `rank` shows the same numbers.

Published prices, for the pre-flight estimate only (DataForSEO pricing page and depth FAQ, checked 2026-11-11). The bill is per SERP of 10 results, with depth rounded up to the next ten:

| Mode | Per SERP | Top-100 check (10 SERPs) | Per keyword per month (daily) |
|---|---|---|---|
| Live (what this adapter uses) | $0.002 | $0.0155–$0.02* | $0.47–$0.60 |
| Standard queue (async, ~5 min, target 45 min) | $0.0006 | $0.0047–$0.006* | $0.14–$0.18 |

\*DataForSEO's pages disagree on whether pages 2+ get the 25% discount. The measured `cost_units` will settle it.

Cost levers (Shuvo's call, not changed here):
- `DATAFORSEO_DEPTH=50` halves the cost.
- A Standard-queue mode (post at 06:00, collect later) is about 3.3× cheaper. It needs a small pending-tasks table, so it's a separate PR if wanted.

## Env (credentials come later)
| Variable | Default | Notes |
|---|---|---|
| `RANK_PROVIDER` | `dataforseo` | Adapter id |
| `DATAFORSEO_LOGIN` / `DATAFORSEO_PASSWORD` | empty | API login and password from app.dataforseo.com → API access. While empty, checks are skipped and cost nothing. |
| `DATAFORSEO_DEPTH` | `100` | Results per check (10..200) |
| `DATAFORSEO_CONCURRENCY` | `5` | Parallel live requests |
| `DATAFORSEO_TIMEOUT` | `60` | Seconds per request |
