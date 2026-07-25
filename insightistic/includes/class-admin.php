<?php

namespace Insightistic\Core;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Admin menu, settings screens, and secure action handlers.
 * Every write action: manage_options capability + nonce verified.
 */
class Admin
{
    private static ?self $instance = null;

    private const ERROR_TRANSIENT = 'insightistic_core_last_error';

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function register_hooks(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_insightistic_core_activate', [$this, 'handle_activate']);
        add_action('admin_post_insightistic_core_deactivate', [$this, 'handle_deactivate']);
        add_action('admin_post_insightistic_core_refresh', [$this, 'handle_refresh']);
        add_action('admin_post_insightistic_core_save_sync', [$this, 'handle_save_sync']);
        add_action('admin_post_insightistic_core_sync_now', [$this, 'handle_sync_now']);
        add_action('admin_post_insightistic_core_clear_logs', [$this, 'handle_clear_logs']);
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
            wp_die(esc_html__('You do not have permission to do this.', 'insightistic'));
        }
        check_admin_referer($action);
    }

    private function redirect(string $tab = 'license', array $args = []): void
    {
        $url = add_query_arg(array_merge(['page' => 'insightistic', 'tab' => $tab], $args), admin_url('admin.php'));
        wp_safe_redirect($url);
        exit;
    }

    // --- handlers ----------------------------------------------------------

    public function handle_activate(): void
    {
        $this->guard('insightistic_core_activate');

        $licenseKey = sanitize_text_field(wp_unslash($_POST['license_key'] ?? ''));
        $saasUrl    = esc_url_raw(wp_unslash($_POST['saas_url'] ?? '')) ?: INSIGHTISTIC_CORE_DEFAULT_SAAS_URL;

        $result = License::activate($licenseKey, $saasUrl);

        if (! $result['ok']) {
            set_transient(self::ERROR_TRANSIENT, $result['error'], MINUTE_IN_SECONDS * 5);
            $this->redirect('license', ['msg' => 'activation_failed']);
        }

        // Weekly full reconciliation + daily incremental (delta) sync + daily
        // license check-in, paced by the server's validate_interval (defaults
        // to 24h). The full historical sync deliberately never runs on the
        // daily cadence — the daily job only sends records modified since the
        // per-entity cursors (see DeltaSync).
        $interval = max(HOUR_IN_SECONDS, (int) Settings::get('validate_interval', DAY_IN_SECONDS));

        if (function_exists('as_schedule_recurring_action')) {
            if (! as_next_scheduled_action('insightistic_core_run_sync')) {
                // First full pass is kicked immediately below, so the first
                // weekly reconciliation tick can wait a full week.
                as_schedule_recurring_action(time() + WEEK_IN_SECONDS, WEEK_IN_SECONDS, 'insightistic_core_run_sync', [], 'insightistic');
            }
            if (! as_next_scheduled_action('insightistic_core_run_delta_sync')) {
                as_schedule_recurring_action(time() + DAY_IN_SECONDS, DAY_IN_SECONDS, 'insightistic_core_run_delta_sync', [], 'insightistic');
            }
            if (! as_next_scheduled_action('insightistic_core_validate_license')) {
                as_schedule_recurring_action(time() + $interval, $interval, 'insightistic_core_validate_license', [], 'insightistic');
            }
        }

        // Kick off the first sync immediately so the dashboard populates without waiting for the cron tick.
        Sync::instance()->start_full_sync();

        $this->redirect('license', ['msg' => 'activated']);
    }

    public function handle_deactivate(): void
    {
        $this->guard('insightistic_core_deactivate');

        if (function_exists('as_unschedule_all_actions')) {
            foreach (Sync::ACTION_HOOKS as $hook) {
                as_unschedule_all_actions($hook);
            }
        }

        License::deactivate();

        $this->redirect('license', ['msg' => 'deactivated']);
    }

    public function handle_refresh(): void
    {
        $this->guard('insightistic_core_refresh');

        $result = License::refresh_features();
        $this->redirect('license', ['msg' => $result['ok'] ? 'refreshed' : 'refresh_failed']);
    }

    public function handle_save_sync(): void
    {
        $this->guard('insightistic_core_save_sync');
        Settings::update([
            'sync_orders'      => isset($_POST['sync_orders']) ? 1 : 0,
            'sync_products'    => isset($_POST['sync_products']) ? 1 : 0,
            'sync_customers'   => isset($_POST['sync_customers']) ? 1 : 0,
            'sync_site_health' => isset($_POST['sync_site_health']) ? 1 : 0,
            'sync_content'     => isset($_POST['sync_content']) ? 1 : 0,
        ]);
        $this->redirect('sync', ['msg' => 'saved']);
    }

    public function handle_sync_now(): void
    {
        $this->guard('insightistic_core_sync_now');
        if (! Settings::is_connected()) {
            $this->redirect('license', ['msg' => 'not_connected']);
        }

        // Optional date-range resync — narrows the ORDERS query to orders
        // created between the two dates; products/customers/content are
        // unaffected by the range. Anything not strictly Y-m-d is dropped.
        $from = $this->sanitize_date($_POST['date_from'] ?? '');
        $to   = $this->sanitize_date($_POST['date_to'] ?? '');
        if ($from !== '' && $to !== '' && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        Sync::instance()->start_full_sync($from, $to);
        $this->redirect('license', ['msg' => 'sync_started']);
    }

    /** Strict Y-m-d or empty string. */
    private function sanitize_date($raw): string
    {
        $value = sanitize_text_field(wp_unslash((string) $raw));
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
    }

    public function handle_clear_logs(): void
    {
        $this->guard('insightistic_core_clear_logs');
        Settings::clear_logs();
        $this->redirect('logs');
    }

    // --- render ------------------------------------------------------------

    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        $tab = sanitize_key($_GET['tab'] ?? 'license');
        $s   = Settings::all();
        $post = admin_url('admin-post.php');

        echo '<div class="wrap"><h1>Insightistic</h1>';
        $this->notice();

        // Tab nav
        $tabs = ['license' => 'License', 'sync' => 'Sync Settings', 'logs' => 'Logs', 'status' => 'System Status'];
        echo '<h2 class="nav-tab-wrapper">';
        foreach ($tabs as $key => $label) {
            $active = $tab === $key ? ' nav-tab-active' : '';
            $url = esc_url(add_query_arg(['page' => 'insightistic', 'tab' => $key], admin_url('admin.php')));
            echo '<a href="' . $url . '" class="nav-tab' . $active . '">' . esc_html($label) . '</a>';
        }
        echo '</h2>';

        if ($tab === 'sync') {
            $this->tab_sync($s, $post);
        } elseif ($tab === 'logs') {
            $this->tab_logs($post);
        } elseif ($tab === 'status') {
            $this->tab_status($s);
        } else {
            $this->tab_license($s, $post);
        }

        echo '</div>';
    }

    private function notice(): void
    {
        $map = [
            'activated'         => ['success', 'License activated — this site is now connected to Insightistic.'],
            'activation_failed' => ['error', get_transient(self::ERROR_TRANSIENT) ?: 'Activation failed. Check your license key and try again.'],
            'deactivated'       => ['warning', 'License deactivated. This site\'s slot has been freed.'],
            'refreshed'         => ['success', 'License entitlements refreshed.'],
            'refresh_failed'    => ['error', 'Could not refresh license. See Logs.'],
            'saved'             => ['success', 'Sync settings saved.'],
            'sync_started'      => ['success', 'Sync started in the background.'],
            'not_connected'     => ['error', 'Activate your license first.'],
        ];
        $msg = sanitize_key($_GET['msg'] ?? '');
        if (isset($map[$msg])) {
            [$type, $text] = $map[$msg];
            printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($type), esc_html($text));
            if ($msg === 'activation_failed') {
                delete_transient(self::ERROR_TRANSIENT);
            }
        }
    }

    private function tab_license(array $s, string $post): void
    {
        $connected = Settings::is_connected();
        ?>
        <?php if (! $connected) : ?>
        <form method="post" action="<?php echo esc_url($post); ?>">
            <input type="hidden" name="action" value="insightistic_core_activate">
            <?php wp_nonce_field('insightistic_core_activate'); ?>
            <table class="form-table">
                <tr>
                    <th><label for="license_key">License key</label></th>
                    <td>
                        <input type="text" id="license_key" name="license_key" class="regular-text code"
                            placeholder="insightistic_xxxxxxxxxxxxxxxxxxxxxxxxxx" required>
                        <p class="description">Find this on your Insightistic dashboard under Licenses.</p>
                    </td>
                </tr>
            </table>
            <details style="margin: 8px 0 16px;">
                <summary style="cursor:pointer;color:#646970;">Advanced: custom SaaS URL</summary>
                <table class="form-table">
                    <tr>
                        <th><label for="saas_url">SaaS URL</label></th>
                        <td><input type="url" id="saas_url" name="saas_url" class="regular-text"
                            value="<?php echo esc_attr($s['saas_url']); ?>" placeholder="<?php echo esc_attr(INSIGHTISTIC_CORE_DEFAULT_SAAS_URL); ?>"></td>
                    </tr>
                </table>
            </details>
            <?php submit_button('Activate'); ?>
        </form>
        <?php else : ?>
            <table class="form-table">
                <tr><th>Status</th><td><strong><?php echo esc_html($this->status_label($s)); ?></strong></td></tr>
                <tr><th>Plan</th><td><?php echo esc_html($s['plan_name'] ?: '—'); ?></td></tr>
                <?php if ($s['license_status'] === 'trialing' && $s['trial_ends_at']) : ?>
                <tr><th>Trial ends</th><td><?php echo esc_html($this->format_date($s['trial_ends_at'])); ?></td></tr>
                <?php endif; ?>
                <?php if ($s['expires_at']) : ?>
                <tr><th>Expires</th><td><?php echo esc_html($this->format_date($s['expires_at'])); ?></td></tr>
                <?php endif; ?>
                <tr><th>License key</th><td><code>insightistic_&hellip;<?php echo esc_html($s['license_key_last4']); ?></code></td></tr>
                <tr><th>Last sync</th><td><?php echo esc_html($s['last_sync'] ?: '—'); ?></td></tr>
                <tr><th>Last license check-in</th><td><?php echo esc_html($s['last_validated'] ?: '—'); ?></td></tr>
                <?php if (! empty($s['features'])) : ?>
                <tr>
                    <th>Features unlocked</th>
                    <td>
                        <?php foreach ($s['features'] as $feature) : ?>
                            <span class="button button-small" style="margin: 0 4px 4px 0; cursor: default;">
                                <?php echo esc_html(str_replace('_', ' ', ucfirst((string) $feature))); ?>
                            </span>
                        <?php endforeach; ?>
                    </td>
                </tr>
                <?php endif; ?>
            </table>

            <form method="post" action="<?php echo esc_url($post); ?>" style="margin:0 0 8px;">
                <input type="hidden" name="action" value="insightistic_core_sync_now">
                <?php wp_nonce_field('insightistic_core_sync_now'); ?>
                <label for="insightistic_date_from">Orders from</label>
                <input type="date" id="insightistic_date_from" name="date_from">
                <label for="insightistic_date_to">to</label>
                <input type="date" id="insightistic_date_to" name="date_to">
                <button type="submit" class="button button-primary">Run Manual Sync</button>
                <p class="description">Optional date range: resyncs only orders <em>created</em> between the two
                    dates (products, customers and content sync in full either way). Leave both empty for a
                    complete sync.</p>
            </form>
            <p style="display:flex;gap:8px;flex-wrap:wrap;">
                <?php
                $this->button_form($post, 'insightistic_core_refresh', 'Refresh License', 'secondary');
                $this->button_form($post, 'insightistic_core_deactivate', 'Deactivate License', 'delete');
                ?>
            </p>
        <?php endif;
    }

    private function status_label(array $s): string
    {
        $labels = [
            'active'         => '✅ Active',
            'trialing'       => '🟣 Trial',
            'payment_failed' => '⚠️ Payment issue',
            'expired'        => '⚠️ Trial expired',
            'cancelled'      => '❌ Cancelled',
            'suspended'      => '❌ Suspended',
            'revoked'        => '❌ Revoked',
        ];
        return $labels[$s['license_status']] ?? '⚪ Connected';
    }

    private function format_date(string $iso): string
    {
        $ts = strtotime($iso);
        return $ts ? date_i18n(get_option('date_format'), $ts) : $iso;
    }

    private function tab_sync(array $s, string $post): void
    {
        ?>
        <form method="post" action="<?php echo esc_url($post); ?>">
            <input type="hidden" name="action" value="insightistic_core_save_sync">
            <?php wp_nonce_field('insightistic_core_save_sync'); ?>
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
                <tr>
                    <th>AI Knowledge Base</th>
                    <td>
                        <label><input type="checkbox" name="sync_content" value="1"
                            <?php checked(! empty($s['sync_content'])); ?>>
                            Share published pages, posts &amp; product descriptions with the Insightistic
                            AI Knowledge Base</label>
                        <p class="description">Privacy note: off by default. Only publicly visible,
                            published content is shared — drafts, private and password-protected posts are
                            never sent, and no comments, user data or metadata are included. Add the post
                            meta <code>_insightistic_exclude</code> to any post to keep it out.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button('Save Sync Settings'); ?>
        </form>
        <?php
    }

    private function tab_logs(string $post): void
    {
        $logs = Settings::logs();
        $this->button_form($post, 'insightistic_core_clear_logs', 'Clear Logs', 'secondary');
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
            'Plugin version'   => INSIGHTISTIC_CORE_VERSION,
            'WordPress'        => $wp_version,
            'WooCommerce'      => defined('WC_VERSION') ? WC_VERSION : 'Not active',
            'Action Scheduler' => function_exists('as_enqueue_async_action') ? 'Available' : 'Missing (sync runs inline)',
            'SaaS URL'         => $s['api_base'] ?: $s['saas_url'],
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
