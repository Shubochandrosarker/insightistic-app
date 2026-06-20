<?php
/**
 * Plugin Name:       Insightistic Connector
 * Plugin URI:        https://insightistic.com
 * Description:       Securely syncs WooCommerce orders, products, customers and site health to the Insightistic SaaS for AI business analytics.
 * Version:           0.1.0
 * Author:            WordPressistic
 * Author URI:        https://wordpressistic.com
 * License:           GPL-2.0+
 * Text Domain:       insightistic-connector
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * WC requires at least: 7.0
 *
 * @package Insightistic\Connector
 */

if (! defined('ABSPATH')) {
    exit; // No direct access.
}

define('INSIGHTISTIC_VERSION', '0.1.0');
define('INSIGHTISTIC_FILE', __FILE__);
define('INSIGHTISTIC_PATH', plugin_dir_path(__FILE__));
define('INSIGHTISTIC_OPTION', 'insightistic_settings');
define('INSIGHTISTIC_LOG_OPTION', 'insightistic_logs');

require_once INSIGHTISTIC_PATH . 'includes/class-insightistic-settings.php';
require_once INSIGHTISTIC_PATH . 'includes/class-insightistic-client.php';
require_once INSIGHTISTIC_PATH . 'includes/class-insightistic-sync.php';
require_once INSIGHTISTIC_PATH . 'includes/class-insightistic-admin.php';

/**
 * Boot the plugin once all plugins are loaded (so WooCommerce/Action Scheduler exist).
 */
function insightistic_boot(): void
{
    Insightistic_Sync::instance()->register_hooks();

    if (is_admin()) {
        Insightistic_Admin::instance()->register_hooks();
    }
}
add_action('plugins_loaded', 'insightistic_boot');

/**
 * Activation: seed default settings. Do NOT schedule recurring sync until the
 * site is actually connected (handled after a successful handshake).
 */
register_activation_hook(__FILE__, function () {
    Insightistic_Settings::ensure_defaults();
});

/**
 * Deactivation: cancel any scheduled sync actions.
 */
register_deactivation_hook(__FILE__, function () {
    if (function_exists('as_unschedule_all_actions')) {
        as_unschedule_all_actions('insightistic_run_sync');
        as_unschedule_all_actions('insightistic_sync_orders');
        as_unschedule_all_actions('insightistic_sync_products');
        as_unschedule_all_actions('insightistic_sync_customers');
    }
});
