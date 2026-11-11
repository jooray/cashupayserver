<?php
/**
 * Unit tests for the CLINK noffer (NIP-69) codec: bech32 + TLV encode/decode,
 * checksum verification, the lightning: scheme prefix, the real reference
 * noffer from @shocknet/clink-sdk, and rejection of malformed input.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require_once dirname(__DIR__, 2) . '/app/includes/clink/noffer.php';

// ---------- round-trip with all fields ----------
$pk = str_repeat('ab', 32);
$enc = ClinkNoffer::encode([
    'pubkey' => $pk,
    'relay' => 'wss://relay.example.com',
    'offer' => 'zap-coffee',
    'price_type' => ClinkNoffer::PRICE_FIXED,
    'price' => 1000,
]);
assert_true(str_starts_with($enc, 'noffer1'), 'encodes with noffer1 prefix');
$d = ClinkNoffer::decode($enc);
assert_eq($pk, $d['pubkey'], 'pubkey round-trips');
assert_eq('wss://relay.example.com', $d['relay'], 'relay round-trips');
assert_eq('zap-coffee', $d['offer'], 'offer round-trips');
assert_eq(ClinkNoffer::PRICE_FIXED, $d['price_type'], 'price_type round-trips');
assert_eq(1000, $d['price'], 'price round-trips');

// ---------- minimal (spontaneous, no price) ----------
$enc2 = ClinkNoffer::encode(['pubkey' => $pk, 'relay' => 'wss://r.test', 'offer' => 'x']);
$d2 = ClinkNoffer::decode($enc2);
assert_null($d2['price_type'], 'no price_type → null');
assert_null($d2['price'], 'no price → null');

// ---------- multiple relays ----------
$enc3 = ClinkNoffer::encode([
    'pubkey' => $pk,
    'relays' => ['wss://a.test', 'wss://b.test'],
    'offer' => 'multi',
]);
$d3 = ClinkNoffer::decode($enc3);
assert_eq('wss://a.test', $d3['relay'], 'first relay is primary');
assert_eq(['wss://a.test', 'wss://b.test'], $d3['relays'], 'all relays decoded');

// ---------- lightning: scheme prefix tolerated ----------
$d4 = ClinkNoffer::decode('lightning:' . $enc);
assert_eq($pk, $d4['pubkey'], 'lightning: prefix stripped');
assert_true(ClinkNoffer::isValid('lightning:' . $enc), 'isValid accepts lightning: prefix');

// ---------- real reference noffer from @shocknet/clink-sdk README ----------
$real = 'noffer1qvqsyqjqxuurvwpcxc6rvvrxxsurqep5vfjk2wf4v33nsenrxumnyvesxfnrswfkvycrwdp3x93xydf5xg6rzce4vv6xgdfh8quxgct9x5erxvspremhxue69uhhgetnwskhyetvv9ujumrfva58gmnfdenjuur4vgqzpccxc30wpf78wf2q78wg3vq008fd8ygtl4qy06gstpye3h5unc47xmee6z';
$r = ClinkNoffer::decode($real);
assert_eq(64, strlen($r['pubkey']), 'real noffer: 32-byte pubkey');
assert_eq('wss://test-relay.lightning.pub', $r['relay'], 'real noffer: relay');
assert_eq(ClinkNoffer::PRICE_SPONTANEOUS, $r['price_type'], 'real noffer: spontaneous price type');

// ---------- rejection cases ----------
assert_false(ClinkNoffer::isValid('noffer1garbage'), 'bad checksum rejected');
assert_false(ClinkNoffer::isValid('npub1qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq'), 'npub (wrong prefix) rejected');
assert_false(ClinkNoffer::isValid('me@strike.me'), 'lightning address rejected as noffer');
assert_false(ClinkNoffer::isValid(''), 'empty rejected');

// A bech32 string with the right checksum but a non-noffer prefix must fail.
$threw = false;
try {
    ClinkNoffer::decode($enc); // valid
    // tamper the prefix → checksum breaks → throws
    ClinkNoffer::decode('xoffer1' . substr($enc, 7));
} catch (InvalidArgumentException $e) {
    $threw = true;
}
assert_true($threw, 'wrong prefix / broken checksum throws');

// ---------- encode input validation ----------
$threw = false;
try { ClinkNoffer::encode(['pubkey' => 'short', 'relay' => 'wss://r', 'offer' => 'o']); }
catch (InvalidArgumentException $e) { $threw = true; }
assert_true($threw, 'encode rejects non-32-byte pubkey');

echo "test_clink_noffer_codec: ok\n";
