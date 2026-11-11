<?php
/**
 * Admin notifications endpoints × payer email capture.
 *
 * The GPL split removed the WordPress guards around the notifications card,
 * so save_notifications_settings now (a) persists the new explicit
 * payer_email_capture_enabled boolean — the operator override behind
 * Config::isPayerEmailCaptureEnabled — and (b) unconditionally persists the
 * payer-receipt and newsletter defaults it used to skip on WordPress
 * installs. get_notifications_settings reports the EFFECTIVE capture value
 * (explicit config, else the deployment-shape default), which is what the
 * settings card's toggle renders from.
 *
 * admin.php echoes and exits, so each call runs in a subprocess whose driver
 * fakes a logged-in admin session + CSRF token before requiring admin.php;
 * the managed declaration travels as the CASHUPAY_MANAGED_INSTALL env var
 * scoped to that one command.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
$dataDir = fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/config.php';

// The session needs a real admin row so anything resolving currentUser works.
Database::insert('users', [
    'id'            => 'user_notif_admin',
    'username'      => 'admin',
    'password_hash' => password_hash('irrelevant', PASSWORD_DEFAULT),
    'role'          => 'admin',
    'created_at'    => Database::timestamp(),
]);

$root = dirname(__DIR__, 2) . '/app';
$runner = $dataDir . '/admin_notif_runner.php';
file_put_contents($runner, sprintf(<<<'PHP'
<?php
declare(strict_types=1);
define('CASHUPAY_DATA_DIR', getenv('T_DATA_DIR'));
$_SERVER['HTTP_HOST'] = 'pay.test';
$_SERVER['SCRIPT_NAME'] = '/admin.php';
$_SERVER['REQUEST_URI'] = '/admin.php';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require %s;
require %s;
require %s;
// Fake the logged-in admin session the way a browser session would carry it,
// then hand the request the matching CSRF token.
Auth::initSession();
$_SESSION['user_id'] = 'user_notif_admin';
$_SESSION['user_role'] = 'admin';
$_SESSION['login_time'] = time();
$_POST = json_decode((string)getenv('T_POST'), true);
$_POST['csrf_token'] = Auth::generateCsrfToken();
register_shutdown_function(function () {
    $c = http_response_code();
    echo "\nHTTP_STATUS:" . ($c === false ? 200 : $c);
});
require %s;
PHP,
    var_export($root . '/includes/database.php', true),
    var_export($root . '/includes/config.php', true),
    var_export($root . '/includes/auth.php', true),
    var_export($root . '/admin.php', true)
));

/**
 * Run one admin.php POST action in a subprocess.
 *
 * @return array{status:int, json:?array}
 */
function admin_post(array $post, bool $managed = false): array {
    global $dataDir, $runner;
    $env = 'T_DATA_DIR=' . escapeshellarg($dataDir)
        . ' T_POST=' . escapeshellarg(json_encode($post))
        . ($managed ? ' CASHUPAY_MANAGED_INSTALL=1' : '');
    $out = [];
    $rc = 0;
    exec($env . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' 2>&1', $out, $rc);
    $raw = implode("\n", $out);
    if (!preg_match('/^(.*)\nHTTP_STATUS:(\d+)$/s', $raw, $m)) {
        fail("admin runner produced unparseable output (rc=$rc): " . substr($raw, 0, 800));
    }
    return ['status' => (int)$m[2], 'json' => json_decode(trim($m[1]), true)];
}

/** A config value read raw, so this process's Config cache can't mask subprocess writes. */
function raw_config(string $key) {
    $row = Database::fetchOne("SELECT value FROM config WHERE key = ?", [$key]);
    return $row === null ? null : json_decode($row['value'], true);
}

// --- get: with nothing saved, the toggle reports the deployment default ------

$res = admin_post(['action' => 'get_notifications_settings']);
assert_eq(200, $res['status'], 'get succeeds standalone');
assert_eq(true, $res['json']['payerEmailCaptureEnabled'] ?? null, 'standalone default reads ON');

