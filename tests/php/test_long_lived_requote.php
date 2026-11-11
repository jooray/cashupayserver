<?php
/**
 * Fiat re-quote of long-lived invoices on page load (Invoice::requoteForView /
 * prepareForView), with a scripted price provider and the mock LNURL host:
 *
 *   1. Rate moved → on-chain amount re-quoted, floor = smallest amount ever
 *      shown, Lightning re-sourced at the new amount (old bolt11 parked),
 *      exchange_rate + requoted_at updated.
 *   2. A reload inside REQUOTE_MIN_INTERVAL changes nothing (throttle).
 *   3. Rate moved the other way → amount up, floor stays at the minimum.
 *   4. Static shared address: on-chain amount locked, Lightning still re-quoted.
 *   5. No re-quote once a payment was seen on-chain, for ordinary invoices,
 *      for sat-denominated invoices, or when no rate is available.
 *   6. prepareForView also refreshes a lapsed bolt11 on a sat invoice.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require __DIR__ . '/mock_lnurl_host.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/invoice.php';
require_once dirname(__DIR__, 2) . '/app/includes/rates.php';

final class StubPriceProvider implements PriceProvider {
    public $behavior;
    public function __construct(callable $behavior) { $this->behavior = $behavior; }
    public function getName(): string { return 'stub'; }
    public function getBtcPrice(string $currency): ?float { return ($this->behavior)($currency); }
}
$price = new StubPriceProvider(fn() => 100000.0);
ExchangeRates::$testProviders = ['stub' => $price];
/** Drop the 5-minute rate cache so the next quote sees the stub's new price. */
function rate_cache_clear(): void {
    Database::query("DELETE FROM config WHERE key LIKE 'rate_%'");
    Config::clearCache();
}

$host = start_mock_lnurl_host();
putenv('CASHU_LNURL_URL_TEMPLATE=' . $host['url_template']);
Config::set('allow_private_endpoints', true);
$now = time();
$lifetime = Config::getSharedInvoiceLifetime();

make_store('s_xpub', 'http://127.0.0.1:1');
Database::update('stores', ['price_provider_primary' => 'stub', 'price_provider_secondary' => 'stub'], 'id = ?', ['s_xpub']);
StoreLnAddresses::replaceForStore('s_xpub', ['merchant@example.test']);
make_store('s_static', 'http://127.0.0.1:1');
Database::update('stores', [
    'price_provider_primary' => 'stub', 'price_provider_secondary' => 'stub',
    'onchain_address_mode' => 'static', 'onchain_static_address' => 'bcrt1qstaticaddress',
], 'id = ?', ['s_static']);
StoreLnAddresses::replaceForStore('s_static', ['merchant@example.test']);

function row(string $id): array { return Database::fetchOne("SELECT * FROM invoices WHERE id = ?", [$id]); }
function parked_count(string $id): int {
    return (int)Database::fetchOne("SELECT COUNT(*) AS c FROM invoice_ln_quotes WHERE invoice_id = ?", [$id])['c'];
}
/** A long-lived $10 invoice priced at 100k USD/BTC: 10 000 sats on both rails. */
function seed_invoice(string $id, string $store, array $extra = []): array {
    global $now, $lifetime;
    Database::insert('invoices', array_merge([
        'id' => $id, 'store_id' => $store, 'status' => 'New', 'additional_status' => 'None',
        'amount' => '10.00', 'currency' => 'USD', 'amount_sats' => 10000, 'exchange_rate' => 100000.0,
        'payment_rail' => 'lnaddress', 'bolt11' => 'lnbc1pretendseed' . $id,
        'lnurl_verify_url' => 'http://127.0.0.1:1/verify/seed', 'ln_destination' => 'merchant@example.test',
        'onchain_address' => 'bcrt1q' . substr(md5($id), 0, 30), 'onchain_amount_sat' => 10000,
        'created_at' => $now - 86400 * 3, 'expiration_time' => $now + $lifetime,
        'ln_expires_at' => $now + 3000, 'long_lived' => 1,
    ], $extra));
    return row($id);
}

// ---- 1. rate doubled: $10 is now 5 000 sats ----
$inv = seed_invoice('ll_a', 's_xpub');
$price->behavior = fn() => 200000.0;
rate_cache_clear();
$res = Invoice::requoteForView($inv);
assert_true($res['requoted'], 're-quoted');
assert_true($res['lightning_done'], 'lightning handled inside the re-quote');
$a = row('ll_a');
assert_eq(5000, (int)$a['onchain_amount_sat'], 'on-chain amount re-quoted');
assert_eq(5000, (int)$a['onchain_amount_floor_sat'], 'floor = smallest amount shown');
assert_eq(5000, (int)$a['amount_sats'], 'Lightning amount re-quoted');
assert_neq($inv['bolt11'], $a['bolt11'], 'new bolt11 sourced at the new amount');
assert_eq(1, parked_count('ll_a'), 'old bolt11 parked');
assert_eq(200000.0, (float)$a['exchange_rate'], 'rate recorded');
assert_true((int)$a['requoted_at'] >= $now, 'requoted_at stamped');
assert_eq('10.00', $a['amount'], 'fiat amount itself never changes');

