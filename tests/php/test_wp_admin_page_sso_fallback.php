<?php
/**
 * The wp-admin "BareBits" page's embed source (admin-menu.php +
 * state.php's barebits_sso_login_url).
 *
 * For a wired alongside install the page embeds the BareBits admin behind a
 * freshly minted one-time SSO URL. Every failure of that mint — server
 * briefly unreachable, non-200, key rejected, or a malformed/pending answer
 * — must fall back to the PLAIN admin URL (where BareBits shows its own
 * login) instead of rendering a broken frame or leaking an error. URL-mode
 * connections never embed at all (cross-site cookies), and the SSO key
 * always travels in the X-SSO-KEY header of a POST, never a query string.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';

// --- minimal WordPress stubs -------------------------------------------------
define('ABSPATH', '/tmp/');
define('DAY_IN_SECONDS', 86400);

$GLOBALS['wp_options'] = [];
$GLOBALS['sso_post'] = null;             // last wp_remote_post [url, args]
$GLOBALS['sso_response'] = 'error';      // 'error' | ['code'=>…, 'body'=>…]

function get_option($name, $default = false) { return $GLOBALS['wp_options'][$name] ?? $default; }
function update_option($name, $value, $autoload = null) { $GLOBALS['wp_options'][$name] = $value; return true; }
function add_action($hook, $cb) {}
function add_menu_page(...$args) {}
function add_submenu_page(...$args) {}
function current_user_can($cap) { return true; }
function admin_url($path = '') { return 'http://wp.test/wp-admin/' . $path; }
function site_url($path = '') { return 'http://wp.test' . $path; }
function wp_create_nonce($action) { return 'nonce-' . $action; }
function wp_nonce_field($action) { echo '<input type="hidden" name="_wpnonce" value="nonce-' . $action . '">'; }
function submit_button($text = 'Save', $type = 'primary') { echo '<button type="submit">' . $text . '</button>'; }
function esc_attr($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
function esc_url($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
function esc_html($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }

class WP_Error {
    public function get_error_message(): string { return 'stub network error'; }
}
function is_wp_error($thing) { return $thing instanceof WP_Error; }
function wp_remote_post($url, $args = []) {
    $GLOBALS['sso_post'] = ['url' => $url, 'args' => $args];
    return $GLOBALS['sso_response'] === 'error' ? new WP_Error() : $GLOBALS['sso_response'];
}
function wp_remote_retrieve_response_code($response) { return $response['code'] ?? 0; }
function wp_remote_retrieve_body($response) { return $response['body'] ?? ''; }

// The onboarding renderer + flash live in onboarding.php, which needs the
// whole admin-post surface; the page under test only *calls* these two.
function barebits_render_onboarding(): void { echo 'ONBOARDING-FLOW'; }
function barebits_take_flash(): ?array { return null; }
// Likewise the discount settings block (payment-discount.php, the whole
// WooCommerce surface): the Connection page under test only *calls* it.
function barebits_render_discount_settings(): void { echo 'DISCOUNT-SETTINGS'; }
// And the wait-out-maintenance form gate (onboarding.php as well).
function barebits_render_maintenance_guard(): void { echo 'MAINTENANCE-GUARD'; }

require __DIR__ . '/wp_compat_stubs.php';
require dirname(__DIR__, 2) . '/wordpress/state.php';
require dirname(__DIR__, 2) . '/wordpress/admin-menu.php';

function render_admin_page(): string {
    ob_start();
    barebits_admin_page();
    return (string)ob_get_clean();
}

/** The iframe's src attribute, or null when the page rendered no iframe. */
function iframe_src(string $html): ?string {
    if (!preg_match('/<iframe id="barebits-admin-frame" src="([^"]*)"/', $html, $m)) {
        return null;
    }
    return html_entity_decode($m[1], ENT_QUOTES);
}

const SERVER = 'http://wp.test/barebits';
$ssoKey = str_repeat('e', 64);

