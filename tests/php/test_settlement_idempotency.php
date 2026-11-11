<?php
/**
 * Theme 1: status-guarded settlement. Concurrent pollers (browser checkout
 * poll + cron + API) can all reach a settlement path for the same invoice.
 * Invoice::updateStatus('Settled') must flip the row and fire InvoiceSettled
 * exactly once — a second call on an already-Settled invoice is a no-op (no
 * duplicate webhook, no paid_at churn).
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';

// Keep hermetic: suppress the opportunistic Background::trigger() in fireEvent.
define('CASHUPAY_IN_CRON', true);

fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/invoice.php';

make_store('s1', 'https://mint.example');
Database::insert('webhooks', [
    'id' => 'wh1',
    'store_id' => 's1',
    'url' => 'http://127.0.0.1:1/never', // never actually sent (enqueue only)
    'secret' => 'shh',
    'events' => json_encode([]),
    'enabled' => 1,
    'created_at' => Database::timestamp(),
]);

$now = Database::timestamp();
Database::insert('invoices', [
    'id' => 'inv1',
    'store_id' => 's1',
    'status' => 'New',
    'amount' => '500',
    'currency' => 'sat',
    'amount_sats' => 500,
    'created_at' => $now,
    'expiration_time' => $now + 900,
]);

/** Count enqueued InvoiceSettled deliveries for the invoice. */
function settled_deliveries(): int {
    $r = Database::fetchOne(
        "SELECT COUNT(*) AS c FROM webhook_deliveries
          WHERE invoice_id = 'inv1' AND event_type = 'InvoiceSettled'"
    );
    return (int)($r['c'] ?? 0);
}

// First settlement: New -> Settled.
Invoice::updateStatus('inv1', 'Settled', null, 'mint');
$inv = Database::fetchOne("SELECT status, paid_at FROM invoices WHERE id = 'inv1'");
assert_eq('Settled', $inv['status'], 'invoice flipped to Settled');
assert_not_null($inv['paid_at'], 'paid_at stamped on first settle');
assert_eq(1, settled_deliveries(), 'exactly one InvoiceSettled enqueued');
$paidAt = (int)$inv['paid_at'];

// Second settlement attempt on an already-Settled invoice: must be a no-op.
Invoice::updateStatus('inv1', 'Settled', null, 'mint');
assert_eq(1, settled_deliveries(), 'no duplicate InvoiceSettled on re-settle');
$inv2 = Database::fetchOne("SELECT status, paid_at FROM invoices WHERE id = 'inv1'");
assert_eq($paidAt, (int)$inv2['paid_at'], 'paid_at not overwritten on re-settle');

// A genuinely new terminal transition on a different invoice still fires.
Database::insert('invoices', [
    'id' => 'inv2',
    'store_id' => 's1',
    'status' => 'New',
    'amount' => '300',
    'currency' => 'sat',
    'amount_sats' => 300,
    'created_at' => $now,
    'expiration_time' => $now + 900,
]);
Invoice::updateStatus('inv2', 'Expired');
$expDeliveries = Database::fetchOne(
    "SELECT COUNT(*) AS c FROM webhook_deliveries
      WHERE invoice_id = 'inv2' AND event_type = 'InvoiceExpired'"
);
assert_eq(1, (int)$expDeliveries['c'], 'non-settlement transition still fires its webhook');

fwrite(STDERR, "test_settlement_idempotency: all assertions passed\n");
