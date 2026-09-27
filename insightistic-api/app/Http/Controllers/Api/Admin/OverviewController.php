<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\AiInsight;
use App\Models\ClientReport;
use App\Models\Organization;
use App\Models\Site;
use App\Models\Subscription;
use App\Models\SyncLog;
use App\Models\User;
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
    public function systemHealth()
    {
        return response()->json($this->systemHealthPayload());
    }

    private function systemHealthPayload(): array
    {
        try {
            DB::connection()->getPdo();
            $db = 'ok';
        } catch (\Throwable $e) {
            $db = 'down';
        }

        $stripeSecret = (string) config('insightistic.stripe.secret');

        return response()->json([
            'app'      => ['status' => 'ok', 'url' => config('insightistic.app_url'), 'env' => app()->environment(), 'debug' => (bool) config('app.debug')],
            'api'      => ['status' => 'ok', 'url' => config('app.url')],
            'release'  => ['sha' => config('insightistic.release.sha', 'unknown'), 'built_at' => config('insightistic.release.built_at')],
            'database' => ['status' => $db, 'driver' => config('database.default')],
            'queue'    => array_merge($this->queueStatus(), ['connection' => config('queue.default')]),
            'scheduler'=> ['status' => 'configured', 'detail' => 'nightly snapshots 02:00'],
            'storage'  => ['writable' => is_writable(storage_path()), 'reports_disk' => config('insightistic.reports.disk', 'public')],
            'mail'     => ['mailer' => config('mail.default'), 'configured' => ! empty(config('mail.mailers.smtp.host')) || config('mail.default') !== 'smtp'],
            'stripe'   => ['configured' => $stripeSecret !== '', 'mode' => Str::startsWith($stripeSecret, 'sk_live') ? 'live' : 'test'],
            'paddle'   => ['configured' => ! empty(config('billing.paddle.api_key')) && ! empty(config('billing.paddle.webhook_secret')), 'environment' => config('billing.paddle.sandbox') ? 'sandbox' : 'production'],
            'ai'       => $this->aiHealth(),
            'recent_failed_jobs' => Schema::hasTable('failed_jobs')
                ? DB::table('failed_jobs')->latest('id')->limit(5)->get(['id', 'queue', 'exception', 'failed_at'])
                    ->map(fn ($r) => ['id' => $r->id, 'queue' => $r->queue, 'failed_at' => $r->failed_at, 'error' => Str::limit((string) $r->exception, 160)])
                : [],
        ]);
    }

    /**
     * AI health — configured AND reachable. A no-token-billed GET /models
     * ping checks API connectivity and credentials; completion quota/model
     * failures are surfaced by Copilot diagnostics. Null when rule mode
     * skips the provider ping.
     */
    private function aiHealth(): array
    {
        $provider = config('insightistic.ai.provider', 'openai');
        $configured = ! empty(config('insightistic.ai.api_key')) || $provider === 'rule';

        $reachable = null;
        if ($configured && $provider === 'openai') {
            try {
                $ping = \Illuminate\Support\Facades\Http::withToken((string) config('insightistic.ai.api_key'))
                    ->timeout(5)
                    ->get(rtrim((string) config('insightistic.ai.base_url', 'https://api.openai.com/v1'), '/') . '/models');
                $reachable = $ping->successful();
            } catch (\Throwable) {
                $reachable = false;
            }
        }

        return [
            'provider'   => $provider,
            'configured' => $configured,
            'reachable'  => $reachable,
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

    private function queueStatus(): array
    {
        return [
            'pending' => Schema::hasTable('jobs') ? DB::table('jobs')->count() : null,
            'failed'  => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : null,
        ];
    }
}
