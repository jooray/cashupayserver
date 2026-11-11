<?php
/**
 * Write-ahead fee-melt idempotency (the dev-fee double-pay fix).
 *
 * A 'pending' intent written BEFORE the proofs are spent must count toward the
 * paid total exactly like a finalized payment, so a crash between the melt and
 * the would-be record can never make computeOwed() re-pay the fee on the next
 * tick. Finalizing keeps it counted (and adds the network fee); deleting it
 * (the mint says the melt never happened) restores the owed amount so it can
 * be retried.
 *
 * This is the DB-level guarantee that backs DevFee::reconcilePendingFeeMelts;
 * the reconcile pass itself queries the mint (covered by the live e2e flow).
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/dev_fee.php';

$store = 'store_wa';
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

// Baseline owed.
$o = DevFee::computeOwed($store);
assert_eq(5000, $o['dev_owed'], 'baseline dev owed');
assert_eq(5000, $o['hosting_owed'], 'baseline hosting owed');

// --- A pending intent suppresses re-pay (the crash-after-melt case) ---
$devIntent = MeltLog::recordPendingIntent($store, 5000, 'fees@getbarebits.com', FEE_NOTE_DEV, 'qid-dev');
$o = DevFee::computeOwed($store);
assert_eq(0, $o['dev_owed'], 'pending intent counts as paid -> dev not re-owed');
assert_eq(5000, $o['dev_paid'], 'pending intent included in dev_paid');

// Confirm it is actually stored as pending with the quote id (what reconcile keys on).
$row = Database::fetchOne("SELECT status, melt_quote_id, network_fee_sats, preimage FROM melts WHERE id = ?", [$devIntent]);
assert_eq('pending', $row['status'], 'intent row is pending');
assert_eq('qid-dev', $row['melt_quote_id'], 'intent carries the melt quote id');

// --- Finalize keeps it counted and records the network fee ---
MeltLog::finalizeIntent($devIntent, 50, 'preimage-dev');
$row = Database::fetchOne("SELECT status, network_fee_sats, preimage FROM melts WHERE id = ?", [$devIntent]);
assert_eq('paid', $row['status'], 'finalized -> paid');
assert_eq(50, (int)$row['network_fee_sats'], 'network fee recorded on finalize');
assert_eq('preimage-dev', $row['preimage'], 'preimage recorded on finalize');

$o = DevFee::computeOwed($store);
assert_eq(50, $o['network_cost'], 'finalized fee contributes network cost');
assert_eq(0, $o['dev_owed'], 'dev still not re-owed after finalize (no double pay)');

// finalizeIntent is idempotent: a second call on a now-paid row is a no-op.
MeltLog::finalizeIntent($devIntent, 999, 'other');
$row = Database::fetchOne("SELECT network_fee_sats, preimage FROM melts WHERE id = ?", [$devIntent]);
assert_eq(50, (int)$row['network_fee_sats'], 'finalize is idempotent (fee unchanged)');

// --- Delete reverts an intent the mint says never happened ---
$hostIntent = MeltLog::recordPendingIntent($store, 5000, 'host@op.com', FEE_NOTE_HOSTING, 'qid-host');
$o = DevFee::computeOwed($store);
assert_eq(0, $o['hosting_owed'], 'pending hosting intent suppresses hosting owed');

MeltLog::deleteIntent($hostIntent);
assert_null(Database::fetchOne("SELECT id FROM melts WHERE id = ?", [$hostIntent]), 'intent row removed');
$o = DevFee::computeOwed($store);
assert_eq(5000, $o['hosting_owed'], 'deleting the intent restores the owed amount for retry');

echo "test_fee_writeahead_idempotent: ok\n";
