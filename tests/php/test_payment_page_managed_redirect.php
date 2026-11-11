<?php
/**
 * Managed-install redirect shaping on the payment page.
 *
 * When a deployment provisions CASHUPAY_SHOP_URL, payer-facing exits prefer
 * the shop's front page: an invoice with no checkout redirect gets the shop
 * URL, and a redirect pointing at the install's own login-gated admin/setup
 * surfaces is rewritten there too — render-time, so invoices stored before
 * the managed split are covered. The prefix match is boundary-checked
 * (/admin, /admin.php, /admin/…, /admin?…) so an unrelated page that merely
 * starts with the same string survives. Without a shop URL nothing changes.
 *
 * payment.php echoes and exits, so each scenario runs in a subprocess; the
 * shop URL travels as the CASHUPAY_SHOP_URL env var scoped to that one
 * command. The effective redirect is read back off the rendered page's
 * `const redirectUrl = …;` line, which is fed by the same variable the
 * Return-to-Shop buttons render from.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
$dataDir = fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/config.php';

make_store('store_redir');

// Base URL under the runner's fixed superglobals is http://pay.test (same
// autodetection the email-gate test rides), so admin/setup surfaces live at
// http://pay.test/admin… and http://pay.test/setup… .
const T_BASE = 'http://pay.test';
const T_SHOP = 'https://shop.example/front';

/** One invoice per scenario, differing only in checkout_config.redirectURL. */
function make_invoice(string $id, ?string $redirectUrl): void {
    Database::insert('invoices', [
        'id' => $id,
        'store_id' => 'store_redir',
        'status' => 'Settled',
        'amount' => '21',
        'currency' => 'sat',
        'checkout_config' => $redirectUrl === null
            ? null
            : json_encode(['redirectURL' => $redirectUrl]),
        'created_at' => Database::timestamp(),
        'expiration_time' => Database::timestamp() + 3600,
        'paid_at' => Database::timestamp(),
    ]);
}

$root = dirname(__DIR__, 2) . '/app';
$runner = $dataDir . '/payment_redirect_runner.php';
file_put_contents($runner, sprintf(<<<'PHP'
<?php
declare(strict_types=1);
define('CASHUPAY_DATA_DIR', getenv('T_DATA_DIR'));
$_SERVER['HTTP_HOST'] = 'pay.test';
$_SERVER['SCRIPT_NAME'] = '/payment.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['id'] = getenv('T_INVOICE');
require %s;
PHP, var_export($root . '/payment.php', true)));

/**
 * Render the payment page for one invoice and return the effective redirect
 * URL the page embedded (null when the page renders no redirect).
 */
function rendered_redirect(string $invoiceId, ?string $shopUrl): ?string {
    global $dataDir, $runner;
    $env = 'T_DATA_DIR=' . escapeshellarg($dataDir)
        . ' T_INVOICE=' . escapeshellarg($invoiceId)
        . ($shopUrl !== null ? ' CASHUPAY_SHOP_URL=' . escapeshellarg($shopUrl) : '');
    $out = [];
    $rc = 0;
    exec($env . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' 2>&1', $out, $rc);
    $raw = implode("\n", $out);
    if ($rc !== 0) {
        fail("payment runner failed (rc=$rc): " . substr($raw, 0, 800));
    }
    if (!preg_match('/const redirectUrl = (.*?);\n/s', $raw, $m)) {
        fail('rendered page has no redirectUrl line: ' . substr($raw, 0, 800));
    }
    $value = json_decode($m[1], true);
    return $value === null ? null : (string)$value;
}

// --- Standalone (no shop URL): nothing is shaped -----------------------------

make_invoice('inv_none', null);
assert_null(rendered_redirect('inv_none', null), 'standalone: no redirect stays no redirect');

make_invoice('inv_plain', 'https://elsewhere.example/thanks');
assert_eq('https://elsewhere.example/thanks', rendered_redirect('inv_plain', null),
    'standalone: a stored redirect renders unchanged');

make_invoice('inv_admin_standalone', T_BASE . '/admin/dashboard');
assert_eq(T_BASE . '/admin/dashboard', rendered_redirect('inv_admin_standalone', null),
    'standalone: even an admin-surface redirect is left alone (return-to-admin convenience)');

// --- Managed (shop URL provisioned) ------------------------------------------

// No redirect at all → the shop's front page.
assert_eq(T_SHOP, rendered_redirect('inv_none', T_SHOP),
    'managed: an invoice with no redirect gets the shop URL');

// Admin-surface redirects are rewritten in every boundary form the SPA and
// its history could have stored.
foreach ([
    'inv_admin_bare'  => T_BASE . '/admin',
    'inv_admin_php'   => T_BASE . '/admin.php',
    'inv_admin_view'  => T_BASE . '/admin/dashboard',
    'inv_admin_query' => T_BASE . '/admin?view=invoices',
    'inv_setup_php'   => T_BASE . '/setup.php?step=done',
    'inv_setup_bare'  => T_BASE . '/setup',
] as $id => $stored) {
    make_invoice($id, $stored);
    assert_eq(T_SHOP, rendered_redirect($id, T_SHOP),
        "managed: {$stored} is a login-gated surface and rewrites to the shop URL");
}

// Boundary check: a page that merely starts with the same string is NOT ours.
make_invoice('inv_admin_guide', T_BASE . '/administration-guide');
assert_eq(T_BASE . '/administration-guide', rendered_redirect('inv_admin_guide', T_SHOP),
    'managed: an unrelated path sharing the /admin prefix survives');

// An off-site redirect (the WooCommerce order-received URL in real life) is
// the shop's own choice and passes through untouched.
assert_eq('https://elsewhere.example/thanks', rendered_redirect('inv_plain', T_SHOP),
    'managed: a foreign redirect is not rewritten');

// A stored redirect that fails re-validation (javascript: etc.) collapses to
// null first, and the managed default then lands on the shop URL — never the
// hostile value, never a dead end.
make_invoice('inv_hostile', 'javascript:alert(1)');
assert_eq(T_SHOP, rendered_redirect('inv_hostile', T_SHOP),
    'managed: a non-http(s) stored redirect ends at the shop URL');
assert_null(rendered_redirect('inv_hostile', null),
    'standalone: the same hostile redirect renders as no redirect at all');

echo "test_payment_page_managed_redirect: ok\n";
