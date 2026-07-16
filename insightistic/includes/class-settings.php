<?php

namespace Insightistic\Core;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Thin wrapper around the plugin's single options row + a local log buffer.
 *
 * Distinct option/constant names from the separate "Insightistic Connector"
 * plugin (insightistic_settings / INSIGHTISTIC_*) so the two can coexist on a
 * site without colliding — this plugin owns the license + connector
 * relationship; Connector is a lighter, token-only alternative for sites that
 * don't run this plugin.
 */
class Settings
{
    public static function defaults(): array
    {
        return [
            // License (spec §4 activation flow).
            'saas_url'          => INSIGHTISTIC_CORE_DEFAULT_SAAS_URL,
            'license_key_last4' => '',
            'license_status'    => '',
            'plan_slug'         => '',
            'plan_name'         => '',
            'features'          => [],
            'activation_limit'  => 0,
            'activations_used'  => 0,
            'trial_ends_at'     => '',
            'expires_at'        => '',
            'validate_interval' => 86400,
            'grace_hours'       => 72,
            'last_validated'    => '',

            // Per-site HMAC connector credentials, issued by /api/plugin/activate.
            'api_base'          => '',
            'key_id'            => '',
            'secret'            => '',
            'connection_status' => 'disconnected',
            'last_sync'         => '',

            // Sync toggles (WooCommerce data sync, same shape as Connector).
            'sync_orders'       => 1,
            'sync_products'     => 1,
            'sync_customers'    => 1,
            'sync_site_health'  => 1,
        ];
    }

    public static function ensure_defaults(): void
    {
        if (false === get_option(INSIGHTISTIC_CORE_OPTION, false)) {
            add_option(INSIGHTISTIC_CORE_OPTION, self::defaults());
        }
    }

    public static function all(): array
    {
        return wp_parse_args(get_option(INSIGHTISTIC_CORE_OPTION, []), self::defaults());
    }

    public static function get(string $key, $default = null)
    {
        $all = self::all();
        return $all[$key] ?? $default;
    }

    public static function update(array $values): void
    {
        update_option(INSIGHTISTIC_CORE_OPTION, array_merge(self::all(), $values));
    }

    /** True once the license activation exchanged connector credentials. */
    public static function is_connected(): bool
    {
        $s = self::all();
        return ! empty($s['key_id']) && ! empty($s['secret']) && $s['connection_status'] === 'connected';
    }

    public static function has_feature(string $feature): bool
    {
        return in_array($feature, self::get('features', []), true);
    }

    // --- local log buffer (last 50 events, for quick troubleshooting) -------

    public static function log(string $message, string $level = 'info'): void
    {
        $logs = get_option(INSIGHTISTIC_CORE_LOG_OPTION, []);
        if (! is_array($logs)) {
            $logs = [];
        }
        array_unshift($logs, [
            'time'    => current_time('mysql'),
            'level'   => $level,
            'message' => $message,
        ]);
        $logs = array_slice($logs, 0, 50);
        update_option(INSIGHTISTIC_CORE_LOG_OPTION, $logs);
    }

    public static function logs(): array
    {
        $logs = get_option(INSIGHTISTIC_CORE_LOG_OPTION, []);
        return is_array($logs) ? $logs : [];
    }

    public static function clear_logs(): void
    {
        update_option(INSIGHTISTIC_CORE_LOG_OPTION, []);
    }
}
