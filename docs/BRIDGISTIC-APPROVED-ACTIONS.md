# Bridgistic Approved Actions

Status: **P9 — implemented**. Eighth step of the Insightistic VNext
roadmap (`... P8 WPAgentistic Investigations → P9 Bridgistic Approved
Actions → P10 Agency Intelligence → ...`).

## What it is, and what it deliberately is not

An `ActionProposal` is a proposed action that **always requires an
explicit human approval before it ever executes** — no auto-execution
path exists anywhere in this system for a proposal, full stop.

```
POST /api/sites/{site}/action-proposals               propose (pending only)
GET  /api/sites/{site}/action-proposals                 list
GET  /api/sites/{site}/action-proposals/{id}             show
POST /api/sites/{site}/action-proposals/{id}/approve     approve + execute
POST /api/sites/{site}/action-proposals/{id}/reject      reject, never executes
```

Contrast with **P6 Automation Studio**: an `AutomationRule` executes
automatically once its trigger fires, because a human already approved it
*by configuring the rule*. A `ActionProposal` requires that approval
**fresh, every single occurrence** — appropriate for an AI-surfaced
suggestion (e.g. from a future automated flow, or manually today), which
is a fundamentally different trust level than a standing rule an admin
wrote themselves.

**This is a new internal subsystem** (per the explicit scoping decision
behind this PR), not an integration with an external "Bridgistic" product.
"Bridgistic" here names the propose → approve → execute framework itself.

**It cannot mutate a customer's live WordPress site.** Every action in
`App\Services\Actions\ActionType::ALL` — the identical allowlist and
`ActionExecutor` P6's `AutomationRule` also uses — only ever touches
Insightistic's own database or sends mail/webhooks the org itself
configured (`resolve_alert`, `dismiss_insight`, `create_saved_insight`,
`send_notification_email`, `send_webhook`). See `docs/AUTOMATION-STUDIO.md`
for the full action reference and `WebhookUrlGuard`'s SSRF hardening. There
is no outbound command channel to the WordPress plugin — building one
would need a new authenticated endpoint on that separate repository and an
even more conservative approval story than this. Not attempted here.

## Approval model

Every action requires human approval — there is no auto-approve tier in
this version. `App\Services\Actions\ActionProposalService::approve()` is
the **only** method in the entire codebase that ever calls
`ActionExecutor` on behalf of a `ActionProposal`, and it always requires a
real, authenticated `$reviewerUserId`. Approval and execution happen
**together, synchronously** — a proposal never sits in an "approved but
not yet run" limbo state that something else could later execute
unattended.

```
pending ──approve()──> executed  (action succeeded)
        └─approve()──> failed    (action itself errored — still recorded, never thrown away)
        └─reject()───> rejected  (never executed)
```

Once a proposal leaves `pending`, it's terminal — `approve()`/`reject()`
both throw if called again (`422 not_pending` at the HTTP layer).

## Provenance — every proposal must say why

`rationale` (required, non-empty) and `evidence_run_ids_json` (the real
`evidence_runs.id`s this proposal is backed by) exist so a proposal is
never a bare "do X" with no accountable reasoning attached. `source_type`
(`manual` | `wpagentistic_investigation` | `anomaly` | `automation_rule`)
+ `source_id` record what prompted it — e.g. a future caller can create a
proposal with `source_type = "wpagentistic_investigation"` and the real
Investigation id, copying its `rationale` from that investigation's
narrative. **P8 (WPAgentistic Investigations) never creates a proposal
automatically** — turning an investigation's recommendation into a real
proposal is always a separate, explicit call, matching P8's read-only
boundary (`docs/WPAGENTISTIC-INVESTIGATIONS.md`).

## `send_webhook` proposals are always unsigned

Unlike `AutomationRule` (P6), `ActionProposal` has **no dedicated
encrypted-secret column**. `ActionProposalService::approve()` always calls
`ActionExecutor::execute()` with `webhookSecret: null` — a `send_webhook`
proposal delivers without an `X-Insightistic-Signature` header. The
`store()` endpoint also actively strips any `secret`/`webhook_secret` key
a caller tries to smuggle into `action_params` before it's ever persisted,
so a plaintext secret can never end up sitting in
`action_proposals.action_params_json`. If a signed webhook is needed, use
a standing `AutomationRule` (P6) instead, which has proper encrypted
storage for exactly that.

## Tenant isolation

Same convention as every other resource in this app: `site.access`
middleware plus an explicit `site_id` match. Approving, rejecting, or even
viewing a proposal outside the caller's organization **404s** — never a
403 that would confirm the proposal exists —
`tests/Feature/Actions/Proposal/ActionProposalEndpointTest.php` proves a
cross-org approve attempt both 404s AND leaves the proposal's status and
the target Alert genuinely untouched.

## What P9 deliberately does not do

- **No auto-approval tier**, even for "low-risk" actions — the explicit
  scoping decision behind this PR. A future version could add one, but it
  would be a new, reviewed capability, not an assumption baked in here.
- **Cannot mutate a customer's live WordPress site** — see above.
- **No expiry** — a `pending` proposal has no TTL; it stays actionable
  until a human approves or rejects it. Follow-up work, analogous to
  `docs/PRIVACY-AND-RETENTION.md`'s existing retention enforcement.
- **No notification when a new proposal is created** — a human has to
  check `GET .../action-proposals?status=pending` (or a future UI) to find
  one; nothing emails/alerts about a pending proposal yet.
- **No UI.** This is the API surface a future "Approved Actions" panel
  reads from and writes to.
