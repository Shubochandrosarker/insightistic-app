<?php
if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}
delete_option('insightistic_core_settings');
delete_option('insightistic_core_logs');
delete_transient('insightistic_core_last_error');
