<?php
/**
 * A checkout that cannot start is recorded for the operator's dashboard, and the shop
 * gets a message it can show a customer, without mint or network internals (A11, C14,
 * 2026-10-28).
 *
 * Run: php tests/checkout_failures.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

$dataDir = sys_get_temp_dir() . '/cashupay-checkoutfail-' . bin2hex(random_bytes(8));
mkdir($dataDir, 0700, true);
define('CASHUPAY_DATA_DIR', $dataDir);
register_shutdown_function(fn() => exec('rm -rf ' . escapeshellarg($dataDir)));

require_once dirname(__DIR__) . '/cashu-wallet-php/CashuWallet.php';
require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/invoice.php';

function check(bool $cond, string $what): void {
    if (!$cond) { fwrite(STDERR, "FAIL: $what\n"); exit(1); }
    echo "  ok: $what\n";
}

Database::initialize();
Config::set('allow_private_mints', true);
Database::insert('stores', [
    'id' => 's1', 'name' => 'Yarn Shop', 'wallet_account_id' => Database::generateWalletAccountId(),
    'mint_url' => 'http://127.0.0.1:1', 'mint_unit' => 'sat',
    'seed_phrase' => 'legal winner thank year wave sausage worth useful legal winner thank yellow',
    'created_at' => Database::timestamp(),
]);

echo "A mint that does not answer stops the checkout, and the operator is told\n";
$error = null;
try {
    Invoice::create('s1', ['amount' => 21, 'currency' => 'sat']);
} catch (Exception $e) {
    $error = $e;
}
check($error !== null, 'invoice creation fails with the mint down');
$public = Invoice::recordCheckoutFailure('s1', $error);
check($public === Invoice::CHECKOUT_UNAVAILABLE_MESSAGE, 'the shop gets the plain customer message');
check(!str_contains($public, '127.0.0.1') && !str_contains(strtolower($public), 'curl'), 'without network internals');
$f = Config::get('checkout_failures_s1');
check(is_array($f) && $f['reason'] === 'mint' && $f['count'] === 1, 'the dashboard record names the mint as the cause');
Invoice::recordCheckoutFailure('s1', $error);
check(Config::get('checkout_failures_s1')['count'] === 2, 'repeated failures are counted');

echo "Paused prices keep their own plain message\n";
$public = Invoice::recordCheckoutFailure('s1', new Exception(ExchangeRates::UNAVAILABLE_MESSAGE));
check($public === ExchangeRates::UNAVAILABLE_MESSAGE && Config::get('checkout_failures_s1')['reason'] === 'prices',
    'the price message is passed through and recorded as such');

echo "The next successful checkout clears it\n";
Invoice::recordCheckoutSuccess('s1');
check(Config::get('checkout_failures_s1') === null, 'the record is gone');

echo "checkout_failures: OK\n";
