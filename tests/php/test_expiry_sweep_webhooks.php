<?php
/**
 * Expiry sweep webhooks: Invoice::markExpiredInvoices() must fire an
 * InvoiceExpired webhook for every row it actually flips — historically it
 * bulk-UPDATEd and swallowed the event, which left WooCommerce orders stuck
 * at "pending" forever. The per-row status-guarded UPDATE also makes the
 * sweep idempotent: a second pass (or a concurrent poller) enqueues nothing.
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
    'events' => json_encode(['InvoiceExpired']),
    'enabled' => 1,
    'created_at' => Database::timestamp(),
]);

$now = Database::timestamp();
$mkInvoice = function (string $id, string $status, int $expirationTime) use ($now): void {
    Database::insert('invoices', [
        'id' => $id,
        'store_id' => 's1',
        'status' => $status,
        'amount' => '500',
        'currency' => 'sat',
        'amount_sats' => 500,
        'created_at' => $now - 3600,
        'expiration_time' => $expirationTime,
    ]);
};

$mkInvoice('inv_lapsed', 'New', $now - 60);       // should expire + fire
$mkInvoice('inv_alive', 'New', $now + 900);       // still valid — untouched
$mkInvoice('inv_settled', 'Settled', $now - 60);  // paid before sweep — untouched

/** Count enqueued InvoiceExpired deliveries for one invoice. */
function expired_deliveries(string $invoiceId): int {
    $r = Database::fetchOne(
        "SELECT COUNT(*) AS c FROM webhook_deliveries
          WHERE invoice_id = ? AND event_type = 'InvoiceExpired'",
        [$invoiceId]
    );
    return (int)($r['c'] ?? 0);
}

// First sweep: only the lapsed New invoice flips, with exactly one event.
$count = Invoice::markExpiredInvoices();
assert_eq(1, $count, 'sweep reports one expired invoice');
$lapsed = Database::fetchOne("SELECT status FROM invoices WHERE id = 'inv_lapsed'");
assert_eq('Expired', $lapsed['status'], 'lapsed invoice flipped to Expired');
assert_eq(1, expired_deliveries('inv_lapsed'), 'InvoiceExpired enqueued for lapsed invoice');

$alive = Database::fetchOne("SELECT status FROM invoices WHERE id = 'inv_alive'");
assert_eq('New', $alive['status'], 'unexpired invoice untouched');
$settled = Database::fetchOne("SELECT status FROM invoices WHERE id = 'inv_settled'");
assert_eq('Settled', $settled['status'], 'settled invoice untouched');
assert_eq(0, expired_deliveries('inv_settled'), 'no event for settled invoice');

// Payload shape: the BTCPay WooCommerce plugin reads partiallyPaid on
// InvoiceExpired; BareBits rails are all-or-nothing so it must be false.
$delivery = Database::fetchOne(
    "SELECT payload FROM webhook_deliveries
      WHERE invoice_id = 'inv_lapsed' AND event_type = 'InvoiceExpired'"
);
$payload = json_decode((string)$delivery['payload'], true);
assert_eq('InvoiceExpired', $payload['type'], 'payload type is InvoiceExpired');
assert_true($payload['partiallyPaid'] === false, 'payload carries partiallyPaid=false');
assert_eq('Expired', $payload['invoice']['status'], 'payload invoice status is Expired');

// Second sweep: idempotent — nothing new flips, no duplicate event.
$count2 = Invoice::markExpiredInvoices();
assert_eq(0, $count2, 'second sweep flips nothing');
assert_eq(1, expired_deliveries('inv_lapsed'), 'no duplicate InvoiceExpired on re-sweep');

fwrite(STDERR, "test_expiry_sweep_webhooks: all assertions passed\n");
