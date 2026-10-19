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

// Reset BTCPay plugin options only if they point to CashuPay. Compared on host and
// path only, so a site that moved between http and https, or a URL saved with a
// trailing slash, still matches.
function cashupay_uninstall_normalize_url(string $url): string {
    $parts = wp_parse_url(trim($url));
    if (!is_array($parts) || empty($parts['host'])) {
        return '';
    }
    $host = strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
    return $host . rtrim($parts['path'] ?? '', '/');
}

$btcpay_url = cashupay_uninstall_normalize_url((string) get_option('btcpay_gf_url', ''));
$cashupay_url = cashupay_uninstall_normalize_url(site_url('/cashupay'));

if ($btcpay_url !== '' && $cashupay_url !== ''
    && ($btcpay_url === $cashupay_url || strpos($btcpay_url, $cashupay_url . '/') === 0)) {
    delete_option('btcpay_gf_url');
    delete_option('btcpay_gf_api_key');
    delete_option('btcpay_gf_store_id');
    // The webhook signing secret CashuPay registered; useless without the store above.
    delete_option('btcpay_gf_webhook');
}

delete_option('cashupay_activation_error');
delete_option('cashupay_data_protection_version');
delete_transient('cashupay_data_exposure');
delete_transient('cashupay_rewrite_flush_retry');

// Unschedule cron
$timestamp = wp_next_scheduled('cashupay_poll_quotes');
if ($timestamp) {
    wp_unschedule_event($timestamp, 'cashupay_poll_quotes');
}

// Flush rewrite rules
delete_option('cashupay_rewrite_rules_version');
flush_rewrite_rules();

// Note: Data directory is NOT removed on uninstall.
// It contains ecash tokens (real value). Users must manually
// export their seed phrases and delete the directory if desired.
// The directory location is shown in the plugin's admin interface.
