<?php
/**
 * Long-lived invoices on the mint (Cashu) rail, against the mock mint:
 *
 *   1. An ordinary mint invoice still expires with the mint quote (unchanged
 *      behaviour); a long-lived one lives for the shared lifetime while
 *      ln_expires_at tracks the quote's own expiry.
 *   2. refreshLightning requests a NEW quote for the SAME mint-unit amount
 *      (no re-quote), parks the old quote (quote_id + mint_url in context),
 *      and resets the poll bookkeeping.
 *   3. Before replacing, the current quote is checked: a PAID quote is driven
 *      into settlement instead of being parked (refresh answers 'settled').
 *   4. Mint down for new quotes → 'unavailable', current quote kept.
 *   5. The mint batch poller skips a long-lived invoice whose quote lapsed.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require __DIR__ . '/mock_mint_stub.php';
define('CASHUPAY_IN_CRON', true);
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/invoice.php';

$now = time();
$mint = start_mock_mint(['quotes' => [], 'quote_ttl' => 600]);
make_store('s1', $mint['url']);
$lifetime = Config::getSharedInvoiceLifetime();

function row(string $id): array { return Database::fetchOne("SELECT * FROM invoices WHERE id = ?", [$id]); }

// ---- 1. creation ----
$reg = Invoice::create('s1', ['amount' => 500, 'currency' => 'sat']);
assert_eq('mint', $reg['payment_rail'], 'ordinary invoice on the mint rail');
assert_eq('qauto1', $reg['quote_id'], 'first mock quote');
assert_true(abs((int)$reg['expiration_time'] - ($now + 600)) <= 5, 'ordinary mint invoice expires with the quote');
assert_eq((int)$reg['expiration_time'], (int)$reg['ln_expires_at'], 'ln_expires_at = quote expiry');

$ll = Invoice::create('s1', ['amount' => 500, 'currency' => 'sat', 'lifetime_sec' => $lifetime, 'long_lived' => true]);
assert_eq('qauto2', $ll['quote_id'], 'second mock quote');
assert_true(abs((int)$ll['expiration_time'] - ($now + $lifetime)) <= 5, 'long-lived invoice outlives its quote');
assert_true(abs((int)$ll['ln_expires_at'] - ($now + 600)) <= 5, 'ln_expires_at = quote expiry (600 s)');

// An explicit lifetime on an ORDINARY invoice (API expirationMinutes on a shop
// order) is an upper bound: the 600 s quote must not stretch a 300 s window.
$bounded = Invoice::create('s1', ['amount' => 500, 'currency' => 'sat', 'lifetime_sec' => 300]);
assert_eq(0, (int)$bounded['long_lived'], 'explicit lifetime alone does not make it long-lived');
assert_true(abs((int)$bounded['expiration_time'] - ($now + 300)) <= 5, 'explicit 300 s lifetime bounds the longer quote');
assert_true((int)$bounded['ln_expires_at'] > (int)$bounded['expiration_time'], 'quote expiry itself still recorded');
$longer = Invoice::create('s1', ['amount' => 500, 'currency' => 'sat', 'lifetime_sec' => 1800]);
assert_true(abs((int)$longer['expiration_time'] - ($now + 600)) <= 5, 'a quote shorter than the explicit window still cuts it (no on-chain rail)');

// ---- 2. refresh sources a new quote at the same amount ----
Database::update('invoices', ['ln_expires_at' => $now - 10, 'last_polled_at' => $now, 'last_poll_state' => 'UNPAID'], 'id = ?', [$ll['id']]);
mock_mint_clear_requests($mint['dir']);
$r = Invoice::refreshLightning($ll['id']);
assert_true($r['ok'], 'refresh ok: ' . json_encode($r['reason'] ?? null));
$a = row($ll['id']);
assert_eq('qauto5', $a['quote_id'], 'new quote id');
assert_eq('lnbc1stubqauto5', $a['bolt11'], 'new bolt11');
assert_eq('mint', $a['payment_rail'], 'still mint rail');
assert_eq($mint['url'], $a['mint_url'], 'same mint');
assert_eq(500, (int)$a['amount_sats'], 'same mint-unit amount');
assert_null($a['last_polled_at'], 'poll bookkeeping reset');
assert_true((int)$a['ln_expires_at'] > $now + 500, 'fresh quote expiry recorded');
$posts = array_values(array_filter(mock_mint_requests($mint['dir']), fn($l) => str_starts_with($l, 'POST /v1/mint/quote/bolt11')));
assert_eq(1, count($posts), 'exactly one quote request');
$mintState = json_decode((string)file_get_contents($mint['dir'] . '/state.json'), true);
assert_eq(500, (int)($mintState['quotes']['qauto5']['amount'] ?? 0), 'requested the fixed 500 amount');
$p = Database::fetchAll("SELECT * FROM invoice_ln_quotes WHERE invoice_id = ?", [$ll['id']]);
assert_eq(1, count($p), 'old quote parked');
$ctx = json_decode($p[0]['context'], true);
assert_eq('qauto2', $ctx['quote_id'], 'parked context carries the old quote id');
assert_eq($mint['url'], $ctx['mint_url'], 'and its mint');
assert_eq('mint', $p[0]['payment_rail'], 'parked rail');

// ---- 3. a PAID current quote is settled, not parked ----
Database::update('invoices', ['ln_expires_at' => $now - 10, 'ln_refresh_lock_at' => null], 'id = ?', [$ll['id']]);
mock_mint_update_state($mint['dir'], ['quotes' => [
    'qauto5' => ['state' => 'PAID', 'expiry' => $now + 600, 'amount' => 500, 'request' => 'lnbc1stubqauto5'],
]]);
$r = Invoice::refreshLightning($ll['id']);
assert_eq('settled', $r['reason'], 'paid quote found before replacing: ' . json_encode($r));
$b = row($ll['id']);
assert_true(in_array($b['status'], ['Processing', 'Settled'], true), 'invoice moved into settlement (' . $b['status'] . ')');
assert_eq('qauto5', $b['quote_id'], 'the paid quote stays on the invoice');
assert_eq(1, count(Database::fetchAll("SELECT * FROM invoice_ln_quotes WHERE invoice_id = ?", [$ll['id']])), 'nothing new parked');

// ---- 4. mint down ----
$ll2 = Invoice::create('s1', ['amount' => 800, 'currency' => 'sat', 'lifetime_sec' => $lifetime, 'long_lived' => true]);
mock_mint_update_state($mint['dir'], ['quote_create_fail' => true]);
Database::update('invoices', ['ln_expires_at' => $now - 10], 'id = ?', [$ll2['id']]);
$r = Invoice::refreshLightning($ll2['id']);
assert_eq('unavailable', $r['reason'], 'mint down → unavailable');
assert_eq($ll2['quote_id'], row($ll2['id'])['quote_id'], 'current quote kept');
mock_mint_update_state($mint['dir'], ['quote_create_fail' => false]);

// ---- 5. batch poller skips a lapsed long-lived quote ----
Database::update('invoices', ['ln_expires_at' => $now - Invoice::EXPIRY_CONFIRM_WINDOW - 100, 'last_polled_at' => null], 'id = ?', [$ll2['id']]);
Database::update('invoices', ['last_polled_at' => null], 'id = ?', [$reg['id']]);
Invoice::pollPendingQuotes(30, 10);
assert_null(row($ll2['id'])['last_polled_at'], 'lapsed long-lived quote not polled');
assert_not_null(row($reg['id'])['last_polled_at'], 'ordinary live quote polled');

echo "PASS test_long_lived_mint_refresh\n";
