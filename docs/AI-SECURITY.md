# AI Security Model

How Insightistic keeps the AI Copilot and knowledge base safe, isolated and
honest.

## Tenant isolation

- Every knowledge/conversation table carries `organization_id`; the
  `BelongsToOrganization` global scope hides foreign rows (404, not 403).
- `KnowledgeRetriever` additionally injects `organization_id` as the first
  SQL predicate on every candidate query AND on hydration (defense in
  depth). The org id always comes from the server-side tenant context —
  never from the browser.
- Client-supplied knowledge-base/source/site ids are resolved through the
  tenant scope: foreign ids silently vanish from filters.
- Regression tests: `KnowledgeTenantIsolationTest` (including forged-filter
  attacks), `CopilotTest` (thread + citation ownership).

## Prompt-injection defense

- Retrieved documents are UNTRUSTED. They are wrapped in a `<documents>`
  envelope and the system prompt instructs the model to treat everything
  inside as data — instructions found in crawled/uploaded content must be
  ignored.
- The system prompt forbids revealing instructions, credentials, or other
  tenants' data, and states the assistant cannot execute actions.
- The model never sees another tenant's content because retrieval is
  org-scoped at the SQL layer — no prompt can widen the scope.
- The AI can only ever produce text: there is no tool/function execution
  path from Copilot into billing, deletion or admin operations. Any future
  action execution must go through explicit user confirmation.

## Citations are never invented

- The server builds the retrieval set; the model may only *reference* items
  by the ids the server supplied (`K1…`, `A1…`). Unknown refs are dropped.
  Citations are stored rows pointing at real `knowledge_chunks` /
  analytics evidence, with source title/type/url/snippet/score and
  last-indexed time.

## Data leaving the platform

- `organizations.allow_external_ai` (consent switch): when false, Copilot
  and insights run only on the local deterministic RuleProvider — nothing
  is sent to any external model.
- Basic PII redaction (emails, long digit runs) is applied to knowledge
  text before external model calls (`CopilotContextBuilder::redactPii`).
- Customer analytics are sent as aggregates (revenue, counts, top products);
  wc_customers already stores hashed emails only.
- Never sent anywhere: API keys, connector secrets, license keys, Stripe
  ids, other tenants' data.

## Telemetry & audit

- `ai_runs` records provider, model, prompt version, latency, tokens and
  cost per message — no prompt/response bodies with secrets.
- Quotas are enforced server-side BEFORE any provider call; failures never
  consume quota.
- Rate limiters: `ai-chat` 20/min/user, `knowledge-upload` 20/min,
  `knowledge-crawl` 6/min (SSRF probing surface), all cache-backed.

## Crawling (SSRF)

See docs/AI-KNOWLEDGE-BASE.md → SSRF policy. Enforced in code
(`SafeUrlFetcher`) with an offline unit-test matrix
(`tests/Unit/SafeUrlFetcherTest.php`) covering private ranges, cloud
metadata IPs, internal hostnames, schemes, ports and credentialed URLs.
