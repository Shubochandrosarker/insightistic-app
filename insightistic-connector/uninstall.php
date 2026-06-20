<?php
if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}
delete_option('insightistic_settings');
delete_option('insightistic_logs');
