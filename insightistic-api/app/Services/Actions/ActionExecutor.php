<?php

namespace App\Services\Actions;

use App\Mail\AutomationNotification;
use App\Models\AiInsight;
use App\Models\Alert;
use App\Models\SavedInsight;
use App\Models\Site;
use App\Query\EvidenceLedger;
use App\Query\PlanValidator;
use App\Query\QueryEngineException;
use App\Services\Ask\ChartSpecBuilder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The single place an action ever actually runs (spec: P6 Automation
 * Studio / P9 Bridgistic Approved Actions). Both `AutomationRule` (P6,
 * fires automatically once its trigger matches — trusted because an org
 * admin configured it) and `ActionProposal` (P9, only ever runs after an
 * explicit human clicks "approve") call this same executor for the same
 * `App\Services\Actions\ActionType` allowlist — there is exactly one
 * implementation of what each action type does, never a duplicated one per
 * caller.
 *
 * Every method returns a JSON-serializable result array with a `success`
 * key rather than throwing for an expected failure (e.g. "alert not
 * found") — callers persist this verbatim onto `automation_runs.action_result_json`
 * / `action_proposals.result_json`, so a failed action is always visible
 * evidence, never a swallowed exception. `execute()` itself only throws for
 * a genuinely unknown action type, which should never happen in practice
 * since both callers validate against `ActionType::ALL` before this is
 * ever invoked.
 */
class ActionExecutor
{
    public function __construct(
        private readonly PlanValidator $validator,
        private readonly EvidenceLedger $ledger,
        private readonly ChartSpecBuilder $chartBuilder,
        private readonly WebhookUrlGuard $webhookGuard,
    ) {}

    /**
     * @param  array<string, mixed>  $params  Action-specific parameters — see each private method.
     * @param  string|null  $webhookSecret  Only used for ActionType::SEND_WEBHOOK; the caller (AutomationRule/ActionProposal) owns storing this encrypted, never this class.
     * @return array{success: bool, error?: string, ...}
     */
    public function execute(string $actionType, array $params, Site $site, ?int $actingUserId, ?string $webhookSecret = null): array
    {
        return match ($actionType) {
            ActionType::RESOLVE_ALERT => $this->resolveAlert($params, $site),
            ActionType::DISMISS_INSIGHT => $this->dismissInsight($params, $site),
            ActionType::CREATE_SAVED_INSIGHT => $this->createSavedInsight($params, $site, $actingUserId),
            ActionType::SEND_NOTIFICATION_EMAIL => $this->sendNotificationEmail($params, $site),
            ActionType::SEND_WEBHOOK => $this->sendWebhook($params, $site, $webhookSecret),
            default => throw new \InvalidArgumentException("Unknown action type \"{$actionType}\"."),
        };
    }

    /** @param array{alert_id: int} $params */
    private function resolveAlert(array $params, Site $site): array
    {
        $alertId = (int) ($params['alert_id'] ?? 0);
        $alert = Alert::where('site_id', $site->id)->find($alertId);

        if (! $alert) {
            return ['success' => false, 'error' => 'alert_not_found'];
        }

        $alert->update(['is_resolved' => true]);

        return ['success' => true, 'alert_id' => $alert->id];
    }

    /** @param array{insight_id: int} $params */
    private function dismissInsight(array $params, Site $site): array
    {
        $insightId = (int) ($params['insight_id'] ?? 0);
        $insight = AiInsight::where('site_id', $site->id)->find($insightId);

        if (! $insight) {
            return ['success' => false, 'error' => 'insight_not_found'];
        }

        $insight->update(['dismissed_at' => now()]);

        return ['success' => true, 'insight_id' => $insight->id];
    }

    /** @param array{name: string, plan: array, description?: string} $params */
    private function createSavedInsight(array $params, Site $site, ?int $actingUserId): array
    {
        $name = trim((string) ($params['name'] ?? ''));
        if ($name === '') {
            return ['success' => false, 'error' => 'name_required'];
        }

        $rawPlan = $params['plan'] ?? null;
        if (! is_array($rawPlan)) {
            return ['success' => false, 'error' => 'invalid_plan'];
        }

        try {
            $plan = $this->validator->validate($rawPlan);
        } catch (QueryEngineException $e) {
            return ['success' => false, 'error' => $e->errorCode];
        }

        $insight = SavedInsight::create([
            'organization_id' => $site->organization_id,
            'site_id' => $site->id,
            'owner_user_id' => $actingUserId,
            'name' => $name,
            'description' => isset($params['description']) ? (string) $params['description'] : null,
            'semantic_plan_json' => $plan->toArray(),
            'metric_versions_json' => $this->ledger->metricVersionsFor($plan->metricIds),
            'chart_spec_json' => $this->chartBuilder->typeFor($plan),
            'scope' => 'organization',
            'version' => 1,
        ]);

        return ['success' => true, 'saved_insight_id' => $insight->id];
    }

    /** @param array{subject: string, body: string} $params */
    private function sendNotificationEmail(array $params, Site $site): array
    {
        $subject = trim((string) ($params['subject'] ?? ''));
        $body = trim((string) ($params['body'] ?? ''));
        if ($subject === '' || $body === '') {
            return ['success' => false, 'error' => 'subject_and_body_required'];
        }

        $recipient = $site->organization?->owner?->email;
        if (! $recipient) {
            return ['success' => false, 'error' => 'no_recipient'];
        }

        try {
            Mail::to($recipient)->send(new AutomationNotification($subject, $body));
        } catch (\Throwable $e) {
            Log::warning('ActionExecutor: notification email failed to send: '.$e->getMessage());

            return ['success' => false, 'error' => 'send_failed'];
        }

        return ['success' => true, 'recipient' => $recipient];
    }

    /** @param array{url: string, payload?: array} $params */
    private function sendWebhook(array $params, Site $site, ?string $secret): array
    {
        $url = (string) ($params['url'] ?? '');

        try {
            $this->webhookGuard->assertSafe($url);
        } catch (WebhookUrlRejected $e) {
            return ['success' => false, 'error' => 'unsafe_url', 'message' => $e->getMessage()];
        }

        $payload = [
            'event' => 'insightistic.automation',
            'site_id' => $site->id,
            'timestamp' => now()->toIso8601String(),
            'data' => is_array($params['payload'] ?? null) ? $params['payload'] : [],
        ];

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $headers = ['Content-Type' => 'application/json'];

        if ($secret) {
            // Same signed-body convention as every other outbound/inbound
            // HMAC channel in this app (see ConnectorAuth, CopilotChat's
            // gateway signing) — a timestamp + hex HMAC-SHA256 of the
            // request body, never the secret itself sent over the wire.
            $timestamp = (string) time();
            $headers['X-Insightistic-Timestamp'] = $timestamp;
            $headers['X-Insightistic-Signature'] = hash_hmac('sha256', $timestamp.'.'.$body, $secret);
        }

        try {
            // send() with a raw 'body' option, not post($url, $payload):
            // the HMAC signature above was computed over the exact bytes
            // of $body, so the request must transmit those exact bytes —
            // re-encoding $payload through post()'s own JSON serialization
            // could theoretically diverge and invalidate the signature.
            $response = Http::withHeaders($headers)
                ->timeout(10)
                ->withOptions(['allow_redirects' => false])
                ->send('POST', $url, ['body' => $body]);
        } catch (\Throwable $e) {
            Log::warning('ActionExecutor: webhook delivery failed: '.$e->getMessage());

            return ['success' => false, 'error' => 'delivery_failed'];
        }

        if (! $response->successful()) {
            return ['success' => false, 'error' => 'non_2xx_response', 'status' => $response->status()];
        }

        return ['success' => true, 'status' => $response->status()];
    }
}
