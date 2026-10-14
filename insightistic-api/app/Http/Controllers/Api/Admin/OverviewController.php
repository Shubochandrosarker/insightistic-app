<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\AiInsight;
use App\Models\ClientReport;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Site;
use App\Models\Subscription;
use App\Models\SyncLog;
use App\Models\User;
use App\Services\Ai\AiRuntimeSettings;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class OverviewController extends AdminController
{
    /** Platform overview cards + recent activity. */
    public function overview()
    {
        return response()->json([
            'cards' => [
                'total_organizations'  => Organization::count(),
                'total_users'          => User::count(),
                'super_admins'         => User::where('is_super_admin', true)->count(),
                'active_subscriptions' => Subscription::withoutGlobalScope('organization')->where('status', 'active')->count(),
                'trial_accounts'       => Organization::where('status', 'trialing')->count(),
                'connected_sites'      => Site::withoutGlobalScope('organization')->where('connection_status', 'connected')->count(),
                'total_sites'          => Site::withoutGlobalScope('organization')->count(),
                'failed_syncs'         => SyncLog::where('status', 'failed')->count(),
                'reports_generated'    => ClientReport::withoutGlobalScope('organization')->count(),
                'ai_insights_used'     => AiInsight::withoutGlobalScope('organization')->count(),
                'mrr'                  => $this->mrr(),
            ],
            'api_health' => 'ok',
            'queue'      => $this->queueStatus(),

            'recent_organizations' => Organization::with(['plan:id,name,slug', 'owner:id,name,email'])
                ->latest()->limit(6)->get(['id', 'name', 'slug', 'status', 'plan_id', 'owner_user_id', 'created_at']),
            'recent_users' => User::latest()->limit(6)->get(['id', 'name', 'email', 'is_super_admin', 'created_at']),
            'recent_failed_syncs' => SyncLog::where('status', 'failed')->with('site:id,name,domain')
                ->latest('id')->limit(6)->get(['id', 'site_id', 'job', 'status', 'message', 'created_at']),
            'recent_reports' => ClientReport::withoutGlobalScope('organization')->with('site:id,name')
                ->latest('id')->limit(6)->get(['id', 'site_id', 'title', 'report_type', 'created_at']),
        ]);
    }

    /** Lightweight platform health probes (no secrets). */
    public function systemHealth(): JsonResponse
    {
        // Deliberately a JsonResponse (not the raw array): the frontend reads
        // this payload directly and a bare array would double-wrap under the
        // controller's implicit JSON handling (root cause of the stuck
        // "Loading" state on /admin/system-health, 2026-10-13).
        return response()->json($this->systemHealthArray());
    }

    /**
     * GET /admin/settings — read-mostly platform facts for the owner.
     * Deliberately exposes NO secret values (only booleans/env-modes) —
     * this endpoint sits behind super_admin but still follows the
     * "health payloads carry status, not credentials" rule.
     */
    public function settings(): JsonResponse
    {
        $health = $this->systemHealthArray();

        return response()->json([
            'release' => $health['release'],
            'app'     => [
                'env'   => $health['app']['env'],
                'url'   => $health['app']['url'],
                'debug' => $health['app']['debug'],
            ],
            'database'  => ['status' => $health['database']['status'], 'driver' => $health['database']['driver']],
            'queue'     => $health['queue'],
            'cache'     => $health['cache'],
            'storage'   => $health['storage'],
            'scheduler' => $health['scheduler'],
            'mail'      => $health['mail'],
            // Paddle billing: configured flag + sandbox/production ONLY.
            // Never key material, webhook secrets, or client-side tokens.
            'paddle'    => $health['paddle'],
            'stripe'    => $health['stripe'],
            'ai'        => app(AiRuntimeSettings::class)->public(),
            'counts'    => [
                'organizations' => Organization::count(),
                'users'         => User::count(),
                'sites'         => Site::withoutGlobalScope('organization')->count(),
                'plans'         => Plan::count(),
                'super_admins'  => User::where('is_super_admin', true)->count(),
            ],
        ]);
    }

    /** GET /admin/ai/configuration — routing metadata only, never secrets. */
    public function aiConfiguration(AiRuntimeSettings $runtime): JsonResponse
    {
        return response()->json(['ai' => $runtime->public()]);
    }

    /** PATCH /admin/ai/configuration — owner-editable non-secret routing. */
    public function updateAiConfiguration(Request $request, AiRuntimeSettings $runtime, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'wpistic_model' => ['sometimes', 'string', 'max:160', 'regex:/^[a-zA-Z0-9._:\/-]+$/'],
            'openrouter_paid_model' => ['sometimes', 'string', 'max:160', 'regex:/^[a-zA-Z0-9._:\/-]+$/'],
            'openrouter_free_model' => ['sometimes', 'string', 'max:160', 'regex:/^[a-zA-Z0-9._:\/-]+$/'],
            'openrouter_paid_fallback_models' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'openrouter_free_fallback_models' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'knowledge_agent_enabled' => ['sometimes', 'boolean'],
            'knowledge_agent_url' => ['sometimes', 'nullable', 'url', 'max:500'],
            'knowledge_agent_slug' => ['sometimes', 'string', 'max:120', 'regex:/^[a-zA-Z0-9._-]+$/'],
            'knowledge_agent_category' => ['sometimes', 'string', 'max:80', 'regex:/^[a-zA-Z0-9._ -]+$/'],
        ]);

        if (array_key_exists('knowledge_agent_url', $data) && $data['knowledge_agent_url']) {
            $scheme = parse_url($data['knowledge_agent_url'], PHP_URL_SCHEME);
            abort_unless($scheme === 'https', 422, 'The shared knowledge-brain URL must use HTTPS.');
        }

        $before = $runtime->public();
        $runtime->update($data, $request->user());
        $after = $runtime->public();
        $audit->log($request, 'ai.configuration_update', 'PlatformSetting', null, $before, $after);

        return response()->json(['ai' => $after]);
    }

    private function systemHealthArray(): array
    {
        try {
            DB::connection()->getPdo();
            $db = 'ok';
        } catch (\Throwable $e) {
            $db = 'down';
        }

        $stripeSecret = (string) config('insightistic.stripe.secret');

        return [
            'app'      => ['status' => 'ok', 'url' => config('insightistic.app_url'), 'env' => app()->environment(), 'debug' => (bool) config('app.debug')],
            'api'      => ['status' => 'ok', 'url' => config('app.url')],
            'release'  => ['sha' => config('insightistic.release.sha', 'unknown'), 'built_at' => config('insightistic.release.built_at')],
            'database' => ['status' => $db, 'driver' => config('database.default')],
            'queue'    => array_merge($this->queueStatus(), ['connection' => config('queue.default')]),
            'scheduler'=> ['status' => 'configured', 'detail' => 'nightly snapshots 02:00'],
            'storage'  => ['writable' => is_writable(storage_path()), 'reports_disk' => config('insightistic.reports.disk', 'public')],
            'mail'     => ['mailer' => config('mail.default'), 'configured' => ! empty(config('mail.mailers.smtp.host')) || config('mail.default') !== 'smtp'],
            'stripe'   => ['configured' => $stripeSecret !== '', 'mode' => Str::startsWith($stripeSecret, 'sk_live') ? 'live' : 'test'],
            'cache'    => ['driver' => config('cache.default'), 'ok' => $this->cacheOk()],
            'paddle'   => ['configured' => ! empty(config('billing.paddle.api_key')) && ! empty(config('billing.paddle.webhook_secret')), 'environment' => config('billing.paddle.sandbox') ? 'sandbox' : 'production'],
            'ai'       => $this->aiHealth(),
            'recent_failed_jobs' => Schema::hasTable('failed_jobs')
                ? DB::table('failed_jobs')->latest('id')->limit(5)->get(['id', 'queue', 'exception', 'failed_at'])
                    ->map(fn ($r) => ['id' => $r->id, 'queue' => $r->queue, 'failed_at' => $r->failed_at, 'error' => Str::limit((string) $r->exception, 160)])
                : [],
        ];
    }

    /** AI routing readiness and a no-generation OpenRouter credential probe. */
    private function aiHealth(): array
    {
        $runtime = app(AiRuntimeSettings::class)->resolved();
        $openrouter = $runtime['openrouter'];
        $gateway = $runtime['gateway'];
        $openrouterConfigured = ! empty($openrouter['api_key']);
        $gatewayConfigured = ! empty($gateway['product_key']);
        $openrouterReachable = null;

        if ($openrouterConfigured) {
            try {
                $ping = \Illuminate\Support\Facades\Http::withToken((string) $openrouter['api_key'])
                    ->timeout(5)
                    ->get(rtrim((string) $openrouter['base_url'], '/') . '/key');
                $openrouterReachable = $ping->successful();
            } catch (\Throwable) {
                $openrouterReachable = false;
            }
        }

        return [
            'provider'   => 'plan_aware',
            'configured' => $openrouterConfigured || $gatewayConfigured,
            'reachable'  => $openrouterReachable,
            'routing' => [
                'free' => array_values(array_filter([
                    $gatewayConfigured ? 'wpistic_gateway' : null,
                    $openrouterConfigured ? (string) $openrouter['free_model'] : null,
                    'rule',
                ])),
                'paid' => array_values(array_filter([
                    $gatewayConfigured ? 'wpistic_gateway' : null,
                    $openrouterConfigured ? (string) $openrouter['paid_model'] : null,
                    ...($openrouterConfigured ? (array) $openrouter['paid_fallback_models'] : []),
                    'rule',
                ])),
            ],
            'providers' => [
                'openrouter' => ['configured' => $openrouterConfigured, 'reachable' => $openrouterReachable],
                'wpistic_gateway' => ['configured' => $gatewayConfigured, 'reachable' => null],
                'rule' => ['configured' => true, 'reachable' => true],
            ],
        ];
    }

    private function mrr(): float
    {
        $cents = Subscription::withoutGlobalScope('organization')
            ->where('subscriptions.status', 'active')
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->sum('plans.price_monthly');

        return round(((int) $cents) / 100, 2);
    }

    private function cacheOk(): bool
    {
        try {
            $key = 'admin:health-probe';
            $token = Str::random(8);
            Cache::put($key, $token, 5);

            return Cache::pull($key) === $token;
        } catch (\Throwable) {
            return false;
        }
    }

    private function queueStatus(): array
    {
        return [
            'pending' => Schema::hasTable('jobs') ? DB::table('jobs')->count() : null,
            'failed'  => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : null,
        ];
    }
}
