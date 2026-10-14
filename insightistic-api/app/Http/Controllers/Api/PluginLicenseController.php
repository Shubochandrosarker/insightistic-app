<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\License;
use App\Models\LicenseActivation;
use App\Models\Notification;
use App\Models\Organization;
use App\Models\PluginEvent;
use App\Models\Site;
use App\Services\LicenseService;
use App\Services\LifecycleMailer;
use App\Support\DomainNormalizer;
use Illuminate\Http\Request;

/**
 * WordPress-plugin-facing license endpoints (spec §4 activation flow).
 *
 * activate   — public + throttle:license; authenticated BY the license key.
 *              Exchanges the key for entitlements + per-site HMAC connector
 *              credentials in one round trip (one key for the user, two
 *              credentials under the hood).
 * validate / deactivate / features — HMAC-signed (connector.auth) so the raw
 *              key never needs to be stored plugin-side after activation.
 */
class PluginLicenseController extends Controller
{
    public function __construct(
        private LicenseService $licenses,
        private LifecycleMailer $mailer,
    ) {}

    public function activate(Request $request)
    {
        $data = $request->validate([
            'license_key' => ['required', 'string', 'max:64'],
            'site_url' => ['required', 'string', 'max:190'],
            'site_name' => ['nullable', 'string', 'max:120'],
            'wp_version' => ['nullable', 'string', 'max:20'],
            'php_version' => ['nullable', 'string', 'max:20'],
            'plugin_version' => ['nullable', 'string', 'max:20'],
            'woocommerce_active' => ['nullable', 'boolean'],
        ]);

        $license = $this->licenses->resolve($data['license_key']);
        if (! $license) {
            return $this->error('invalid_key', 'This license key is not valid.', 403);
        }

        $org = Organization::find($license->organization_id);

        // Re-project status before deciding (trial may have lapsed since last sync).
        $license->status = $this->licenses->computeStatus($org, $license);
        $license->save();

        if ($license->status === 'revoked') {
            return $this->error('key_revoked', 'This license key has been revoked.', 403);
        }
        if (! $license->isEntitled()) {
            $code = $license->status === 'expired' ? 'license_expired' : 'payment_required';

            return $this->error($code, 'This license is not active. Sign in to reactivate.', 402, [
                'status' => $license->status,
                'upgrade_url' => rtrim(config('insightistic.app_url'), '/').'/dashboard/billing',
            ]);
        }

        $normalized = $this->licenses->normalizeSiteUrl($data['site_url']);
        $siteHash = $this->licenses->siteHash($normalized);
        $environment = $this->licenses->classifyEnvironment($normalized);

        // Idempotent: re-activating the same site returns fresh creds + state.
        $activation = LicenseActivation::where('license_id', $license->id)
            ->where('site_hash', $siteHash)
            ->first();

        $isNew = ! $activation || $activation->status !== 'active';

        $resolved = app(\App\Domain\Billing\EntitlementResolver::class)->resolve($org);
        $effectiveActivationLimit = (int) ($resolved['limits']['connected_websites'] ?? $resolved['limits']['sites'] ?? $license->activation_limit);

        if ($isNew && $environment === 'production') {
            $used = $license->countedActivations();
            if ($used >= $effectiveActivationLimit) {
                PluginEvent::record($license, $activation, 'limit_reached', ['site' => $normalized]);
                $this->mailer->send($org, 'license_limit_reached', [], now()->format('Y-m'));

                return $this->error(
                    'activation_limit_reached',
                    "Your plan allows {$effectiveActivationLimit} connected website(s) and all slots are in use.",
                    402,
                    ['upgrade_url' => rtrim(config('insightistic.app_url'), '/').'/dashboard/billing'],
                );
            }
        }

        // Find-or-create the Site row and issue fresh HMAC connector creds.
        // Re-issuing on every activation is deliberate: a reinstall replaces
        // the only plugin instance that held the old secret.
        $site = $activation?->site_id ? Site::withoutGlobalScope('organization')->find($activation->site_id) : null;
        $site ??= Site::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)
            ->where('normalized_domain', DomainNormalizer::hostKey($normalized))
            ->whereNull('archived_at')
            ->first();
        $site ??= Site::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)
            ->where('domain', $normalized)
            ->first();

        if (! $site) {
            $site = new Site([
                'name' => $data['site_name'] ?: $normalized,
                'domain' => $normalized,
                'normalized_domain' => DomainNormalizer::hostKey($normalized),
                'platform' => ($data['woocommerce_active'] ?? false) ? 'woocommerce' : 'wordpress',
                'detected_platform' => ($data['woocommerce_active'] ?? false) ? 'woocommerce' : 'wordpress',
                'status' => 'active',
            ]);
            $site->organization_id = $org->id;
        }

        $siteAttributes = [
            'connection_status' => 'connected',
            'wp_version' => $data['wp_version'] ?? $site->wp_version,
            'plugin_version' => $data['plugin_version'] ?? $site->plugin_version,
        ];
        // An activating plugin is definitive platform proof — record it as
        // detection (never override an explicit user choice).
        if (! $site->platform_override) {
            $siteAttributes['detected_platform'] = ($data['woocommerce_active'] ?? false) ? 'woocommerce' : 'wordpress';
            $siteAttributes['platform'] = $siteAttributes['detected_platform'];
        }

        $connectorToken = $site->issueConnectorToken($siteAttributes); // "<key_id>.<secret>"
        [$connectorKeyId, $connectorSecret] = explode('.', $connectorToken, 2);

        $activation ??= new LicenseActivation(['license_id' => $license->id, 'site_hash' => $siteHash]);
        $activation->fill([
            'site_id' => $site->id,
            'site_url' => $data['site_url'],
            'normalized_site_url' => $normalized,
            'wp_version' => $data['wp_version'] ?? null,
            'php_version' => $data['php_version'] ?? null,
            'plugin_version' => $data['plugin_version'] ?? null,
            'woocommerce_active' => (bool) ($data['woocommerce_active'] ?? false),
            'environment' => $environment,
            'status' => 'active',
            'activated_at' => $activation->activated_at ?? now(),
            'last_seen_at' => now(),
            'deactivated_at' => null,
        ]);
        $activation->license_id = $license->id;
        $activation->site_hash = $siteHash;
        $activation->save();

        PluginEvent::record($license, $activation, $isNew ? 'activated' : 'reactivated', [
            'site' => $normalized,
            'environment' => $environment,
        ]);

        if ($isNew) {
            $this->mailer->send($org, 'site_connected', [
                'site_url' => $normalized,
                'site_name' => $site->name,
            ], 'site:'.substr($siteHash, 0, 16));

            Notification::withoutGlobalScope('organization')->create([
                'organization_id' => $org->id,
                'type' => 'site_connected',
                'title' => 'Website connected',
                'message' => $normalized.' activated your license and is syncing now.',
                'action_url' => '/dashboard/licenses',
                'created_at' => now(),
            ]);
        }

        return response()->json([
            'ok' => true,
            'license' => $this->licenses->entitlements($license->fresh()),
            'activation_id' => $activation->id,
            'site_id' => $site->id,
            'connector' => [
                'key_id' => $connectorKeyId,
                'secret' => $connectorSecret,
                'api_base' => rtrim(config('app.url') ?: 'https://api.insightistic.com', '/'),
            ],
        ]);
    }

    /** Daily check-in (HMAC-signed). Refreshes entitlements → plan changes propagate. */
    public function validate(Request $request)
    {
        [$license, $activation] = $this->resolveFromConnector($request);
        if (! $license) {
            return $this->error('no_activation', 'No active license for this site. Re-activate with your key.', 403);
        }

        $org = Organization::find($license->organization_id);
        $license->status = $this->licenses->computeStatus($org, $license);
        $license->last_validated_at = now();
        $license->save();

        $activation->last_seen_at = now();
        foreach (['wp_version', 'php_version', 'plugin_version'] as $field) {
            if ($request->filled($field)) {
                $activation->{$field} = substr((string) $request->input($field), 0, 20);
            }
        }
        if ($request->has('woocommerce_active')) {
            $activation->woocommerce_active = $request->boolean('woocommerce_active');
        }
        $activation->save();

        PluginEvent::record($license, $activation, $license->isEntitled() ? 'validation_ok' : 'validation_denied');

        return response()->json([
            'ok' => true,
            'license' => $this->licenses->entitlements($license),
        ]);
    }

    /** Same payload as validate, zero side effects (plugin "Refresh License" preview). */
    public function features(Request $request)
    {
        [$license] = $this->resolveFromConnector($request);
        if (! $license) {
            return $this->error('no_activation', 'No active license for this site.', 403);
        }

        return response()->json([
            'ok' => true,
            'license' => $this->licenses->entitlements($license),
        ]);
    }

    /** Release this site's activation slot (HMAC-signed; plugin disconnect/uninstall). */
    public function deactivate(Request $request)
    {
        [$license, $activation] = $this->resolveFromConnector($request);
        if (! $license) {
            return response()->json(['ok' => true]); // already gone — idempotent
        }

        $activation->update(['status' => 'deactivated', 'deactivated_at' => now()]);

        $site = $request->attributes->get('connector_site');
        $site?->update(['connection_status' => 'disconnected']);

        PluginEvent::record($license, $activation, 'deactivated');

        return response()->json(['ok' => true]);
    }

    // -------------------------------------------------------------------------

    /** @return array{0: ?License, 1: ?LicenseActivation} */
    private function resolveFromConnector(Request $request): array
    {
        return $this->licenses->resolveFromConnector($request);
    }

    private function error(string $code, string $message, int $status, array $extra = [])
    {
        return response()->json(['ok' => false, 'code' => $code, 'message' => $message] + $extra, $status);
    }
}
