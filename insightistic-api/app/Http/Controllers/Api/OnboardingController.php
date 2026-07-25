<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DataSourceConnection;
use App\Models\KnowledgeSource;
use App\Models\License;
use App\Models\Site;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Guided setup progress (spec §9).
 *
 * Every step is **derived from real state** rather than stored as a cursor.
 * A stored cursor drifts the moment something changes outside the flow — a
 * site deleted, a license revoked, a plugin deactivated — and then tells the
 * user they finished a step that is no longer true. Deriving also makes the
 * flow resumable on any device for free, which is what the spec asks for.
 *
 * The only persisted field is the dismissal, because "stop showing me this"
 * is a preference and genuinely cannot be inferred.
 */
class OnboardingController extends Controller
{
    /** GET /onboarding */
    public function index(Request $request)
    {
        $org = app(Tenancy::class)->organization();

        $siteIds = Site::where('organization_id', $org->id)->pluck('id');
        $hasSite = $siteIds->isNotEmpty();

        $license = License::where('organization_id', $org->id)->first();

        $connectedSite = $hasSite && Site::whereIn('id', $siteIds)
            ->where('connection_status', 'connected')
            ->exists();

        $analyticsConnected = $hasSite && DataSourceConnection::whereIn('site_id', $siteIds)
            ->whereIn('source', ['ga4', 'gsc', 'cloudflare'])
            ->where('state', 'connected')
            ->exists();

        $hasSyncedData = $hasSite && DB::table('sync_logs')
            ->whereIn('site_id', $siteIds)
            ->where('status', 'success')
            ->exists();

        $hasKnowledge = KnowledgeSource::where('organization_id', $org->id)
            ->where('status', 'indexed')
            ->exists();

        $hasInsight = DB::table('ai_insights')->where('organization_id', $org->id)->exists();

        $steps = [
            $this->step('organization', 'Create your organization', true, null),
            $this->step('site', 'Add your website', $hasSite, '/dashboard/settings'),
            $this->step('license', 'Get your license key', $license !== null, '/dashboard/licenses'),
            $this->step('plugin', 'Install and activate the plugin', $connectedSite, '/dashboard/licenses'),
            $this->step('sync', 'Run your first sync', $hasSyncedData, '/dashboard/licenses'),
            $this->step('integrations', 'Connect GA4, Search Console or Cloudflare', $analyticsConnected, '/dashboard/integrations'),
            $this->step('knowledge', 'Upload knowledge for the AI', $hasKnowledge, '/dashboard/knowledge'),
            $this->step('insight', 'Generate your first insight', $hasInsight, '/dashboard/insights'),
        ];

        $done = count(array_filter($steps, fn ($s) => $s['complete']));

        return response()->json([
            'steps'     => $steps,
            'completed' => $done,
            'total'     => count($steps),
            'finished'  => $done === count($steps),
            'dismissed' => $org->onboarding_dismissed_at !== null,
            // The first thing still to do — what the UI should point at.
            'next'      => collect($steps)->firstWhere('complete', false)['key'] ?? null,
        ]);
    }

    /** POST /onboarding/dismiss */
    public function dismiss(Request $request)
    {
        $org = app(Tenancy::class)->organization();
        $org->forceFill(['onboarding_dismissed_at' => now()])->save();

        return response()->json(['dismissed' => true]);
    }

    /** DELETE /onboarding/dismiss — bring the checklist back. */
    public function restore(Request $request)
    {
        $org = app(Tenancy::class)->organization();
        $org->forceFill(['onboarding_dismissed_at' => null])->save();

        return response()->json(['dismissed' => false]);
    }

    private function step(string $key, string $label, bool $complete, ?string $href): array
    {
        return ['key' => $key, 'label' => $label, 'complete' => $complete, 'href' => $href];
    }
}
