<?php
/**
 * Long-lived invoices that are fee-redirected (dev / hosting fee owed >= the
 * invoice): the sats decided at creation are locked, and a lapsed fee bolt11
 * is refreshed from the SAME fee payee at that amount, exactly like a
 * merchant invoice refreshes — never from the merchant chain or a mint.
 *
 *   1. Creation: a long-lived invoice IS fee-redirected when the gate holds
 *      (sat invoice -> dev LNURL, lnaddress rail, long_lived=1).
 *   2. Live bolt11: refresh is 'not_due' (not refused as unroutable).
 *   3. Lapsed: refresh sources a NEW bolt11 from the dev LNURL at the same
 *      sats; fee_redirect_* / ln_destination / amount_sats unchanged; the old
 *      quote is parked; the only thing the fee host saw is `amount` (the
 *      privacy contract holds on refresh, not just at creation).
 *   4. Fiat lock: a USD fee invoice is NOT re-quoted when the rate moves
 *      (requoteForView: none, prepareForView leaves amount_sats + on-chain
 *      amount alone) — but its lapsed bolt11 is still refreshed at the
 *      locked sats; a 'requote' refresh keeps the locked sats too.
 *   5. Fee payee down: 'unavailable', current bolt11 kept, NO request made to
 *      the merchant's address and no routing change.
 *   6. Superseded fee quote paid after a refresh: settles via the parked
 *      quote and records the via='redirect' fee credit for the locked amount.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require __DIR__ . '/mock_lnurl_host.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/invoice.php';
require_once dirname(__DIR__, 2) . '/app/includes/dev_fee.php';
require_once dirname(__DIR__, 2) . '/app/includes/rates.php';
require_once dirname(__DIR__, 2) . '/app/includes/store_ln_addresses.php';

final class StubPriceProvider implements PriceProvider {
    public $behavior;
    public function __construct(callable $behavior) { $this->behavior = $behavior; }
    public function getName(): string { return 'stub'; }
    public function getBtcPrice(string $currency): ?float { return ($this->behavior)($currency); }
}
$price = new StubPriceProvider(fn() => 100000.0);
ExchangeRates::$testProviders = ['stub' => $price];
function rate_cache_clear(): void {
    Database::query("DELETE FROM config WHERE key LIKE 'rate_%'");
    Config::clearCache();
}

$host = start_mock_lnurl_host();
putenv('CASHU_LNURL_URL_TEMPLATE=' . $host['url_template']);
Config::set('allow_private_endpoints', true);
Config::set('fee_tracking_start_at', 0);
$now = time();
$lifetime = Config::getSharedInvoiceLifetime();
[$devUser] = explode('@', (string)CASHUPAY_DEV_FEE_LNURL, 2);

$store = 'store_ll_fee';
make_store($store, 'http://127.0.0.1:1'); // mint non-routable: never a fallback
Database::update('stores', ['price_provider_primary' => 'stub', 'price_provider_secondary' => 'stub'], 'id = ?', [$store]);
StoreLnAddresses::replaceForStore($store, ['merchant@example.test']);
// 1% dev fee on 2M sats revenue = 20k owed: covers every invoice below.
Database::insert('invoices', [
    'id' => 'seed_rev', 'store_id' => $store, 'status' => 'Settled', 'amount' => '2000000',
    'currency' => 'sat', 'amount_sats' => 2000000, 'created_at' => $now - 60,
    'expiration_time' => $now + 3600, 'payment_rail' => 'lnaddress', 'settled_rail' => 'lnaddress', 'paid_at' => $now - 60,
]);
assert_true((int)DevFee::computeOwed($store)['dev_owed'] >= 20000, 'dev fee owed covers the invoices');

function row(string $id): array { return Database::fetchOne("SELECT * FROM invoices WHERE id = ?", [$id]); }
function parked(string $id): array {
    return Database::fetchAll("SELECT * FROM invoice_ln_quotes WHERE invoice_id = ? ORDER BY created_at, id", [$id]);
}
function requests_since(array $before): array {
    global $host;
    return array_slice(mock_lnurl_requests($host['dir']), count($before));
}
/** The fee host saw one lookup for the dev user and one amount-only callback. */
function assert_fee_only_requests(array $reqs, int $sats, string $label): void {
    global $devUser;
    assert_eq(["/.well-known/lnurlp/{$devUser}"], array_values(array_filter($reqs, fn($r) => str_starts_with($r, '/.well-known/'))), "$label: only the dev LNURL was looked up");
    $cbs = array_values(array_filter($reqs, fn($r) => str_starts_with($r, '/cb')));
    assert_eq(1, count($cbs), "$label: one callback");
    parse_str((string)parse_url($cbs[0], PHP_URL_QUERY), $q);
    assert_eq(['amount' => (string)($sats * 1000)], $q, "$label: callback carries the locked amount and nothing else");
    assert_false(stripos(implode("\n", $reqs), 'merchant') !== false, "$label: merchant address never contacted");
}
function assert_fee_columns_unchanged(array $a, array $b, string $label): void {
    foreach (['fee_redirect_note', 'fee_redirect_destination', 'fee_redirect_rails', 'ln_destination', 'amount_sats', 'long_lived', 'amount', 'currency'] as $col) {
        assert_eq($a[$col], $b[$col], "$label: $col unchanged");
    }
}

