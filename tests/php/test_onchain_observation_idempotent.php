<?php
/**
 * OnchainPayments::upsertObservation() must be idempotent on (txid, vout). Two
 * concurrent pollers (the customer's ~2s browser poll and the cron pass) both
 * observe the same new UTXO; the old SELECT-then-INSERT let the loser fall
 * through to a plain INSERT that threw an uncaught UNIQUE violation (a 500 in
 * the browser path). The ON CONFLICT upsert must instead update the existing
 * row in place, never throw, and never reassign the first observer's invoice.
 *
 * upsertObservation is private; we drive it via reflection (the public entry
 * needs a live blockchain provider).
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require_once dirname(__DIR__, 2) . '/app/includes/onchain/payments.php';

fresh_db();
make_store('s1');
Database::insert('invoices', [
    'id' => 'invA', 'store_id' => 's1', 'status' => 'New',
    'amount' => '5000', 'currency' => 'sat', 'amount_sats' => 5000,
    'created_at' => time(), 'expiration_time' => time() + 3600,
]);
Database::insert('invoices', [
    'id' => 'invB', 'store_id' => 's1', 'status' => 'New',
    'amount' => '5000', 'currency' => 'sat', 'amount_sats' => 5000,
    'created_at' => time(), 'expiration_time' => time() + 3600,
]);

$m = new ReflectionMethod('OnchainPayments', 'upsertObservation');
$m->setAccessible(true);

$obs1 = new OnchainTxObservation('deadbeef', 0, 5000, 0, null);          // mempool
$obs2 = new OnchainTxObservation('deadbeef', 0, 5000, 3, 800000);        // 3 confs

// First observation inserts.
$m->invoke(null, 'invA', $obs1, 1000);
$rows = Database::fetchAll("SELECT * FROM onchain_payments WHERE txid='deadbeef' AND vout=0");
assert_eq(1, count($rows), 'one row after first observation');
assert_eq(0, (int)$rows[0]['confirmations'], 'starts at 0 confs');
assert_eq(1000, (int)$rows[0]['first_seen_at'], 'first_seen_at recorded');

// Second observation of the SAME utxo (racing poller) must upsert, not throw.
$threw = false;
try {
    $m->invoke(null, 'invA', $obs2, 2000);
} catch (Throwable $e) {
    $threw = true;
}
assert_false($threw, 'second observation does not throw on UNIQUE(txid,vout)');
$rows = Database::fetchAll("SELECT * FROM onchain_payments WHERE txid='deadbeef' AND vout=0");
assert_eq(1, count($rows), 'still one row after second observation');
assert_eq(3, (int)$rows[0]['confirmations'], 'confirmations updated');
assert_eq(800000, (int)$rows[0]['block_height'], 'block_height updated');
assert_eq(2000, (int)$rows[0]['last_seen_at'], 'last_seen_at updated');
assert_eq(1000, (int)$rows[0]['first_seen_at'], 'first_seen_at preserved');

// A racing observer that attributes the same utxo to a DIFFERENT invoice must
// not steal it — first writer's invoice_id wins.
$m->invoke(null, 'invB', $obs2, 3000);
$rows = Database::fetchAll("SELECT * FROM onchain_payments WHERE txid='deadbeef' AND vout=0");
assert_eq(1, count($rows), 'still one row after cross-invoice observation');
assert_eq('invA', $rows[0]['invoice_id'], 'first observer keeps the attribution');

echo "PASS test_onchain_observation_idempotent\n";
exit(0);
