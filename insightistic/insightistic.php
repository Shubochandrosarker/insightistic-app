<?php
/**
 * Plugin Name:       Insightistic
 * Plugin URI:        https://insightistic.com
 * Description:       AI-powered WooCommerce analytics for Insightistic. Activate with your license key to sync orders, products, customers and site health, and unlock AI insights, reports and email automation.
 * Version:           1.1.0
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

define('INSIGHTISTIC_CORE_VERSION', '1.1.0');
define('INSIGHTISTIC_CORE_FILE', __FILE__);
define('INSIGHTISTIC_CORE_PATH', plugin_dir_path(__FILE__));
define('INSIGHTISTIC_CORE_OPTION', 'insightistic_core_settings');
define('INSIGHTISTIC_CORE_LOG_OPTION', 'insightistic_core_logs');
define('INSIGHTISTIC_CORE_VERSION_OPTION', 'insightistic_core_version');
define('INSIGHTISTIC_CORE_DEFAULT_SAAS_URL', 'https://api.insightistic.com');

require_once INSIGHTISTIC_CORE_PATH . 'includes/class-settings.php';
require_once INSIGHTISTIC_CORE_PATH . 'includes/class-client.php';
require_once INSIGHTISTIC_CORE_PATH . 'includes/class-license.php';
require_once INSIGHTISTIC_CORE_PATH . 'includes/class-sync.php';
require_once INSIGHTISTIC_CORE_PATH . 'includes/class-delta-sync.php';
require_once INSIGHTISTIC_CORE_PATH . 'includes/class-events.php';
require_once INSIGHTISTIC_CORE_PATH . 'includes/class-content-sync.php';
require_once INSIGHTISTIC_CORE_PATH . 'includes/class-admin.php';

use Insightistic\Core\Admin;
use Insightistic\Core\ContentSync;
use Insightistic\Core\DeltaSync;
use Insightistic\Core\Events;
use Insightistic\Core\Settings;
use Insightistic\Core\Sync;

/**
 * HPOS (custom order tables) compatibility. All order access goes through
 * wc_get_order()/wc_get_orders(), so both storage backends work identically.
 */
add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

/**
 * Boot the plugin once all plugins are loaded (so WooCommerce/Action Scheduler exist).
 */
function insightistic_core_boot(): void
{
    Sync::instance()->register_hooks();
    DeltaSync::instance()->register_hooks();
    Events::instance()->register_hooks();
    ContentSync::instance()->register_hooks();

    if (is_admin()) {
        Admin::instance()->register_hooks();
    }

    // Action Scheduler is fully initialised on init — reshape schedules there.
    add_action('init', 'insightistic_core_maybe_upgrade', 20);
}
add_action('plugins_loaded', 'insightistic_core_boot');

/**
 * One-time upgrade routine (runs once per version change). 1.0.0 scheduled
 * the FULL sync daily; since 1.1.0 the full sync is a weekly reconciliation
 * and the lightweight delta sync runs daily instead — a full historical
 * crawl must never run on the daily cadence, so already-connected upgraded
 * sites get their recurring actions reshaped here.
 */
function insightistic_core_maybe_upgrade(): void
{
    if (get_option(INSIGHTISTIC_CORE_VERSION_OPTION) === INSIGHTISTIC_CORE_VERSION) {
        return;
    }

    // Without Action Scheduler nothing is scheduled and nothing can be
    // reshaped — try again on a later load instead of marking the upgrade done.
    if (! function_exists('as_unschedule_all_actions') || ! function_exists('as_schedule_recurring_action')) {
        return;
    }

    as_unschedule_all_actions('insightistic_core_run_sync');
    as_unschedule_all_actions('insightistic_core_run_delta_sync');

    if (Settings::is_connected()) {
        as_schedule_recurring_action(time() + HOUR_IN_SECONDS, WEEK_IN_SECONDS, 'insightistic_core_run_sync', [], 'insightistic');
        as_schedule_recurring_action(time() + DAY_IN_SECONDS, DAY_IN_SECONDS, 'insightistic_core_run_delta_sync', [], 'insightistic');
    }

    update_option(INSIGHTISTIC_CORE_VERSION_OPTION, INSIGHTISTIC_CORE_VERSION);
}

/**
 * Activation: seed default settings. Do NOT schedule recurring sync/validation
 * until the site is actually licensed (handled after a successful activation).
 */
register_activation_hook(__FILE__, function () {
    Settings::ensure_defaults();
});

/**
 * Deactivation: cancel any scheduled actions (Sync::ACTION_HOOKS is the
 * single source of truth for every hook the plugin owns — full sync chain,
 * delta chain, content chain, event flush, license validation). Deliberately
 * does NOT release the license activation slot — that only happens via the
 * explicit "Deactivate license" button (or uninstall), matching how licensing
 * is meant to survive a temporary plugin deactivation.
 */
register_deactivation_hook(__FILE__, function () {
    if (function_exists('as_unschedule_all_actions')) {
        foreach (Sync::ACTION_HOOKS as $hook) {
            as_unschedule_all_actions($hook);
        }
    }
});
