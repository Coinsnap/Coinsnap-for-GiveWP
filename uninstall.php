<?php
// Removes only this plugin's own data.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

delete_option('coinsnap_givewp_webhook_secrets');
delete_option('give_settings_coinsnap_webhook');
delete_option('coinsnap_givewp_db_version');
delete_option('coinsnap_givewp_security_upgraded_at');
delete_transient('coinsnap_givewp_btcpay_state');

// The API keys live in GiveWP's settings; remove only this plugin's keys from them.
$coinsnap_givewp_settings = get_option('give_settings');
if (is_array($coinsnap_givewp_settings)) {
    foreach (['coinsnap_api_key', 'btcpay_api_key'] as $coinsnap_givewp_key) {
        unset($coinsnap_givewp_settings[$coinsnap_givewp_key]);
    }
    update_option('give_settings', $coinsnap_givewp_settings);
}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'coinsnap_givewp_invoices');