// ---- 2. throttle ----
$price->behavior = fn() => 50000.0;
rate_cache_clear();
$res = Invoice::requoteForView(row('ll_a'));
assert_false($res['requoted'], 'reload inside the throttle window does nothing');
assert_eq(5000, (int)row('ll_a')['onchain_amount_sat'], 'amount unchanged by the throttled reload');

// ---- 3. rate halved: $10 is now 20 000 sats; floor stays at 5 000 ----
Database::update('invoices', ['requoted_at' => $now - 2 * Invoice::REQUOTE_MIN_INTERVAL, 'ln_refresh_lock_at' => null], 'id = ?', ['ll_a']);
$res = Invoice::requoteForView(row('ll_a'));
assert_true($res['requoted'], 're-quoted after the window');
$a = row('ll_a');
assert_eq(20000, (int)$a['onchain_amount_sat'], 'on-chain amount up');
assert_eq(5000, (int)$a['onchain_amount_floor_sat'], 'floor keeps the minimum ever shown');
assert_eq(20000, (int)$a['amount_sats'], 'Lightning amount up');
assert_eq(2, parked_count('ll_a'), 'second bolt11 parked');

// ---- 4. static shared address: on-chain locked, Lightning re-quoted ----
$price->behavior = fn() => 200000.0;
rate_cache_clear();
$st = seed_invoice('ll_static', 's_static', ['onchain_address' => 'bcrt1qstaticaddress', 'onchain_amount_tweak_sats' => 7, 'onchain_amount_sat' => 10007]);
$res = Invoice::requoteForView($st);
assert_true($res['requoted'], 'static store: Lightning side re-quoted');
$s = row('ll_static');
assert_eq(10007, (int)$s['onchain_amount_sat'], 'static-address amount locked (exact-amount attribution)');
assert_null($s['onchain_amount_floor_sat'], 'no floor on a static address');
assert_eq(5000, (int)$s['amount_sats'], 'Lightning re-quoted');

// ---- 5. never re-quoted ----
$seen = seed_invoice('ll_seen', 's_xpub', ['onchain_first_seen_at' => $now - 60]);
assert_false(Invoice::requoteForView($seen)['requoted'], 'payment seen on-chain → locked');
assert_eq(10000, (int)row('ll_seen')['onchain_amount_sat'], 'amount untouched');
$reg = seed_invoice('reg_fiat', 's_xpub', ['long_lived' => 0, 'expiration_time' => $now + 3600]);
assert_false(Invoice::requoteForView($reg)['requoted'], 'ordinary invoice → never');
assert_null(row('reg_fiat')['requoted_at'], 'ordinary invoice not even stamped');
$sat = seed_invoice('ll_sat', 's_xpub', ['amount' => '10000', 'currency' => 'sat', 'exchange_rate' => null]);
assert_false(Invoice::requoteForView($sat)['requoted'], 'sat invoice → nothing to re-quote');
assert_eq($sat['bolt11'], row('ll_sat')['bolt11'], 'sat invoice bolt11 untouched (not lapsed)');
$price->behavior = fn() => throw new RuntimeException('provider down');
rate_cache_clear();
$dark = seed_invoice('ll_dark', 's_xpub');
$res = Invoice::requoteForView($dark);
assert_false($res['requoted'], 'no rate → no re-quote, no exception');
assert_true((int)row('ll_dark')['requoted_at'] >= $now, 'stamped anyway so reloads do not hammer the providers');
assert_eq(10000, (int)row('ll_dark')['onchain_amount_sat'], 'amount untouched');
$price->behavior = fn() => 100000.0;

// ---- 6. prepareForView: lapsed bolt11 on a sat invoice is refreshed ----
Database::update('invoices', ['ln_expires_at' => $now - 10], 'id = ?', ['ll_sat']);
Invoice::prepareForView('ll_sat');
$sp = row('ll_sat');
assert_neq($sat['bolt11'], $sp['bolt11'], 'prepareForView refreshed the lapsed bolt11');
assert_eq(10000, (int)$sp['amount_sats'], 'amount unchanged');
Invoice::prepareForView('reg_fiat'); // ordinary invoice: no-op, no exception
assert_eq($reg['bolt11'], row('reg_fiat')['bolt11'], 'ordinary invoice untouched by prepareForView');

echo "PASS test_long_lived_requote\n";
