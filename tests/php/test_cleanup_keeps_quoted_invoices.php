<?php
/**
 * AV-5: cron Task 8 (Invoice::cleanupOldInvoices) never deletes an
 * Expired/Invalid row that still carries a quote_id — it is the only link to
 * a payment that may still be collected. Settled rows and quote-less
 * Expired/Invalid rows go by age; young rows are untouched.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/invoice.php';

make_store('s1', 'https://mint.example');
$now = time();
$mk = function (string $id, string $status, ?string $quote, int $age) use ($now): void {
    Database::insert('invoices', [
        'id' => $id, 'store_id' => 's1', 'status' => $status, 'amount' => '500', 'currency' => 'sat',
        'amount_sats' => 500, 'quote_id' => $quote, 'created_at' => $now - $age, 'expiration_time' => $now - $age + 900,
    ]);
};
$mk('settled_quoted', 'Settled', 'q1', 100 * 86400);
$mk('expired_quoted', 'Expired', 'q2', 100 * 86400);
$mk('invalid_quoted', 'Invalid', 'q3', 100 * 86400);
$mk('expired_bare', 'Expired', null, 100 * 86400);
$mk('invalid_empty', 'Invalid', '', 100 * 86400);
$mk('expired_young', 'Expired', null, 10 * 86400);
$mk('new_old', 'New', 'q4', 100 * 86400);

$deleted = Invoice::cleanupOldInvoices(90 * 86400);
assert_eq(3, $deleted, 'settled + bare expired + empty-quote invalid deleted');
$left = array_column(Database::fetchAll("SELECT id FROM invoices ORDER BY id"), 'id');
assert_eq(['expired_quoted', 'expired_young', 'invalid_quoted', 'new_old'], $left, 'quoted and young rows kept: ' . json_encode($left));

fwrite(STDERR, "test_cleanup_keeps_quoted_invoices: all assertions passed\n");
