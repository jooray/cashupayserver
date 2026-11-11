<?php
/**
 * Long-lived (shareable) invoices on the Lightning-address rail:
 *
 *   1. Creation: a long-lived invoice lives for the requested lifetime while
 *      its bolt11 keeps its own expiry (ln_expires_at); an ordinary invoice is
 *      untouched (expires with the window, long_lived=0, not refreshable).
 *   2. Pollers: a long-lived invoice whose bolt11 lapsed is left alone by the
 *      LNURL batch poller (nothing payable to watch); an ordinary invoice with
 *      time left is polled as before.
 *   3. refreshLightning: refused while the bolt11 is live (not_due); once
 *      lapsed it sources a NEW bolt11 from the same destination, parks the old
 *      one in invoice_ln_quotes, stamps ln_refreshed_at, resets the poll
 *      state — and a second attempt inside the lock window is 'busy'.
 *   4. Superseded payment: the OLD bolt11 is paid after the refresh; the
 *      superseded-quote poller finds it, restores that quote onto the invoice
 *      and settles through markLnAddressPaid (preimage recorded, bolt11 is
 *      the one actually paid, the displaced quote is closed).
 *   5. Destination down: a refresh that cannot source anything reports
 *      'unavailable' and leaves the current bolt11 in place.
 *   6. Grace: a parked quote long past its expiry is closed, not polled.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require __DIR__ . '/mock_lnurl_host.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/invoice.php';

$host = start_mock_lnurl_host();
putenv('CASHU_LNURL_URL_TEMPLATE=' . $host['url_template']);
Config::set('allow_private_endpoints', true);
$now = time();
$lifetime = Config::getSharedInvoiceLifetime();
assert_eq(90 * 86400, $lifetime, 'shared lifetime defaults to 90 days');

$store = 'store_ll_lnurl';
make_store($store, 'http://127.0.0.1:1'); // mint non-routable: LNURL wins the chain
StoreLnAddresses::replaceForStore($store, ['merchant@example.test']);

function row(string $id): array { return Database::fetchOne("SELECT * FROM invoices WHERE id = ?", [$id]); }
function parked(string $id): array {
    return Database::fetchAll("SELECT * FROM invoice_ln_quotes WHERE invoice_id = ? ORDER BY created_at, id", [$id]);
}

// ---- 1. creation ----
$reg = Invoice::create($store, ['amount' => 5000, 'currency' => 'sat']);
assert_eq(0, (int)$reg['long_lived'], 'ordinary invoice is not long-lived');
assert_true(abs((int)$reg['expiration_time'] - ($now + 3600)) <= 5, 'ordinary invoice expires with the 1h window');
assert_true(abs((int)$reg['ln_expires_at'] - ($now + 3600)) <= 5, 'undecodable stub bolt11 → default window as ln_expires_at');
assert_eq('not_refreshable', Invoice::refreshLightning($reg['id'], ['force' => true])['reason'], 'ordinary invoice is never refreshed');

$ll = Invoice::create($store, ['amount' => 5000, 'currency' => 'sat', 'lifetime_sec' => $lifetime, 'long_lived' => true]);
assert_eq(1, (int)$ll['long_lived'], 'long-lived flag persisted');
assert_eq('lnaddress', $ll['payment_rail'], 'rides the lnaddress rail');
assert_true(abs((int)$ll['expiration_time'] - ($now + $lifetime)) <= 5, 'long-lived invoice expires after the shared lifetime');
assert_true(abs((int)$ll['ln_expires_at'] - ($now + 3600)) <= 5, 'bolt11 keeps its own (1h) expiry');
assert_null($ll['fee_redirect_note'], 'no fee owed on this store -> not fee-redirected (fee-routed long-lived invoices: test_long_lived_fee_redirect_refresh.php)');
$api = Invoice::formatForApi($ll);
assert_true($api['longLived'], 'API exposes longLived');
assert_eq((int)$ll['expiration_time'], (int)$api['expirationTime'], 'API expirationTime is the far-future real expiry');

// ---- 2. pollers skip a lapsed long-lived bolt11 ----
Database::update('invoices', ['ln_expires_at' => $now - 10], 'id = ?', [$ll['id']]);
$reg2 = Invoice::create($store, ['amount' => 5000, 'currency' => 'sat']);
Database::update('invoices', ['ln_expires_at' => $now - 10], 'id = ?', [$reg2['id']]); // ordinary: ln_expires_at is not consulted
Invoice::pollPendingLnAddress(30, 10);
assert_null(row($ll['id'])['last_polled_at'], 'lapsed long-lived bolt11 is not polled');
assert_not_null(row($reg2['id'])['last_polled_at'], 'ordinary invoice with time left is still polled');
Database::update('invoices', ['ln_expires_at' => $now + 3600], 'id = ?', [$ll['id']]);

// ---- 3. refresh ----
$r = Invoice::refreshLightning($ll['id']);
assert_eq('not_due', $r['reason'], 'live bolt11 is not refreshed');
Database::update('invoices', ['ln_expires_at' => $now - 10, 'last_polled_at' => $now - 5, 'last_poll_state' => 'x'], 'id = ?', [$ll['id']]);
$callsBefore = mock_lnurl_calls($host['dir']);
$r = Invoice::refreshLightning($ll['id']);
assert_true($r['ok'], 'lapsed bolt11 is refreshed: ' . json_encode($r['reason'] ?? null));
$after = row($ll['id']);
assert_neq($ll['bolt11'], $after['bolt11'], 'a new bolt11 was sourced');
assert_neq($ll['lnurl_verify_url'], $after['lnurl_verify_url'], 'with its own verify URL');
assert_eq('lnaddress', $after['payment_rail'], 'same rail');
assert_eq(5000, (int)$after['amount_sats'], 'same sats amount (no re-quote)');
assert_true((int)$after['ln_expires_at'] > $now + 3000, 'fresh ln_expires_at');
assert_true((int)$after['ln_refreshed_at'] >= $now, 'ln_refreshed_at stamped');
assert_null($after['last_polled_at'], 'poll state reset for the new bolt11');
assert_null($after['last_poll_state'], 'poll state reset');
assert_eq($callsBefore + 1, mock_lnurl_calls($host['dir']), 'exactly one callback for the refresh');
$p = parked($ll['id']);
assert_eq(1, count($p), 'old bolt11 parked');
assert_eq('open', $p[0]['state'], 'parked quote is open');
assert_eq($ll['bolt11'], $p[0]['bolt11'], 'parked row carries the old bolt11');
$ctx = json_decode($p[0]['context'], true);
assert_eq($ll['lnurl_verify_url'], $ctx['lnurl_verify_url'], 'parked context carries the old verify URL');
assert_eq($now - 10, (int)$p[0]['expires_at'], 'parked row keeps the (backdated) old expiry');

$r = Invoice::refreshLightning($ll['id'], ['force' => true]);
assert_eq('busy', $r['reason'], 'second refresh inside the lock window is refused');

// ---- 4. the OLD bolt11 gets paid ----
Database::update('invoices', ['ln_expires_at' => $now - 10], 'id = ?', [$ll['id']]); // lapsed again: still fine, we're testing history
mock_lnurl_settle($host['dir'], (string)$ll['lnurl_verify_url']);
$res = Invoice::pollSupersededQuotes($ll['id'], 0, 10);
assert_eq(1, $res['polled'], 'parked quote polled');
assert_eq(1, $res['settled'], 'parked quote settled the invoice');
$settled = row($ll['id']);
assert_eq('Settled', $settled['status'], 'invoice settled via the superseded bolt11');
assert_eq('lnaddress', $settled['settled_rail'], 'settled rail recorded');
assert_eq($ll['bolt11'], $settled['bolt11'], 'the bolt11 actually paid is the invoice bolt11 again');
assert_eq($ll['lnurl_verify_url'], $settled['lnurl_verify_url'], 'and its verify URL');
assert_eq(str_repeat('ab', 32), $settled['lnurl_preimage'], 'preimage recorded');
$p = parked($ll['id']);
assert_eq(2, count($p), 'the displaced quote was parked in turn');
$states = array_column($p, 'state', 'bolt11');
assert_eq('settled', $states[$ll['bolt11']], 'paid quote marked settled');
assert_eq('open', $states[$after['bolt11']], 'displaced quote parked open');
$res = Invoice::pollSupersededQuotes($ll['id'], 0, 10);
assert_eq(1, $res['closed'], 'displaced quote of a settled invoice is closed');
$wh = Database::fetchOne("SELECT COUNT(*) AS c FROM webhook_deliveries WHERE invoice_id = ? AND event_type = 'InvoiceSettled'", [$ll['id']]);
assert_eq(0, (int)($wh['c'] ?? 0), 'no webhooks registered → no deliveries (sanity)');

// ---- 5. destination down ----
$ll2 = Invoice::create($store, ['amount' => 7000, 'currency' => 'sat', 'lifetime_sec' => $lifetime, 'long_lived' => true]);
mock_lnurl_set_mode($host['dir'], 'fail');
Database::update('invoices', ['ln_expires_at' => $now - 10], 'id = ?', [$ll2['id']]);
$r = Invoice::refreshLightning($ll2['id']);
assert_eq('unavailable', $r['reason'], 'no rail could re-source: unavailable');
assert_eq('lnurl', $r['receive_errors'][0]['type'] ?? null, 'sanitized reason reported');
$same = row($ll2['id']);
assert_eq($ll2['bolt11'], $same['bolt11'], 'current bolt11 kept');
assert_eq(0, count(parked($ll2['id'])), 'nothing parked on a failed refresh');
mock_lnurl_set_mode($host['dir'], 'ok');

// ---- 6. grace: an ancient parked quote is closed ----
Database::insert('invoice_ln_quotes', [
    'id' => 'lnq_old', 'invoice_id' => $ll2['id'], 'store_id' => $store, 'payment_rail' => 'lnaddress',
    'bolt11' => 'lnbc1old', 'amount_sats' => 7000, 'context' => json_encode(['lnurl_verify_url' => 'http://127.0.0.1:1/x']),
    'expires_at' => $now - Invoice::SUPERSEDED_QUOTE_GRACE - 100, 'superseded_at' => $now - 7200,
    'state' => 'open', 'created_at' => $now - 7200,
]);
$res = Invoice::pollSupersededQuotes($ll2['id'], 0, 10);
assert_eq(1, $res['closed'], 'quote past grace closed');
assert_eq(0, $res['polled'], 'and not polled');
assert_eq('closed', Database::fetchOne("SELECT state FROM invoice_ln_quotes WHERE id = 'lnq_old'")['state'], 'state=closed');

echo "PASS test_long_lived_lightning_refresh\n";
