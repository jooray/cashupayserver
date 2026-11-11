<?php
/**
 * MeltLog::record persists what it's given. Used by every successful melt
 * site (user withdraw, auto-melt, the three fee paths).
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/dev_fee.php';

$store = 'store_log';
make_store($store, 'https://m.example.com');

// User withdrawal: note=NULL
MeltLog::record($store, 50000, 25, 'user@dest.com', 'preimage_abc', null);
$row = Database::fetchOne("SELECT * FROM melts WHERE store_id = ?", [$store]);
assert_not_null($row, 'row inserted');
assert_eq(50000, (int)$row['amount_sats']);
assert_eq(25, (int)$row['network_fee_sats']);
assert_eq('user@dest.com', $row['destination']);
assert_eq('preimage_abc', $row['preimage']);
assert_eq(null, $row['note']);

// Fee payments use tagged notes.
MeltLog::record($store, 2500, 0, 'https://sink', null, FEE_NOTE_UPSTREAM);
MeltLog::record($store, 5000, 30, 'fees@getbarebits.com', 'p1', FEE_NOTE_DEV);
MeltLog::record($store, 7500, 40, 'host@op.com', 'p2', FEE_NOTE_HOSTING);

$rows = Database::fetchAll("SELECT note FROM melts WHERE store_id = ? ORDER BY id", [$store]);
assert_eq(4, count($rows));
assert_eq(null, $rows[0]['note']);
assert_eq(FEE_NOTE_UPSTREAM, $rows[1]['note']);
assert_eq(FEE_NOTE_DEV, $rows[2]['note']);
assert_eq(FEE_NOTE_HOSTING, $rows[3]['note']);

// Negative values clamped to 0 (defensive).
MeltLog::record($store, -100, -50, 'whatever', null, null);
$last = Database::fetchOne("SELECT * FROM melts WHERE store_id = ? ORDER BY id DESC LIMIT 1", [$store]);
assert_eq(0, (int)$last['amount_sats']);
assert_eq(0, (int)$last['network_fee_sats']);
assert_eq(null, $last['strike_invoice_id'], 'no Strike id unless the melt paid a Strike quote');

// Strike cashout: the Strike invoice id created for the payout is recorded
// for dashboard reconciliation.
MeltLog::record($store, 12000, 15, 'Strike API (…ABCD)', 'p3', null, 'strk_inv_123');
$last = Database::fetchOne("SELECT * FROM melts WHERE store_id = ? ORDER BY id DESC LIMIT 1", [$store]);
assert_eq('strk_inv_123', $last['strike_invoice_id'], 'strike_invoice_id persisted');
assert_eq('Strike API (…ABCD)', $last['destination']);

echo "test_melt_log_record: ok\n";
