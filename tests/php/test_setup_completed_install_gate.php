<?php
/**
 * Completed-install gate in setup.php (SO-2).
 *
 * setup_complete flips when the last rail screen is answered; the `seed`,
 * `updates`, `cron` and `done` screens come after it and used to be exempt
 * from the redirect-if-set-up guard for EVERY request. Anyone could
 * therefore POST step=updates to a live install, flip the auto-update
 * opt-in and be handed the cron screen — which lazily mints and renders the
 * cron key (full cron runs on demand, forced "Update now").
 *
 * Now a tail POST on a completed install is honoured only for the session
 * that just completed the wizard (it still carries the first-run store
 * marker) or a logged-in user; everyone else is redirected to admin and no
 * handler runs. The completing session is additionally allowed to GET any
 * screen by slug (the Back links from the tail screens land on the rail
 * screens) until the done screen clears its marker. Driven over real HTTP
 * (php -S) because the CLI SAPI cannot observe redirects or sessions.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require __DIR__ . '/http_driver.php';

// Fresh data dir WITHOUT the schema: the wizard itself initialises it, the
// way a real first run does.
$dir = sys_get_temp_dir() . '/cashupay_test_' . bin2hex(random_bytes(6));
mkdir($dir, 0750, true);
define('CASHUPAY_DATA_DIR', $dir);
register_shutdown_function(function () use ($dir) { @cleanup_db($dir); });
require_once dirname(__DIR__, 2) . '/app/includes/config.php';

$srv = HttpDriver::start($dir);

function cfg(string $key) {
    $row = Database::fetchOne("SELECT value FROM config WHERE key = ?", [$key]);
    if ($row === null) return null;
    $v = json_decode($row['value'], true);
    return json_last_error() === JSON_ERROR_NONE ? $v : $row['value']; // strings are stored raw
}

// ---- walk the wizard to completion in session A ------------------------------
$r = $srv->get('/setup.php');
assert_eq(200, $r['status'], 'wizard opens on a fresh install');
$csrf = HttpDriver::wizardCsrf($r['body']);
assert_not_null($csrf, 'wizard renders a csrf_token field');

$post = function (array $data) use ($srv, &$csrf): array {
    $data['csrf_token'] = $csrf;
    $r = $srv->post('/setup.php', $data);
    assert_true($r['status'] < 400, ($data['step'] ?? '?') . ' -> ' . $r['status']);
    assert_false(str_contains($r['body'], '<div class="error"'), ($data['step'] ?? '?') . ' rendered an error: ' . substr($r['body'], 0, 300));
    return $r;
};
$post(['step' => 'terms', 'terms_legal' => '1', 'terms_warranty' => '1', 'terms_fee' => '1']);
$post(['step' => 'analytics', 'usage_stats' => '0']);
$post(['step' => 'install_type', 'install_type' => 'other']);
// The security screen is skipped (data dir outside the web root); posting
// it anyway is harmless and keeps the walk shape-independent.
$post(['step' => 'security', 'security_acknowledged' => '1']);
$post(['step' => 'password', 'password' => 'gate-test-pw-1234', 'confirm_password' => 'gate-test-pw-1234']);
$post(['step' => 'email', 'email_action' => 'skip']);
$post(['step' => 'store', 'store_name' => 'Gate Store', 'default_currency' => 'sat']);
$post(['step' => 'onchain', 'onchain_action' => 'skip']);
$post(['step' => 'lightning', 'lightning_action' => 'skip']);
$r = $post(['step' => 'mints', 'mints_enabled' => '0']);
assert_eq('wallet_tabs', HttpDriver::wizardStep($r['body']), 'declining mints lands on the wallet-tabs screen');
assert_true(cfg('setup_complete') === true, 'setup is complete from here');
function store_wallet_tabs(): ?string {
    $row = Database::fetchOne("SELECT wallet_tabs FROM stores WHERE name = 'Gate Store'");
    return $row ? $row['wallet_tabs'] : null;
}
assert_null(store_wallet_tabs(), 'wallet tabs not yet answered');
assert_null(cfg('auto_update_enabled'), 'auto-update not yet answered');
$cronKey = (string)cfg('cron_key');
assert_true(strlen($cronKey) >= 32, 'cron key was seeded at initialize');

// ---- a stranger's fresh session: tail POSTs are refused ---------------------
$sessionA = $srv->newSession();
$r = $srv->post('/setup.php', ['step' => 'wallet_tabs', 'wallet_tabs' => ['cashapp'], 'wallet_tabs_action' => 'save']);
assert_eq(302, $r['status'], 'anonymous step=wallet_tabs on a completed install is redirected');
assert_null(store_wallet_tabs(), 'the wallet-tabs answer was NOT written');
$r = $srv->post('/setup.php', ['step' => 'updates', 'auto_update' => '0']);
assert_eq(302, $r['status'], 'anonymous step=updates on a completed install is redirected');
assert_true(str_contains((string)($r['headers']['location'] ?? ''), 'admin'), 'redirect target is admin: ' . ($r['headers']['location'] ?? ''));
assert_null(cfg('auto_update_enabled'), 'the auto-update opt-in was NOT written');
assert_false(str_contains($r['body'], $cronKey), 'the cron key is not in the body');
assert_false(str_contains($r['body'], 'X-CRON-KEY'), 'no cron line in the body');

foreach (['cron', 'done'] as $tail) {
    $r = $srv->post('/setup.php', ['step' => $tail]);
    assert_eq(302, $r['status'], "anonymous step=$tail is redirected");
    assert_false(str_contains($r['body'], $cronKey), "step=$tail leaks no cron key");
}
$r = $srv->get('/setup.php?step=cron');
assert_eq(302, $r['status'], 'anonymous GET of the cron screen is redirected');

// Even with the (stolen) csrf token of the completing session: a different
// session has no first-run marker and is refused before CSRF is consulted.
$r = $srv->post('/setup.php', ['step' => 'updates', 'auto_update' => '0', 'csrf_token' => $csrf]);
assert_eq(302, $r['status'], 'a foreign csrf token does not help');
assert_null(cfg('auto_update_enabled'), 'opt-in still unwritten');

// A stranger can't reach the rail screens by slug either (the Back links
// of the completing session).
$r = $srv->get('/setup.php?step=mints');
assert_eq(302, $r['status'], 'anonymous GET of a rail screen on a completed install is redirected');

// ---- the session that completed the wizard may finish the tail ----------------
$strangerJar = $srv->switchSession($sessionA);
// Back from the first tail screen lands on the last rail screen and renders
// it (a GET by slug) — the completing session is let through.
$r = $srv->get('/setup.php?step=mints');
assert_eq(200, $r['status'], 'the completing session may step Back onto the mints screen');
assert_eq('mints', HttpDriver::wizardStep($r['body']), 'and sees the mints screen');
assert_true(str_contains($r['body'], 'wizard-back-link'), 'the mints screen has a Back link');
$r = $post(['step' => 'wallet_tabs', 'wallet_tabs' => ['venmo', 'cashapp'], 'wallet_tabs_action' => 'save']);
assert_eq(200, $r['status'], 'the completing session answers the wallet-tabs screen');
assert_eq('updates', HttpDriver::wizardStep($r['body']), 'and is shown the updates screen');
assert_eq('cashapp,venmo', store_wallet_tabs(), 'the ticked wallets are written in canonical order');
$r = $post(['step' => 'updates', 'auto_update' => '1']);
assert_eq(200, $r['status'], 'the completing session answers the updates screen');
assert_eq('cron', HttpDriver::wizardStep($r['body']), 'and is shown the cron screen');
assert_true(str_contains($r['body'], 'wizard-back-link'), 'the cron screen has a Back link');
assert_true(str_contains($r['body'], $cronKey), 'the cron screen renders the cron key for the operator');
assert_true(cfg('auto_update_enabled') === true, 'the operator answer is written');
// The usage-statistics screen moved to the front of the wizard: it is no
// longer a tail screen, so nobody — not even a logged-in operator — can
// re-answer it on a completed install through setup.php.
$srv->switchSession($strangerJar);
$r = $srv->post('/setup.php', ['step' => 'analytics', 'usage_stats' => '3', 'csrf_token' => $csrf]);
assert_eq(302, $r['status'], 'anonymous step=analytics on a completed install is redirected');
assert_true(cfg('usage_stats_enabled') === false, 'the operator\'s decline is unchanged');
$srv->switchSession($sessionA);
$r = $post(['step' => 'cron']);
assert_eq(200, $r['status'], 'cron -> done for the completing session');
assert_true(str_contains($r['body'], 'all set'), 'done screen rendered');
// The done screen clears the first-run marker: the same session is now a
// stranger to the tail screens too.
$r = $srv->post('/setup.php', ['step' => 'updates', 'auto_update' => '0', 'csrf_token' => $csrf]);
assert_eq(302, $r['status'], 'after done the completing session is redirected like anyone else');
assert_true(cfg('auto_update_enabled') === true, 'opt-in unchanged');
$r = $srv->get('/setup.php?step=mints');
assert_eq(302, $r['status'], 'after done the completing session can no longer step Back onto a rail screen');
$srv->switchSession($strangerJar);

// ---- a logged-in user may finish the tail -----------------------------------
$r = $srv->post('/admin.php', ['action' => 'login', 'username' => 'admin', 'password' => 'gate-test-pw-1234']);
assert_eq(200, $r['status'], 'admin login works: ' . $r['body']);
$adminCsrf = json_decode($r['body'], true)['csrfToken'] ?? null;
assert_not_null($adminCsrf, 'login response carries the csrf token');
$r = $srv->get('/setup.php?step=updates');
assert_eq(302, $r['status'], 'a logged-in GET of a tail screen still goes to admin (no wizard re-entry)');
// A logged-in POST needs that session's csrf token; the admin login hands it out.
$r = $srv->post('/setup.php', ['step' => 'updates', 'auto_update' => '0', 'csrf_token' => $adminCsrf]);
assert_eq(200, $r['status'], 'a logged-in user may answer the updates screen');
assert_true(cfg('auto_update_enabled') === false, 'their answer is written');
// wallet_tabs is a tail screen too, but its store comes from the wizard
// session marker, which a plain admin login does not carry: refused with
// the generic error rather than silently writing to the wrong store.
$r = $srv->post('/setup.php', ['step' => 'wallet_tabs', 'wallet_tabs_action' => 'skip', 'csrf_token' => $adminCsrf]);
assert_true($r['status'] < 500, 'logged-in wallet_tabs POST without a wizard store does not crash');
assert_eq('cashapp,venmo', store_wallet_tabs(), 'and does not touch the store');
$r = $srv->post('/setup.php', ['step' => 'analytics', 'usage_stats' => '1', 'csrf_token' => $adminCsrf]);
assert_eq(302, $r['status'], 'the usage-statistics screen is not a tail screen: a logged-in POST is redirected to admin (the settings card owns it now)');
assert_true(cfg('usage_stats_enabled') === false, 'the opt-in is unchanged');

echo "ok\n";
