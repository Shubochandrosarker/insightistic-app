<?php

namespace App\Services\Actions;

/**
 * The complete allowlist of actions this system can ever execute on behalf
 * of a user or an automated proposal (spec: P6 Automation Studio / P9
 * Bridgistic Approved Actions). Deliberately small and entirely internal —
 * every action here only ever mutates Insightistic's own database or sends
 * mail/webhooks the org itself configured. None of them reach out and
 * mutate a customer's live WordPress site; that capability does not exist
 * yet (see docs/BRIDGISTIC-APPROVED-ACTIONS.md "What this deliberately
 * does not do").
 *
 * Both callers that execute an action — App\Models\AutomationRule (P6,
 * pre-configured by an org admin, executes automatically when its trigger
 * fires) and App\Models\ActionProposal (P9, always requires an explicit
 * human approval before executing) — share this exact list and the same
 * ActionExecutor, so "what an action can actually do" is defined in
 * exactly one place regardless of which surface triggered it.
 */
final class ActionType
{
    public const RESOLVE_ALERT = 'resolve_alert';

    public const DISMISS_INSIGHT = 'dismiss_insight';

    public const CREATE_SAVED_INSIGHT = 'create_saved_insight';

    public const SEND_NOTIFICATION_EMAIL = 'send_notification_email';

    public const SEND_WEBHOOK = 'send_webhook';

    public const ALL = [
        self::RESOLVE_ALERT,
        self::DISMISS_INSIGHT,
        self::CREATE_SAVED_INSIGHT,
        self::SEND_NOTIFICATION_EMAIL,
        self::SEND_WEBHOOK,
    ];
}
