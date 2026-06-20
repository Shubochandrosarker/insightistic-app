<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Thin wrapper around the plugin's single options row + a local log buffer.
 */
class Insightistic_Settings
{
    public static function defaults(): array
    {
        return [
            'saas_url'          => 'https://api.insightistic.com',
            'key_id'            => '',
            'secret'            => '',
            'connection_status' => 'disconnected',
            'last_sync'         => '',
            'sync_orders'       => 1,
            'sync_products'     => 1,
            'sync_customers'    => 1,
            'sync_site_health'  => 1,
        ];
    }

    public static function ensure_defaults(): void
    {
        if (false === get_option(INSIGHTISTIC_OPTION, false)) {
            add_option(INSIGHTISTIC_OPTION, self::defaults());
        }
    }

    public static function all(): array
    {
        return wp_parse_args(get_option(INSIGHTISTIC_OPTION, []), self::defaults());
    }

    public static function get(string $key, $default = null)
    {
        $all = self::all();
        return $all[$key] ?? $default;
    }

    public static function update(array $values): void
    {
        update_option(INSIGHTISTIC_OPTION, array_merge(self::all(), $values));
    }

    public static function is_connected(): bool
    {
        $s = self::all();
        return ! empty($s['key_id']) && ! empty($s['secret']) && $s['connection_status'] === 'connected';
    }

    public static function has_credentials(): bool
    {
        $s = self::all();
        return ! empty($s['key_id']) && ! empty($s['secret']);
    }

    /**
     * Parse a one-time connector token "<key_id>.<secret>" into its parts.
     * Returns [key_id, secret] or null if malformed.
     */
    public static function parse_token(string $token): ?array
    {
        $token = trim($token);
        if (substr_count($token, '.') !== 1) {
            return null;
        }
        [$keyId, $secret] = explode('.', $token, 2);
        if (! str_starts_with($keyId, 'ik_') || ! str_starts_with($secret, 'sk_')) {
            return null;
        }
        return [$keyId, $secret];
    }

    // --- local log buffer (last 50 events, for quick troubleshooting) -------

    public static function log(string $message, string $level = 'info'): void
    {
        $logs = get_option(INSIGHTISTIC_LOG_OPTION, []);
        if (! is_array($logs)) {
            $logs = [];
        }
        array_unshift($logs, [
            'time'    => current_time('mysql'),
            'level'   => $level,
            'message' => $message,
        ]);
        $logs = array_slice($logs, 0, 50);
        update_option(INSIGHTISTIC_LOG_OPTION, $logs);
    }

    public static function logs(): array
    {
        $logs = get_option(INSIGHTISTIC_LOG_OPTION, []);
        return is_array($logs) ? $logs : [];
    }

    public static function clear_logs(): void
    {
        update_option(INSIGHTISTIC_LOG_OPTION, []);
    }
}