$res = admin_post(['action' => 'get_notifications_settings'], managed: true);
assert_eq(false, $res['json']['payerEmailCaptureEnabled'] ?? null, 'managed default reads OFF');

// --- save: the capture toggle persists as an explicit boolean ----------------
//
// The formerly WordPress-guarded fields (payer receipt, newsletter default)
// ride along and must persist too, now that the guard is gone.
$res = admin_post([
    'action' => 'save_notifications_settings',
    'payer_email_capture_enabled' => '1',
    'payer_receipt_enabled' => '1',
    'newsletter_default_checked' => '1',
    'enabled' => '0',
    'to_email' => '',
]);
assert_eq(200, $res['status'], 'save succeeds: ' . json_encode($res['json']));
assert_eq(true, $res['json']['success'] ?? null, 'save reports success');
assert_eq(true, raw_config('payer_email_capture_enabled'), 'explicit ON persisted');
assert_eq(true, raw_config('notifications_payer_receipt_enabled'), 'payer receipt persisted (no WP guard anymore)');
assert_eq(true, raw_config('newsletter_default_checked'), 'newsletter default persisted (no WP guard anymore)');

// The explicit value now beats the managed default on reads.
$res = admin_post(['action' => 'get_notifications_settings'], managed: true);
assert_eq(true, $res['json']['payerEmailCaptureEnabled'] ?? null, 'explicit ON wins over the managed default');

// --- save with the toggle off: an unchecked checkbox posts nothing -----------
//
// The browser omits unchecked boxes, so absence must store explicit false —
// not fall back to the deployment default.
$res = admin_post([
    'action' => 'save_notifications_settings',
    'enabled' => '0',
    'to_email' => '',
]);
assert_eq(true, $res['json']['success'] ?? null, 'save with the box unchecked succeeds');
assert_eq(false, raw_config('payer_email_capture_enabled'), 'absent field stores explicit OFF');

$res = admin_post(['action' => 'get_notifications_settings']);
assert_eq(false, $res['json']['payerEmailCaptureEnabled'] ?? null, 'explicit OFF wins over the standalone default');

// --- auth boundaries: the endpoints stay admin-gated -------------------------

// No CSRF token: rejected before any handler runs. (The runner always adds a
// valid token, so drive admin.php directly with a broken one.)
$noCsrf = $dataDir . '/admin_notif_nocsrf.php';
file_put_contents($noCsrf, sprintf(<<<'PHP'
<?php
declare(strict_types=1);
define('CASHUPAY_DATA_DIR', getenv('T_DATA_DIR'));
$_SERVER['HTTP_HOST'] = 'pay.test';
$_SERVER['SCRIPT_NAME'] = '/admin.php';
$_SERVER['REQUEST_URI'] = '/admin.php';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require %s;
require %s;
require %s;
Auth::initSession();
$_SESSION['user_id'] = 'user_notif_admin';
$_SESSION['user_role'] = 'admin';
$_SESSION['login_time'] = time();
$_POST = [
    'action' => 'save_notifications_settings',
    'payer_email_capture_enabled' => '1',
    'csrf_token' => 'forged',
];
register_shutdown_function(function () {
    $c = http_response_code();
    echo "\nHTTP_STATUS:" . ($c === false ? 200 : $c);
});
require %s;
PHP,
    var_export($root . '/includes/database.php', true),
    var_export($root . '/includes/config.php', true),
    var_export($root . '/includes/auth.php', true),
    var_export($root . '/admin.php', true)
));
$out = [];
exec('T_DATA_DIR=' . escapeshellarg($dataDir) . ' '
    . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($noCsrf) . ' 2>&1', $out);
$raw = implode("\n", $out);
assert_true(str_contains($raw, 'HTTP_STATUS:403'), 'a forged CSRF token is rejected: ' . substr($raw, 0, 300));
assert_eq(false, raw_config('payer_email_capture_enabled'), 'the forged request changed nothing');

echo "test_admin_notifications_capture_save: ok\n";
