<?php
/**
 * Configurable payment-rail ordering (RailOrder + StoreLnAddresses):
 *
 *   1. Lenient read-side normalization: NULL/empty → default order; unknown
 *      types dropped; duplicates collapsed; missing types appended in
 *      default order; case-folded.
 *   2. Strict write-side parsing: only a full permutation of the known
 *      types is accepted; anything else throws.
 *   3. Persistence: setLnOrder / setOnchainOrder round-trip through the
 *      stores columns; lnOrderForStore / onchainOrderForStore read them.
 *   4. listForStore: with NO explicit order the stored per-row order is
 *      returned verbatim (historical behavior — a legacy interleaved chain
 *      stays interleaved); with an explicit order the chain is
 *      stable-sorted by type, entries of the same type keeping their
 *      stored relative priority.
 *   5. primaryForStore follows the configured order.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/rail_order.php';
require_once dirname(__DIR__, 2) . '/app/includes/store_ln_addresses.php';
require_once dirname(__DIR__, 2) . '/app/includes/clink/noffer.php';

$store = 'store_rail_order';
make_store($store);

// ---------- 1. lenient normalization ----------
assert_eq(['strike', 'lnaddress', 'nwc', 'noffer'], RailOrder::lnOrderFromRow(null),
    'missing row → default order');
assert_eq(['strike', 'lnaddress', 'nwc', 'noffer'], RailOrder::lnOrderFromRow(['ln_rail_order' => null]),
    'NULL → default order');
assert_eq(['strike', 'lnaddress', 'nwc', 'noffer'], RailOrder::lnOrderFromRow(['ln_rail_order' => '']),
    'empty → default order');
assert_eq(['noffer', 'nwc', 'lnaddress', 'strike'],
    RailOrder::lnOrderFromRow(['ln_rail_order' => 'noffer,nwc,lnaddress,strike']),
    'full permutation honored');
assert_eq(['nwc', 'strike', 'lnaddress', 'noffer'],
    RailOrder::lnOrderFromRow(['ln_rail_order' => 'nwc']),
    'partial value → missing types appended in default order');
assert_eq(['noffer', 'strike', 'lnaddress', 'nwc'],
    RailOrder::lnOrderFromRow(['ln_rail_order' => 'noffer,bogus,noffer, ,']),
    'unknowns dropped, duplicates collapsed, blanks skipped');
assert_eq(['noffer', 'nwc', 'lnaddress', 'strike'],
    RailOrder::lnOrderFromRow(['ln_rail_order' => 'NOFFER, Nwc ,LNADDRESS,Strike']),
    'case-folded and trimmed');
assert_eq(['strike', 'local'], RailOrder::onchainOrderFromRow(null),
    'on-chain default order');
assert_eq(['local', 'strike'], RailOrder::onchainOrderFromRow(['onchain_source_order' => 'local,strike']),
    'on-chain order honored');
assert_eq(['local', 'strike'], RailOrder::onchainOrderFromRow(['onchain_source_order' => 'local']),
    'on-chain partial → missing appended');

// ---------- 2. strict write-side parsing ----------
assert_eq(['noffer', 'strike', 'lnaddress', 'nwc'],
    RailOrder::parseLnOrder('noffer,strike,lnaddress,nwc'), 'valid permutation parses');
foreach ([
    'strike,lnaddress,nwc',                    // missing a type
    'strike,lnaddress,nwc,noffer,strike',      // duplicate
    'strike,lnaddress,nwc,bogus',              // unknown
    '',                                        // empty
    'strike,lnaddress,nwc,noffer,extra',       // extra entry
] as $bad) {
    $threw = false;
    try {
        RailOrder::parseLnOrder($bad);
    } catch (InvalidArgumentException $e) {
        $threw = true;
    }
    assert_true($threw, "strict parse rejects '{$bad}'");
}
$threw = false;
try {
    RailOrder::parseOnchainOrder('strike');
} catch (InvalidArgumentException $e) {
    $threw = true;
}
assert_true($threw, 'strict on-chain parse rejects a partial order');
assert_eq(['local', 'strike'], RailOrder::parseOnchainOrder('local,strike'), 'valid on-chain order parses');

// ---------- 3. persistence round-trip ----------
assert_null(RailOrder::storedLnOrderCsv($store), 'fresh store has no explicit order');
RailOrder::setLnOrder($store, 'noffer,nwc,lnaddress,strike');
assert_eq('noffer,nwc,lnaddress,strike', RailOrder::storedLnOrderCsv($store), 'CSV persisted');
assert_eq(['noffer', 'nwc', 'lnaddress', 'strike'], RailOrder::lnOrderForStore($store), 'order read back');
$threw = false;
try {
    RailOrder::setLnOrder($store, 'strike,strike,nwc,noffer');
} catch (InvalidArgumentException $e) {
    $threw = true;
}
assert_true($threw, 'setLnOrder rejects a non-permutation');
assert_eq('noffer,nwc,lnaddress,strike', RailOrder::storedLnOrderCsv($store),
    'rejected write leaves the stored order untouched');

RailOrder::setOnchainOrder($store, 'local,strike');
assert_eq(['local', 'strike'], RailOrder::onchainOrderForStore($store), 'on-chain order persisted');
RailOrder::setLnOrder($store, 'strike,lnaddress,nwc,noffer'); // back to default for section 4

// ---------- 4. listForStore ordering ----------
$noffer = ClinkNoffer::encode([
    'pubkey' => str_repeat('ab', 32),
    'relay' => 'wss://relay.test',
    'offer' => 'shop',
    'price_type' => ClinkNoffer::PRICE_SPONTANEOUS,
]);
$nwcUri = 'nostr+walletconnect://' . str_repeat('11', 32)
    . '?relay=ws%3A%2F%2F127.0.0.1%3A7777&secret=' . str_repeat('22', 32);
$strikeKey = 'RAILORDERKEY0' . str_repeat('A', 27);

// A deliberately interleaved chain (only reachable through direct
// replaceForStore / the legacy shape-classified API): noffer first, then two
// addresses, strike, nwc, second noffer.
$noffer2 = ClinkNoffer::encode([
    'pubkey' => str_repeat('cd', 32),
    'relay' => 'wss://relay2.test',
    'offer' => 'shop2',
    'price_type' => ClinkNoffer::PRICE_SPONTANEOUS,
]);
$interleaved = [
    ['type' => 'noffer', 'address' => $noffer],
    ['type' => 'lnaddress', 'address' => 'first@wallet.test', 'supports_verify' => 1],
    ['type' => 'strike', 'address' => $strikeKey],
    ['type' => 'lnaddress', 'address' => 'second@wallet.test', 'supports_verify' => 1],
    ['type' => 'nwc', 'address' => $nwcUri],
    ['type' => 'noffer', 'address' => $noffer2],
];

$storeLegacy = 'store_rail_legacy';
make_store($storeLegacy);
StoreLnAddresses::replaceForStore($storeLegacy, $interleaved);
// No explicit order → stored order verbatim, interleaving preserved.
assert_eq(['noffer', 'lnaddress', 'strike', 'lnaddress', 'nwc', 'noffer'],
    array_map(fn($d) => $d['type'], StoreLnAddresses::destinationsForStore($storeLegacy)),
    'NULL order keeps the stored interleaving (historical behavior)');

// Explicit DEFAULT order → grouped strike-first, within-type order kept.
RailOrder::setLnOrder($storeLegacy, 'strike,lnaddress,nwc,noffer');
$d = StoreLnAddresses::destinationsForStore($storeLegacy);
assert_eq(['strike', 'lnaddress', 'lnaddress', 'nwc', 'noffer', 'noffer'],
    array_map(fn($x) => $x['type'], $d), 'explicit default order groups by type');
assert_eq('first@wallet.test', $d[1]['value'], 'within-type order preserved (first address)');
assert_eq('second@wallet.test', $d[2]['value'], 'within-type order preserved (second address)');
assert_eq($noffer, $d[4]['value'], 'within-type order preserved (first noffer)');
assert_eq($noffer2, $d[5]['value'], 'within-type order preserved (second noffer)');

// Reversed order → noffers lead, strike last.
RailOrder::setLnOrder($storeLegacy, 'noffer,nwc,lnaddress,strike');
$d = StoreLnAddresses::destinationsForStore($storeLegacy);
assert_eq(['noffer', 'noffer', 'nwc', 'lnaddress', 'lnaddress', 'strike'],
    array_map(fn($x) => $x['type'], $d), 'reversed order re-sorts the chain');
assert_eq($noffer, $d[0]['value'], 'noffer relative order kept after re-sort');

// ---------- 5. primaryForStore follows the configured order ----------
assert_eq($noffer, StoreLnAddresses::primaryForStore($storeLegacy),
    'primary is the first destination of the configured order');
RailOrder::setLnOrder($storeLegacy, 'lnaddress,strike,nwc,noffer');
assert_eq('first@wallet.test', StoreLnAddresses::primaryForStore($storeLegacy),
    'primary follows an order change');

echo "test_rail_order: ok\n";
