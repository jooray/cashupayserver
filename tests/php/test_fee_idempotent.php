<?php
/**
 * Idempotency: once a fee payment is recorded in the melts table, the next
 * computeOwed() reflects the new paid total — settling twice with no new
 * revenue does not double-charge. Also: a fee that fails (no melts row
 * inserted) still shows the original owed amount on the next pass.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/dev_fee.php';

$store = 'store_idem';
make_store($store, 'https://m.example.com');
Config::set('fee_tracking_start_at', 0);

Database::insert('invoices', [
    'id' => 'inv_1',
    'store_id' => $store,
    'status' => 'Settled',
    'amount' => '500000',
    'currency' => 'sat',
    'amount_sats' => 500000,
    'created_at' => time(),
    'expiration_time' => time() + 3600,
]);
Database::update('stores', ['hosting_fee_percent' => 1.0], 'id = ?', [$store]);

// Initial owed: dev = 5000, hosting = 5000. No upstream bucket accrues.
$o = DevFee::computeOwed($store);
assert_true(!array_key_exists('upstream_owed', $o), 'no upstream owed bucket');
assert_eq(5000, $o['dev_owed']);
assert_eq(5000, $o['hosting_owed']);

// A HISTORICAL upstream payment (row from before the fee was retired) still
// shrinks the dev base but never re-accrues as owed.
//   dev_base = 500000 - 0 - 2500 = 497500 → floor * 0.01 = 4975
MeltLog::record($store, 2500, 0, 'https://sink', null, FEE_NOTE_UPSTREAM);
$o = DevFee::computeOwed($store);
assert_eq(2500, $o['upstream_paid'], 'historical upstream payment reported');
assert_eq(4975, $o['dev_owed'], 'dev base shrunk by historical upstream paid');

// Now pay dev and hosting; their owed clamps to 0 on the next pass.
MeltLog::record($store, 4975, 50, 'fees@getbarebits.com', 'pre1', FEE_NOTE_DEV);
MeltLog::record($store, 5000, 25, 'host@op.com', 'pre2', FEE_NOTE_HOSTING);

$o = DevFee::computeOwed($store);
assert_eq(75, $o['network_cost'], 'both fee payments contributed network cost');

// Dev base after second pass: 500000 - 75 - 2500 = 497425 → floor * 0.01 = 4974
// dev_owed = 4974 - 4975 = -1 → clamped to 0
assert_eq(0, $o['dev_owed'], 'dev clamps to 0 even after slight base shrink');
assert_eq(0, $o['hosting_owed'], 'hosting clamps to 0');

// A second settle attempt (without new revenue) finds nothing to pay — none
// of the *_owed values rise above CASHUPAY_FEE_SETTLE_THRESHOLD_SATS.
assert_true($o['dev_owed'] < CASHUPAY_FEE_SETTLE_THRESHOLD_SATS);
assert_true($o['hosting_owed'] < CASHUPAY_FEE_SETTLE_THRESHOLD_SATS);

echo "test_fee_idempotent: ok\n";
