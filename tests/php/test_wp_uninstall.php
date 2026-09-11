<?php
/**
 * Plugin uninstall (wordpress/uninstall.php).
 *
 * Deleting the plugin from wp-admin cleans up the plugin's WordPress-side
 * state — the barebits_* options and the WP-cron pinger — and resets the
 * BTCPay gateway options ONLY when they point at the server this plugin
 * connected; a hand-configured BTCPay connection is not ours to remove.
 *
 * When an alongside install exists, the install RECORD survives even the
 * uninstall: its location, address, admin password, SSO key, and cron key
 * (the one-time provisioning handshake can never mint another — a reinstall
 * of the plugin resumes the install's heartbeat from it). That server keeps
 * running with real money behind a generated password the merchant never
 * chose — these options are the only copy, and deleting them would lock the
 * merchant out of their own wallet UI. A site with no alongside install
 * keeps nothing. The script has no filesystem or HTTP
 * surface (the stub set below provides none), which is the "never touches
 * the server or its data" promise in executable form.
 *
 * uninstall.php is a plain top-level script (no exit, no functions), so each
 * scenario seeds the option store and requires it again.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';

// --- minimal WordPress stubs -------------------------------------------------
define('WP_UNINSTALL_PLUGIN', true);

$GLOBALS['wp_options'] = [];
$GLOBALS['unscheduled'] = [];
$GLOBALS['next_scheduled'] = false;

function get_option($name, $default = false) { return $GLOBALS['wp_options'][$name] ?? $default; }
function delete_option($name) { unset($GLOBALS['wp_options'][$name]); return true; }
function wp_next_scheduled($hook) { return $GLOBALS['next_scheduled']; }
function wp_unschedule_event($ts, $hook) { $GLOBALS['unscheduled'][] = $hook; $GLOBALS['next_scheduled'] = false; return true; }

const UNINSTALL = __DIR__ . '/../../wordpress/uninstall.php';

// The install record that must survive whenever an alongside install exists.
const INSTALL_RECORD = [
    'barebits_server_url', 'barebits_install_dir', 'barebits_install_url',
    'barebits_install_data_dir', 'barebits_install_dirname',
    'barebits_admin_password', 'barebits_sso_key', 'barebits_cron_key',
];

/** Every option the plugin owns, as a fully-populated install would hold them. */
function seed_plugin_options(string $serverUrl, bool $withInstall): void {
    $GLOBALS['wp_options'] = array_merge($GLOBALS['wp_options'], [
        'barebits_mode' => $withInstall ? 'install' : 'url',
        'barebits_server_url' => $serverUrl,
        'barebits_store_id' => 'store_x',
        'barebits_api_key' => str_repeat('a', 64),
        'barebits_cron_key' => str_repeat('b', 64),
        'barebits_cron_backoff_until' => 1700000000,
        'barebits_cron_last_ok' => 1700000000,
        'barebits_wired_at' => 1700000000,
        'barebits_discount_percent' => 3,
        'barebits_pairing_expected' => ['state' => 'x', 'at' => 1700000000],
        'barebits_provision_token' => str_repeat('c', 64),
        'barebits_admin_password' => 'super-secret',
        'barebits_sso_key' => str_repeat('d', 64),
        'barebits_gateway_icon_attachment_id' => 42,
        'barebits_btcpay_override_consent' => 'https://old.example',
        'barebits_review_banner' => ['count' => 1, 'dismissed_at' => 1700000000],
    ]);
    if ($withInstall) {
        $GLOBALS['wp_options'] += [
            'barebits_install_dir' => '/var/www/barebits',
            'barebits_install_url' => $serverUrl,
            'barebits_install_data_dir' => '/var/www/barebits-data-abc123def456',
            'barebits_install_dirname' => 'barebits',
        ];
    }
}

function remaining_barebits_options(): array {
    $keys = array_values(array_filter(
        array_keys($GLOBALS['wp_options']),
        fn($k) => str_starts_with($k, 'barebits_')
    ));
    sort($keys);
    return $keys;
}

