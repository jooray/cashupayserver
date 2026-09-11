<?php
/**
 * Stale-heartbeat warning in wp-admin (admin-menu.php).
 *
 * Install mode delegates the BareBits server's cron to the WP-cron pinger,
 * and WP-cron only fires on site traffic — so a quiet shop, DISABLE_WP_CRON
 * without a system cron, or a host blocking self-requests silently stalls
 * payment confirmations. cron-integration.php stamps barebits_cron_last_ok
 * on every successful ping; admin_notices warns once the LATER of that
 * stamp and the wiring time is more than BAREBITS_CRON_STALE_WARN_SECONDS
 * old. URL-mode connections (the remote server runs its own cron) and
 * non-admins never see it, and the check is state-only — it must never fire
 * HTTP from an admin pageview (the stub set below provides no HTTP surface
 * at all).
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';

// --- minimal WordPress stubs -------------------------------------------------
define('ABSPATH', '/tmp/');
define('DAY_IN_SECONDS', 86400);
function admin_url($path = '') { return 'http://wp.test/wp-admin/' . $path; }

$GLOBALS['wp_options'] = [];
$GLOBALS['wp_can_manage'] = true;

function add_action($hook, $cb) {}
function add_menu_page(...$args) {}
function current_user_can($cap) { return $GLOBALS['wp_can_manage']; }
function get_option($name, $default = false) { return $GLOBALS['wp_options'][$name] ?? $default; }
function update_option($name, $value, $autoload = null) { $GLOBALS['wp_options'][$name] = $value; return true; }
function wp_create_nonce($action) { return 'nonce-' . $action; }
function esc_attr($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
function esc_url($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
function esc_html($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }

require __DIR__ . '/wp_compat_stubs.php';
require dirname(__DIR__, 2) . '/wordpress/state.php';
require dirname(__DIR__, 2) . '/wordpress/admin-menu.php';

function render_notices(): string {
    ob_start();
    barebits_admin_notice();
    return (string)ob_get_clean();
}

/** A wired alongside install with a heartbeat last seen $lastOk. */
function set_install_state(int $lastOk, int $wiredAt): void {
    $GLOBALS['wp_options'] = array_merge($GLOBALS['wp_options'], [
        'barebits_mode' => 'install',
        'barebits_server_url' => 'http://wp.test/barebits',
        'barebits_install_dir' => '/var/www/barebits',
        'barebits_install_url' => 'http://wp.test/barebits',
        'barebits_wired_at' => $wiredAt,
        'barebits_cron_key' => str_repeat('b', 64),
        'barebits_cron_last_ok' => $lastOk,
    ]);
}

const WARNING_MARKER = 'background heartbeat';

// --- Fresh heartbeat: quiet ---------------------------------------------------
set_install_state(lastOk: time() - 90, wiredAt: time() - 3600);
assert_false(str_contains(render_notices(), WARNING_MARKER), 'a fresh heartbeat renders no warning');

// --- Stale heartbeat: warn, with the age and a pointer to the details --------
set_install_state(lastOk: time() - 1800, wiredAt: time() - 3600);
$html = render_notices();
assert_true(str_contains($html, WARNING_MARKER), 'a stale heartbeat warns');
assert_true(str_contains($html, '30 minutes'), 'and states how long it has been quiet');
assert_true(str_contains($html, 'page=barebits-connection'), 'and links the connection details');
assert_true(str_contains($html, 'DISABLE_WP_CRON'), 'and names the usual suspect');

// --- Just wired, no tick recorded yet: the wiring time is the baseline -------
//
// Installs wired before the stamp existed (or whose synchronous first ping
// was skipped) must not warn until they have actually been quiet.
set_install_state(lastOk: 0, wiredAt: time() - 120);
assert_false(str_contains(render_notices(), WARNING_MARKER), 'freshly wired installs get the grace period');

set_install_state(lastOk: 0, wiredAt: time() - 1800);
assert_true(str_contains(render_notices(), WARNING_MARKER), 'but not forever — a quiet install warns');

// --- The warning coexists with, not replaces, the review banner --------------
$html = render_notices();
assert_true(str_contains($html, 'Leave us a review!'), 'the review banner still renders alongside');

// --- URL mode with no install record: the remote server runs its own cron ----
set_install_state(lastOk: 0, wiredAt: time() - 86400);
$GLOBALS['wp_options']['barebits_mode'] = 'url';
unset($GLOBALS['wp_options']['barebits_install_dir'], $GLOBALS['wp_options']['barebits_install_url'],
    $GLOBALS['wp_options']['barebits_cron_key'], $GLOBALS['wp_options']['barebits_cron_last_ok']);
assert_false(str_contains(render_notices(), WARNING_MARKER), 'plain URL-mode connections never warn');

// --- but a surviving alongside install is owed its heartbeat in ANY mode ------
//
// The pinger keeps ticking the install through "Start over" and after a
// URL-mode reconnect, so its staleness must stay visible there too.
set_install_state(lastOk: time() - 1800, wiredAt: time() - 86400);
$GLOBALS['wp_options']['barebits_mode'] = 'url';
assert_true(str_contains(render_notices(), WARNING_MARKER),
    'a reconnected-by-URL alongside install still warns when stale');
unset($GLOBALS['wp_options']['barebits_mode'], $GLOBALS['wp_options']['barebits_wired_at']);
$html = render_notices();
assert_true(str_contains($html, WARNING_MARKER),
    'mid-reset (unconfigured) the surviving install still warns when stale');
assert_true(str_contains($html, 'almost ready'),
    'alongside the finish-setup nag, not instead of it');
// Back to a stale install-mode state so the remaining gates are meaningful.
set_install_state(lastOk: time() - 1800, wiredAt: time() - 86400);

// --- No cron key (nothing to ping with): quiet --------------------------------
$GLOBALS['wp_options']['barebits_cron_key'] = '';
assert_false(str_contains(render_notices(), WARNING_MARKER), 'no cron key means no heartbeat to judge');
$GLOBALS['wp_options']['barebits_cron_key'] = str_repeat('b', 64);

// --- Non-admins never see it ---------------------------------------------------
$GLOBALS['wp_can_manage'] = false;
assert_eq('', render_notices(), 'no notice for users without manage_options');
$GLOBALS['wp_can_manage'] = true;

echo "test_wp_cron_stale_notice: ok\n";
