<?php

namespace App\Services;

use App\Models\License;
use App\Models\LicenseActivation;
use App\Models\Organization;
use App\Models\PluginEvent;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Single owner of all license logic (spec §4/§7).
 *
 * Raw keys are shown once and never stored — only HMAC-SHA256(app key, raw)
 * plus the last four characters for display. The license is an org-level
 * entitlement in FRONT of the per-site HMAC connector credentials: activation
 * exchanges the key for entitlements + connector creds in one round trip.
 */
class LicenseService
{
    /**
     * Canonical entitlement vocabulary (spec §6.3) — the single source of
     * truth Admin\PlanController's editor validates features_json against,
     * so a plan can never be saved with a feature slug nothing else in the
     * app recognizes. `seo_analysis` and `email_audit_automation` are kept
     * only because PlanSeeder's existing rows already use them; every other
     * plan should use their canonical spec §6.3 replacements
     * (`seo_analytics`, and email automation has no dedicated flag yet).
     */
    public const FEATURES = [
        'basic_analytics', 'traffic_analytics', 'seo_analytics', 'performance_tracking',
        'woocommerce_analytics', 'engagement_analytics', 'ai_insights', 'ai_copilot',
        'advanced_reports', 'scheduled_reports', 'alerts', 'data_export',
        'multi_site', 'team_access', 'agency_white_label', 'custom_domain', 'api_access',
        // legacy — see docblock above.
        'seo_analysis', 'email_audit_automation',
    ];

    // ---- key lifecycle ------------------------------------------------------

    /** Issue a new license for an org. Returns the RAW key — the only time it exists. */
    public function issue(Organization $org): string
    {
        $raw = $this->generateKey();

        License::withoutGlobalScope('organization')->create([
            'organization_id'   => $org->id,
            'plan_id'           => $org->plan_id,
            'subscription_id'   => $org->subscription()->first()?->id,
            'license_key_hash'  => $this->hash($raw),
            'license_key_last4' => substr($raw, -4),
            'status'            => $this->computeStatus($org),
            'activation_limit'  => (int) ($org->plan?->site_limit ?? 1),
        ]);

        return $raw;
    }

    /** Replace the key on an existing license. Old key stops working instantly. */
    public function regenerate(License $license): string
    {
        $raw = $this->generateKey();

        $license->forceFill([
            'license_key_hash'  => $this->hash($raw),
            'license_key_last4' => substr($raw, -4),
        ])->save();

        PluginEvent::record($license, null, 'key_regenerated');

        return $raw;
    }

    /** Constant-time raw-key → license lookup (hash is unique-indexed). */
    public function resolve(string $rawKey): ?License
    {
        if (! preg_match('/^insightistic_[A-Za-z0-9]{20,40}$/', $rawKey)) {
            return null;
        }

        return License::withoutGlobalScope('organization')
            ->where('license_key_hash', $this->hash($rawKey))
            ->first();
    }

    // ---- status projection ---------------------------------------------------

    /**
     * License status is a pure projection of org + subscription + trial state.
     * Precedence: revoked > suspended > billing trouble > cancelled > trial > active.
     */
    public function computeStatus(Organization $org, ?License $license = null): string
    {
        if ($license && $license->revoked_at) {
            return 'revoked';
        }
        if ($license && $license->status === 'suspended') {
            return 'suspended';
        }

        $sub = $org->subscription()->first();

        // Super Admin entitlement override (spec §13.8) — forces 'active' without
        // touching Stripe's own data. Checked after the sticky license-level
        // suspend/revoke branches above (those still win), but before every
        // billing/trial-derived branch below.
        if ($sub?->internal_override_active) {
            return 'active';
        }

        if ($org->status === 'past_due') {
            // Past the payment grace window → suspended; inside it → payment_failed.
            $graceDays = (int) config('insightistic.license.payment_grace_days', 7);
            $failedAt = $sub?->updated_at;
            if ($failedAt && $failedAt->addDays($graceDays)->isPast()) {
                return 'suspended';
            }
            return 'payment_failed';
        }

        if ($org->status === 'canceled') {
            // Access lasts until the paid period ends.
            if ($sub?->current_period_end && $sub->current_period_end->isFuture()) {
                return 'active';
            }
            return 'cancelled';
        }

        if ($org->status === 'trialing') {
            return ($org->trial_ends_at && $org->trial_ends_at->isFuture()) ? 'trialing' : 'expired';
        }

        return 'active';
    }

