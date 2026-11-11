<?php
/**
 * DX-5: Invoice::updateStatus() is a status-guarded CAS for every transition.
 * A stale read must never write Expired / Invalid / Processing over Settled
 * (and must enqueue no InvoiceExpired/InvoiceInvalid after InvoiceSettled);
 * an explicit expected-status list narrows the "from" set further.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
define('CASHUPAY_IN_CRON', true);
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/invoice.php';

make_store('s1', 'https://mint.example');
Database::insert('webhooks', [
    'id' => 'wh1', 'store_id' => 's1', 'url' => 'http://127.0.0.1:1/never', 'secret' => 'shh',
    'events' => json_encode([]), 'enabled' => 1, 'created_at' => Database::timestamp(),
]);
$now = Database::timestamp();
foreach (['inv1', 'inv2', 'inv3'] as $id) {
    Database::insert('invoices', [
        'id' => $id, 'store_id' => 's1', 'status' => 'New', 'amount' => '500', 'currency' => 'sat',
        'amount_sats' => 500, 'created_at' => $now, 'expiration_time' => $now + 900,
    ]);
}
function deliveries(string $id, string $type): int {
    $r = Database::fetchOne("SELECT COUNT(*) AS c FROM webhook_deliveries WHERE invoice_id = ? AND event_type = ?", [$id, $type]);
    return (int)($r['c'] ?? 0);
}
function status_of(string $id): string {
    return (string)Database::fetchOne("SELECT status FROM invoices WHERE id = ?", [$id])['status'];
}

assert_true(Invoice::updateStatus('inv1', 'Settled', null, 'mint'), 'settle returns true');
assert_false(Invoice::updateStatus('inv1', 'Expired'), 'Expired over Settled refused');
assert_eq('Settled', status_of('inv1'), 'still Settled');
assert_eq(0, deliveries('inv1', 'InvoiceExpired'), 'no InvoiceExpired after settle');
assert_false(Invoice::updateStatus('inv1', 'Invalid'), 'Invalid over Settled refused');
assert_eq(0, deliveries('inv1', 'InvoiceInvalid'), 'no InvoiceInvalid after settle');
assert_false(Invoice::updateStatus('inv1', 'Processing'), 'Processing over Settled refused');
assert_eq('Settled', status_of('inv1'), 'still Settled after Processing attempt');
assert_eq(1, deliveries('inv1', 'InvoiceSettled'), 'exactly one InvoiceSettled');

// Explicit expected list: Invalid only from New; a Processing row is refused.
assert_true(Invoice::updateStatus('inv2', 'Processing'), 'New → Processing');
assert_false(Invoice::updateStatus('inv2', 'Invalid', null, null, ['New']), 'Invalid from Processing with expected [New] refused');
assert_eq('Processing', status_of('inv2'), 'row untouched');
assert_true(Invoice::updateStatus('inv2', 'Invalid'), 'Invalid from Processing with the default mutable set');
assert_eq(1, deliveries('inv2', 'InvoiceInvalid'), 'InvoiceInvalid fired once');
assert_false(Invoice::updateStatus('inv2', 'Expired'), 'Expired over Invalid refused (terminal)');

// Provisional is mutable (offline-cashu reconcile moves it on).
Database::update('invoices', ['status' => 'Provisional'], 'id = ?', ['inv3']);
assert_true(Invoice::updateStatus('inv3', 'Settled', null, 'cashu'), 'Provisional → Settled');
assert_false(Invoice::updateStatus('inv3', 'Settled', null, 'cashu'), 'second settle is a no-op');
assert_eq(1, deliveries('inv3', 'InvoiceSettled'), 'one InvoiceSettled for inv3');

fwrite(STDERR, "test_update_status_mutable: all assertions passed\n");
