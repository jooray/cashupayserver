<?php
/**
 * WM-4 (B4): the expiry sweep no longer closes a mint-rail invoice on the
 * local clock alone.
 *
 *   - a New mint-rail (quote_id set) row 10 min past expiry with no UNPAID
 *     confirmation is left alone;
 *   - more than EXPIRY_CONFIRM_WINDOW past expiry it is expired;
 *   - a post-expiry poll that saw UNPAID (last_poll_state) expires it at once,
 *     a pre-expiry UNPAID does not, and a poll error keeps it open;
 *   - the batch poller keeps polling a just-expired row and expires it once the
 *     mint confirms UNPAID (with InvoiceExpired fired exactly once);
 *   - a non-mint row (no quote) still expires at expiration_time;
 *   - an invoice carrying an on-chain address is not swept until
 *     expiration_time + the store's onchain_confirm_timeout_sec;
 *   - the Lightning-rail single pollers ask the rail first within the window:
 *     an lnaddress row past expiry without a verify answer stays New, and is
 *     expired past the window.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require __DIR__ . '/mock_mint_stub.php';
define('CASHUPAY_IN_CRON', true);
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/invoice.php';

$now = time();
$mint = start_mock_mint(['quotes' => [
    'q_confirm' => ['state' => 'UNPAID', 'expiry' => $now + 600, 'amount' => 500],
    'q_paid_late' => ['state' => 'ISSUED', 'expiry' => $now + 600, 'amount' => 500],
]]);
make_store('s1', $mint['url']);
Database::update('stores', ['seed_phrase' => 'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about'], 'id = ?', ['s1']); // the library validates BIP39 checksums
Database::update('stores', ['onchain_confirm_timeout_sec' => 7200], 'id = ?', ['s1']);
Database::insert('webhooks', [
    'id' => 'wh1', 'store_id' => 's1', 'url' => 'http://127.0.0.1:1/never', 'secret' => 'shh',
    'events' => json_encode(['InvoiceExpired']), 'enabled' => 1, 'created_at' => Database::timestamp(),
]);
$W = Invoice::EXPIRY_CONFIRM_WINDOW;

$mk = function (string $id, array $cols) use ($now, $mint): void {
    Database::insert('invoices', array_merge([
        'id' => $id, 'store_id' => 's1', 'status' => 'New', 'amount' => '500', 'currency' => 'sat',
        'amount_sats' => 500, 'created_at' => $now - 7200, 'mint_url' => $mint['url'],
    ], $cols));
};
$mk('m_recent', ['quote_id' => 'q1', 'expiration_time' => $now - 600]);
$mk('m_old', ['quote_id' => 'q2', 'expiration_time' => $now - $W - 60]);
$mk('m_confirmed', ['quote_id' => 'q3', 'expiration_time' => $now - 600, 'last_polled_at' => $now - 300, 'last_poll_state' => 'UNPAID']);
$mk('m_pre_expiry_poll', ['quote_id' => 'q4', 'expiration_time' => $now - 600, 'last_polled_at' => $now - 900, 'last_poll_state' => 'UNPAID']);
$mk('m_poll_error', ['quote_id' => 'q5', 'expiration_time' => $now - 600, 'last_polled_at' => $now - 300, 'last_poll_state' => 'UNPAID', 'last_poll_error' => 'timeout']);
$mk('ln_recent', ['payment_rail' => 'lnaddress', 'expiration_time' => $now - 600]);
$mk('oc_recent', ['quote_id' => null, 'onchain_address' => 'bcrt1qexample', 'expiration_time' => $now - 600]);
$mk('oc_old', ['quote_id' => null, 'onchain_address' => 'bcrt1qexample2', 'expiration_time' => $now - 7300]);
$mk('oc_mint_recent', ['quote_id' => 'q6', 'onchain_address' => 'bcrt1qexample3', 'expiration_time' => $now - $W - 60]);

function st(string $id): string {
    return (string)Database::fetchOne("SELECT status FROM invoices WHERE id = ?", [$id])['status'];
}
function expired_events(string $id): int {
    $r = Database::fetchOne("SELECT COUNT(*) AS c FROM webhook_deliveries WHERE invoice_id = ? AND event_type = 'InvoiceExpired'", [$id]);
    return (int)($r['c'] ?? 0);
}

$n = Invoice::markExpiredInvoices();
assert_eq('New', st('m_recent'), 'mint-rail row 10 min past expiry without confirmation is left alone');
assert_eq('Expired', st('m_old'), 'mint-rail row past the confirm window is expired');
assert_eq(1, expired_events('m_old'), 'InvoiceExpired fired for it');
assert_eq('Expired', st('m_confirmed'), 'post-expiry UNPAID poll → expired');
assert_eq('New', st('m_pre_expiry_poll'), 'pre-expiry UNPAID poll does not count');
assert_eq('New', st('m_poll_error'), 'a poll error keeps it open');
assert_eq('Expired', st('ln_recent'), 'non-mint rail still expires at expiration_time in the sweep');
assert_eq('New', st('oc_recent'), 'on-chain invoice within the confirm timeout is not swept');
assert_eq('Expired', st('oc_old'), 'on-chain invoice past expiration + confirm timeout is swept');
assert_eq('New', st('oc_mint_recent'), 'on-chain grace applies even when the mint window has passed');
assert_eq(4, $n, 'sweep count');

// Batch poller: a just-expired New row is still polled; UNPAID confirms → Expired.
$mk('m_poll', ['quote_id' => 'q_confirm', 'expiration_time' => $now - 120]);
$mk('m_late_paid', ['quote_id' => 'q_paid_late', 'expiration_time' => $now - 120]);
Invoice::pollPendingQuotes(0, 10);
assert_eq('Expired', st('m_poll'), 'poller expired the row the mint confirmed UNPAID');
assert_eq(1, expired_events('m_poll'), 'exactly one InvoiceExpired');
$row = Database::fetchOne("SELECT last_poll_state, last_poll_error FROM invoices WHERE id = 'm_poll'");
assert_eq('UNPAID', strtoupper((string)$row['last_poll_state']), 'last_poll_state stamped');
assert_null($row['last_poll_error'], 'no error recorded');
// A quote ISSUED after local expiry: no proofs stored here, so it is left for
// recovery, but crucially it is NOT expired.
assert_eq('New', st('m_late_paid'), 'ISSUED quote is never expired by the poller');
Invoice::markExpiredInvoices();
assert_eq('New', st('m_late_paid'), 'nor by the sweep within the window');

// Mint unreachable: the error is recorded and the row stays open within the window.
make_store('s_dead', 'http://127.0.0.1:1');
Database::update('stores', ['seed_phrase' => 'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about'], 'id = ?', ['s_dead']);
Database::insert('invoices', [
    'id' => 'm_dead', 'store_id' => 's_dead', 'status' => 'New', 'amount' => '500', 'currency' => 'sat',
    'amount_sats' => 500, 'created_at' => $now - 7200, 'mint_url' => 'http://127.0.0.1:1', 'quote_id' => 'qd',
    'expiration_time' => $now - 120,
]);
Invoice::pollPendingQuotes(0, 10);
$dead = Database::fetchOne("SELECT status, last_poll_error FROM invoices WHERE id = 'm_dead'");
assert_eq('New', $dead['status'], 'unreachable mint keeps the row open within the window');
assert_not_null($dead['last_poll_error'], 'poll error recorded for the operator');
Invoice::markExpiredInvoices();
assert_eq('New', st('m_dead'), 'sweep leaves it alone while the error stands');

// Lightning-rail single poller: within the window the rail is asked first.
$mk('ln_single', ['payment_rail' => 'lnaddress', 'expiration_time' => $now - 60, 'lnurl_verify_url' => 'http://127.0.0.1:1/verify']);
Invoice::pollSingleLnAddress('ln_single');
assert_eq('New', st('ln_single'), 'lnaddress row past expiry without a verify answer stays New');
Database::update('invoices', ['expiration_time' => $now - $W - 60], 'id = ?', ['ln_single']);
Invoice::pollSingleLnAddress('ln_single');
assert_eq('Expired', st('ln_single'), 'and is expired past the window');

// Page poller on the mint rail: asks the mint, expires only on confirmed UNPAID.
$mk('m_page', ['quote_id' => 'q_confirm', 'expiration_time' => $now - 60]);
Invoice::pollSingleQuote('m_page');
assert_eq('Expired', st('m_page'), 'page poller expires on a confirmed UNPAID');
$mk('m_page_paid', ['quote_id' => 'q_paid_late', 'expiration_time' => $now - 60]);
Invoice::pollSingleQuote('m_page_paid');
assert_eq('New', st('m_page_paid'), 'page poller never expires an ISSUED quote');

fwrite(STDERR, "test_expiry_confirm_window: all assertions passed\n");
