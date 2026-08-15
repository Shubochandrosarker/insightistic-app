# Ask Insightistic V2

Status: **P3 — implemented**. Fourth step of the Insightistic VNext roadmap
(`P0 Semantic Metric Registry → P1 Safe WooCommerce Query Engine → P2
Analytical Evidence Ledger → P3 Ask Insightistic V2 → P4 Multi-source
Analytics → P5 Saved Insights → ...`).

## What it is

`POST /api/sites/{site}/ask` takes a free-text question ("What was our
revenue last month?", "What are our top 5 products this month?") and
returns a cited, confidence-scored answer, a small chart spec, and the raw
evidence it was computed from — all backed by the same Safe WooCommerce
Query Engine (P1) and recorded to the Analytical Evidence Ledger (P2) that
every other analytical surface uses.

**Ask is not a second query path.** A question is only ever a different way
of producing the same `{metrics, dimension, period, comparison, limit}`
plan shape `PlanValidator` already accepts from a hand-authored request.
Every safety property P1 established — allowlisted metrics/tables/columns,
tenant scope from `$site` only (never the request body), the read-only DB
identity, statement timeouts, per-organization concurrency caps — applies
identically whether the plan came from a form or from a question.

## Pipeline

```
question
  → QuestionPlanBuilder     (deterministic keyword match → raw plan array)
  → PlanValidator            (same P1 allowlist checks, unconditionally)
  → SafeWooQueryEngine       (same P1 engine, unconditionally)
  → ConfidenceScorer         (freshness + match quality + result shape)
  → ChartSpecBuilder         (QueryResult → {"kpi"|"bar"} spec)
  → AnswerSynthesizer        (deterministic template, optional AI rephrase)
  → EvidenceLedger           (run_type = "ask", ai_used/ai_model recorded)
```

### QuestionPlanBuilder — deterministic, not AI-authored

`App\Services\Ask\QuestionPlanBuilder` matches keyword phrases against
`App\Metrics\MetricRegistry` (the Semantic Metric Registry, P0) to build a
raw plan. This is the **primary and fully tested** path — no AI call is
involved in understanding the question at all. A metric id can only ever
come from the registry, so this class cannot invent a metric that doesn't
exist. A question that matches no known metric keyword throws
`QueryEngineException::questionNotUnderstood()` (`422`,
`question_not_understood`) rather than guessing.

Recognized patterns (see the class for the full keyword table):

- **Metric keywords** — "revenue"/"sales" → `net_revenue`, "average order
  value"/"aov" → `aov`, "refund rate" → `refund_rate`, "top products"/"top N
  products" → `product_revenue`, etc. Multiple metrics in one question are
  all matched (capped downstream by `PlanValidator::MAX_METRICS`).
- **Dimension** — "by product", "top products", "top N products" → a
  product breakdown instead of a site-level aggregate.
- **Period** — "today", "yesterday", "this month", "last month", "last 7
  days", etc. Defaults to `last_30_days` when nothing matches, exactly like
  a hand-authored plan with no `period`.
- **Comparison** — "compared to", "vs last month", etc. Only honored for an
  aggregate; never combined with a dimension breakdown (mirrors
  `PlanValidator::validateComparison()`'s own rule).
- **Limit** — "top 5", "top 10" → row cap for a breakdown.

An AI-authored plan is **not** part of this pipeline. If a future iteration
adds one, it still has to pass through the exact same `PlanValidator` call
the deterministic path uses — there is no shortcut around that boundary for
any plan source.

### ConfidenceScorer — never the model's own confidence

`App\Services\Ask\ConfidenceScorer` computes `high`/`medium`/`low` from
things that are actually checkable: how stale the site's data sync is
(`App\Query\DataFreshness`, shared with the Evidence Ledger's freshness
field), whether the question matched an unusually large number of unrelated
metrics (a signal the question itself was ambiguous), and whether a
breakdown actually returned rows. **A provider's own self-reported
"confidence: high" is never used** — a model can be equally confident about
a right or an invented number, so it isn't evidence.

### AnswerSynthesizer — deterministic first, AI narration-only

`App\Services\Ask\AnswerSynthesizer` always builds a fully deterministic
answer contract from `QueryResult`'s real numbers first — mirroring
`CopilotChat`'s established answer-contract shape (`direct_answer`,
`key_finding`, `supporting_metrics`, `why_it_matters`,
`recommended_actions`, `confidence`, `data_limitations`,
`citation_refs`) so client UIs built for Copilot chat can render an Ask
answer too. This deterministic contract is the entire response whenever:

- no AI provider is configured,
- the organization has `allow_external_ai = false` (enforced by
  `App\Services\Ai\ProviderResolver`, the same consent gate
  `InsightGenerator` uses — see `docs/CONVERSATIONAL-ANALYTICS-AUDIT.md`
  §3), or
- the AI call fails for any reason.

An optional AI rephrase step may then run, but it can only touch **narrative
text** — `direct_answer`, `key_finding`, `why_it_matters`,
`recommended_actions`. It is given the deterministic answer plus the exact
evidence numbers and instructed to rephrase only, never introduce a new
number. Critically, **`confidence`, `supporting_metrics`, and
`citation_refs` are never taken from the AI response, no matter what it
returns** — they stay whatever the deterministic layer computed. A model
cannot inflate its own confidence, invent a supporting number, or fabricate
a citation; there is no code path that reads those three fields out of an
AI response at all. `tests/Unit/Ask/AnswerSynthesizerTest.php` asserts this
directly with a provider that tries to do exactly that.

### ChartSpecBuilder — two shapes only

`App\Services\Ask\ChartSpecBuilder` reshapes a `QueryResult` into one of two
declarative specs — it computes nothing itself, only copies numbers that
already passed through the query engine:

- `{"type": "kpi", "items": [{"metric_id", "label", "unit", "value",
  "previous_value", "delta_pct"}, ...]}` for a site-level aggregate.
- `{"type": "bar", "dimension": "product", "x_field": "label", "y_field":
  "value", "items": [...], "truncated": bool}` for a product breakdown.

## HTTP surface

```
POST /api/sites/{site}/ask
Body: {"question": "What was our revenue last month?"}
```

Response (`200`):

```json
{
  "answer": {
    "direct_answer": "Net Revenue was 1,234.56 (+12.3% vs the previous period).",
    "key_finding": "...",
    "supporting_metrics": [...],
    "why_it_matters": "...",
    "recommended_actions": [...],
    "confidence": "high",
    "data_limitations": "",
    "citation_refs": ["evidence_run:42"]
  },
  "chart": {"type": "kpi", "items": [...]},
  "evidence": { "...QueryResult::toArray()..." },
  "evidence_run_id": 42
}
```

Errors mirror `AnalyticsQueryController`: `422` for a validation/plan
failure (including `question_not_understood`), `429` for the organization's
concurrency cap, `504` for a query timeout, `503` if the read-only DB
identity isn't configured in production. Every outcome — success or error —
is recorded to the Evidence Ledger with `run_type = "ask"` before the
response is returned, exactly like `analytics/query`. The route shares that
endpoint's `throttle:query-engine` limiter, since it ultimately runs the
same engine.

Tenant scope: identical to every other `sites/{site}/...` route —
`site.access` middleware resolves `$site`; a question about a site outside
the caller's organization 404s before `AskInsightisticService` ever runs
(`tests/Feature/Ask/AskInsightisticEndpointTest.php`).

## What P3 deliberately does not do

- No conversational memory / multi-turn context. Each question is
  independent — no "and last month?" follow-up resolution yet.
- No AI-authored plan generation. Only the deterministic keyword builder
  proposes a plan; see "QuestionPlanBuilder" above for why that boundary is
  where it is for now.
- No streaming. Copilot chat streams tokens; Ask returns one JSON response
  once the query engine has finished, since its answer is built from an
  already-complete `QueryResult`, not incrementally generated text.
- Sources beyond `woocommerce` are not yet queryable (`PlanValidator` still
  only allows the `woocommerce` source) — a question that matches a GA4/GSC
  metric keyword (already in the P0 catalog) reaches `unsupported_source`
  honestly rather than silently degrading to WooCommerce data. P4 lifts
  this restriction.