    /** Re-project and persist status + plan-derived fields for all of an org's licenses. */
    public function syncForOrganization(Organization $org): void
    {
        $licenses = License::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)
            ->get();

        foreach ($licenses as $license) {
            $license->status           = $this->computeStatus($org, $license);
            $license->plan_id          = $org->plan_id;
            $license->subscription_id  = $org->subscription()->first()?->id;
            $license->activation_limit = (int) ($org->plan?->site_limit ?? 1);
            $license->save();
        }
    }

    // ---- Super Admin lifecycle actions -----------------------------------------
    // computeStatus() treats a persisted 'suspended'/'revoked' status and a set
    // revoked_at as sticky (checked before any org/subscription/trial branch —
    // see the precedence comment above), so these three write status directly
    // rather than going through computeStatus(). suspend()/revoke() rely on that
    // stickiness to hold; reactivate() clears it first so computeStatus() falls
    // through to the org's actual current state instead of re-deriving 'suspended'.

    /** Suspend a license regardless of org/billing state. Immediately revokes entitlement. */
    public function suspend(License $license, ?string $reason = null): void
    {
        $license->status = 'suspended';
        $license->save();

        PluginEvent::record($license, null, 'license_suspended', array_filter(['reason' => $reason]));
    }

    /** Lift a Super Admin suspension; status re-derives from current org/billing/trial state. */
    public function reactivate(License $license): void
    {
        $org = Organization::find($license->organization_id);

        $license->status = 'active'; // clear the sticky 'suspended' flag before recomputing
        $license->status = $this->computeStatus($org, $license);
        $license->save();

        PluginEvent::record($license, null, 'license_reactivated');
    }

    /** Permanently revoke a license. Unlike suspend(), this cannot be undone by reactivate(). */
    public function revoke(License $license, ?string $reason = null): void
    {
        $license->revoked_at = now();
        $license->status = 'revoked';
        $license->save();

        PluginEvent::record($license, null, 'license_revoked', array_filter(['reason' => $reason]));
    }

    /**
     * Deactivate every active activation on a license and mark their sites
     * disconnected. Callers must gate this behind an explicit confirmation —
     * it's a hard reset for every connected site at once, not a per-site action.
     *
     * @return int Number of activations disconnected.
     */
    public function disconnectAll(License $license): int
    {
        $activations = $license->activations()->where('status', 'active')->get();
        $siteIds = $activations->pluck('site_id')->filter()->values()->all();

        $license->activations()->where('status', 'active')->update([
            'status'         => 'deactivated',
            'deactivated_at' => now(),
        ]);

        if ($siteIds) {
            Site::withoutGlobalScope('organization')
                ->whereIn('id', $siteIds)
                ->update(['connection_status' => 'disconnected']);
        }

        PluginEvent::record($license, null, 'license_disconnect_all', ['count' => count($siteIds)]);

        return count($siteIds);
    }

    /** Auditable override of a license's activation limit (independent of its plan's site_limit). */
    public function overrideActivationLimit(License $license, int $limit, ?string $reason = null): void
    {
        $previous = (int) $license->activation_limit;

        $license->activation_limit = max(0, $limit);
        $license->save();

        PluginEvent::record($license, null, 'activation_limit_overridden', array_filter([
            'previous' => $previous,
            'limit'    => $license->activation_limit,
            'reason'   => $reason,
        ]));
    }

    // ---- entitlements ---------------------------------------------------------

    /**
     * The payload the plugin caches: what may run, for how long, and how to
     * degrade when the API is unreachable.
     */
    public function entitlements(License $license): array
    {
        $org  = Organization::find($license->organization_id);
        $plan = $license->plan;

        $features = ['basic_analytics'];
        if ($license->isEntitled() && $plan) {
            $planFeatures = $plan->features_json ?? [];
            $features = array_values(array_unique(array_merge($features, $planFeatures)));
        }

        return [
            'status'            => $license->status,
            'plan'              => $plan?->slug,
            'plan_name'         => $plan?->name,
            'trial_ends_at'     => $org?->trial_ends_at?->toIso8601String(),
            'expires_at'        => $license->expires_at?->toIso8601String(),
            'features'          => $features,
            'activation_limit'  => (int) $license->activation_limit,
            'activations_used'  => $license->countedActivations(),
            'validate_interval' => (int) config('insightistic.license.validate_interval', 86400),
            'grace_hours'       => (int) config('insightistic.license.grace_hours', 72),
        ];
    }

    // ---- site URL handling -----------------------------------------------------

    /** Lowercase host+path, no scheme/www/default ports/trailing slash. */
    public function normalizeSiteUrl(string $url): string
    {
        $url = trim($url);
        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }

        $parts = parse_url($url);
        $host  = strtolower($parts['host'] ?? '');
        $host  = preg_replace('/^www\./', '', $host);
        $port  = isset($parts['port']) && ! in_array($parts['port'], [80, 443], true)
            ? ':' . $parts['port'] : '';
        $path  = rtrim($parts['path'] ?? '', '/');

        return $host . $port . $path;
    }

    /** Classify so staging/local sites never consume a production slot. */
    public function classifyEnvironment(string $normalizedUrl): string
    {
        $host = explode('/', explode(':', $normalizedUrl)[0])[0];

        if ($host === 'localhost'
            || filter_var($host, FILTER_VALIDATE_IP)
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.test')
            || str_ends_with($host, '.example')) {
            return 'local';
        }

        foreach (['staging.', 'stage.', 'dev.', 'test.'] as $prefix) {
            if (str_starts_with($host, $prefix)) {
                return 'staging';
            }
        }
        if (str_contains($host, '.staging.') || str_contains($host, 'wpengine.com')
            || str_contains($host, 'kinsta.cloud') || str_contains($host, 'pantheonsite.io')) {
            return 'staging';
        }

        return 'production';
    }

    public function siteHash(string $normalizedUrl): string
    {
        return hash('sha256', $normalizedUrl);
    }

    /** Find the activation bound to a given site id (used by HMAC-authed validate). */
    public function activationForSite(int $siteId): ?LicenseActivation
    {
        return LicenseActivation::where('site_id', $siteId)
            ->where('status', 'active')
            ->latest('id')
            ->first();
    }

    /**
     * Resolve the license + activation for an HMAC-authenticated plugin
     * request. Shared by every `plugin/*` controller behind the
     * `connector.auth` middleware (PluginLicenseController,
     * PluginAiController, PluginCloudflareController) — the middleware
     * already put the resolved Site on the request; this just walks
     * Site → LicenseActivation → License.
     *
     * @return array{0: ?License, 1: ?LicenseActivation}
     */
    public function resolveFromConnector(Request $request): array
    {
        $site = $request->attributes->get('connector_site');
        if (! $site) {
            return [null, null];
        }

        $activation = $this->activationForSite($site->id);
        if (! $activation) {
            return [null, null];
        }

        $license = License::withoutGlobalScope('organization')->find($activation->license_id);

        return [$license, $activation];
    }

    // ---- internals --------------------------------------------------------------

    private function generateKey(): string
    {
        return 'insightistic_' . Str::random(26);
    }

    private function hash(string $raw): string
    {
        $secret = (string) config('app.key');

        return hash_hmac('sha256', $raw, $secret);
    }
}
