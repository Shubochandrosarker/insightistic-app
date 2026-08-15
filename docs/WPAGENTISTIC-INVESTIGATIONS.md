# WPAgentistic Investigations

Status: **P8 — implemented**. Seventh step of the Insightistic VNext
roadmap (`... P7 Anomaly Engine → P8 WPAgentistic Investigations → P9
Bridgistic Approved Actions → ...`).

## What it is

A read-only, multi-metric diagnostic run — "why did revenue drop?", "what
changed this month?" — that gathers real evidence across several metrics
at once and synthesizes a narrative from it. Where **Ask Insightistic V2**
(P3) answers one question with one plan, an Investigation answers one
question by running **several** metrics and comparing them together.

```
POST /api/sites/{site}/investigations   {"subject": "...", "metric_ids": [...]}
GET  /api/sites/{site}/investigations              list
GET  /api/sites/{site}/investigations/{id}          show
```

**WPAgentistic is read-only. It cannot take any action** — no site
mutation, no alert resolution, no automation trigger of its own. Its
narrative may *recommend* a follow-up (`recommended_actions`), but turning
that recommendation into something that actually executes is always a
separate, explicit step through **P9 Bridgistic Approved Actions** — never
automatic. This boundary is deliberate: P8 investigates, P9 (with a human
in the loop) acts.

## Pipeline

```
{subject, metric_ids[]}
  → for each metric id (capped to PlanValidator::MAX_METRICS):
      → same raw-plan shape {metrics:[id], period:last_30_days, comparison:true}
      → PlanValidator → SafeWooQueryEngine → EvidenceLedger (run_type="investigation")
  → InvestigationSynthesizer  deterministic narrative + optional AI rephrase
  → Investigation             persisted, findings + narrative + citations
```

Every metric is investigated through the **exact same** `PlanValidator` /
`SafeWooQueryEngine` / `EvidenceLedger` pipeline every other analytical
surface uses — an Investigation is not a second query engine, only a
caller that runs the existing one once per requested metric and gathers
the results. Each run — success or failure — is recorded with
`run_type = "investigation"`; a metric that fails (unknown id, disallowed
source) does not abort the investigation, it's recorded as one failed
finding among the rest (`docs/ANALYTICAL-EVIDENCE.md`).

## Synthesis — the same AI guarantee as Ask (P3)

`App\Services\Investigation\InvestigationSynthesizer` mirrors
`App\Services\Ask\AnswerSynthesizer`'s two-layer contract exactly, applied
across multiple metrics instead of one:

1. **Deterministic first** — `direct_answer`/`key_findings` built only
   from the real `QueryResult` values already computed for each metric.
   This is the entire answer whenever no AI provider is configured, the
   organization has `allow_external_ai = false`
   (`App\Services\Ai\ProviderResolver`), or the AI call fails.
2. **Optional AI rephrase** — narrative text only
   (`direct_answer`/`key_findings`/`recommended_actions`). **`confidence`
   and `citation_refs` are never read from the AI response under any
   circumstance** — `tests/Unit/Investigation/InvestigationSynthesizerTest.php`
   proves this against a provider that tries to smuggle a fabricated
   number, a fake confidence, and a fabricated citation through.

`confidence` is computed from the site's own data freshness
(`App\Query\DataFreshness`) and how many of the requested metrics
succeeded — never from a model's own self-reported confidence, same rule
`App\Services\Ask\ConfidenceScorer` (P3) already follows.

`citation_refs` is built by `InvestigationService` itself, after
synthesis, from the real `evidence_run_id` of every finding — exactly the
same "citations are computed, never AI-supplied" pattern P3 established.

## What P8 deliberately does not do

- **Cannot take any action.** No alert resolution, no site mutation, no
  automation trigger. See "What it is" above.
- **Does not auto-create an ActionProposal.** A recommendation surfaced in
  `narrative_json.recommended_actions` stays plain text; turning it into a
  real proposal (P9) is always a separate, explicit call a human or a
  follow-up service makes — never something `InvestigationService` does on
  its own.
- **No conversational follow-up.** Each investigation is one self-contained
  run — no "and what about last month?" multi-turn refinement yet (same
  limitation Ask (P3) documents).
- **No AI-authored metric selection.** `metric_ids` is supplied by the
  caller (a human today; `trigger_source`/`trigger_ref_id` exist so a
  future alert/anomaly-triggered investigation can record what prompted
  it, but nothing currently auto-triggers one).
- **No UI.** This is the API surface a future "Investigations" panel reads
  from and writes to.
