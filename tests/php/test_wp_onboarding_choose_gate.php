<?php
/**
 * Onboarding chooser: the server-check gate and the pairing authorize URL.
 *
 * The chooser page shows the install-alongside server checks inline and
 * disables that option when one fails — but the disabled radio is only
 * markup. Pinned here is the server side of that promise:
 *
 *   - barebits_handle_choose_mode refuses 'install' while a preflight check
 *     fails (no mode is stored, an error flash names the failing check),
 *     and only stores the mode when the host passes. The Advanced folder
 *     name is saved BEFORE the preflight runs so the writable-location
 *     check resolves the merchant's choice, not the default.
 *   - barebits_handle_run_install refuses on the same gate — conditions can
 *     regress between the chooser and the download click, and the stub set
 *     below provides no HTTP functions at all, so reaching the download
 *     would fatal rather than silently pass.
 *   - barebits_handle_start_pairing always redirects to the REAL file at
 *     /api-keys/authorize.php — never the pretty /api-keys/authorize
 *     rewrite, which 404s on hosts that ignore .htaccess (a merchant hit
 *     exactly that pairing with an existing server by URL).
 *
 * Handlers end in exit, so each scenario runs in a subprocess whose
 * shutdown hook dumps the surviving state as JSON for the parent to assert.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';

$T = sys_get_temp_dir() . '/barebits_choose_gate_' . bin2hex(random_bytes(6));
mkdir($T, 0750, true);
register_shutdown_function(function () use ($T) {
    @unlink($T . '/choose_driver.php');
    @rmdir($T);
});

$root = dirname(__DIR__, 2);
$driver = $T . '/choose_driver.php';
file_put_contents($driver, sprintf(<<<'PHP'
<?php
declare(strict_types=1);
// A real, writable ABSPATH so the writable-location check resolves a target.
define('ABSPATH', sys_get_temp_dir() . '/');
define('WP_CONTENT_DIR', sys_get_temp_dir());

// --- minimal WordPress stubs -------------------------------------------------
$GLOBALS['wp_options'] = [];
if (getenv('T_ACTION') === 'pair') {
    $GLOBALS['wp_options'] = [
        'barebits_mode' => 'url',
        'barebits_server_url' => 'https://pay.example.test',
    ];
}
$GLOBALS['transients'] = [];
$GLOBALS['redirects'] = [];

function get_option($name, $default = false) { return $GLOBALS['wp_options'][$name] ?? $default; }
function update_option($name, $value, $autoload = null) { $GLOBALS['wp_options'][$name] = $value; return true; }
function delete_option($name) { unset($GLOBALS['wp_options'][$name]); return true; }
function set_transient($name, $value, $ttl = 0) { $GLOBALS['transients'][$name] = $value; return true; }
function get_transient($name) { return $GLOBALS['transients'][$name] ?? false; }
function delete_transient($name) { unset($GLOBALS['transients'][$name]); return true; }
function add_action($hook, $cb) {}
function add_filter($hook, $cb) {}
function apply_filters($hook, $value) { return $value; }
function current_user_can($cap) { return true; }
function check_admin_referer($action) { return 1; }
function wp_die($message = '', $code = 200) { echo "WP_DIED:" . $message; exit; }
function admin_url($path = '') { return 'http://wp.test/wp-admin/' . $path; }
function site_url($path = '') { return 'http://wp.test' . $path; }
function content_url($path = '') { return 'http://wp.test/wp-content' . $path; }
function wp_safe_redirect($url) { $GLOBALS['redirects'][] = $url; return true; }
function wp_redirect($url) { $GLOBALS['redirects'][] = $url; return true; }
function wp_is_writable($path) { return is_writable($path); }
function sanitize_key($key) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string)$key)); }
function sanitize_file_name($name) { return trim((string)$name); }
function get_bloginfo($show = '') { return 'Test Shop'; }
function __($s) { return $s; }
function esc_html__($s, $domain = 'default') { return htmlspecialchars((string)$s, ENT_QUOTES); }
function wp_unslash($value) { return is_string($value) ? stripslashes($value) : $value; }
function sanitize_text_field($str) { return trim(preg_replace('/[\r\n\t ]+/', ' ', strip_tags((string)$str))); }
// Defined in btcpay-integration.php, which stays out of this stub set; the
// env flag is the test's lever for failing exactly one preflight check.
function barebits_can_install_plugins(): bool { return getenv('T_PREFLIGHT_FAIL') !== '1'; }

register_shutdown_function(function () {
    echo "\nSTATE:" . json_encode([
        'options' => $GLOBALS['wp_options'],
        'flash' => $GLOBALS['transients']['barebits_flash'] ?? null,
        'redirects' => $GLOBALS['redirects'],
    ]);
});

require %s;
require %s;
require %s;

switch (getenv('T_ACTION')) {
    case 'choose_install':
        $_POST = ['barebits_mode' => 'install'];
        if (getenv('T_DIRNAME') !== false && getenv('T_DIRNAME') !== '') {
            $_POST['barebits_install_dirname'] = getenv('T_DIRNAME');
        }
        barebits_handle_choose_mode();
        break;
    case 'run_install':
        barebits_handle_run_install();
        break;
    case 'pair':
        barebits_handle_start_pairing();
        break;
}
PHP,
    var_export($root . '/wordpress/state.php', true),
    var_export($root . '/wordpress/installer.php', true),
    var_export($root . '/wordpress/onboarding.php', true)
));

/**
 * Run one handler scenario in a subprocess.
 *
 * @return array{raw:string, state:?array}
 */
