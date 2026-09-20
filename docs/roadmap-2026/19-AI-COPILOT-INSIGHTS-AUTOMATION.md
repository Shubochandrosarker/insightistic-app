# AI Copilot, Insights & Automation

## Three separate AI products

Do not mix these concepts:

1. **AI Visibility** — observes external AI answers.
2. **Insights** — proactively analyzes Insightistic evidence.
3. **Copilot** — interactive user Q&A over authorized evidence.

## Evidence contract

Every AI request receives a server-assembled evidence bundle. The frontend does not supply arbitrary tenant data as truth.

Evidence may include:

- search metrics
- crawl issues
- performance runs
- traffic trends
- commerce metrics
- alerts
- knowledge-base content

## Insight lifecycle

Suggested states/fields:

- generated
- saved
- dismissed
- actioned
- stale/expired

Do not overload one enum if an insight can be both read and saved.

## Insight types

- executive
- search
- technical
- performance
- traffic
- commerce
- anomaly
- opportunity

## Generation rules

Before AI call:

1. verify plan/credits
2. assemble evidence
3. ensure sufficient data floor
4. check freshness/duplicate suppression
5. reserve usage

After call:

1. validate response contract
2. store provider/model/cost metadata
3. store citations/evidence references
4. finalize usage

## Copilot

### User questions

Examples:

- Why did organic traffic fall this month?
- Which pages should I fix first?
- Did site speed changes affect revenue?
- Which keywords are closest to page one?

### Response structure

Preferred answer contract:

```json
{
  "answer": "...",
  "evidence": [
    {"type":"gsc_query","id":"...","label":"..."}
  ],
  "recommended_actions": ["..."],
  "limitations": ["..."]
}
```

Server owns evidence references so the model cannot fabricate links to nonexistent records.

## Automation Studio

### Initial trigger types

- search traffic drops
- new critical crawl issue
- uptime incident
- CWV regression
- rank threshold crossed
- AI visibility drop
- low stock
- integration stale

### Initial actions

- email
- in-app notification
- generate report
- create saved insight
- webhook

Later:

- Slack/other connectors
- approved WordPress fixes

## Safe automation principles

- default to notify/recommend
- destructive mutations require approval
- every execution logged
- rate limits per rule
- loop prevention

## Cost controls

AI execution consumes monthly allowance or credits. Automations that call AI must reserve quota before running.

## Acceptance criteria

- AI cannot access another tenant's evidence
- generated claims are linked to data where possible
- empty/insufficient data returns explicit reason
- provider failure does not lose usage balance incorrectly
- automation history shows trigger, action, result and error
