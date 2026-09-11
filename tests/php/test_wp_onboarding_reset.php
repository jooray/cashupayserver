<?php
/**
 * Onboarding "Start over" (barebits_handle_reset_onboarding).
 *
 * The reset forgets the plugin's CONNECTION state — wiring, credentials for
 * the shop side, pairing/provision leftovers — and never touches the
 * BareBits server or its data (the handler has no filesystem or HTTP
 * surface at all, which the stub set below enforces by simply not providing
 * any). When an alongside install exists, the install RECORD survives:
 * its location, address, admin password, SSO key, and cron key — that
 * server keeps running with real money behind a password the merchant never
 * chose, so the plugin's copy is the only one, and it has no crontab of its
 * own, so the WP-cron pinger keeps ticking it through the reset. A URL-mode
 * reset (no install) wipes everything and stops the pinger. Also pinned:
 * the capability + nonce gate runs before any state is destroyed.
 *
 * The handler ends in exit, so each scenario runs in a subprocess whose
 * shutdown hook dumps the surviving state as JSON for the parent to assert.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';

$T = sys_get_temp_dir() . '/barebits_reset_' . bin2hex(random_bytes(6));
mkdir($T, 0750, true);
register_shutdown_function(function () use ($T) { @cleanup_db($T); });

$root = dirname(__DIR__, 2);
$driver = $T . '/reset_driver.php';
file_put_contents($driver, sprintf(<<<'PHP'
<?php
declare(strict_types=1);
define('ABSPATH', '/tmp/');

// --- minimal WordPress stubs -------------------------------------------------
$GLOBALS['wp_options'] = [
    'barebits_mode' => getenv('T_MODE') ?: 'install',
    'barebits_server_url' => 'http://wp.test/barebits',
    'barebits_store_id' => 'store_x',
    'barebits_api_key' => str_repeat('a', 64),
    'barebits_cron_key' => str_repeat('b', 64),
    'barebits_wired_at' => 1700000000,
    'barebits_discount_percent' => 3,
    'barebits_pairing_expected' => ['state' => 'x', 'at' => 1700000000],
    'barebits_provision_token' => str_repeat('c', 64),
    'barebits_admin_password' => 'super-secret',
    'barebits_sso_key' => str_repeat('d', 64),
    'barebits_btcpay_override_consent' => 'https://old.example',
    // NOT a connection option: the reset must leave it alone (review-banner
    // dismissals are UI state, not onboarding state).
    'barebits_review_banner' => ['count' => 2, 'dismissed_at' => 1700000000],
];
if ((getenv('T_MODE') ?: 'install') === 'install') {
    $GLOBALS['wp_options'] += [
        'barebits_install_dir' => '/var/www/barebits',
        'barebits_install_data_dir' => '/var/www/barebits-data-abc123def456',
        'barebits_install_dirname' => 'barebits',
        // Deliberately NOT seeding barebits_install_url: the reset must
        // backfill it from the connected URL before forgetting the mode.
    ];
}
$GLOBALS['transients'] = [];
$GLOBALS['unscheduled'] = [];
$GLOBALS['redirects'] = [];
$GLOBALS['next_scheduled'] = 1700000000;

function get_option($name, $default = false) { return $GLOBALS['wp_options'][$name] ?? $default; }
function update_option($name, $value, $autoload = null) { $GLOBALS['wp_options'][$name] = $value; return true; }
function delete_option($name) { unset($GLOBALS['wp_options'][$name]); return true; }
function set_transient($name, $value, $ttl = 0) { $GLOBALS['transients'][$name] = $value; return true; }
function get_transient($name) { return $GLOBALS['transients'][$name] ?? false; }
function delete_transient($name) { unset($GLOBALS['transients'][$name]); return true; }
function add_action($hook, $cb) {}
function add_filter($hook, $cb) {}
function current_user_can($cap) { return getenv('T_CAN_MANAGE') !== '0'; }
function check_admin_referer($action) {
    if (getenv('T_NONCE_OK') === '0') { wp_die('nonce failure', 403); }
    return 1;
}
function wp_die($message = '', $code = 200) { echo "WP_DIED:" . $message; exit; }
function admin_url($path = '') { return 'http://wp.test/wp-admin/' . $path; }
function wp_safe_redirect($url) { $GLOBALS['redirects'][] = $url; return true; }
function wp_next_scheduled($hook) { return $GLOBALS['next_scheduled']; }
function wp_unschedule_event($ts, $hook) { $GLOBALS['unscheduled'][] = $hook; $GLOBALS['next_scheduled'] = false; return true; }
function site_url($path = '') { return 'http://wp.test' . $path; }
function __($s) { return $s; }
function esc_html__($s, $domain = 'default') { return htmlspecialchars((string)$s, ENT_QUOTES); }

register_shutdown_function(function () {
    echo "\nSTATE:" . json_encode([
        'options' => $GLOBALS['wp_options'],
        'unscheduled' => $GLOBALS['unscheduled'],
        'flash' => $GLOBALS['transients']['barebits_flash'] ?? null,
        'redirects' => $GLOBALS['redirects'],
    ]);
});

require %s;
require %s;
require %s;

barebits_handle_reset_onboarding();
PHP,
    var_export($root . '/wordpress/state.php', true),
    var_export($root . '/wordpress/cron-integration.php', true),
    var_export($root . '/wordpress/onboarding.php', true)
));

/**
 * Run the reset handler in a subprocess.
 *
 * @return array{raw:string, state:?array}
 */
