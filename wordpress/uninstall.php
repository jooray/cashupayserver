<?php
/**
 * CashuPay WordPress Uninstall
 *
 * Runs when the plugin is deleted via WordPress admin.
 * Only cleans up non-critical data; ecash tokens are preserved
 * unless the user explicitly removes the data directory.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Reset BTCPay plugin options only if they point to CashuPay
$btcpay_url = get_option('btcpay_gf_url', '');
$cashupay_url = site_url('/cashupay');

if (!empty($btcpay_url) && strpos($btcpay_url, $cashupay_url) === 0) {
    delete_option('btcpay_gf_url');
    delete_option('btcpay_gf_api_key');
    delete_option('btcpay_gf_store_id');
}

// Unschedule cron
$timestamp = wp_next_scheduled('cashupay_poll_quotes');
if ($timestamp) {
    wp_unschedule_event($timestamp, 'cashupay_poll_quotes');
}

// Flush rewrite rules
flush_rewrite_rules();

// Note: Data directory is NOT removed on uninstall.
// It contains ecash tokens (real value). Users must manually
// export their seed phrases and delete the directory if desired.
// The directory location is shown in the plugin's admin interface.
