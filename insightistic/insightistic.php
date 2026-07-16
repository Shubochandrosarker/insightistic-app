<?php
/**
 * Plugin Name:       Insightistic
 * Plugin URI:        https://insightistic.com
 * Description:       AI-powered WooCommerce analytics for Insightistic. Activate with your license key to sync orders, products, customers and site health, and unlock AI insights, reports and email automation.
 * Version:           1.0.0
 * Author:            WordPressistic
 * Author URI:        https://wordpressistic.com
 * License:           GPL-2.0+
 * Text Domain:       insightistic
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * WC requires at least: 7.0
 *
 * @package Insightistic\Core
 */

if (! defined('ABSPATH')) {
    exit; // No direct access.
}

define('INSIGHTISTIC_CORE_VERSION', '1.0.0');
define('INSIGHTISTIC_CORE_FILE', __FILE__);
define('INSIGHTISTIC_CORE_PATH', plugin_dir_path(__FILE__));
define('INSIGHTISTIC_CORE_OPTION', 'insightistic_core_settings');
define('INSIGHTISTIC_CORE_LOG_OPTION', 'insightistic_core_logs');
define('INSIGHTISTIC_CORE_DEFAULT_SAAS_URL', 'https://api.insightistic.com');

require_once INSIGHTISTIC_CORE_PATH . 'includes/class-settings.php';
require_once INSIGHTISTIC_CORE_PATH . 'includes/class-client.php';
require_once INSIGHTISTIC_CORE_PATH . 'includes/class-license.php';
require_once INSIGHTISTIC_CORE_PATH . 'includes/class-sync.php';
require_once INSIGHTISTIC_CORE_PATH . 'includes/class-admin.php';

use Insightistic\Core\Admin;
use Insightistic\Core\Settings;
use Insightistic\Core\Sync;

/**
 * Boot the plugin once all plugins are loaded (so WooCommerce/Action Scheduler exist).
 */
function insightistic_core_boot(): void
{
    Sync::instance()->register_hooks();

    if (is_admin()) {
        Admin::instance()->register_hooks();
    }
}
add_action('plugins_loaded', 'insightistic_core_boot');

/**
 * Activation: seed default settings. Do NOT schedule recurring sync/validation
 * until the site is actually licensed (handled after a successful activation).
 */
register_activation_hook(__FILE__, function () {
    Settings::ensure_defaults();
});

/**
 * Deactivation: cancel any scheduled actions. Deliberately does NOT release the
 * license activation slot — that only happens via the explicit "Deactivate
 * license" button (or uninstall), matching how licensing is meant to survive
 * a temporary plugin deactivation.
 */
register_deactivation_hook(__FILE__, function () {
    if (function_exists('as_unschedule_all_actions')) {
        as_unschedule_all_actions('insightistic_core_run_sync');
        as_unschedule_all_actions('insightistic_core_sync_orders');
        as_unschedule_all_actions('insightistic_core_sync_products');
        as_unschedule_all_actions('insightistic_core_sync_customers');
        as_unschedule_all_actions('insightistic_core_validate_license');
    }
});
