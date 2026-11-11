<?php
/**
 * Invoices created BEFORE fee_tracking_start_at must NOT count toward the
 * dev-fee base. Migration sets the timestamp to "now" the first time the
 * schema runs, so pre-existing revenue is grandfathered out.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/dev_fee.php';

$store = 'store_cutoff';
make_store($store, 'https://m.example.com');

$cutoff = 1_700_000_000; // arbitrary epoch second
Config::set('fee_tracking_start_at', $cutoff);

// Pre-cutoff invoice — should be ignored.
Database::insert('invoices', [
    'id' => 'old',
    'store_id' => $store,
    'status' => 'Settled',
    'amount' => '1000000',
    'currency' => 'sat',
    'amount_sats' => 1_000_000,
    'created_at' => $cutoff - 1,
    'expiration_time' => $cutoff + 3600,
]);

$o = DevFee::computeOwed($store);
assert_eq(0, $o['revenue'], 'pre-cutoff invoices excluded');

// Post-cutoff invoice — counts.
Database::insert('invoices', [
    'id' => 'new',
    'store_id' => $store,
    'status' => 'Settled',
    'amount' => '500000',
    'currency' => 'sat',
    'amount_sats' => 500_000,
    'created_at' => $cutoff + 10,
    'expiration_time' => $cutoff + 3610,
]);

$o = DevFee::computeOwed($store);
assert_eq(500_000, $o['revenue'], 'post-cutoff invoices counted');

// Exactly-at-cutoff invoice (created_at == fee_tracking_start_at) DOES count
// — the SQL uses >=, so the boundary is inclusive.
Database::insert('invoices', [
    'id' => 'edge',
    'store_id' => $store,
    'status' => 'Settled',
    'amount' => '1000',
    'currency' => 'sat',
    'amount_sats' => 1000,
    'created_at' => $cutoff,
    'expiration_time' => $cutoff + 3610,
]);
$o = DevFee::computeOwed($store);
assert_eq(501_000, $o['revenue'], 'cutoff boundary inclusive');

// Unpaid invoices don't count.
Database::insert('invoices', [
    'id' => 'unpaid',
    'store_id' => $store,
    'status' => 'New',
    'amount' => '5000',
    'currency' => 'sat',
    'amount_sats' => 5000,
    'created_at' => $cutoff + 100,
    'expiration_time' => $cutoff + 3710,
]);
$o = DevFee::computeOwed($store);
assert_eq(501_000, $o['revenue'], 'unpaid invoices excluded');

echo "test_fee_tracking_start: ok\n";
