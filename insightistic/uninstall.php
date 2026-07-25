<?php
if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}
delete_option('insightistic_core_settings');
delete_option('insightistic_core_logs');
delete_option('insightistic_core_version');
delete_option('insightistic_core_pending_orders');
delete_option('insightistic_core_pending_products');
delete_option('insightistic_core_pending_content_deletes');
delete_transient('insightistic_core_last_error');
