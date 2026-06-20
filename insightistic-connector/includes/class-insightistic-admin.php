<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Admin menu, settings screens, and secure action handlers.
 * Every write action: manage_options capability + nonce verified.
 */
class Insightistic_Admin
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function register_hooks(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_insightistic_connect', [$this, 'handle_connect']);
        add_action('admin_post_insightistic_disconnect', [$this, 'handle_disconnect']);
        add_action('admin_post_insightistic_save_sync', [$this, 'handle_save_sync']);
        add_action('admin_post_insightistic_sync_now', [$this, 'handle_sync_now']);
        add_action('admin_post_insightistic_test', [$this, 'handle_test']);
        add_action('admin_post_insightistic_clear_logs', [$this, 'handle_clear_logs']);
    }

    public function menu(): void
    {
        add_menu_page(
            'Insightistic',
            'Insightistic',
            'manage_options',
            'insightistic',
            [$this, 'render'],
            'dashicons-chart-area',
            58
        );
    }

    private function guard(string $action): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to do this.', 'insightistic-connector'));
        }
        check_admin_referer($action);
    }

    private function redirect(string $tab = 'connection', array $args = []): void
    {
        $url = add_query_arg(array_merge(['page' => 'insightistic', 'tab' => $tab], $args), admin_url('admin.php'));
        wp_safe_redirect($url);
        exit;
    }

    // --- handlers ----------------------------------------------------------

    public function handle_connect(): void
    {
        $this->guard('insightistic_connect');

        $saas_url = esc_url_raw(wp_unslash($_POST['saas_url'] ?? ''));
        $token    = sanitize_text_field(wp_unslash($_POST['connector_token'] ?? ''));

        $parsed = Insightistic_Settings::parse_token($token);
        if (! $saas_url || ! $parsed) {
            Insightistic_Settings::log('Connect failed: invalid SaaS URL or token format.', 'error');
            $this->redirect('connection', ['msg' => 'invalid']);
        }

        [$keyId, $secret] = $parsed;
        Insightistic_Settings::update([
            'saas_url' => $saas_url,
            'key_id'   => $keyId,
            'secret'   => $secret,
        ]);

        // Handshake confirms the credentials and registers environment info.
        $res = Insightistic_Client::post('/api/connector/v1/handshake', [
            'site_name'      => get_bloginfo('name'),
            'domain'         => wp_parse_url(home_url(), PHP_URL_HOST),
            'timezone'       => wp_timezone_string(),
            'currency'       => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : null,
            'wp_version'     => get_bloginfo('version'),
            'wc_version'     => defined('WC_VERSION') ? WC_VERSION : null,
            'plugin_version' => INSIGHTISTIC_VERSION,
        ]);

        if (! $res['ok']) {
            Insightistic_Settings::update(['connection_status' => 'error']);
            Insightistic_Settings::log('Handshake failed: ' . $res['error'], 'error');
            $this->redirect('connection', ['msg' => 'handshake_failed']);
        }

        Insightistic_Settings::update(['connection_status' => 'connected']);
        Insightistic_Settings::log('Connected successfully.', 'info');

        // Daily auto-sync.
        if (function_exists('as_schedule_recurring_action') && ! as_next_scheduled_action('insightistic_run_sync')) {
            as_schedule_recurring_action(time() + 60, DAY_IN_SECONDS, 'insightistic_run_sync', [], 'insightistic');
        }

        $this->redirect('connection', ['msg' => 'connected']);
    }

    public function handle_disconnect(): void
    {
        $this->guard('insightistic_disconnect');

        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions('insightistic_run_sync');
        }
        Insightistic_Settings::update([
            'key_id'            => '',
            'secret'            => '',
            'connection_status' => 'disconnected',
        ]);
        Insightistic_Settings::log('Disconnected.', 'info');
        $this->redirect('connection', ['msg' => 'disconnected']);
    }

    public function handle_save_sync(): void
    {
        $this->guard('insightistic_save_sync');
        Insightistic_Settings::update([
            'sync_orders'      => isset($_POST['sync_orders']) ? 1 : 0,
            'sync_products'    => isset($_POST['sync_products']) ? 1 : 0,
            'sync_customers'   => isset($_POST['sync_customers']) ? 1 : 0,
            'sync_site_health' => isset($_POST['sync_site_health']) ? 1 : 0,
        ]);
        $this->redirect('sync', ['msg' => 'saved']);
    }

    public function handle_sync_now(): void
    {
        $this->guard('insightistic_sync_now');
        if (! Insightistic_Settings::is_connected()) {
            $this->redirect('connection', ['msg' => 'not_connected']);
        }
        Insightistic_Sync::instance()->start_full_sync();
        $this->redirect('connection', ['msg' => 'sync_started']);
    }

    public function handle_test(): void
    {
        $this->guard('insightistic_test');

        // Read-only diagnostic — proves the HMAC chain without mutating site state.
        $res = Insightistic_Client::post('/api/connector/v1/ping');

        if (! $res['ok']) {
            Insightistic_Settings::log('Test connection failed: ' . $res['error'], 'error');
            $this->redirect('connection', ['msg' => 'test_failed']);
            return;
        }

        // Compare local clock to server clock. Drift beyond the window will make
        // real signed requests fail, so flag it now while it's easy to fix.
        $server = $res['data']['server'] ?? [];
        $window = (int) ($server['skew_window_seconds'] ?? 300);
        $drift  = isset($server['time_unix']) ? abs(time() - (int) $server['time_unix']) : 0;

        if ($drift > $window) {
            Insightistic_Settings::log(
                sprintf('Connection OK but clock drift is %ds (limit %ds). Fix server time (NTP) or syncs will fail.', $drift, $window),
                'error'
            );
            $this->redirect('connection', ['msg' => 'test_drift']);
            return;
        }

        Insightistic_Settings::log(sprintf('Test connection OK (clock drift %ds).', $drift), 'info');
        $this->redirect('connection', ['msg' => 'test_ok']);
    }

    public function handle_clear_logs(): void
    {
        $this->guard('insightistic_clear_logs');
        Insightistic_Settings::clear_logs();
        $this->redirect('logs');
    }

    // --- render ------------------------------------------------------------

    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        $tab = sanitize_key($_GET['tab'] ?? 'connection');
        $s   = Insightistic_Settings::all();
        $post = admin_url('admin-post.php');

        echo '<div class="wrap"><h1>Insightistic Connector</h1>';
        $this->notice();

        // Tab nav
        $tabs = ['connection' => 'Connection', 'sync' => 'Sync Settings', 'logs' => 'Logs', 'status' => 'System Status'];
        echo '<h2 class="nav-tab-wrapper">';
        foreach ($tabs as $key => $label) {
            $active = $tab === $key ? ' nav-tab-active' : '';
            $url = esc_url(add_query_arg(['page' => 'insightistic', 'tab' => $key], admin_url('admin.php')));
            echo '<a href="' . $url . '" class="nav-tab' . $active . '">' . esc_html($label) . '</a>';
        }
        echo '</h2>';

        if ($tab === 'connection') {
            $this->tab_connection($s, $post);
        } elseif ($tab === 'sync') {
            $this->tab_sync($s, $post);
        } elseif ($tab === 'logs') {
            $this->tab_logs($post);
        } else {
            $this->tab_status($s);
        }

        echo '</div>';
    }

    private function notice(): void
    {
        $map = [
            'connected'        => ['success', 'Site connected to Insightistic.'],
            'disconnected'     => ['warning', 'Site disconnected.'],
            'invalid'          => ['error', 'Invalid SaaS URL or connector token.'],
            'handshake_failed' => ['error', 'Could not verify connection. Check the token and SaaS URL.'],
            'saved'            => ['success', 'Sync settings saved.'],
            'sync_started'     => ['success', 'Sync started in the background.'],
            'not_connected'    => ['error', 'Connect the site first.'],
            'test_ok'          => ['success', 'Connection test passed.'],
            'test_failed'      => ['error', 'Connection test failed. See Logs.'],
            'test_drift'       => ['error', 'Connected, but this server\'s clock is too far off. Fix server time (NTP) — see Logs.'],
        ];
        $msg = sanitize_key($_GET['msg'] ?? '');
        if (isset($map[$msg])) {
            [$type, $text] = $map[$msg];
            printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($type), esc_html($text));
        }
    }

    private function tab_connection(array $s, string $post): void
    {
        $connected = Insightistic_Settings::is_connected();
        $status_label = $connected ? '✅ Connected' : ($s['connection_status'] === 'error' ? '⚠️ Error' : '⚪ Not connected');
        ?>
        <table class="form-table">
            <tr><th>Status</th><td><strong><?php echo esc_html($status_label); ?></strong></td></tr>
            <tr><th>Last Sync</th><td><?php echo esc_html($s['last_sync'] ?: '—'); ?></td></tr>
        </table>

        <?php if (! $connected) : ?>
        <form method="post" action="<?php echo esc_url($post); ?>">
            <input type="hidden" name="action" value="insightistic_connect">
            <?php wp_nonce_field('insightistic_connect'); ?>
            <table class="form-table">
                <tr>
                    <th><label for="saas_url">SaaS URL</label></th>
                    <td><input type="url" id="saas_url" name="saas_url" class="regular-text"
                        value="<?php echo esc_attr($s['saas_url']); ?>" placeholder="https://api.insightistic.com" required></td>
                </tr>
                <tr>
                    <th><label for="connector_token">Connector Token</label></th>
                    <td>
                        <input type="text" id="connector_token" name="connector_token" class="regular-text"
                            placeholder="ik_xxx.sk_xxx" required>
                        <p class="description">Paste the one-time token from your Insightistic dashboard (Sites → Add site).</p>
                    </td>
                </tr>
            </table>
            <?php submit_button('Connect Site'); ?>
        </form>
        <?php else : ?>
            <p style="display:flex;gap:8px;">
                <?php
                $this->button_form($post, 'insightistic_sync_now', 'Run Manual Sync', 'primary');
                $this->button_form($post, 'insightistic_test', 'Test Connection', 'secondary');
                $this->button_form($post, 'insightistic_disconnect', 'Disconnect', 'delete');
                ?>
            </p>
        <?php endif;
    }

    private function tab_sync(array $s, string $post): void
    {
        ?>
        <form method="post" action="<?php echo esc_url($post); ?>">
            <input type="hidden" name="action" value="insightistic_save_sync">
            <?php wp_nonce_field('insightistic_save_sync'); ?>
            <table class="form-table">
                <?php
                $fields = [
                    'sync_orders'      => 'Sync WooCommerce orders',
                    'sync_products'    => 'Sync products',
                    'sync_customers'   => 'Sync customers',
                    'sync_site_health' => 'Sync site health',
                ];
                foreach ($fields as $key => $label) :
                    ?>
                    <tr>
                        <th><?php echo esc_html($label); ?></th>
                        <td><label><input type="checkbox" name="<?php echo esc_attr($key); ?>"
                            value="1" <?php checked(! empty($s[$key])); ?>> Enabled</label></td>
                    </tr>
                <?php endforeach; ?>
            </table>
            <?php submit_button('Save Sync Settings'); ?>
        </form>
        <?php
    }

    private function tab_logs(string $post): void
    {
        $logs = Insightistic_Settings::logs();
        $this->button_form($post, 'insightistic_clear_logs', 'Clear Logs', 'secondary');
        echo '<table class="widefat striped" style="margin-top:12px;"><thead><tr><th>Time</th><th>Level</th><th>Message</th></tr></thead><tbody>';
        if (empty($logs)) {
            echo '<tr><td colspan="3">No logs yet.</td></tr>';
        } else {
            foreach ($logs as $log) {
                printf(
                    '<tr><td>%s</td><td>%s</td><td>%s</td></tr>',
                    esc_html($log['time']),
                    esc_html($log['level']),
                    esc_html($log['message'])
                );
            }
        }
        echo '</tbody></table>';
    }

    private function tab_status(array $s): void
    {
        global $wp_version;
        $rows = [
            'Plugin version'   => INSIGHTISTIC_VERSION,
            'WordPress'        => $wp_version,
            'WooCommerce'      => defined('WC_VERSION') ? WC_VERSION : 'Not active',
            'Action Scheduler' => function_exists('as_enqueue_async_action') ? 'Available' : 'Missing (sync runs inline)',
            'SaaS URL'         => $s['saas_url'],
            'Connection'       => $s['connection_status'],
        ];
        echo '<table class="form-table">';
        foreach ($rows as $k => $v) {
            printf('<tr><th>%s</th><td>%s</td></tr>', esc_html($k), esc_html((string) $v));
        }
        echo '</table>';
    }

    private function button_form(string $post, string $action, string $label, string $variant): void
    {
        $class = $variant === 'delete' ? 'button button-link-delete' : 'button button-' . $variant;
        echo '<form method="post" action="' . esc_url($post) . '" style="display:inline;">';
        echo '<input type="hidden" name="action" value="' . esc_attr($action) . '">';
        wp_nonce_field($action);
        echo '<button type="submit" class="' . esc_attr($class) . '">' . esc_html($label) . '</button>';
        echo '</form>';
    }
}
