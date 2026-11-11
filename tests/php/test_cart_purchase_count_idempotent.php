<?php
/**
 * Cart::reconcileSettledCounts() bumps each product's purchase_count once per
 * settled cart invoice. The reconcile now CLAIMS each invoice with a
 * conditional flag-flip (cart_purchase_counted 0 -> 1) before applying the
 * increments, so two reconcile passes (cron overlapping a retry, or two cron
 * runs) can't both count the same invoice. This test proves the exactly-once
 * behaviour: a second pass is a no-op, and a pre-claimed invoice is skipped.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require_once dirname(__DIR__, 2) . '/app/includes/cart.php';

fresh_db();
make_store('s1');

Database::insert('products', [
    'id' => 'p1', 'store_id' => 's1', 'title' => 'Widget',
    'price' => '1000', 'currency' => 'sat',
    'created_at' => time(), 'updated_at' => time(),
]);

Database::insert('invoices', [
    'id' => 'inv1', 'store_id' => 's1', 'status' => 'Settled',
    'amount' => '3000', 'currency' => 'sat', 'amount_sats' => 3000,
    'created_at' => time(), 'expiration_time' => time() + 3600,
    'cart_purchase_counted' => 0,
]);
Database::insert('invoice_items', [
    'id' => 'it1', 'invoice_id' => 'inv1', 'store_id' => 's1', 'product_id' => 'p1',
    'title' => 'Widget', 'unit_price' => '1000', 'unit_currency' => 'sat',
    'quantity' => 3, 'amount_sats' => 3000, 'created_at' => time(),
]);

// First pass counts it once.
$n = Cart::reconcileSettledCounts();
assert_eq(1, $n, 'first pass counts one invoice');
$pc = (int)Database::fetchOne("SELECT purchase_count FROM products WHERE id='p1'")['purchase_count'];
assert_eq(3, $pc, 'purchase_count bumped by quantity');
$counted = (int)Database::fetchOne("SELECT cart_purchase_counted FROM invoices WHERE id='inv1'")['cart_purchase_counted'];
assert_eq(1, $counted, 'invoice marked counted');

// Second pass is a no-op (the flag gate excludes it) — no double count.
$n2 = Cart::reconcileSettledCounts();
assert_eq(0, $n2, 'second pass counts nothing');
$pc2 = (int)Database::fetchOne("SELECT purchase_count FROM products WHERE id='p1'")['purchase_count'];
assert_eq(3, $pc2, 'purchase_count unchanged on second pass');

// Direct gate test: a fresh invoice that a concurrent claimer has already
// flipped to counted=1 must be skipped — the conditional UPDATE matches 0 rows.
Database::insert('invoices', [
    'id' => 'inv2', 'store_id' => 's1', 'status' => 'Settled',
    'amount' => '1000', 'currency' => 'sat', 'amount_sats' => 1000,
    'created_at' => time(), 'expiration_time' => time() + 3600,
    'cart_purchase_counted' => 0,
]);
Database::insert('invoice_items', [
    'id' => 'it2', 'invoice_id' => 'inv2', 'store_id' => 's1', 'product_id' => 'p1',
    'title' => 'Widget', 'unit_price' => '1000', 'unit_currency' => 'sat',
    'quantity' => 5, 'amount_sats' => 1000, 'created_at' => time(),
]);
// Simulate the winning claimer flipping the flag before our pass reads it.
$claimed = Database::update('invoices', ['cart_purchase_counted' => 1],
    'id = ? AND cart_purchase_counted = 0', ['inv2']);
assert_eq(1, $claimed, 'concurrent claim wins the flag flip');
$n3 = Cart::reconcileSettledCounts();
assert_eq(0, $n3, 'pre-claimed invoice is not counted again');
$pc3 = (int)Database::fetchOne("SELECT purchase_count FROM products WHERE id='p1'")['purchase_count'];
assert_eq(3, $pc3, 'purchase_count not bumped for the pre-claimed invoice');

echo "PASS test_cart_purchase_count_idempotent\n";
exit(0);
