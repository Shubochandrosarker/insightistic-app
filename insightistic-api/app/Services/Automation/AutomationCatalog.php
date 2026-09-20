<?php

namespace App\Services\Automation;

use App\Services\Actions\ActionType;
use App\Services\Anomaly\AnomalyDetector;

/**
 * Static, read-only catalog of everything the Automation Studio rule
 * builder (spec: P6 / doc 19 "Automation Studio") may offer a user: the
 * trigger types AutomationDispatcher actually implements, the actions
 * ActionExecutor::execute() actually supports, and each entry's config
 * fields for form rendering. Deliberately DERIVED FROM the real code, not
 * wishful UI copy:
 *
 *   - triggers match AutomationRule::TRIGGER_TYPES exactly; each entry
 *     documents the services that really call
 *     AutomationDispatcher::dispatch(<type>, ...) (verified by
 *     tests/Feature/AutomationCatalogTest.php, which greps those sources
 *     at runtime).
 *   - actions with status 'active' are exactly the ActionType::ALL
 *     allowlist — a rule can never be saved with anything the executor
 *     does not run (AutomationRuleController::validatePayload enforces the
 *     same list). Doc-19 actions/triggers that do NOT exist yet are listed
 *     with status 'coming_soon' so the UI can gray them out instead of
 *     silently pretending they work.
 *
 * Safe-automation note (doc 19): 'schedule' is rate limited by its own
 * cadence; 'alert_created' / 'anomaly_detected' are deduped at their
 * source (a firing only happens for a genuinely new alert/anomaly row) but
 * carry no per-rule post-firing cooldown yet — see each trigger's
 * 'rate_limit' note and the reported AutomationDispatcher patch.
 */
class AutomationCatalog
{
    /** All alert_type values any 'alert_created' source can actually raise. */
    public const ALERT_TYPES = [
        // App\Services\AlertDetector::raise() calls
        'revenue_drop',
        'refund_spike',
        'failed_order_spike',
        'low_stock',
        'traffic_drop',
        'traffic_spike',
        'seo_decline',
        'performance_regression',
        'sync_failed',
        'sync_stale',
        'broken_link_spike',
        // App\Services\Crawler\ChangeDetection::ALERT_TYPE
        'critical_site_change',
        // App\Services\Uptime\IncidentManager::ALERT_TYPE
        'uptime_incident_confirmed',
        // App\Services\Uptime\SslInspector::ALERT_TYPE_WARNING / _CRITICAL
        'ssl_expiry_warning',
        'ssl_expiry_critical',
        // App\Services\Rank\RankChecker::ALERT_* constants
        'rank_entered_top10',
        'rank_dropped',
        'rank_position_changed_url',
        'rank_not_found',
    ];

