<?php
/**
 * DX-6 / M6-3 / WM-4 / WM-5 / AV-5: late payments on the mint rail.
 *
 * Against a php -S mint stub answering GET /v1/mint/quote/bolt11/{id} from a
 * state file:
 *   (a) an Invalid invoice whose quote is ISSUED and whose proofs are already
 *       in storage is Settled (settled_rail=mint), fires exactly one
 *       InvoiceSettled, and is returned;
 *   (b) the same for an Expired invoice;
 *   (c) UNPAID with the mint quote long expired (> 7 days) → quote_id written
 *       off, status kept; UNPAID with a fresh quote → quote_id kept;
 *       UNPAID written off only on Expired/Invalid rows (status-guarded);
 *   (d) an Invalid row on the nwc rail is never selected;
 *   (e) rows past the 72 h frequent window are only re-checked daily;
 *   (f) cleanupOldInvoices keeps a 100-day-old Expired row whose quote_id is
 *       set and deletes the one whose quote was written off;
 *   (g) a quote the mint reports PAID is claimed New/Expired/Invalid →
 *       Processing (status-guarded) before mint() runs — covered by the mint()
 *       path failing at our stub (no real signatures) and leaving Processing.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require __DIR__ . '/mock_mint_stub.php';
define('CASHUPAY_IN_CRON', true);
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/invoice.php';

use Cashu\Proof;
use Cashu\WalletStorage;

$now = time();
$mint = start_mock_mint(['quotes' => [
    'q_invalid' => ['state' => 'ISSUED', 'expiry' => $now + 600, 'amount' => 500],
    'q_expired' => ['state' => 'ISSUED', 'expiry' => $now + 600, 'amount' => 500],
    'q_old_unpaid' => ['state' => 'UNPAID', 'expiry' => $now - 8 * 86400, 'amount' => 500],
    'q_fresh_unpaid' => ['state' => 'UNPAID', 'expiry' => $now - 3600, 'amount' => 500],
    'q_paid' => ['state' => 'PAID', 'expiry' => $now + 600, 'amount' => 500],
    'q_slow' => ['state' => 'UNPAID', 'expiry' => $now + 600, 'amount' => 500],
]]);
make_store('s1', $mint['url']);
Database::update('stores', ['seed_phrase' => 'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about'], 'id = ?', ['s1']); // the library validates BIP39 checksums
Database::insert('webhooks', [
    'id' => 'wh1', 'store_id' => 's1', 'url' => 'http://127.0.0.1:1/never', 'secret' => 'shh',
    'events' => json_encode([]), 'enabled' => 1, 'created_at' => Database::timestamp(),
]);

$mk = function (string $id, string $status, ?string $quote, array $extra = []) use ($now, $mint): void {
    Database::insert('invoices', array_merge([
        'id' => $id, 'store_id' => 's1', 'status' => $status, 'amount' => '500', 'currency' => 'sat',
        'amount_sats' => 500, 'quote_id' => $quote, 'mint_url' => $mint['url'], 'payment_rail' => 'mint',
        'created_at' => $now - 7200, 'expiration_time' => $now - 3600,
    ], $extra));
};
$mk('inv_invalid', 'Invalid', 'q_invalid');
$mk('inv_expired', 'Expired', 'q_expired');
$mk('inv_old_unpaid', 'Invalid', 'q_old_unpaid');
$mk('inv_fresh_unpaid', 'Expired', 'q_fresh_unpaid');
$mk('inv_nwc', 'Invalid', 'q_invalid', ['payment_rail' => 'nwc']);
$mk('inv_noquote', 'Expired', null);
$mk('inv_paid', 'Expired', 'q_paid');
$mk('inv_slow', 'Expired', 'q_slow', ['expiration_time' => $now - 4 * 86400, 'last_polled_at' => $now - 3600]);

// Proofs already minted for the ISSUED quotes (the mint() call that stored
// them was interrupted before the row was settled).
$storage = new WalletStorage(Database::getDbPath(), $mint['url'], 'sat');
$ks = '00ad268c4d1f5826';
$C = '02' . str_repeat('44', 32);
$storage->storeProofs([new Proof($ks, 500, 'p-invalid', $C)], 'q_invalid');
$storage->storeProofs([new Proof($ks, 500, 'p-expired', $C)], 'q_expired');

function inv(string $id): ?array {
    return Database::fetchOne("SELECT * FROM invoices WHERE id = ?", [$id]);
}
function settled_count(string $id): int {
    $r = Database::fetchOne("SELECT COUNT(*) AS c FROM webhook_deliveries WHERE invoice_id = ? AND event_type = 'InvoiceSettled'", [$id]);
    return (int)($r['c'] ?? 0);
}

$recovered = Invoice::recoverLatePaidInvoices(0, 50);
sort($recovered);
assert_eq(['inv_expired', 'inv_invalid'], $recovered, 'both ISSUED late payments recovered: ' . json_encode($recovered));

foreach (['inv_invalid', 'inv_expired'] as $id) {
    $row = inv($id);
    assert_eq('Settled', $row['status'], "$id settled");
    assert_eq('mint', $row['settled_rail'], "$id settled on the mint rail");
    assert_not_null($row['paid_at'], "$id paid_at stamped");
    assert_eq(1, settled_count($id), "$id fired exactly one InvoiceSettled");
    assert_eq('ISSUED', strtoupper((string)$row['last_poll_state']), "$id last_poll_state stamped");
}

$old = inv('inv_old_unpaid');
assert_eq('Invalid', $old['status'], 'old UNPAID keeps its status');
assert_null($old['quote_id'], 'old UNPAID quote written off');
assert_eq('written_off_unpaid', $old['last_poll_state'], 'write-off is recorded');

$fresh = inv('inv_fresh_unpaid');
assert_eq('q_fresh_unpaid', $fresh['quote_id'], 'UNPAID with a young quote expiry keeps its quote_id');
assert_eq('Expired', $fresh['status'], 'status untouched');

assert_null(inv('inv_nwc')['last_polled_at'], 'nwc-rail Invalid row never selected');
assert_null(inv('inv_noquote')['last_polled_at'], 'row without a quote never selected');
assert_eq($now - 3600, (int)inv('inv_slow')['last_polled_at'], 'row past the 72h window polled an hour ago is not re-checked yet');

// PAID: claimed to Processing (status-guarded) and mint() attempted — the
// stub cannot sign, so the row stays Processing rather than Expired, and the
// pending claim is visible as the mint-retry state.
$paid = inv('inv_paid');
assert_eq('Processing', $paid['status'], 'PAID quote claimed for minting from Expired: ' . json_encode($paid['status']));

// A second pass re-polls nothing (throttle) …
$again = Invoice::recoverLatePaidInvoices(60, 50);
assert_eq([], $again, 'nothing to recover on the immediate second pass');
// … and the daily window applies past 72 h.
Database::update('invoices', ['last_polled_at' => $now - 86401], 'id = ?', ['inv_slow']);
Invoice::recoverLatePaidInvoices(0, 50);
assert_true((int)inv('inv_slow')['last_polled_at'] >= $now, 'slow-window row re-checked once a day');

// Write-off is status-guarded: an UNPAID answer on a row that has since been
// settled must not clear its quote.
$mk('inv_guard', 'Invalid', 'q_old_unpaid', ['last_polled_at' => null]);
Database::update('invoices', ['status' => 'Settled'], 'id = ?', ['inv_guard']);
Invoice::recoverLatePaidInvoices(0, 50);
assert_eq('q_old_unpaid', inv('inv_guard')['quote_id'], 'write-off never touches a non-Expired/Invalid row');

// Cleanup keeps quoted Expired/Invalid rows.
$mk('inv_old_keep', 'Expired', 'q_keep', ['created_at' => $now - 100 * 86400]);
$mk('inv_old_drop', 'Expired', null, ['created_at' => $now - 100 * 86400]);
$mk('inv_old_settled', 'Settled', 'q_settled', ['created_at' => $now - 100 * 86400]);
$deleted = Invoice::cleanupOldInvoices(90 * 86400);
assert_eq(2, $deleted, 'cleanup deleted the written-off Expired row and the Settled row');
assert_not_null(inv('inv_old_keep'), 'Expired row with a quote is kept');
assert_null(inv('inv_old_drop'), 'Expired row without a quote is deleted');
assert_null(inv('inv_old_settled'), 'old Settled row is deleted');

fwrite(STDERR, "test_late_payment_recovery: all assertions passed\n");