// --- Alongside install, gateway pointed at OUR server ------------------------
//
// Gateway options are reset, the install record survives, everything else goes.

seed_plugin_options('http://wp.test/barebits', withInstall: true);
$GLOBALS['wp_options'] += [
    'btcpay_gf_url' => 'http://wp.test/barebits',
    'btcpay_gf_api_key' => str_repeat('a', 64),
    'btcpay_gf_store_id' => 'store_x',
    'btcpay_gf_webhook' => ['id' => 'wh_1', 'url' => 'http://wp.test/?wc-api=btcpaygf_default', 'secret' => 's'],
];
$GLOBALS['next_scheduled'] = 1700000000;

require UNINSTALL;

$expected = INSTALL_RECORD;
sort($expected);
assert_eq($expected, remaining_barebits_options(),
    'exactly the install record survives — every other barebits_* option is deleted');
assert_eq('super-secret', $GLOBALS['wp_options']['barebits_admin_password'],
    'the only copy of the server\'s admin password is preserved');
foreach (['btcpay_gf_url', 'btcpay_gf_api_key', 'btcpay_gf_store_id', 'btcpay_gf_webhook'] as $option) {
    assert_false(array_key_exists($option, $GLOBALS['wp_options']),
        "{$option} pointing at our server is cleaned up");
}
assert_eq(['barebits_cron_tick'], $GLOBALS['unscheduled'], 'the WP-cron pinger is unscheduled');

// --- Alongside install, gateway pointed at a REAL BTCPay Server --------------

$GLOBALS['wp_options'] = [];
$GLOBALS['unscheduled'] = [];
seed_plugin_options('http://wp.test/barebits', withInstall: true);
$GLOBALS['wp_options'] += [
    'btcpay_gf_url' => 'https://btcpay.example.com',
    'btcpay_gf_api_key' => 'their-key',
    'btcpay_gf_store_id' => 'their-store',
    'btcpay_gf_webhook' => ['id' => 'wh_theirs'],
];

require UNINSTALL;

assert_eq($expected, remaining_barebits_options(), 'the install record still survives');
assert_eq('https://btcpay.example.com', $GLOBALS['wp_options']['btcpay_gf_url'] ?? null,
    'a foreign BTCPay connection survives the uninstall');
assert_eq('their-key', $GLOBALS['wp_options']['btcpay_gf_api_key'] ?? null,
    'so does its API key');

// --- URL mode (no alongside install): nothing is kept ------------------------

$GLOBALS['wp_options'] = [];
$GLOBALS['unscheduled'] = [];
seed_plugin_options('https://pay.example.com', withInstall: false);
$GLOBALS['wp_options'] += [
    'btcpay_gf_url' => 'https://pay.example.com',
    'btcpay_gf_api_key' => str_repeat('a', 64),
];

require UNINSTALL;

assert_eq([], remaining_barebits_options(),
    'a URL-mode uninstall keeps nothing — the remote server has its own credentials');
assert_false(array_key_exists('btcpay_gf_url', $GLOBALS['wp_options']),
    'the gateway options pointing at the connected server are cleaned up');

// --- No server ever connected: gateway options are left alone entirely -------
//
// barebits_server_url is '' here; an empty prefix must not read as
// "everything is ours" (the same guard test_btcpay_takeover_decision pins
// for the wiring direction).
$GLOBALS['wp_options'] = [
    'barebits_mode' => 'url',
    'btcpay_gf_url' => 'https://btcpay.example.com',
    'btcpay_gf_api_key' => 'their-key',
];

require UNINSTALL;

assert_eq([], remaining_barebits_options(), 'the half-configured state is deleted');
assert_eq('https://btcpay.example.com', $GLOBALS['wp_options']['btcpay_gf_url'] ?? null,
    'an unconfigured plugin never touches the gateway options');

echo "test_wp_uninstall: ok\n";