// ---- 1. creation: long-lived AND fee-redirected ----
$before = mock_lnurl_requests($host['dir']);
$ll = Invoice::create($store, ['amount' => 5000, 'currency' => 'sat', 'lifetime_sec' => $lifetime, 'long_lived' => true]);
assert_eq(1, (int)$ll['long_lived'], 'long-lived');
assert_eq(FEE_NOTE_DEV, $ll['fee_redirect_note'], 'dev fee claimed the invoice');
assert_eq('lightning', $ll['fee_redirect_rails'], 'lightning rail fee-routed');
assert_eq('lnaddress', $ll['payment_rail'], 'rides the lnaddress rail');
assert_eq((string)CASHUPAY_DEV_FEE_LNURL, $ll['ln_destination'], 'bolt11 from the dev LNURL');
assert_eq(5000, (int)$ll['amount_sats']);
assert_true(abs((int)$ll['expiration_time'] - ($now + $lifetime)) <= 5, 'lives for the shared lifetime');
assert_true((int)$ll['ln_expires_at'] > $now + 60, 'bolt11 has its own expiry');
assert_fee_only_requests(requests_since($before), 5000, 'create');
assert_true(!empty(Invoice::formatForApi($ll)['feeRedirect']['note']), 'API badge data present');

// ---- 2. live bolt11: not due, NOT refused ----
$r = Invoice::refreshLightning($ll['id']);
assert_eq('not_due', $r['reason'], 'live fee bolt11 is simply not due');

// ---- 3. lapsed: refreshed from the same fee payee at the same sats ----
Database::update('invoices', ['ln_expires_at' => $now - 10], 'id = ?', [$ll['id']]);
assert_true(Invoice::lightningRefreshDue(row($ll['id'])), 'due once lapsed');
$before = mock_lnurl_requests($host['dir']);
$r = Invoice::refreshLightning($ll['id']);
assert_true(!empty($r['ok']), 'refresh succeeded: ' . json_encode($r['reason'] ?? null));
$after = row($ll['id']);
assert_neq($ll['bolt11'], $after['bolt11'], 'new bolt11');
assert_neq($ll['lnurl_verify_url'], $after['lnurl_verify_url'], 'new verify URL');
assert_eq('lnaddress', $after['payment_rail'], 'still the lnaddress rail');
assert_fee_columns_unchanged($ll, $after, 'refresh');
assert_true((int)$after['ln_expires_at'] > $now + 60, 'fresh expiry');
assert_not_null($after['ln_refreshed_at'], 'ln_refreshed_at stamped');
assert_fee_only_requests(requests_since($before), 5000, 'refresh');
$p = parked($ll['id']);
assert_eq(1, count($p), 'old quote parked');
assert_eq($ll['bolt11'], $p[0]['bolt11']);
assert_eq('open', $p[0]['state']);
assert_eq('busy', Invoice::refreshLightning($ll['id'], ['force' => true])['reason'], 'lock window honoured');

// ---- 4. fiat: sats locked at creation, no re-quote, refresh still works ----
$before = mock_lnurl_requests($host['dir']);
$usd = Invoice::create($store, ['amount' => '5.00', 'currency' => 'USD', 'lifetime_sec' => $lifetime, 'long_lived' => true]);
assert_eq(FEE_NOTE_DEV, $usd['fee_redirect_note'], 'USD invoice fee-redirected');
assert_eq(5000, (int)$usd['amount_sats'], '$5 at 100k = 5000 sats');
assert_fee_only_requests(requests_since($before), 5000, 'usd create');

$price->behavior = fn() => 200000.0; // $5 would now be 2500 sats
rate_cache_clear();
$rq = Invoice::requoteForView(row($usd['id']));
assert_eq(['requoted' => false, 'lightning_done' => false], $rq, 'fee-redirected invoice is never re-quoted');
$same = row($usd['id']);
assert_eq(5000, (int)$same['amount_sats'], 'sats locked');
assert_eq($usd['bolt11'], $same['bolt11'], 'bolt11 untouched while live');
assert_null($same['requoted_at'], 'no re-quote stamp');

