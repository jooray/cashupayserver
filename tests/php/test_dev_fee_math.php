<?php
/**
 * DevFee::computeOwed math. Verifies the dev / hosting formulas work together
 * with the network-cost decrement, and that HISTORICAL upstream-fee payments
 * (the retired 0.5% fee) still shrink the dev-fee base without any new
 * upstream amount accruing.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/dev_fee.php';

$store = 'store_math';
make_store($store, 'https://m.example.com');

// Force a known fee_tracking_start_at so the harness's idempotent migration
// doesn't pin the cutoff to "now" (which would exclude our test invoices).
Config::set('fee_tracking_start_at', 0);

/**
 * Insert a paid invoice with a sat-denominated amount. Helper that ensures
 * created_at > fee_tracking_start_at so the row is in scope for the math.
 */
function paid_invoice(string $storeId, int $sats, int $createdAt): void {
    Database::insert('invoices', [
        'id' => 'inv_' . bin2hex(random_bytes(4)),
        'store_id' => $storeId,
        'status' => 'Settled',
        'amount' => (string) $sats,
        'currency' => 'sat',
        'amount_sats' => $sats,
        'created_at' => $createdAt,
        'expiration_time' => $createdAt + 3600,
    ]);
}

// 1. Empty store: nothing owed. The retired upstream fee never appears as an
//    owed bucket.
$o = DevFee::computeOwed($store);
assert_eq(0, $o['revenue'], 'no revenue');
assert_true(!array_key_exists('upstream_owed', $o), 'upstream_owed bucket removed');
assert_eq(0, $o['dev_owed']);
assert_eq(0, $o['hosting_owed']);

// 2. 100k sats revenue, no network costs, no hosting fee.
//    dev      = 100000 * 0.01 = 1000 sats
//    hosting  = 100000 * 0 / 100 = 0
paid_invoice($store, 100000, time());
$o = DevFee::computeOwed($store);
assert_eq(100000, $o['revenue']);
assert_eq(1000, $o['dev_owed'], 'dev 1% of revenue');
assert_eq(0, $o['hosting_owed']);

// 3. Historical upstream payments (rows written before the fee was retired)
//    still shrink the dev-fee base — existing deployments must not see their
//    dev fee retroactively increase.
//    dev base = 100000 - 0 - 500 = 99500 → floor(99500 * 0.01) = 995
Database::insert('melts', [
    'store_id' => $store,
    'amount_sats' => 500,
    'network_fee_sats' => 0,
    'destination' => 'https://cypherpunk.today/donation-sink/donation-sink.php',
    'preimage' => null,
    'note' => FEE_NOTE_UPSTREAM,
    'created_at' => time(),
]);
$o = DevFee::computeOwed($store);
assert_eq(500, $o['upstream_paid'], 'historical upstream payment still reported');
assert_eq(995, $o['dev_owed'], 'dev base shrunk by historical upstream paid');

// 4. Network cost from a user withdraw further reduces the dev base.
//    dev base = 100000 - 100 - 500 = 99400 → floor(99400 * 0.01) = 994
Database::insert('melts', [
    'store_id' => $store,
    'amount_sats' => 50000,
    'network_fee_sats' => 100,
    'destination' => 'user@somewhere.com',
    'preimage' => 'abc',
    'note' => null,
    'created_at' => time(),
]);
$o = DevFee::computeOwed($store);
assert_eq(100, $o['network_cost']);
assert_eq(994, $o['dev_owed'], 'dev base reflects network cost + historical upstream paid');

// 5. Hosting fee is flat over revenue (does NOT subtract network costs).
//    With 2% hosting: 100000 * 0.02 = 2000
Database::update('stores', ['hosting_fee_percent' => 2.0], 'id = ?', [$store]);
$o = DevFee::computeOwed($store);
assert_eq(2000, $o['hosting_owed'], 'hosting flat-over-revenue, ignores network cost');

// 6. After hosting paid, owed clamps to 0.
Database::insert('melts', [
    'store_id' => $store,
    'amount_sats' => 2000,
    'network_fee_sats' => 5,
    'destination' => 'host@somewhere.com',
    'preimage' => 'def',
    'note' => FEE_NOTE_HOSTING,
    'created_at' => time(),
]);
$o = DevFee::computeOwed($store);
assert_eq(2000, $o['hosting_paid']);
assert_eq(0, $o['hosting_owed']);
// The hosting payment also added 5 sats to network cost, shrinking dev base:
// dev base = 100000 - 105 - 500 = 99395 → floor * 0.01 = 993
assert_eq(105, $o['network_cost'], 'hosting payment network fee counted');
assert_eq(993, $o['dev_owed'], 'dev base shrunk by hosting payment network fee');

echo "test_dev_fee_math: ok\n";
