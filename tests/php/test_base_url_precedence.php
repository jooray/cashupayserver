<?php
/**
 * Config::getBaseUrl precedence with a deployment-time pin.
 *
 * Installers that know the served URL up front (the WordPress plugin's
 * alongside install) write CASHUPAY_BASE_URL into user_config.php. The pin
 * behaves like CASHUPAY_DATA_DIR — a true deployment declaration: it beats
 * BOTH the database-stored base_url and Host-header autodetection, so
 * nothing an admin-UI action ever writes can silently defeat the deployed
 * URL (whoever controls user_config.php outranks the UI, and removes the
 * line to hand control back). The pin is normalized (trailing slash
 * trimmed) like every other base-URL source.
 *
 * Constants are process-sticky, so the constant is defined once up front and
 * the database value is flipped around it; pure autodetection (no constant
 * at all) is covered by test_base_url_windows_backslash.php.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();

define('CASHUPAY_BASE_URL', 'https://shop.example/barebits/');

require_once dirname(__DIR__, 2) . '/app/includes/config.php';

// Autodetection would produce http://pay.test — the assertions below prove
// it never gets the chance while the pin exists.
$_SERVER['HTTP_HOST'] = 'pay.test';
$_SERVER['SCRIPT_NAME'] = '/index.php';

// --- No database value: the pin wins over autodetection ----------------------
assert_eq('https://shop.example/barebits', Config::getBaseUrl(),
    'the CASHUPAY_BASE_URL pin beats Host-header autodetection, trailing slash trimmed');

// --- A database base_url does NOT beat the pin -------------------------------
//
// The pin is a deployment declaration: an installer wrote it because
// auto-detection (and anything derived from it that later lands in the
// database) cannot be trusted on that host. A stored value silently
// overriding it would un-pin the URL behind the deployer's back.
Config::set('base_url', 'https://operator-choice.example/pay/');
assert_eq('https://shop.example/barebits', Config::getBaseUrl(),
    'a database base_url never overrides the deployment pin');

// --- Without the database value the pin still rules --------------------------
Config::set('base_url', '');
assert_eq('https://shop.example/barebits', Config::getBaseUrl(),
    'an empty database value changes nothing');
Config::delete('base_url');
assert_eq('https://shop.example/barebits', Config::getBaseUrl(),
    'a deleted database value changes nothing');

echo "test_base_url_precedence: ok\n";
