# AI Visibility Monitoring

## Product goal

Measure whether a brand/site appears in AI-generated answers, which competitors appear, and which URLs are cited.

This is distinct from AI Copilot. Copilot helps the user analyze Insightistic data. AI Visibility measures external AI-answer surfaces.

## Core concepts

### Prompt

A stable question monitored over time.

Examples:

- best WordPress booking plugin
- best WooCommerce analytics plugin
- how to improve WordPress Core Web Vitals

### Provider/surface

Target surfaces may include API-accessible or commercially feasible versions of:

- OpenAI/ChatGPT-related search/answer experiences
- Gemini
- Perplexity
- Claude where feasible
- other AI search surfaces added through adapters

Provider availability must be treated as configurable because APIs and product surfaces change.

## Data model

See `05-DATA-MODEL.md`.

Required outputs per run:

```text
prompt
provider
run time
brand mentioned?
competitors mentioned
citation URLs
citation owned-domain?
mention order
sentiment/stance if confidently derived
response hash
usage/cost units
```

## Provider adapter

```php
interface AiVisibilityProvider
{
    public function runPrompt(AiPrompt $prompt): AiVisibilityObservation;
    public function estimateCost(AiPrompt $prompt): UsageEstimate;
}
```

Do not mix this with the Copilot provider interface because the goals and evidence contracts differ.

## Metrics

### Mention rate

`runs with brand mention / eligible runs`

### Citation rate

`runs citing owned domain / eligible runs`

### Share of voice

Define transparently. Example:

`brand mentions / total monitored entity mentions`

Do not call an ordinal mention order a universal "rank" unless the provider response format makes ordering meaningful.

### Provider coverage

Show performance separately by provider and combined only with a documented weighting method.

## Competitors

Users can define competitors per site. Also suggest recurring entities observed in answers.

Competitor detection should use normalized aliases:

```text
brand canonical name
known product names
known domains
common abbreviations
```

## Citation intelligence

Track:

- owned pages cited
- competitor domains cited
- third-party sources repeatedly cited
- newly appearing/lost citations

This can feed content strategy:

> "Your brand is mentioned in 42% of monitored answers, but your own domain is cited in only 12%."

## Prompt management

Prompt sources:

- user-created
- GSC-derived intent prompts
- competitor/category templates
- AI-generated suggestions requiring user approval

Never automatically add unlimited prompts because each prompt has recurring cost.

## Scheduling

Suggested:

- Starter: weekly or limited daily sample
- Growth: daily for core prompts
- Agency: daily with larger allowance

Batch by provider and respect rate limits.

## Cost controls

- reserve quota before dispatch
- record actual cost units
- stop when plan/credit allowance exhausted
- use lower-cost model/surface only where output is methodologically comparable
- show usage in Billing/Usage screen

## Quality controls

AI outputs are non-deterministic. Therefore:

- store repeated observations over time
- avoid overreacting to one run
- use rolling metrics
- label provider/model/surface
- do not claim causal SEO effects from AI mentions

## UI

Navigation:

```text
AI Visibility
├── Overview
├── Prompts
├── Mentions
├── Citations
└── Competitors
```

Overview KPIs:

- visibility/mention rate
- citation rate
- prompts monitored
- providers monitored
- competitor share
- changes vs previous period

## Acceptance criteria

- every run is associated with provider/model/surface metadata
- cost is metered before and after run
- raw secrets never reach browser
- metrics are computed from stored observations, not regenerated from memory
- UI can explain exactly how each visibility metric is calculated
