<?php
/**
 * The wizard's "Popular wallet tabs" screen (setup.php step=wallet_tabs).
 *
 * First run: it follows the last rail screen (the install is already
 * complete), pre-ticks all three wallets on a store that never answered,
 * writes the ticked set (or '' on Skip) to stores.wallet_tabs, then hands
 * over to the auto-update question; stepping Back onto it shows what was
 * saved. add_store: it is the last screen, and answering it is what returns
 * the operator to admin (302) — the hand-off used to happen on mints.
 * Driven over real HTTP (php -S) because the handler redirects and sessions.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require __DIR__ . '/http_driver.php';

$dir = sys_get_temp_dir() . '/cashupay_test_' . bin2hex(random_bytes(6));
mkdir($dir, 0750, true);
define('CASHUPAY_DATA_DIR', $dir);
register_shutdown_function(function () use ($dir) { @cleanup_db($dir); });
require_once dirname(__DIR__, 2) . '/includes/config.php';
require_once dirname(__DIR__, 2) . '/includes/wallet_tabs.php';

$srv = HttpDriver::start($dir);

function wallet_tabs_of(string $name): ?string {
    $row = Database::fetchOne("SELECT wallet_tabs FROM stores WHERE name = ?", [$name]);
    return $row ? $row['wallet_tabs'] : null;
}
function heading(string $body): string {
    if (!preg_match('/<h2[^>]*>(.*?)<\/h2>/s', $body, $m)) { return ''; }
    $h = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5));
    // Drop a leading emoji / pictograph (anything >= U+2000) and whitespace.
    return trim(preg_replace('/^[\x{2000}-\x{10FFFF}\s]+/u', '', $h));
}
function checked(string $body, string $key): bool {
    return preg_match('/id="wallet_tab_' . $key . '" name="wallet_tabs\[\]" value="' . $key . '" checked>/', $body) === 1;
}

// ---- first run up to the wallet-tabs screen ----------------------------------
$r = $srv->get('/setup.php');
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
$post(['step' => 'security', 'security_acknowledged' => '1']);
$post(['step' => 'password', 'password' => 'wallet-tabs-pw-1234', 'confirm_password' => 'wallet-tabs-pw-1234']);
$post(['step' => 'email', 'email_action' => 'skip']);
$post(['step' => 'store', 'store_name' => 'Tabs Store', 'default_currency' => 'sat']);
$post(['step' => 'onchain', 'onchain_action' => 'skip']);
$post(['step' => 'lightning', 'lightning_action' => 'skip']);
$r = $post(['step' => 'mints', 'mints_enabled' => '0']);

// The screen itself.
assert_eq('wallet_tabs', HttpDriver::wizardStep($r['body']), 'declining mints lands on the wallet-tabs screen');
assert_eq('Popular wallet tabs', heading($r['body']), 'screen heading');
assert_true(str_contains($r['body'], 'Enable additional tabs on the payment page for these popular'), 'the operator-approved question');
assert_true(str_contains($r['body'], 'help guide customers in the'), 'the operator-approved rationale');
foreach (['cashapp', 'venmo', 'paypal'] as $k) {
    assert_true(checked($r['body'], $k), "$k is pre-ticked on a store that never answered");
    assert_true(str_contains($r['body'], 'payment-methods/' . WalletTabs::BRANDS[$k]['file']), "$k row shows the brand-row logo");
    assert_true(str_contains($r['body'], htmlspecialchars(WalletTabs::BRANDS[$k]['note'])), "$k row shows the app note");
}
assert_true(str_contains($r['body'], 'name="wallet_tabs_action" value="save"'), 'Continue button');
assert_true(str_contains($r['body'], 'name="wallet_tabs_action" value="skip"'), 'Skip button');
assert_true(str_contains($r['body'], 'wizard-back-link'), 'the screen has a Back link');
assert_true(str_contains($r['body'], 'step=mints'), 'Back leads to the last rail screen');
// Counter: this rig's data dir is outside the web root, so the security
// screen is dropped — 13 screens without zeroconf/seed (terms analytics
// install_type password email store onchain lightning mints wallet_tabs
// updates cron done), wallet_tabs the 10th.
assert_true(str_contains($r['body'], 'Step 10 of 13'), 'step counter counts the new screen: ' . (preg_match('/Step \d+ of \d+/', $r['body'], $m) ? $m[0] : '?'));
assert_null(wallet_tabs_of('Tabs Store'), 'nothing written until answered');
assert_true(Config::isSetupComplete(), 'the install is already complete on this screen');

// Unknown wallet refused, nothing written.
$bad = $srv->post('/setup.php', ['step' => 'wallet_tabs', 'wallet_tabs' => ['strike'], 'wallet_tabs_action' => 'save', 'csrf_token' => $csrf]);
assert_true(str_contains($bad['body'], 'Please choose from the listed wallets.'), 'an unknown wallet is refused');
assert_null(wallet_tabs_of('Tabs Store'), 'a refused answer writes nothing');

// Save two of three.
$r = $post(['step' => 'wallet_tabs', 'wallet_tabs' => ['paypal', 'cashapp'], 'wallet_tabs_action' => 'save']);
assert_eq('updates', HttpDriver::wizardStep($r['body']), 'Continue hands over to the auto-update question');
assert_eq('cashapp,paypal', wallet_tabs_of('Tabs Store'), 'ticked wallets written in canonical order');

// Back shows the saved selection, not the pre-tick.
$r = $srv->get('/setup.php?step=wallet_tabs');
assert_eq(200, $r['status'], 'the completing session may step Back onto the screen');
assert_true(checked($r['body'], 'cashapp'), 'saved: Cash App ticked');
assert_false(checked($r['body'], 'venmo'), 'saved: Venmo unticked');
assert_true(checked($r['body'], 'paypal'), 'saved: PayPal ticked');

// Skip writes an explicit none.
$r = $post(['step' => 'wallet_tabs', 'wallet_tabs' => ['venmo'], 'wallet_tabs_action' => 'skip']);
assert_eq('updates', HttpDriver::wizardStep($r['body']), 'Skip also hands over to the auto-update question');
assert_eq('', wallet_tabs_of('Tabs Store'), 'Skip writes an explicit "none" even with boxes ticked');
$r = $srv->get('/setup.php?step=wallet_tabs');
foreach (['cashapp', 'venmo', 'paypal'] as $k) {
    assert_false(checked($r['body'], $k), "$k unticked after Skip (answered: none)");
}

// Saving with every box unticked is "none" too.
$r = $post(['step' => 'wallet_tabs', 'wallet_tabs_action' => 'save']);
assert_eq('updates', HttpDriver::wizardStep($r['body']), 'Continue with nothing ticked advances');
assert_eq('', wallet_tabs_of('Tabs Store'), 'nothing ticked = none');

// Finish the first run so add_store can be tested on a completed install.
$post(['step' => 'updates', 'auto_update' => '0']);
$post(['step' => 'cron']);

// ---- add_store: the screen is last, and answering it hands off ----------------
$srv->newSession();
$r = $srv->post('/admin.php', ['action' => 'login', 'username' => 'admin', 'password' => 'wallet-tabs-pw-1234']);
assert_eq(200, $r['status'], 'admin login works: ' . $r['body']);
$r = $srv->get('/setup.php?mode=add_store');
assert_eq(200, $r['status'], 'add_store opens');
assert_true(str_contains($r['body'], 'Step 1 of 5'), 'add_store counts the wallet-tabs screen: ' . (preg_match('/Step \d+ of \d+/', $r['body'], $m) ? $m[0] : '?'));
$csrf = HttpDriver::wizardCsrf($r['body']);
$apost = function (array $data) use ($srv, &$csrf): array {
    $data['csrf_token'] = $csrf;
    $data['mode'] = 'add_store';
    $r = $srv->post('/setup.php', $data);
    assert_true($r['status'] < 400, 'add_store ' . ($data['step'] ?? '?') . ' -> ' . $r['status']);
    return $r;
};
$apost(['step' => 'store', 'store_name' => 'Second Store', 'default_currency' => 'sat']);
$apost(['step' => 'onchain', 'onchain_action' => 'skip']);
$apost(['step' => 'lightning', 'lightning_action' => 'skip']);
$r = $apost(['step' => 'mints', 'mints_enabled' => '0']);
assert_eq(200, $r['status'], 'declining mints in add_store no longer redirects');
assert_eq('wallet_tabs', HttpDriver::wizardStep($r['body']), 'add_store asks about wallet tabs after mints');
assert_eq('Popular wallet tabs', heading($r['body']), 'same screen in add_store');
assert_true(str_contains($r['body'], 'Step 5 of 5'), 'last add_store screen');
assert_not_null(Database::fetchOne("SELECT id FROM stores WHERE name = 'Second Store'"), 'the store exists before the screen');
assert_null(wallet_tabs_of('Second Store'), 'not answered yet');

// The screen's own form must carry the mode (the browser posts exactly
// what the form holds; a POST without it would be treated as first-run).
assert_true(str_contains($r['body'], '<input type="hidden" name="mode" value="add_store">'), 'the add_store form carries the mode field');
// Post it the way the browser does: no mode in the request beyond the form field.
$r = $srv->post('/setup.php', ['step' => 'wallet_tabs', 'wallet_tabs' => ['venmo'], 'wallet_tabs_action' => 'save', 'mode' => 'add_store', 'csrf_token' => $csrf]);
assert_eq(302, $r['status'], 'answering the last add_store screen hands off to admin');
assert_true(str_contains((string)($r['headers']['location'] ?? ''), 'store_created='), 'redirect carries store_created: ' . ($r['headers']['location'] ?? ''));
assert_eq('venmo', wallet_tabs_of('Second Store'), 'the add_store answer is written to the new store');
assert_eq('', wallet_tabs_of('Tabs Store'), 'the first store is untouched');

echo "ok\n";