// --- Not configured: the onboarding flow renders, nothing is embedded --------
$html = render_admin_page();
assert_true(str_contains($html, 'ONBOARDING-FLOW'), 'unconfigured page renders onboarding');
assert_null(iframe_src($html), 'and no iframe');

// --- Wired install mode, SSO mint succeeds: embed behind the one-time URL ----
$GLOBALS['wp_options'] = [
    'barebits_mode' => 'install',
    'barebits_server_url' => SERVER,
    'barebits_wired_at' => 1700000000,
    'barebits_sso_key' => $ssoKey,
    'barebits_store_id' => 'store_x',
];
$token = str_repeat('f', 64);
$GLOBALS['sso_response'] = ['code' => 200, 'body' => json_encode(['status' => 'ready', 'token' => $token])];
$html = render_admin_page();
assert_eq(SERVER . '/sso.php?token=' . $token, iframe_src($html), 'the iframe embeds the minted SSO URL');
assert_eq(SERVER . '/sso.php', $GLOBALS['sso_post']['url'] ?? null, 'the mint POSTs to sso.php');
assert_eq($ssoKey, $GLOBALS['sso_post']['args']['headers']['X-SSO-KEY'] ?? null,
    'the SSO key travels in the X-SSO-KEY header, never a URL');

// --- Every mint failure falls back to the plain admin URL --------------------
foreach ([
    'network error' => 'error',
    'HTTP 500' => ['code' => 500, 'body' => '{}'],
    'key rejected' => ['code' => 403, 'body' => json_encode(['error' => 'Invalid SSO key'])],
    'still pending' => ['code' => 200, 'body' => json_encode(['status' => 'pending'])],
    'malformed body' => ['code' => 200, 'body' => 'not json'],
    'ready but no token' => ['code' => 200, 'body' => json_encode(['status' => 'ready'])],
] as $label => $response) {
    $GLOBALS['sso_response'] = $response;
    $html = render_admin_page();
    assert_eq(SERVER . '/admin.php', iframe_src($html),
        "{$label}: the embed falls back to the plain admin URL");
    assert_false(str_contains($html, 'stub network error'), "{$label}: no error text leaks into the page");
}

// --- SSO not provisioned (an old alongside install): straight to fallback ----
unset($GLOBALS['wp_options']['barebits_sso_key']);
$GLOBALS['sso_post'] = null;
$html = render_admin_page();
assert_eq(SERVER . '/admin.php', iframe_src($html), 'no SSO key embeds the plain admin URL');
assert_null($GLOBALS['sso_post'], 'and never even attempts a mint');

// --- URL mode never embeds: the connection panel renders instead -------------
$GLOBALS['wp_options']['barebits_mode'] = 'url';
$html = render_admin_page();
assert_null(iframe_src($html), 'a remote server is not embedded');
assert_true(str_contains($html, 'WooCommerce is connected'), 'the connection panel renders instead');
assert_true(str_contains($html, 'Existing server (connected by URL)'), 'labelled as a URL-mode connection');

// --- barebits_is_same_host_url: full origin, not hostname --------------------
//
// The check decides which requests may skip TLS peer verification (and so
// where the plaintext SSO/cron/provision keys travel unverified). Only this
// site's own origin — scheme AND host AND port (the stubbed site_url is
// http://wp.test, so port 80) — qualifies; a different service on another
// port of the same host is a different server.
assert_true(barebits_is_same_host_url('http://wp.test/barebits'), 'the site\'s own origin matches');
assert_true(barebits_is_same_host_url('http://WP.TEST:80/x'), 'host case and an explicit default port are normalized');
assert_false(barebits_is_same_host_url('http://wp.test:8080/x'), 'another port on the same host is NOT this site');
assert_false(barebits_is_same_host_url('https://wp.test/x'), 'another scheme is NOT this site');
assert_false(barebits_is_same_host_url('http://evil.test/x'), 'another host is NOT this site');
assert_false(barebits_is_same_host_url('not a url'), 'garbage never matches');
assert_false(barebits_is_same_host_url(''), 'nor does an empty URL');

echo "test_wp_admin_page_sso_fallback: ok\n";
