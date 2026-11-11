<?php
/**
 * Default payment method on the customer payment page.
 *
 * When an invoice offers both on-chain and Lightning, the on-chain QR must be
 * the one shown on load: its tab is active and listed first, its block is
 * visible, and the Lightning block starts hidden (still selectable via the
 * tabs). Lightning-only invoices keep Lightning as the default with no tab
 * bar at all.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
$dataDir = fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/config.php';

make_store('store_def');

// Both rails offered: bolt11 + allocated on-chain address.
Database::insert('invoices', [
    'id' => 'inv_both',
    'store_id' => 'store_def',
    'status' => 'New',
    'amount' => '21000',
    'currency' => 'sat',
    'amount_sats' => 21000,
    'bolt11' => 'lnbc210u1pexamplebolt11string',
    'payment_rail' => 'mint',
    'onchain_address' => 'bc1qexampledefaultmethodaddr0000000000',
    'onchain_amount_sat' => 21000,
    'created_at' => Database::timestamp(),
    'expiration_time' => Database::timestamp() + 3600,
]);

// Lightning only.
Database::insert('invoices', [
    'id' => 'inv_ln_only',
    'store_id' => 'store_def',
    'status' => 'New',
    'amount' => '21000',
    'currency' => 'sat',
    'amount_sats' => 21000,
    'bolt11' => 'lnbc210u1pexamplebolt11string',
    'payment_rail' => 'mint',
    'created_at' => Database::timestamp(),
    'expiration_time' => Database::timestamp() + 3600,
]);

// On-chain only (store presenting no Lightning invoice).
Database::insert('invoices', [
    'id' => 'inv_oc_only',
    'store_id' => 'store_def',
    'status' => 'New',
    'amount' => '21000',
    'currency' => 'sat',
    'amount_sats' => 21000,
    'payment_rail' => 'onchain',
    'onchain_address' => 'bc1qexampledefaultmethodaddr0000000000',
    'onchain_amount_sat' => 21000,
    'created_at' => Database::timestamp(),
    'expiration_time' => Database::timestamp() + 3600,
]);

$root = dirname(__DIR__, 2) . '/app';
$runner = $dataDir . '/payment_runner.php';
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

/** Run payment.php in a subprocess; returns its full output. */
function run_payment_page(string $invoiceId): string {
    global $dataDir, $runner;
    $env = sprintf('T_DATA_DIR=%s T_INVOICE=%s', escapeshellarg($dataDir), escapeshellarg($invoiceId));
    return (string)shell_exec($env . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' 2>&1');
}

// --- both rails: on-chain default, Lightning selectable but hidden ---------
$html = run_payment_page('inv_both');
assert_true(str_contains($html, 'class="method-tab active" data-method="onchain"'),
    'on-chain tab active by default');
assert_true(str_contains($html, 'class="method-tab " data-method="lightning"'),
    'lightning tab present but inactive');
assert_true(str_contains($html, 'class="method-block " data-method-block="onchain"'),
    'on-chain block visible on load');
assert_true(str_contains($html, 'class="method-block hidden" data-method-block="lightning"'),
    'lightning block starts hidden');
assert_true(str_contains($html, 'id="payment-methods" data-method="onchain"'),
    'brand row keyed to on-chain on load');
$ocTab = strpos($html, 'data-method="onchain" role="tab"');
$lnTab = strpos($html, 'data-method="lightning" role="tab"');
assert_true($ocTab !== false && $lnTab !== false && $ocTab < $lnTab,
    'on-chain tab listed before lightning');

// --- Lightning only: falls back to lightning, no tab bar --------------------
$html = run_payment_page('inv_ln_only');
assert_true(str_contains($html, 'class="method-block " data-method-block="lightning"'),
    'lightning-only invoice shows lightning block');
assert_false(str_contains($html, 'role="tablist"'), 'single method renders no tab bar');
assert_true(str_contains($html, 'id="payment-methods" data-method="lightning"'),
    'brand row keyed to lightning for lightning-only invoice');

// --- on-chain only: on-chain shown, no tab bar ------------------------------
$html = run_payment_page('inv_oc_only');
assert_true(str_contains($html, 'class="method-block " data-method-block="onchain"'),
    'on-chain-only invoice shows on-chain block');
assert_false(str_contains($html, 'role="tablist"'), 'single method renders no tab bar');

// ---------------------------------------------------------------------------
// Store "Payment order" = Lightning first (stores.invoice_first_method).
// ---------------------------------------------------------------------------
make_store('store_ln_first');
Database::update('stores', ['invoice_first_method' => 'lightning'], 'id = ?', ['store_ln_first']);
foreach (['inv_both', 'inv_oc_only'] as $src) {
    $row = Database::fetchOne("SELECT * FROM invoices WHERE id = ?", [$src]);
    unset($row['id'], $row['store_id']);
    Database::insert('invoices', ['id' => $src . '_lnf', 'store_id' => 'store_ln_first'] + $row);
}

// Both rails: Lightning active, listed first, on-chain still selectable.
$html = run_payment_page('inv_both_lnf');
assert_true(str_contains($html, 'class="method-tab active" data-method="lightning"'),
    'lightning-first store: lightning tab active by default');
assert_true(str_contains($html, 'class="method-tab " data-method="onchain"'),
    'lightning-first store: on-chain tab present but inactive');
assert_true(str_contains($html, 'class="method-block " data-method-block="lightning"'),
    'lightning-first store: lightning block visible on load');
assert_true(str_contains($html, 'class="method-block hidden" data-method-block="onchain"'),
    'lightning-first store: on-chain block starts hidden');
assert_true(str_contains($html, 'id="payment-methods" data-method="lightning"'),
    'lightning-first store: brand row keyed to lightning on load');
$ocTab = strpos($html, 'data-method="onchain" role="tab"');
$lnTab = strpos($html, 'data-method="lightning" role="tab"');
assert_true($ocTab !== false && $lnTab !== false && $lnTab < $ocTab,
    'lightning-first store: lightning tab listed before on-chain');

// On-chain only: the preference can't apply, falls back to on-chain.
$html = run_payment_page('inv_oc_only_lnf');
assert_true(str_contains($html, 'class="method-block " data-method-block="onchain"'),
    'lightning-first store: on-chain-only invoice still shows on-chain block');
assert_true(str_contains($html, 'id="payment-methods" data-method="onchain"'),
    'lightning-first store: brand row keyed to on-chain for on-chain-only invoice');
assert_false(str_contains($html, 'role="tablist"'), 'lightning-first store: single method renders no tab bar');

// An explicit 'onchain' value and a junk value both behave like NULL.
foreach (['onchain', 'bogus', ''] as $v) {
    Database::update('stores', ['invoice_first_method' => $v], 'id = ?', ['store_ln_first']);
    $html = run_payment_page('inv_both_lnf');
    assert_true(str_contains($html, 'class="method-tab active" data-method="onchain"'),
        "invoice_first_method=" . var_export($v, true) . " resolves to on-chain first");
}

echo "test_payment_page_default_method: ok\n";
