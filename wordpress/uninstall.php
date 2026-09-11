<?php
/**
 * BareBits plugin — uninstall.
 *
 * Runs when the plugin is deleted via WordPress admin. Cleans up only the
 * plugin's own WordPress-side state. The BareBits server (whether remote or
 * installed alongside) and its data directory are deliberately NOT touched:
 * the data directory holds wallet keys and ecash tokens — real money. The
 * install/data directory locations are shown on the plugin's status page;
 * operators who truly want them gone must export their recovery phrases and
 * delete those directories by hand. License: GPLv2 or later.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Reset BTCPay gateway options only if they point at the server this plugin
// connected — a hand-configured BTCPay connection is not ours to remove.
$barebits_server = rtrim((string) get_option('barebits_server_url', ''), '/');
$barebits_btcpay_url = (string) get_option('btcpay_gf_url', '');
if ($barebits_server !== '' && $barebits_btcpay_url !== '' && strpos($barebits_btcpay_url, $barebits_server) === 0) {
    delete_option('btcpay_gf_url');
    delete_option('btcpay_gf_api_key');
    delete_option('btcpay_gf_store_id');
    delete_option('btcpay_gf_webhook');
}

// Stop the WP-cron pinger.
$barebits_timestamp = wp_next_scheduled('barebits_cron_tick');
if ($barebits_timestamp) {
    wp_unschedule_event($barebits_timestamp, 'barebits_cron_tick');
}

// The plugin's own state.
$barebits_options = [
    'barebits_mode',
    'barebits_server_url',
    'barebits_store_id',
    'barebits_api_key',
    'barebits_cron_key',
    'barebits_cron_backoff_until',
    'barebits_cron_last_ok',
    'barebits_wired_at',
    'barebits_discount_percent',
    'barebits_pairing_expected',
    'barebits_provision_token',
    'barebits_admin_password',
    'barebits_sso_key',
    'barebits_install_dir',
    'barebits_install_url',
    'barebits_install_data_dir',
    'barebits_install_dirname',
    'barebits_gateway_icon_attachment_id',
    // A future reinstall must re-warn about replacing a BTCPay connection,
    // not inherit a stale approval.
    'barebits_btcpay_override_consent',
    'barebits_review_banner',
];

// When this plugin installed a BareBits server alongside WordPress, that
// server keeps running after the uninstall — with real money behind a
// generated admin password the merchant never chose. These few rows are the
// ONLY copy of that password (and of where the install lives): deleting them
// would lock the merchant out of their own wallet UI. They survive the
// uninstall deliberately, and a reinstall of this plugin offers to reconnect
// from them. A site with no alongside install keeps nothing.
if ((string) get_option('barebits_install_dir', '') !== '') {
    $barebits_options = array_values(array_diff($barebits_options, [
        'barebits_server_url',
        'barebits_install_dir',
        'barebits_install_url',
        'barebits_install_data_dir',
        'barebits_install_dirname',
        'barebits_admin_password',
        'barebits_sso_key',
        // The heartbeat key too: the install has no crontab of its own and
        // the handshake that minted this key was one-time. The pinger stops
        // with the plugin (the event above is unscheduled), but a reinstall
        // resumes it from this key at activation.
        'barebits_cron_key',
    ]));
}

foreach ($barebits_options as $barebits_option) {
    delete_option($barebits_option);
}
