<?php
/**
 * Taproot::parseUnsignedTx must bounds-check provider-supplied transaction
 * hex. A truncated or maliciously-sized lockup tx (the input comes from the
 * swap provider) must raise a clear exception instead of warning on a short
 * unpack() and feeding garbage into the claim builder — which previously left
 * the swap wedged, re-crashing every poll.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require_once dirname(__DIR__, 2) . '/app/includes/crypto/taproot.php';

// A minimal, well-formed unsigned tx: 1 input, 1 output (OP_1 spk), value 1000.
$hex =
    '01000000' .                       // version
    '01' .                             // numIn = 1
    str_repeat('00', 32) .             // prevout txid
    '00000000' .                       // prevout vout
    '00' .                             // scriptSig len = 0
    'ffffffff' .                       // sequence
    '01' .                             // numOut = 1
    'e803000000000000' .               // value = 1000 sat (LE uint64)
    '01' .                             // scriptPubKey len = 1
    '51' .                             // OP_1
    '00000000';                        // locktime
$raw = hex2bin($hex);

// 1. Valid tx parses and yields the expected output value.
$parsed = Taproot::parseUnsignedTx($raw);
assert_eq(1000, (int)$parsed['outputs'][0]['value'], 'valid tx output value');
assert_eq(1, count($parsed['inputs']), 'valid tx input count');

// 2. Truncated buffer (cut mid-output) must throw, not warn+misparse.
$threw = false;
try {
    Taproot::parseUnsignedTx(substr($raw, 0, strlen($raw) - 6));
} catch (\Throwable $e) {
    $threw = true;
}
assert_true($threw, 'truncated tx must throw');

// 3. A compactSize claiming 65535 inputs in a tiny buffer must be rejected
//    before driving a giant loop.
$evil = hex2bin('01000000' . 'fdffff' . '00000000');
$threw = false;
try {
    Taproot::parseUnsignedTx($evil);
} catch (\Throwable $e) {
    $threw = true;
}
assert_true($threw, 'implausible input count must throw');

// 4. Empty / 1-byte buffers must throw rather than fatal on offset read.
foreach (['', "\x01"] as $junk) {
    $threw = false;
    try {
        Taproot::parseUnsignedTx($junk);
    } catch (\Throwable $e) {
        $threw = true;
    }
    assert_true($threw, 'junk buffer must throw');
}

echo "PASS test_tx_parser_bounds\n";
exit(0);