function run_reset(array $env = []): array {
    global $driver;
    $prefix = '';
    foreach ($env as $k => $v) {
        $prefix .= $k . '=' . escapeshellarg($v) . ' ';
    }
    $out = [];
    $rc = 0;
    exec($prefix . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($driver) . ' 2>&1', $out, $rc);
    $raw = implode("\n", $out);
    $state = null;
    if (preg_match('/STATE:(\{.*\})\s*$/s', $raw, $m)) {
        $state = json_decode($m[1], true);
    }
    return ['raw' => $raw, 'state' => $state];
}

// The connection state every reset must destroy, whatever the mode.
const WIPED_ALWAYS = [
    'barebits_mode', 'barebits_store_id', 'barebits_api_key',
    'barebits_wired_at', 'barebits_discount_percent',
    'barebits_pairing_expected', 'barebits_provision_token',
    'barebits_btcpay_override_consent',
];

// --- Install mode: connection wiped, the install record survives -------------

$res = run_reset();
assert_not_null($res['state'], 'driver produced state: ' . substr($res['raw'], 0, 400));
$options = $res['state']['options'];

foreach (WIPED_ALWAYS as $option) {
    assert_false(array_key_exists($option, $options), "{$option} is deleted by the reset");
}
// The install record — location, address, the ONLY copy of the admin
// credentials for a server that keeps running with real money, and the cron
// key its heartbeat needs — survives.
foreach (['barebits_server_url', 'barebits_install_dir', 'barebits_install_data_dir',
          'barebits_install_dirname', 'barebits_admin_password', 'barebits_sso_key',
          'barebits_cron_key'] as $option) {
    assert_true(array_key_exists($option, $options), "{$option} survives an install-mode reset");
}
assert_eq('super-secret', $options['barebits_admin_password'], 'the admin password is intact');
assert_eq('http://wp.test/barebits', $options['barebits_install_url'] ?? null,
    'the install\'s own URL is backfilled before the mode is forgotten');
assert_true(array_key_exists('barebits_review_banner', $options),
    'review-banner UI state survives — the reset only forgets the connection');

assert_eq([], $res['state']['unscheduled'],
    'the WP-cron pinger keeps ticking the surviving install');
assert_eq('success', $res['state']['flash']['kind'] ?? null, 'a success notice is queued');
assert_true(str_contains((string)($res['state']['flash']['message'] ?? ''), 'admin password stay saved'),
    'and it tells the merchant the credentials were kept');
assert_eq(['http://wp.test/wp-admin/admin.php?page=barebits'], $res['state']['redirects'],
    'the merchant lands back on the onboarding page');

// --- URL mode (no install): everything goes ----------------------------------

$res = run_reset(['T_MODE' => 'url']);
$options = $res['state']['options'];
foreach (array_merge(WIPED_ALWAYS, ['barebits_server_url', 'barebits_admin_password',
        'barebits_sso_key', 'barebits_cron_key']) as $option) {
    assert_false(array_key_exists($option, $options), "{$option} is deleted by a URL-mode reset");
}
assert_eq(['barebits_cron_tick'], $res['state']['unscheduled'],
    'with no install to tick, the WP-cron pinger is unscheduled');
assert_true(array_key_exists('barebits_review_banner', $options), 'UI state still survives');
assert_true(str_contains((string)($res['state']['flash']['message'] ?? ''), 'Nothing on the BareBits side was removed'),
    'the URL-mode flash keeps the nothing-server-side promise');

// --- The gate runs before anything is destroyed ------------------------------

$res = run_reset(['T_CAN_MANAGE' => '0']);
assert_true(str_contains($res['raw'], 'WP_DIED:'), 'a non-admin is refused');
assert_eq('install', $res['state']['options']['barebits_mode'] ?? null, 'and nothing was deleted');
assert_eq([], $res['state']['unscheduled'], 'and the cron pinger is untouched');

$res = run_reset(['T_NONCE_OK' => '0']);
assert_true(str_contains($res['raw'], 'WP_DIED:nonce failure'), 'a bad nonce is refused');
assert_eq('install', $res['state']['options']['barebits_mode'] ?? null, 'and nothing was deleted');

echo "test_wp_onboarding_reset: ok\n";