function run_scenario(array $env): array {
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

// --- Choosing install on a host that passes the checks stores the mode -------

$res = run_scenario(['T_ACTION' => 'choose_install', 'T_DIRNAME' => 'mybits']);
assert_not_null($res['state'], 'driver produced state: ' . substr($res['raw'], 0, 400));
assert_eq('install', $res['state']['options']['barebits_mode'] ?? null, 'mode stored on a passing host');
assert_eq('mybits', $res['state']['options']['barebits_install_dirname'] ?? null, 'folder choice stored');
assert_eq(null, $res['state']['flash'], 'no flash on the happy path');
assert_eq(['http://wp.test/wp-admin/admin.php?page=barebits'], $res['state']['redirects']);

// --- A failing check refuses the install choice server-side ------------------
// The chooser disables the radio, but that is markup; a hand-crafted POST
// (or a chooser rendered before conditions changed) must hit this wall.

$res = run_scenario(['T_ACTION' => 'choose_install', 'T_PREFLIGHT_FAIL' => '1', 'T_DIRNAME' => 'mybits']);
assert_not_null($res['state'], 'driver produced state: ' . substr($res['raw'], 0, 400));
assert_false(array_key_exists('barebits_mode', $res['state']['options']), 'no mode stored on a failing host');
assert_eq('mybits', $res['state']['options']['barebits_install_dirname'] ?? null,
    'the folder choice is saved before the preflight so the check resolves it');
assert_eq('error', $res['state']['flash']['kind'] ?? null, 'an error flash is queued');
$message = (string) ($res['state']['flash']['message'] ?? '');
assert_true(str_contains($message, 'server checks'), 'the flash names the gate: ' . $message);
assert_true(str_contains($message, 'Direct filesystem access'), 'and the failing check: ' . $message);

// --- The download handler re-checks: regressions between screens refuse ------
// The stub set has no HTTP functions at all, so anything past the gate
// would fatal instead of producing state — the gate must run FIRST.

$res = run_scenario(['T_ACTION' => 'run_install', 'T_PREFLIGHT_FAIL' => '1']);
assert_not_null($res['state'], 'driver produced state: ' . substr($res['raw'], 0, 400));
assert_eq('error', $res['state']['flash']['kind'] ?? null, 'run_install refuses on a failing check');
assert_true(str_contains((string) ($res['state']['flash']['message'] ?? ''), 'no longer passes'),
    'and says the host regressed');
assert_eq(['http://wp.test/wp-admin/admin.php?page=barebits'], $res['state']['redirects']);

// --- Pairing always targets the real authorize.php file ----------------------
// The pretty /api-keys/authorize needs rewrites the target host may not do;
// authorize.php executes anywhere BareBits itself runs.

$res = run_scenario(['T_ACTION' => 'pair']);
assert_not_null($res['state'], 'driver produced state: ' . substr($res['raw'], 0, 400));
$location = $res['state']['redirects'][0] ?? '';
assert_true(str_starts_with($location, 'https://pay.example.test/api-keys/authorize.php?'),
    'remote pairing goes to authorize.php: ' . $location);
assert_true(str_contains($location, 'permissions=btcpay.store.cancreateinvoice'), $location);
assert_true(str_contains($location, 'redirect=http%3A%2F%2Fwp.test'), $location);
assert_true(is_array($res['state']['options']['barebits_pairing_expected'] ?? null),
    'a state token was minted for the callback');

echo "test_wp_onboarding_choose_gate: ok\n";
