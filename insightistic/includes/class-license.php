<?php

namespace Insightistic\Core;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Owns the license lifecycle against the SaaS's /api/plugin/* endpoints
 * (spec §4 activation flow): activate exchanges a license key for
 * entitlements + per-site HMAC connector credentials in one round trip;
 * validate/features/deactivate then use those credentials like any other
 * connector call.
 */
class License
{
    private const KEY_PATTERN = '/^insightistic_[A-Za-z0-9]{20,40}$/';

    /** @return array{ok:bool,error?:string,data?:array} */
    public static function activate(string $licenseKey, string $saasUrl): array
    {
        $licenseKey = trim($licenseKey);
        $saasUrl    = trim($saasUrl) ?: INSIGHTISTIC_CORE_DEFAULT_SAAS_URL;

        if (! preg_match(self::KEY_PATTERN, $licenseKey)) {
            return ['ok' => false, 'error' => "That doesn't look like a valid Insightistic license key."];
        }

        $payload = [
            'license_key'        => $licenseKey,
            'site_url'           => home_url(),
            'site_name'          => get_bloginfo('name'),
            'wp_version'         => get_bloginfo('version'),
            'php_version'        => PHP_VERSION,
            'plugin_version'     => INSIGHTISTIC_CORE_VERSION,
            'woocommerce_active' => class_exists('WooCommerce'),
        ];

        $res = Client::unsigned('POST', $saasUrl, '/api/plugin/activate', $payload);

        if (! $res['ok']) {
            $message = (is_array($res['data']) && isset($res['data']['message'])) ? $res['data']['message'] : $res['error'];
            Settings::log('Activation failed: ' . $message, 'error');
            return ['ok' => false, 'error' => $message ?: 'Activation failed.', 'data' => $res['data']];
        }

        $data      = $res['data'] ?? [];
        $connector = $data['connector'] ?? [];

        if (empty($connector['key_id']) || empty($connector['secret'])) {
            Settings::log('Activation succeeded but the server did not return connector credentials.', 'error');
            return ['ok' => false, 'error' => 'Unexpected response from server — no connector credentials returned.'];
        }

        Settings::update([
            'saas_url'          => $saasUrl,
            'api_base'          => $connector['api_base'] ?? $saasUrl,
            'key_id'            => $connector['key_id'],
            'secret'            => $connector['secret'],
            'connection_status' => 'connected',
            'license_key_last4' => substr($licenseKey, -4),
        ]);
        self::apply_entitlements($data['license'] ?? []);
        Settings::update(['last_validated' => current_time('mysql')]);

        Settings::log('License activated successfully.', 'info');

        return ['ok' => true, 'data' => $data];
    }

    /** Daily check-in (HMAC-signed). Refreshes entitlements so plan changes propagate. */
    public static function validate(): bool
    {
        if (! Settings::is_connected()) {
            return false;
        }

        $res = Client::request('POST', '/api/plugin/validate', [
            'wp_version'         => get_bloginfo('version'),
            'php_version'        => PHP_VERSION,
            'plugin_version'     => INSIGHTISTIC_CORE_VERSION,
            'woocommerce_active' => class_exists('WooCommerce'),
        ]);

        if (! $res['ok']) {
            Settings::log('License validation failed: ' . $res['error'], 'error');
            return false;
        }

        self::apply_entitlements($res['data']['license'] ?? []);
        Settings::update(['last_validated' => current_time('mysql')]);
        Settings::log('License validated.', 'info');

        return true;
    }

    /** Same payload as validate, zero side effects server-side ("Refresh License" button). */
    public static function refresh_features(): array
    {
        if (! Settings::is_connected()) {
            return ['ok' => false, 'error' => 'Not connected.'];
        }

        $res = Client::request('GET', '/api/plugin/features');
        if (! $res['ok']) {
            Settings::log('Refresh license failed: ' . $res['error'], 'error');
            return ['ok' => false, 'error' => $res['error']];
        }

        self::apply_entitlements($res['data']['license'] ?? []);
        Settings::log('License refreshed.', 'info');

        return ['ok' => true];
    }

    /** Releases this site's activation slot (best-effort) and clears local credentials. */
    public static function deactivate(): void
    {
        if (Settings::is_connected()) {
            $res = Client::request('POST', '/api/plugin/deactivate', []);
            if (! $res['ok']) {
                Settings::log('Server-side deactivation failed (clearing local credentials anyway): ' . $res['error'], 'warning');
            }
        }

        Settings::update([
            'api_base'          => '',
            'key_id'            => '',
            'secret'            => '',
            'connection_status' => 'disconnected',
            'license_status'    => '',
            'plan_slug'         => '',
            'plan_name'         => '',
            'features'          => [],
            'activation_limit'  => 0,
            'activations_used'  => 0,
        ]);
        Settings::log('License deactivated.', 'info');
    }

    private static function apply_entitlements(array $lic): void
    {
        if (empty($lic)) {
            return;
        }

        Settings::update([
            'license_status'    => $lic['status'] ?? '',
            'plan_slug'         => $lic['plan'] ?? '',
            'plan_name'         => $lic['plan_name'] ?? '',
            'features'          => $lic['features'] ?? [],
            'activation_limit'  => (int) ($lic['activation_limit'] ?? 0),
            'activations_used'  => (int) ($lic['activations_used'] ?? 0),
            'trial_ends_at'     => $lic['trial_ends_at'] ?? '',
            'expires_at'        => $lic['expires_at'] ?? '',
            'validate_interval' => (int) ($lic['validate_interval'] ?? 86400),
            'grace_hours'       => (int) ($lic['grace_hours'] ?? 72),
        ]);
    }
}
