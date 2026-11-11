<?php
/**
 * AV-3 (E5): the expiry sweep runs in bounded batches with per-row
 * status-guarded updates. 250 expired New invoices (plus one Settled) are all
 * flipped in one call with exactly one InvoiceExpired each; a second call
 * flips nothing; the 30-day bulk expiry goes through the same path and fires
 * InvoiceExpired too.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
define('CASHUPAY_IN_CRON', true);
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/invoice.php';

make_store('s1', 'https://mint.example');
Database::insert('webhooks', [
    'id' => 'wh1', 'store_id' => 's1', 'url' => 'http://127.0.0.1:1/never', 'secret' => 'shh',
    'events' => json_encode(['InvoiceExpired']), 'enabled' => 1, 'created_at' => Database::timestamp(),
]);
$now = time();
Database::beginImmediate();
for ($i = 0; $i < 250; $i++) {
    Database::insert('invoices', [
        'id' => sprintf('inv_%03d', $i), 'store_id' => 's1', 'status' => 'New', 'amount' => '500',
        'currency' => 'sat', 'amount_sats' => 500, 'created_at' => $now - 7200,
        'expiration_time' => $now - 60, // no quote → immediate expiry
    ]);
}
Database::insert('invoices', [
    'id' => 'inv_settled', 'store_id' => 's1', 'status' => 'Settled', 'amount' => '500',
    'currency' => 'sat', 'amount_sats' => 500, 'created_at' => $now - 7200, 'expiration_time' => $now - 60,
]);
Database::commit();

$count = Invoice::markExpiredInvoices();
assert_eq(250, $count, 'all 250 flipped in one call across batches');
$r = Database::fetchOne("SELECT COUNT(*) AS c FROM invoices WHERE status = 'Expired'");
assert_eq(250, (int)$r['c'], '250 rows Expired');
$r = Database::fetchOne("SELECT COUNT(*) AS c FROM webhook_deliveries WHERE event_type = 'InvoiceExpired'");
assert_eq(250, (int)$r['c'], '250 InvoiceExpired rows enqueued');
$r = Database::fetchOne("SELECT COUNT(*) AS c FROM webhook_deliveries WHERE invoice_id = 'inv_settled'");
assert_eq(0, (int)$r['c'], 'settled row untouched');
assert_false(Database::inTransaction(), 'no transaction left open');

assert_eq(0, Invoice::markExpiredInvoices(), 'second call flips nothing');
$r = Database::fetchOne("SELECT COUNT(*) AS c FROM webhook_deliveries WHERE event_type = 'InvoiceExpired'");
assert_eq(250, (int)$r['c'], 'no duplicate events');

// 30-day bulk expiry (cron Task 7) fires InvoiceExpired too.
Database::insert('invoices', [
    'id' => 'inv_ancient', 'store_id' => 's1', 'status' => 'New', 'amount' => '500', 'currency' => 'sat',
    'amount_sats' => 500, 'created_at' => $now - 31 * 86400, 'expiration_time' => $now + 86400 * 365,
    'quote_id' => 'q_ancient',
]);
assert_eq(1, Invoice::expireInvoicesCreatedBefore($now - 30 * 86400), 'ancient New row expired by age');
$r = Database::fetchOne("SELECT status FROM invoices WHERE id = 'inv_ancient'");
assert_eq('Expired', $r['status'], 'status Expired');
$r = Database::fetchOne("SELECT COUNT(*) AS c FROM webhook_deliveries WHERE invoice_id = 'inv_ancient' AND event_type = 'InvoiceExpired'");
assert_eq(1, (int)$r['c'], 'InvoiceExpired fired for the bulk expiry');

fwrite(STDERR, "test_expiry_batches: all assertions passed\n");
