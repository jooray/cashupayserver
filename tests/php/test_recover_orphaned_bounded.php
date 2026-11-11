<?php
/**
 * AV-3 (E6): Invoice::recoverOrphanedInvoices() is bounded — at most 50 rows
 * per call, a 300 s per-row cooldown stamped on last_polled_at, and a
 * \Throwable on one row (a store whose mint URL makes the wallet constructor
 * throw) never aborts the rest.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
define('CASHUPAY_IN_CRON', true);
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/invoice.php';

make_store('s1', 'http://127.0.0.1:1'); // dead mint: getWalletForStore fails at loadMint
Database::update('stores', ['seed_phrase' => 'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about'], 'id = ?', ['s1']);
$now = time();
for ($i = 0; $i < 60; $i++) {
    Database::insert('invoices', [
        'id' => sprintf('inv_%03d', $i), 'store_id' => 's1', 'status' => 'Processing', 'amount' => '500',
        'currency' => 'sat', 'amount_sats' => 500, 'quote_id' => "q$i", 'mint_url' => 'http://127.0.0.1:1',
        'created_at' => $now - 7200 + $i, 'expiration_time' => $now + 600,
    ]);
}
// One row whose store has no wallet seed: getWalletForStore() throws for it;
// the per-row catch must keep the batch going.
Database::insert('stores', ['id' => 's_broken', 'name' => 'broken', 'mint_url' => 'http://127.0.0.1:1', 'created_at' => $now]);
Database::query("UPDATE invoices SET store_id = 's_broken' WHERE id = 'inv_010'");

function stamped(): int {
    $r = Database::fetchOne("SELECT COUNT(*) AS c FROM invoices WHERE last_polled_at IS NOT NULL");
    return (int)($r['c'] ?? 0);
}

$recovered = Invoice::recoverOrphanedInvoices();
assert_eq([], $recovered, 'nothing recoverable at a dead mint');
assert_eq(50, stamped(), 'first call touches exactly 50 rows');
assert_not_null(Database::fetchOne("SELECT last_polled_at FROM invoices WHERE id = 'inv_010'")['last_polled_at'], 'the failing row was stamped and skipped without aborting the batch');

$firstStamps = array_column(Database::fetchAll("SELECT id FROM invoices WHERE last_polled_at IS NOT NULL ORDER BY id"), 'id');
assert_eq('inv_000', $firstStamps[0], 'oldest rows first');
assert_eq('inv_049', $firstStamps[49], 'exactly the first 50 by age');

// Immediate second call: the 50 are cooling down; only the 10 untouched rows
// are eligible.
Invoice::recoverOrphanedInvoices();
assert_eq(60, stamped(), 'second call stamps the remaining 10 (cooldown holds the first 50)');
$r = Database::fetchOne("SELECT COUNT(*) AS c FROM invoices WHERE last_polled_at >= ?", [$now]);
assert_eq(60, (int)$r['c'], 'all 60 now stamped in this run');

// Third call: nothing is eligible.
$before = Database::fetchAll("SELECT id, last_polled_at FROM invoices ORDER BY id");
Invoice::recoverOrphanedInvoices();
$after = Database::fetchAll("SELECT id, last_polled_at FROM invoices ORDER BY id");
assert_eq($before, $after, 'third call touches nothing while every row is cooling down');

// Cooldown elapsed → eligible again, still capped at 50 per call.
Database::query("UPDATE invoices SET last_polled_at = ?", [$now - 301]);
Invoice::recoverOrphanedInvoices();
$r = Database::fetchOne("SELECT COUNT(*) AS c FROM invoices WHERE last_polled_at >= ?", [$now]);
assert_eq(50, (int)$r['c'], 'after the cooldown a call re-visits at most 50 rows');

// A settled row is never stamped by this path (status-guarded stamp).
Database::query("UPDATE invoices SET status = 'Settled', last_polled_at = NULL WHERE id = 'inv_059'");
Database::query("UPDATE invoices SET last_polled_at = ? WHERE id <> 'inv_059'", [$now - 301]);
Invoice::recoverOrphanedInvoices();
assert_null(Database::fetchOne("SELECT last_polled_at FROM invoices WHERE id = 'inv_059'")['last_polled_at'], 'Settled row untouched');

fwrite(STDERR, "test_recover_orphaned_bounded: all assertions passed\n");
