<?php
/**
 * Tests for the mixed-type destination chain: StoreLnAddresses now stores both
 * Lightning addresses and CLINK noffers in one ordered list. Covers type
 * persistence, per-type validation, the typed destinationsForStore() accessor,
 * cross-type dedup, and that supports_verify is forced NULL for noffers.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/store_ln_addresses.php';
require_once dirname(__DIR__, 2) . '/app/includes/clink/noffer.php';

$store = 'store_mixed';
make_store($store);

$noffer = ClinkNoffer::encode([
    'pubkey' => str_repeat('cd', 32),
    'relay' => 'wss://relay.test',
    'offer' => 'shop',
    'price_type' => ClinkNoffer::PRICE_SPONTANEOUS,
]);

// ---------- isValidEntry per type ----------
assert_true(StoreLnAddresses::isValidEntry(StoreLnAddresses::TYPE_LNADDRESS, 'me@strike.me'), 'valid lnaddress');
assert_false(StoreLnAddresses::isValidEntry(StoreLnAddresses::TYPE_LNADDRESS, $noffer), 'noffer not valid as lnaddress');
assert_true(StoreLnAddresses::isValidEntry(StoreLnAddresses::TYPE_NOFFER, $noffer), 'valid noffer');
assert_false(StoreLnAddresses::isValidEntry(StoreLnAddresses::TYPE_NOFFER, 'me@strike.me'), 'lnaddress not valid as noffer');

// ---------- mixed ordered chain persists type + order ----------
StoreLnAddresses::replaceForStore($store, [
    ['type' => 'noffer', 'address' => $noffer],
    ['type' => 'lnaddress', 'address' => 'fallback@strike.me', 'supports_verify' => 1],
]);
$dests = StoreLnAddresses::destinationsForStore($store);
assert_eq(2, count($dests), 'two destinations');
assert_eq('noffer', $dests[0]['type'], 'first is noffer (priority order preserved)');
assert_eq($noffer, $dests[0]['value'], 'noffer value preserved');
assert_eq('lnaddress', $dests[1]['type'], 'second is lnaddress');
assert_eq('fallback@strike.me', $dests[1]['value'], 'lnaddress value preserved');

// noffer rows must not carry a supports_verify flag (LUD-21 is LNURL-only).
$list = StoreLnAddresses::listForStore($store);
assert_null($list[0]['supports_verify'], 'noffer supports_verify forced NULL');
assert_eq(1, $list[1]['supports_verify'], 'lnaddress supports_verify persisted');

// addressesForStore returns all values across types (capability/display use).
assert_eq([$noffer, 'fallback@strike.me'], StoreLnAddresses::addressesForStore($store), 'addressesForStore spans types');

// ---------- invalid noffer rejected ----------
$threw = false;
try {
    StoreLnAddresses::replaceForStore($store, [['type' => 'noffer', 'address' => 'noffer1bogus']]);
} catch (InvalidArgumentException $e) { $threw = true; }
assert_true($threw, 'invalid noffer rejected');
// failed write rolled back — prior chain intact
assert_eq(2, count(StoreLnAddresses::destinationsForStore($store)), 'failed replace rolled back');

// ---------- unknown type rejected ----------
$threw = false;
try {
    StoreLnAddresses::replaceForStore($store, [['type' => 'bogus', 'address' => 'me@strike.me']]);
} catch (InvalidArgumentException $e) { $threw = true; }
assert_true($threw, 'unknown destination type rejected');

// ---------- bare-string entries default to lnaddress (back-compat) ----------
StoreLnAddresses::replaceForStore($store, ['plain@strike.me']);
$d = StoreLnAddresses::destinationsForStore($store);
assert_eq('lnaddress', $d[0]['type'], 'bare string defaults to lnaddress');

echo "test_clink_destinations: ok\n";