    /** @return array{triggers: array<int, array<string, mixed>>, actions: array<int, array<string, mixed>>} */
    public static function catalog(): array
    {
        return [
            'triggers' => [
                [
                    'type' => 'schedule',
                    'label' => 'On a schedule',
                    'description' => 'Fires on its own cadence, driven by the hourly insightistic:automation-run-scheduled command. AutomationDispatcher::shouldFireSchedule() compares trigger_config frequency against the rule\'s last_fired_at — hourly/daily/weekly (anything else falls back to daily).',
                    'status' => 'active',
                    'rate_limit' => 'Rate limited by cadence: cannot fire more often than its frequency (hourly=1h, daily=24h, weekly=168h) since last_fired_at.',
                    'dispatched_by' => [
                        'App\Console\Commands\RunScheduledAutomations',
                    ],
                    'config_fields' => [
                        [
                            'key' => 'frequency',
                            'label' => 'Frequency',
                            'type' => 'select',
                            'options' => ['hourly', 'daily', 'weekly'],
                        ],
                    ],
                ],
                [
                    'type' => 'alert_created',
                    'label' => 'When a new alert is created',
                    'description' => 'Fires when a monitoring service creates a genuinely NEW alert row (never re-fires while the same alert is still open). trigger_config may pin alert_type to one subtype; every configured key must be present in the dispatch context with an identical value, and an empty config matches any alert.',
                    'status' => 'active',
                    'rate_limit' => 'Deduped at the source: AlertDetector raises at most one open alert per type, ChangeDetection/SslInspector/RankChecker dedupe per run/certificate/keyword+day. No per-rule post-firing cooldown yet (doc 19 "rate limits per rule" — dispatcher patch pending).',
                    'dispatched_by' => [
                        'App\Services\AlertDetector',
                        'App\Services\Crawler\ChangeDetection',
                        'App\Services\Uptime\IncidentManager',
                        'App\Services\Uptime\SslInspector',
                        'App\Services\Rank\RankChecker',
                    ],
                    'config_fields' => [
                        [
                            'key' => 'alert_type',
                            'label' => 'Alert type',
                            'type' => 'select',
                            'options' => self::ALERT_TYPES,
                        ],
                    ],
                ],
                [
                    'type' => 'anomaly_detected',
                    'label' => 'When a metric anomaly is detected',
                    'description' => 'Fires when AnomalyScanner (median/MAD detection over the supported metric set) persists a genuinely NEW anomaly row — deduped per site+metric+date. trigger_config may pin metric_id to one supported metric.',
                    'status' => 'active',
                    'rate_limit' => 'Deduped at the source: one anomaly row per site+metric+date, so the same day\'s anomaly never re-fires. No per-rule post-firing cooldown yet (doc 19 — dispatcher patch pending).',
                    'dispatched_by' => [
                        'App\Services\Anomaly\AnomalyScanner',
                    ],
                    'config_fields' => [
                        [
                            'key' => 'metric_id',
                            'label' => 'Metric',
                            'type' => 'select',
                            'options' => AnomalyDetector::supportedMetrics(),
                        ],
                    ],
                ],
                [
                    'type' => 'ai_visibility_drop',
                    'label' => 'AI visibility drop',
                    'description' => 'Doc 19 initial trigger. NOT implemented: no service dispatches an ai_visibility_drop trigger yet — AI Visibility observations are not wired into AutomationDispatcher.',
                    'status' => 'coming_soon',
                    'dispatched_by' => [],
                    'config_fields' => [],
                ],
            ],
            'actions' => [
                [
                    'type' => ActionType::SEND_NOTIFICATION_EMAIL,
                    'label' => 'Send notification email',
                    'description' => 'Emails the organization owner (subject + body are free text; both required). Fails gracefully with no_recipient/send_failed recorded on the AutomationRun.',
                    'status' => 'active',
                    'config_fields' => [
                        ['key' => 'subject', 'label' => 'Email subject', 'type' => 'text'],
                        ['key' => 'body', 'label' => 'Email body', 'type' => 'text'],
                    ],
                ],
                [
                    'type' => ActionType::SEND_WEBHOOK,
                    'label' => 'Call a webhook',
                    'description' => 'POSTs a signed insightistic.automation event to your URL (HTTPS-guarded against internal targets; no redirects). Optional webhook_secret is stored encrypted on the rule and sent as an HMAC-SHA256 X-Insightistic-Signature header, never in the body.',
                    'status' => 'active',
                    'config_fields' => [
                        ['key' => 'url', 'label' => 'Webhook URL', 'type' => 'text'],
                        ['key' => 'payload', 'label' => 'Payload (JSON object)', 'type' => 'text'],
                        ['key' => 'webhook_secret', 'label' => 'Webhook signing secret', 'type' => 'text'],
                    ],
                ],
                [
                    'type' => ActionType::CREATE_SAVED_INSIGHT,
                    'label' => 'Create saved insight',
                    'description' => 'Creates a SavedInsight from a semantic query-engine plan (name + plan required; plan validated by PlanValidator, metric versions and chart spec attached automatically).',
                    'status' => 'active',
                    'config_fields' => [
                        ['key' => 'name', 'label' => 'Insight name', 'type' => 'text'],
                        ['key' => 'plan', 'label' => 'Query plan (semantic JSON)', 'type' => 'text'],
                        ['key' => 'description', 'label' => 'Description', 'type' => 'text'],
                    ],
                ],
                [
                    'type' => ActionType::RESOLVE_ALERT,
                    'label' => 'Resolve an alert',
                    'description' => 'Marks the alert with the given alert_id resolved on this site (mutates Insightistic\'s own database only). Use with an alert_created trigger pinned to a low-severity alert_type to auto-clear noise.',
                    'status' => 'active',
                    'config_fields' => [
                        ['key' => 'alert_id', 'label' => 'Alert ID', 'type' => 'number'],
                    ],
                ],
                [
                    'type' => ActionType::DISMISS_INSIGHT,
                    'label' => 'Dismiss an insight',
                    'description' => 'Dismisses the AI insight with the given insight_id on this site.',
                    'status' => 'active',
                    'config_fields' => [
                        ['key' => 'insight_id', 'label' => 'Insight ID', 'type' => 'number'],
                    ],
                ],
                [
                    'type' => 'in_app_notification',
                    'label' => 'In-app notification',
                    'description' => 'Doc 19 initial action. NOT implemented: no notification action exists in ActionExecutor::execute() yet.',
                    'status' => 'coming_soon',
                    'config_fields' => [],
                ],
                [
                    'type' => 'generate_report',
                    'label' => 'Generate report',
                    'description' => 'Doc 19 initial action. NOT implemented: report generation exists as a standalone surface but is not an AutomationExecutor action.',
                    'status' => 'coming_soon',
                    'config_fields' => [],
                ],
            ],
        ];
    }
}
