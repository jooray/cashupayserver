<?php
/**
 * Every wizard POST needs the session's csrf_token.
 *
 *  - a screen POST without the token (or with a wrong one) is refused with
 *    403, re-renders the posted screen with the error, and runs no handler
 *    (the wizard does not advance; nothing is written);
 *  - the AJAX helpers (validate_xpub, save_url_mode) are refused with a JSON
 *    403 and write nothing;
 *  - the same POST with the token from the rendered form advances;
 *  - add_store mode requires an ADMIN session, not merely a logged-in one.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require __DIR__ . '/http_driver.php';

$dir = sys_get_temp_dir() . '/cashupay_test_' . bin2hex(random_bytes(6));
mkdir($dir, 0750, true);
define('CASHUPAY_DATA_DIR', $dir);
register_shutdown_function(function () use ($dir) { @cleanup_db($dir); });
require_once dirname(__DIR__, 2) . '/app/includes/auth.php';

$srv = HttpDriver::start($dir);

function cfg(string $key) {
    $row = Database::fetchOne("SELECT value FROM config WHERE key = ?", [$key]);
    if ($row === null) return null;
    $v = json_decode($row['value'], true);
    return json_last_error() === JSON_ERROR_NONE ? $v : $row['value']; // strings are stored raw
}

$r = $srv->get('/setup.php');
assert_eq(200, $r['status'], 'wizard opens');
$csrf = HttpDriver::wizardCsrf($r['body']);
assert_not_null($csrf, 'every wizard screen renders a csrf_token field');
assert_eq('terms', HttpDriver::wizardStep($r['body']), 'first screen is terms');

// No token.
$r = $srv->post('/setup.php', ['step' => 'terms', 'terms_legal' => '1', 'terms_warranty' => '1', 'terms_fee' => '1']);
assert_eq(403, $r['status'], 'wizard POST without csrf_token is refused');
assert_true(str_contains($r['body'], 'Session expired or invalid request'), 'recoverable error rendered');
assert_eq('terms', HttpDriver::wizardStep($r['body']), 'the posted screen re-renders; the wizard did not advance');

// Wrong token.
$r = $srv->post('/setup.php', ['step' => 'terms', 'terms_legal' => '1', 'terms_warranty' => '1', 'terms_fee' => '1', 'csrf_token' => str_repeat('0', 64)]);
assert_eq(403, $r['status'], 'wrong csrf_token is refused');

// A token from ANOTHER session is refused too.
$jarA = $srv->newSession();
$r = $srv->get('/setup.php');
$csrfB = HttpDriver::wizardCsrf($r['body']);
assert_not_null($csrfB, 'second session has its own token');
assert_neq($csrf, $csrfB, 'tokens are per session');
$r = $srv->post('/setup.php', ['step' => 'terms', 'terms_legal' => '1', 'terms_warranty' => '1', 'terms_fee' => '1', 'csrf_token' => $csrf]);
assert_eq(403, $r['status'], 'another session\'s token is refused');
$srv->switchSession($jarA);

// AJAX helpers: JSON 403, nothing written.
$r = $srv->post('/setup.php', ['action' => 'save_url_mode', 'mode' => 'clean']);
assert_eq(403, $r['status'], 'save_url_mode without token refused');
$j = json_decode($r['body'], true);
assert_true(is_array($j) && ($j['success'] ?? null) === false, 'JSON error body: ' . $r['body']);
assert_null(cfg('url_mode'), 'url_mode was not written');
$r = $srv->post('/setup.php', ['action' => 'validate_xpub', 'xpub' => 'xpub6garbage']);
assert_eq(403, $r['status'], 'validate_xpub without token refused');
assert_true(is_array(json_decode($r['body'], true)), 'JSON body');

// With the token: advances, and the helper works.
$r = $srv->post('/setup.php', ['action' => 'save_url_mode', 'mode' => 'router', 'csrf_token' => $csrf]);
assert_eq(200, $r['status'], 'save_url_mode with token ok');
assert_eq('router', cfg('url_mode'), 'url_mode written');
$r = $srv->post('/setup.php', ['step' => 'terms', 'terms_legal' => '1', 'terms_warranty' => '1', 'terms_fee' => '1', 'csrf_token' => $csrf]);
assert_eq(200, $r['status'], 'terms with token accepted');
assert_neq('terms', HttpDriver::wizardStep($r['body']), 'wizard advanced past terms');

// The password step still refuses when posted without a token even though
// no admin exists yet (the CSRF check precedes every handler).
$users = Database::fetchOne("SELECT COUNT(*) AS n FROM users");
$r = $srv->post('/setup.php', ['step' => 'password', 'password' => 'evil-pw-12345', 'confirm_password' => 'evil-pw-12345']);
assert_eq(403, $r['status'], 'password step without token refused');
$after = Database::fetchOne("SELECT COUNT(*) AS n FROM users");
assert_eq((int)$users['n'], (int)$after['n'], 'no user row written');

// ---- add_store is admin-only -------------------------------------------------
// Finish the wizard so the install is complete, then try add_store as a
// plain user and as an admin.
$post = function (array $data) use ($srv, &$csrf): array {
    $data['csrf_token'] = $csrf;
    $r = $srv->post('/setup.php', $data);
    assert_true($r['status'] < 400, ($data['step'] ?? '?') . ' -> ' . $r['status']);
    return $r;
};
$post(['step' => 'analytics', 'usage_stats' => '0']);
$post(['step' => 'install_type', 'install_type' => 'other']);
$post(['step' => 'security', 'security_acknowledged' => '1']);
$post(['step' => 'password', 'password' => 'csrf-test-pw-1234', 'confirm_password' => 'csrf-test-pw-1234']);
$post(['step' => 'email', 'email_action' => 'skip']);
$post(['step' => 'store', 'store_name' => 'CSRF Store', 'default_currency' => 'sat']);
$post(['step' => 'onchain', 'onchain_action' => 'skip']);
$post(['step' => 'lightning', 'lightning_action' => 'skip']);
$post(['step' => 'mints', 'mints_enabled' => '0']);
assert_true(cfg('setup_complete') === true, 'install complete');

Auth::createUser('staff', 'staffpw1234', Auth::ROLE_USER);
$srv->newSession();
$r = $srv->post('/admin.php', ['action' => 'login', 'username' => 'staff', 'password' => 'staffpw1234']);
assert_eq(200, $r['status'], 'staff login ok: ' . $r['body']);
$r = $srv->get('/setup.php?mode=add_store');
assert_eq(302, $r['status'], 'add_store as a non-admin is redirected');
$storesBefore = (int)Database::fetchOne("SELECT COUNT(*) AS n FROM stores")['n'];
$r = $srv->post('/setup.php', ['mode' => 'add_store', 'step' => 'store', 'store_name' => 'Staff Store', 'default_currency' => 'sat']);
assert_eq(302, $r['status'], 'add_store POST as a non-admin is redirected');
assert_eq($storesBefore, (int)Database::fetchOne("SELECT COUNT(*) AS n FROM stores")['n'], 'no store created');

$srv->newSession();
$r = $srv->post('/admin.php', ['action' => 'login', 'username' => 'admin', 'password' => 'csrf-test-pw-1234']);
assert_eq(200, $r['status'], 'admin login ok');
$r = $srv->get('/setup.php?mode=add_store');
assert_eq(200, $r['status'], 'add_store as admin opens');
$adminCsrf = HttpDriver::wizardCsrf($r['body']);
assert_not_null($adminCsrf, 'add_store screen renders the token');
$r = $srv->post('/setup.php', ['mode' => 'add_store', 'step' => 'store', 'store_name' => 'Admin Store', 'default_currency' => 'sat']);
assert_eq(403, $r['status'], 'add_store POST without token refused even for admin');
assert_eq($storesBefore, (int)Database::fetchOne("SELECT COUNT(*) AS n FROM stores")['n'], 'no store created without token');
$r = $srv->post('/setup.php', ['mode' => 'add_store', 'step' => 'store', 'store_name' => 'Admin Store', 'default_currency' => 'sat', 'csrf_token' => $adminCsrf]);
assert_eq(200, $r['status'], 'add_store POST with token works');
assert_eq($storesBefore + 1, (int)Database::fetchOne("SELECT COUNT(*) AS n FROM stores")['n'], 'store created');

echo "ok\n";
