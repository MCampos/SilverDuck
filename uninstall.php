<?php
// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;
$opt_key = 'silver_duck_options';
$table   = $wpdb->prefix . 'silver_duck_logs';

// Delete options
delete_option($opt_key);

// Drop logs table
$wpdb->query("DROP TABLE IF EXISTS `{$table}`");
