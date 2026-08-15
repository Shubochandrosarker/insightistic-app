# Outcome Verification

Status: **P11 — implemented**. Final step of this roadmap slice
(`... P9 Bridgistic Approved Actions → P10 Agency Intelligence → P11
Outcome Verification`).

## What it is

Closes the loop **P9 Bridgistic Approved Actions** opens: for every
`ActionProposal` that gets approved and executed, capture a baseline
reading of whatever metric its source implicates, then later re-measure
the same metric and record — honestly, from real evidence — whether it
actually improved.

```
GET  /api/sites/{site}/outcome-verifications?outcome=
GET  /api/sites/{site}/outcome-verifications/{id}
POST /api/sites/{site}/outcome-verifications/{id}/verify
```

There is no `store()` route — an `OutcomeVerification` row only ever
exists because a real approved-and-executed `ActionProposal` created it,
automatically, via `App\Services\Actions\ActionProposalService::approve()`.
You cannot fabricate one through the API.

## How a metric gets watched

Not every proposal has an obvious metric to verify — `resolve_alert` or
`dismiss_insight` don't move a number the way an anomaly-driven action
might. `OutcomeVerificationService::watchedMetricFor()` derives it from the
proposal's own `source_type`:

| `source_type` | Watched metric |
|---|---|
| `anomaly` | The `Anomaly`'s own `metric_id` (P7). |
| `wpagentistic_investigation` | The first **successful** finding's `metric_id` in that `Investigation` (P8) — an errored finding is skipped, never used. |
| `manual` / `automation_rule` | None — no metric is derivable from these sources yet. |

When no metric can be derived, the row is created **already terminal**
with `outcome = "no_metric_to_verify"` and `verified_at` set immediately —
never left dangling with a null outcome forever, which would be
indistinguishable from "not checked yet."

## Baseline → follow-up → classification

Both the baseline (captured at approval time) and the follow-up (captured
by `verify()`) run through the **exact same** `SafeWooQueryEngine` /
`EvidenceLedger` pipeline (`run_type = "query"`, same as a direct
`analytics/query` call) every other analytical surface uses — a
point-in-time aggregate reading of the watched metric, no comparison
window.

**Why the measured values live directly on `outcome_verifications`
(`baseline_value`/`followup_value`), not re-derived from `evidence_runs`
later**: `evidence_runs` persists a run's plan/hash/duration/outcome (P2's
documented scope) — it does **not** store a copy of the computed metric
value. The linked `baseline_evidence_run_id`/`followup_evidence_run_id`
exist purely as citations to the reproducible run that produced each
value, not as a place to look the value back up from.

`verify()` requires at least **24 hours** between baseline and follow-up
(`OutcomeVerificationService::MIN_HOURS_BEFORE_VERIFY`) before a
scheduled sweep will pick it up — not enough time has passed for a
re-measurement to mean anything sooner. A human calling `POST .../verify`
directly is **not** held to that wait (an explicit human action is always
allowed), matching how other "run now" endpoints in this app (e.g.
`AnomalyController::scan`) bypass their own schedule.

```
delta_pct = (followup - baseline) / baseline * 100

>= +5.0%  -> "improved"
<= -5.0%  -> "worsened"
otherwise -> "unchanged"   (a ±5-point noise band — small moves are noise, not a verified outcome either way)
```

## Automatic wiring into P9

`ActionProposalService::approve()` calls `captureBaseline()` immediately
after a successful execution (`status = "executed"`) — **never** on a
failed one, since there is nothing to verify the outcome of an action that
didn't actually run. This is the only place a baseline is captured; there
is no separate "start watching" step.

`php artisan insightistic:outcome-verifications-run [--site=]`, scheduled
**daily** — a verification isn't due until 24h after its baseline anyway,
so a finer cadence would find nothing due most runs.

## What P11 deliberately does not do

- **No metric derivation for `manual`/`automation_rule`-sourced
  proposals** — those proposals have no natural "the metric this was
  about" the way an anomaly or investigation does. A future version could
  let a proposer explicitly name a metric to watch; not attempted here.
- **No causal claim.** `outcome = "improved"` means the watched metric
  measurably moved up after the action executed — it does **not** claim
  the action *caused* that movement. Two things happening in sequence is
  not evidence of causation, and this system never asserts one.
- **No retry/backoff for a failed follow-up query** — if the follow-up
  `SafeWooQueryEngine` run itself fails (e.g. read-only identity
  unconfigured), `followup_value` stays `null` and `classify()` reports
  `"unchanged"` rather than fabricating a number — honest, but the row
  then needs a manual re-`verify()` since nothing retries it automatically.
- **No UI.** This is the API surface a future "Outcomes" panel reads from.
