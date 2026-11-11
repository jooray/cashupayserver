<?php
/**
 * Owed amounts below CASHUPAY_FEE_SETTLE_THRESHOLD_SATS (1000) do not fire
 * a payment. settleStore() returns outcomes only for fees that crossed the
 * threshold. (We can't end-to-end pay an LNURL in the test harness, so we
 * verify the gating decision via computeOwed before/after threshold.)
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/dev_fee.php';

$store = 'store_threshold';
make_store($store, 'https://m.example.com');
Config::set('fee_tracking_start_at', 0);

function add_paid_invoice(string $storeId, int $sats): void {
    Database::insert('invoices', [
        'id' => 'inv_' . bin2hex(random_bytes(4)),
        'store_id' => $storeId,
        'status' => 'Settled',
        'amount' => (string) $sats,
        'currency' => 'sat',
        'amount_sats' => $sats,
        'created_at' => time(),
        'expiration_time' => time() + 3600,
    ]);
}

// Revenue = 100 sats. dev_owed=1, hosting_owed=0. All below 1000-sat threshold.
add_paid_invoice($store, 100);
$o = DevFee::computeOwed($store);
assert_eq(100, $o['revenue']);
assert_true($o['dev_owed'] < 1000, 'dev below threshold');
assert_true($o['hosting_owed'] < 1000, 'hosting below threshold');

// Bump revenue so dev fee crosses 1000 (need dev_owed ≥ 1000 → revenue ≥ 100000).
//   revenue = 250_000 sats
//   dev_base = 250000 - 0 - 0 = 250000 → floor * 0.01 = 2500 ← ≥ 1000 ✓
//   hosting_owed = 0 (hosting % still 0)
add_paid_invoice($store, 249900);
$o = DevFee::computeOwed($store);
assert_eq(250000, $o['revenue']);
assert_true($o['dev_owed'] >= 1000, 'dev now above threshold');
assert_eq(0, $o['hosting_owed'], 'hosting still zero (no % set)');

// Hosting fee crosses threshold once configured at ≥ 0.4%:
//   hosting_owed = floor(250000 * 0.4 / 100) = 1000  ← ≥ 1000 ✓
Database::update('stores', ['hosting_fee_percent' => 0.4], 'id = ?', [$store]);
$o = DevFee::computeOwed($store);
assert_true($o['hosting_owed'] >= 1000, 'hosting crosses threshold at 0.4%');

// And at 0.3% it does NOT cross:
//   hosting_owed = floor(250000 * 0.3 / 100) = 750  ← below threshold
Database::update('stores', ['hosting_fee_percent' => 0.3], 'id = ?', [$store]);
$o = DevFee::computeOwed($store);
assert_true($o['hosting_owed'] < 1000, 'hosting does not cross at 0.3%');

echo "test_fee_threshold: ok\n";