// prepareForView with a lapsed bolt11: refresh at the LOCKED sats, no re-quote.
Database::update('invoices', ['ln_expires_at' => $now - 10, 'ln_refresh_lock_at' => null], 'id = ?', [$usd['id']]);
$before = mock_lnurl_requests($host['dir']);
Invoice::prepareForView($usd['id']);
$v = row($usd['id']);
assert_neq($usd['bolt11'], $v['bolt11'], 'lapsed fee bolt11 refreshed on view');
assert_eq(5000, (int)$v['amount_sats'], 'refreshed at the locked 5000 sats, not 2500');
assert_fee_only_requests(requests_since($before), 5000, 'usd view refresh');
assert_null($v['requoted_at'], 'still no re-quote');
assert_eq($usd['exchange_rate'], $v['exchange_rate'], 'exchange_rate untouched');

// an explicit requote refresh is harmless: locked sats win
Database::update('invoices', ['ln_expires_at' => $now - 10, 'ln_refresh_lock_at' => null], 'id = ?', [$usd['id']]);
$before = mock_lnurl_requests($host['dir']);
$r = Invoice::refreshLightning($usd['id'], ['force' => true, 'requote' => true]);
assert_true(!empty($r['ok']), 'requote-flagged refresh ok');
assert_eq(5000, (int)row($usd['id'])['amount_sats'], 'requote flag ignored on a fee-routed rail');
assert_fee_only_requests(requests_since($before), 5000, 'usd requote refresh');
$price->behavior = fn() => 100000.0;
rate_cache_clear();

// ---- 5. fee payee down: unavailable, nothing else tried ----
$ll2 = Invoice::create($store, ['amount' => 7000, 'currency' => 'sat', 'lifetime_sec' => $lifetime, 'long_lived' => true]);
assert_eq(FEE_NOTE_DEV, $ll2['fee_redirect_note']);
mock_lnurl_set_mode($host['dir'], 'fail');
Database::update('invoices', ['ln_expires_at' => $now - 10], 'id = ?', [$ll2['id']]);
$before = mock_lnurl_requests($host['dir']);
$r = Invoice::refreshLightning($ll2['id']);
assert_eq('unavailable', $r['reason'], 'fee payee down -> unavailable');
assert_eq('lnurl', $r['receive_errors'][0]['type'] ?? null, 'sanitized reason reported');
$reqs = requests_since($before);
assert_false(stripos(implode("\n", $reqs), 'merchant') !== false, 'merchant address NOT tried as a fallback');
assert_eq(1, count(array_filter($reqs, fn($r) => str_starts_with($r, '/cb'))), 'one failed callback to the fee payee');
$kept = row($ll2['id']);
assert_eq($ll2['bolt11'], $kept['bolt11'], 'current bolt11 kept');
assert_eq('lnaddress', $kept['payment_rail'], 'rail unchanged (no mint takeover)');
assert_fee_columns_unchanged($ll2, $kept, 'unavailable');
assert_eq(0, count(parked($ll2['id'])), 'nothing parked on a failed refresh');
mock_lnurl_set_mode($host['dir'], 'ok');

// ---- 6. the OLD (superseded) fee bolt11 gets paid ----
mock_lnurl_settle($host['dir'], (string)$ll['lnurl_verify_url']);
$res = Invoice::pollSupersededQuotes($ll['id'], 0, 10);
assert_eq(1, $res['settled'], 'parked fee quote settled the invoice');
$settled = row($ll['id']);
assert_eq('Settled', $settled['status']);
assert_eq('lnaddress', $settled['settled_rail']);
assert_eq($ll['bolt11'], $settled['bolt11'], 'the bolt11 actually paid is recorded');
assert_eq(FEE_NOTE_DEV, $settled['fee_redirect_note'], 'fee attribution intact');
assert_true(Invoice::formatForApi($settled)['feeRedirect']['settledToFee'] ?? false, 'API reports settled to the fee');
$melt = Database::fetchOne("SELECT * FROM melts WHERE invoice_id = ? AND via = 'redirect'", [$ll['id']]);
assert_not_null($melt, 'redirect credit recorded');
assert_eq(5000, (int)$melt['amount_sats'], 'credit = locked sats');
assert_eq(FEE_NOTE_DEV, $melt['note']);
assert_eq((string)CASHUPAY_DEV_FEE_LNURL, $melt['destination'], 'credit destination = fee LNURL');
$owedAfter = DevFee::computeOwed($store);
assert_true((int)$owedAfter['dev_owed'] < 20000 + 50, 'owed went down by the credited amount (' . $owedAfter['dev_owed'] . ')');

echo "PASS test_long_lived_fee_redirect_refresh\n";
