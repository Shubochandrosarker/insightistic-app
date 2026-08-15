# Automation Studio

Status: **P6 — implemented**. Fifth step of the Insightistic VNext roadmap
(`... P5 Saved Insights → P6 Automation Studio → P7 Anomaly Engine → ...`).
`docs/CONVERSATIONAL-ANALYTICS-AUDIT.md` §7 flagged this as "a genuinely
new subsystem, not a retrofit" — no trigger→action chain or outbound
webhook-delivery mechanism existed anywhere in the app before this.

## What it is

An `AutomationRule` an org admin configures once — "when X happens, do
Y" — that fires automatically without further human involvement. Contrast
with **P9 Bridgistic Approved Actions**: a rule here executes immediately
because a human already approved it *by configuring it*; a P9
`ActionProposal` always requires an explicit human approval **per
occurrence** because it's an AI-surfaced suggestion, not a standing rule.

```
POST   /api/sites/{site}/automation-rules            create
GET    /api/sites/{site}/automation-rules             list
GET    /api/sites/{site}/automation-rules/{id}         show
PATCH  /api/sites/{site}/automation-rules/{id}         update
DELETE /api/sites/{site}/automation-rules/{id}         delete
POST   /api/sites/{site}/automation-rules/{id}/run     fire now, for testing
GET    /api/sites/{site}/automation-rules/{id}/runs    audit trail
```

## Triggers

| `trigger_type` | Fires when | `trigger_config_json` |
|---|---|---|
| `schedule` | Its own cadence is due | `{"frequency": "hourly"\|"daily"\|"weekly"}` (default `daily`). Checked against the rule's own `last_fired_at` by `AutomationDispatcher::shouldFireSchedule()` — see below. |
| `alert_created` | `AlertDetector` creates a genuinely **new** alert (not on every scan — only when a dedup check finds no already-open alert of that type) | `{"alert_type": "revenue_drop"}` optional — omit to match every alert type. |
| `anomaly_detected` | `AnomalyScanner` (P7) persists a genuinely new anomaly | `{"metric_id": "net_revenue"}` optional — omit to match every metric. See `docs/ANOMALY-ENGINE.md`. |

`AutomationDispatcher::matchesContext()` requires every key in
`trigger_config_json` to be present in the firing event's context with an
identical value — an empty config matches everything. There is
deliberately no generic condition-expression language (no "revenue < X
AND orders > Y"): each trigger type's own event context IS the condition,
matching how P1 explicitly scoped its plan schema down rather than
building a general-purpose query DSL.

The `schedule` trigger is driven by `insightistic:automation-run-scheduled`,
run **hourly** — the finest cadence a rule can configure. A `daily`/`weekly`
rule is safe to check hourly because `shouldFireSchedule()` looks at the
rule's own `last_fired_at`, not wall-clock alignment; running the command
more often than any given rule needs never double-fires it.

## Actions

Both this rule (auto-executes) and P9's `ActionProposal` (executes only
after explicit approval) share the exact same
`App\Services\Actions\ActionType` allowlist and the exact same
`App\Services\Actions\ActionExecutor` — there is one implementation of what
an action type actually does, never a duplicate per caller.

| `action_type` | `action_params_json` | Does |
|---|---|---|
| `resolve_alert` | `{"alert_id": 123}` | Marks the alert resolved, scoped to the rule's site. |
| `dismiss_insight` | `{"insight_id": 456}` | Sets `AiInsight.dismissed_at`, scoped to the rule's site. |
| `create_saved_insight` | `{"name": "...", "plan": {...}}` | Validates `plan` through the identical `PlanValidator` every other surface uses, then creates a `SavedInsight` (see `docs/SAVED-INSIGHTS.md`). |
| `send_notification_email` | `{"subject": "...", "body": "..."}` | Emails the organization's owner via `App\Mail\AutomationNotification`. |
| `send_webhook` | `{"url": "...", "payload": {...}}` | Signed `POST` to `url` — see below. |

**Every action here only ever mutates Insightistic's own database or sends
mail/webhooks the org itself configured.** None of them reach out and
mutate a customer's live WordPress site — see "What this deliberately does
not do" below.

`action_params_json` is a static template — there is no interpolation from
the trigger's `$context` into it yet (e.g. an `alert_created` rule cannot
say "resolve *the* alert that just fired," only a hardcoded `alert_id`).
This is a known, documented V1 limitation, not an oversight: templating
risks silently doing the wrong thing on a shape the author didn't
anticipate, so it's deferred rather than half-built.

### `send_webhook` — the new outbound-delivery mechanism

- **SSRF-hardened before every send**: `App\Services\Actions\WebhookUrlGuard`
  rejects non-http(s) schemes, non-standard ports, obvious internal
  hostnames (`localhost`, `*.local`, `*.internal`, single-label hosts) —
  all without touching DNS — and then resolves and screens every returned
  IP against private/loopback/link-local/reserved ranges. Independent of
  `App\Services\Knowledge\SafeUrlFetcher` (the knowledge crawler's SSRF
  guard) on purpose: that class follows redirects for GET-fetching
  arbitrary pages; a webhook is a single POST to a URL an org admin
  configured once, with **no redirect following** (`allow_redirects: false`
  — an unexpected redirect is a delivery failure, not something silently
  followed).
- **Signed**: `X-Insightistic-Timestamp` (unix seconds) +
  `X-Insightistic-Signature` (hex HMAC-SHA256 of `timestamp . '.' . body`,
  keyed by the rule's own `webhook_secret`) — the same signed-body
  convention every other outbound/inbound HMAC channel in this app already
  uses (`ConnectorAuth`, `CopilotChat`'s gateway signing). The exact bytes
  signed are the exact bytes sent (`Http::send('POST', $url, ['body' =>
  $body])`, not a second independent JSON encoding of the payload array)
  — a signature can never silently mismatch what the receiver actually
  gets.
- `webhook_secret` is stored **encrypted at rest** (`AutomationRule::casts()`
  — same `'encrypted'` cast convention as `Site::connector_secret`) and
  `hidden` from every API response — a `GET` on a rule never leaks it back
  out, even to the org that owns it.

## Audit trail

Every firing — success or failure — is recorded as one `AutomationRun`
row, mirroring `evidence_runs`' "never silently fail" convention:
`fired_at`, `status`, `trigger_context_json` (what triggered it),
`action_result_json` (verbatim from `ActionExecutor`), `error_message`.
`AutomationRule.last_fired_at`/`last_run_status` mirror the most recent run
onto the rule itself for quick "is this rule healthy" checks without a
join.

## What P6 deliberately does not do

- **Cannot mutate a customer's live WordPress site.** There is no outbound
  command channel to the WordPress plugin — `send_webhook` posts to a URL
  the *org* configured (e.g. a Zapier/Slack endpoint), not to the
  customer's own site via the existing `ConnectorAuth`-authenticated
  inbound channel. Building that would require a new authenticated
  endpoint on the plugin side (a separate repository) and a real
  human-approval story for what it's allowed to do — exactly the boundary
  P9 draws even more conservatively. Not attempted here.
- **No parameter templating** from trigger context into `action_params_json`
  — see above.
- **No generic condition/expression engine** — each trigger type's own
  event context is the only condition, on purpose.
- **No UI.** This is the API surface a future "Automation Studio" panel
  reads from and writes to.
