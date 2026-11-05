<?php
/**
 * Long-lived invoices honour strict no-mint-fallback on refresh.
 *
 * With swaps enabled the mint is only ever a fallback for the swap rail, and
 * strict mode forbids exactly that at creation (Invoice::create). A refresh
 * never creates a swap (decision 6b), so under strict mode the refresh must
 * not reach for the mint either — otherwise an invoice created on-chain only
 * would quietly move onto the mint on its first payment-page view (the
 * regression behind test_noffer_amount_rejection_falls_back_to_onchain_under_strict_swaps).
 *
 *   1. Strict store (xpub + swaps on, no swap provider on regtest, working
 *      mint): the long-lived invoice is created on-chain only with the swap
 *      failure recorded; refreshLightning answers 'unavailable', the row is
 *      untouched (rail, bolt11, mint_url, receive_errors) and the mint saw no
 *      quote request. The admin log names strict mode.
 *   2. Same store with strict OFF: the refresh moves the invoice onto the mint
 *      (the gate is strict-specific, decision 6b otherwise unchanged).
 *   3. Strict flag on but swaps disabled: strict is only meaningful as a swap
 *      fallback rule (as at creation), so the mint serves the refresh.
 *   4. An invoice already on the mint rail whose bolt11 lapses after the
 *      operator turned strict on: 'unavailable', the lapsed bolt11 stays in
 *      place like any other failed re-source (nothing parked).
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require __DIR__ . '/mock_mint_stub.php';
define('CASHUPAY_IN_CRON', true);
fresh_db();
require_once dirname(__DIR__, 2) . '/includes/invoice.php';
require_once dirname(__DIR__, 2) . '/includes/swap/config.php';

const TPUB = 'tpubD6NzVbkrYhZ4WaWSyoBvQwbpLkojyoTZPRsgXELWz3Popb3qkjcJyJUGLnL4qHHoQvao8ESaAstxYSnhyswJ76uZPStJRJCTKvosUCJZL5B';

$now = time();
$lifetime = Config::getSharedInvoiceLifetime();
$mint = start_mock_mint(['quotes' => [], 'quote_ttl' => 600]);
$store = 'store_strict_ll';
make_store($store, $mint['url']);
Database::update('stores', [
    'onchain_xpub' => TPUB,
    'onchain_address_type' => 'P2WPKH',
    'onchain_network' => 'regtest', // no swap provider has a regtest endpoint
    'swaps_enabled' => SwapsConfig::FORCE_ON,
], 'id = ?', [$store]);
SwapsConfig::setStoreStrictOverride($store, SwapsConfig::FORCE_ON);

function row(string $id): array { return Database::fetchOne("SELECT * FROM invoices WHERE id = ?", [$id]); }
function error_types(?string $json): array {
    $decoded = json_decode((string)$json, true);
    return is_array($decoded) ? array_values(array_unique(array_map(fn($e) => $e['type'], $decoded))) : [];
}
function last_refresh_log(string $invoiceId): ?string {
    $r = Database::fetchOne(
        "SELECT message FROM admin_event_log WHERE invoice_id = ? AND context = 'refresh' ORDER BY id DESC LIMIT 1",
        [$invoiceId]
    );
    return $r ? (string)$r['message'] : null;
}
function new_long_lived(string $store, int $lifetime): array {
    return Invoice::create($store, ['amount' => 50000, 'currency' => 'sat', 'lifetime_sec' => $lifetime, 'long_lived' => true]);
}

// ---- 1. strict + swaps on: created on-chain only, refresh must not touch the mint ----
$ll = new_long_lived($store, $lifetime);
assert_eq('onchain', $ll['payment_rail'], 'strict store without a swap provider: on-chain only');
assert_null($ll['bolt11'], 'no bolt11 at creation');
assert_null($ll['mint_url'], 'creation did not fall back to the mint');
assert_not_null($ll['onchain_address'], 'on-chain address allocated');
assert_eq(['swap'], error_types($ll['receive_errors']), 'swap failure recorded at creation');
assert_true(abs((int)$ll['expiration_time'] - ($now + $lifetime)) <= 5, 'shared lifetime');
assert_true(Invoice::lightningRefreshDue($ll), 'an invoice without a bolt11 counts as due (first page view refreshes)');

mock_mint_clear_requests($mint['dir']);
$r = Invoice::refreshLightning($ll['id']);
assert_false((bool)$r['ok'], 'refresh did not succeed');
assert_eq('unavailable', $r['reason'], 'strict mode: no mint fallback on refresh');
$after = row($ll['id']);
assert_eq('onchain', $after['payment_rail'], 'still on-chain only');
assert_null($after['bolt11'], 'no bolt11 sourced');
assert_null($after['mint_url'], 'mint not used');
assert_eq(['swap'], error_types($after['receive_errors']), 'create-time receive_errors kept for the payment page');
assert_eq([], mock_mint_requests($mint['dir']), 'the mint saw no request at all');
assert_eq(0, (int)Database::fetchOne("SELECT COUNT(*) AS c FROM invoice_ln_quotes WHERE invoice_id = ?", [$ll['id']])['c'],
    'nothing parked');
$msg = last_refresh_log($ll['id']);
assert_true($msg !== null && str_contains($msg, 'strict mode'), 'admin log names strict mode: ' . var_export($msg, true));

// A later view (lock window elapsed) behaves the same — no drift over time.
Database::update('invoices', ['ln_refresh_lock_at' => null], 'id = ?', [$ll['id']]);
$r = Invoice::refreshLightning($ll['id'], ['force' => true]);
assert_eq('unavailable', $r['reason'], 'forced refresh under strict mode is still unavailable');
assert_eq('onchain', row($ll['id'])['payment_rail'], 'rail unchanged after a forced refresh');

// ---- 2. strict off: the mint may take the invoice over (decision 6b unchanged) ----
SwapsConfig::setStoreStrictOverride($store, SwapsConfig::FORCE_OFF);
Database::update('invoices', ['ln_refresh_lock_at' => null], 'id = ?', [$ll['id']]);
mock_mint_clear_requests($mint['dir']);
$r = Invoice::refreshLightning($ll['id']);
assert_true((bool)$r['ok'], 'strict off: refresh ok: ' . json_encode($r['reason'] ?? null));
$after = row($ll['id']);
assert_eq('mint', $after['payment_rail'], 'strict off: moved onto the mint rail');
assert_eq($mint['url'], $after['mint_url'], 'mint recorded');
assert_not_null($after['bolt11'], 'bolt11 sourced from the mint');
assert_true(mock_mint_requests($mint['dir']) !== [], 'the mint was asked for a quote');
assert_eq(50000, (int)$after['amount_sats'], 'same sats amount');

// ---- 3. strict flag on but swaps disabled: strict is a swap-fallback rule only ----
SwapsConfig::setStoreStrictOverride($store, SwapsConfig::FORCE_ON);
Database::update('stores', ['swaps_enabled' => SwapsConfig::FORCE_OFF], 'id = ?', [$store]);
$ll3 = new_long_lived($store, $lifetime);
assert_eq('mint', $ll3['payment_rail'], 'swaps off: creation uses the mint even with the strict flag set');
Database::update('invoices', ['ln_expires_at' => $now - 10, 'ln_refresh_lock_at' => null], 'id = ?', [$ll3['id']]);
$r = Invoice::refreshLightning($ll3['id']);
assert_true((bool)$r['ok'], 'swaps off: refresh ok: ' . json_encode($r['reason'] ?? null));
assert_eq('mint', row($ll3['id'])['payment_rail'], 'swaps off: refresh served by the mint');

// ---- 4. mint-rail invoice, then operator turns strict + swaps on: lapsed bolt11 stays ----
$ll4 = row($ll3['id']);
$oldBolt11 = $ll4['bolt11'];
$oldQuote = $ll4['quote_id'];
$parkedBefore = (int)Database::fetchOne("SELECT COUNT(*) AS c FROM invoice_ln_quotes WHERE invoice_id = ?", [$ll4['id']])['c'];
Database::update('stores', ['swaps_enabled' => SwapsConfig::FORCE_ON], 'id = ?', [$store]);
Database::update('invoices', ['ln_expires_at' => $now - 10, 'ln_refresh_lock_at' => null], 'id = ?', [$ll4['id']]);
mock_mint_clear_requests($mint['dir']);
$r = Invoice::refreshLightning($ll4['id']);
assert_eq('unavailable', $r['reason'], 'mint-rail invoice under strict mode: not refreshed');
$after = row($ll4['id']);
assert_eq('mint', $after['payment_rail'], 'rail kept');
assert_eq($oldBolt11, $after['bolt11'], 'lapsed bolt11 left in place (same as any failed re-source)');
assert_eq($oldQuote, $after['quote_id'], 'quote kept');
// The pre-refresh "was the current quote paid?" check may GET the quote's
// status; what must not happen is a request for a NEW quote.
$newQuoteRequests = array_values(array_filter(
    mock_mint_requests($mint['dir']), fn($req) => str_starts_with($req, 'POST ')
));
assert_eq([], $newQuoteRequests, 'mint not asked for a new quote');
assert_eq($parkedBefore, (int)Database::fetchOne("SELECT COUNT(*) AS c FROM invoice_ln_quotes WHERE invoice_id = ?", [$ll4['id']])['c'],
    'nothing parked by the refused refresh');

echo "OK test_long_lived_strict_no_mint_refresh\n";
