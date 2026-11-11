<?php
/**
 * admin.php ?api=test_mint / test_mint_expiry are admin-only, and even for an
 * admin the URL is validated before any connection.
 *
 * Both endpoints fetch an operator-supplied URL and echo the mint's answer
 * (or the error text) back; for a ROLE_USER dashboard account that was a
 * read-back SSRF primitive. Driven over real HTTP (php -S) with a staff and
 * an admin session.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require __DIR__ . '/http_driver.php';
$dir = fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/auth.php';

Auth::setAdminPassword('admin-pw-1234');
Auth::createUser('staff', 'staffpw1234', Auth::ROLE_USER);
make_store('store_a');
// Private endpoints OFF for the policy checks below (the driver env sets the
// opt-in; the DB key wins).
Config::set('allow_private_endpoints', false);
// Keep the driver's single php -S worker free: a fresh external-cron stamp
// makes the login's opportunistic self-trigger run essentials-only (no ipgeo
// download holding the worker while the next request waits).
Config::set('last_external_cron_at', time());

$srv = HttpDriver::start($dir);

function login(HttpDriver $srv, string $user, string $pw): string {
    $r = $srv->post('/admin.php', ['action' => 'login', 'username' => $user, 'password' => $pw]);
    assert_eq(200, $r['status'], "$user login: " . $r['body']);
    $tok = json_decode($r['body'], true)['csrfToken'] ?? '';
    assert_true($tok !== '', 'csrf token issued');
    return $tok;
}

// ---- unauthenticated ----------------------------------------------------------
$r = $srv->post('/admin.php', ['action' => 'test_mint', 'mint_url' => 'http://127.0.0.1:1']);
assert_eq(401, $r['status'], 'anonymous test_mint is 401');

// ---- ROLE_USER -----------------------------------------------------------------
$tok = login($srv, 'staff', 'staffpw1234');
$t0 = microtime(true);
$r = $srv->post('/admin.php', ['action' => 'test_mint', 'mint_url' => 'http://127.0.0.1:1'], ['X-CSRF-Token' => $tok]);
assert_eq(403, $r['status'], 'ROLE_USER test_mint is 403: ' . $r['body']);
assert_true(str_contains($r['body'], 'Admin role required'), 'admin gate message');
$r = $srv->post('/admin.php', ['action' => 'test_mint_expiry', 'mint_url' => 'http://127.0.0.1:1', 'unit' => 'sat'], ['X-CSRF-Token' => $tok]);
assert_eq(403, $r['status'], 'ROLE_USER test_mint_expiry is 403: ' . $r['body']);
assert_true(microtime(true) - $t0 < 10.0, 'refused without any connection attempt');

// ---- admin: URL policy ---------------------------------------------------------
$srv->newSession();
$tok = login($srv, 'admin', 'admin-pw-1234');

$r = $srv->post('/admin.php', ['action' => 'test_mint', 'mint_url' => 'file:///etc/passwd'], ['X-CSRF-Token' => $tok]);
assert_eq(200, $r['status'], 'admin gets a JSON verdict');
$j = json_decode($r['body'], true);
assert_true(is_array($j) && $j['success'] === false, 'file:// refused: ' . $r['body']);
assert_true(stripos((string)$j['error'], 'http') !== false, 'scheme error: ' . $j['error']);
assert_false(str_contains($r['body'], 'root:'), 'no file contents');

$t0 = microtime(true);
$r = $srv->post('/admin.php', ['action' => 'test_mint', 'mint_url' => 'http://127.0.0.1:1'], ['X-CSRF-Token' => $tok]);
$j = json_decode($r['body'], true);
assert_true(is_array($j) && $j['success'] === false, 'loopback refused with opt-in off: ' . $r['body']);
assert_true(stripos((string)$j['error'], 'private or local network') !== false, 'generic message: ' . $j['error']);
assert_true(microtime(true) - $t0 < 3.0, 'refused without connecting');

$r = $srv->post('/admin.php', ['action' => 'test_mint', 'mint_url' => 'http://169.254.169.254/latest/meta-data/'], ['X-CSRF-Token' => $tok]);
$j = json_decode($r['body'], true);
assert_true(is_array($j) && $j['success'] === false && stripos((string)$j['error'], 'private or local network') !== false, 'metadata endpoint refused: ' . $r['body']);

$r = $srv->post('/admin.php', ['action' => 'test_mint_expiry', 'mint_url' => 'gopher://x', 'unit' => 'sat'], ['X-CSRF-Token' => $tok]);
assert_eq(400, $r['status'], 'test_mint_expiry rejects a non-http URL: ' . $r['body']);

echo "ok\n";
