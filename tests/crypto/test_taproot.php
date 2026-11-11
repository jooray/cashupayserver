<?php
/**
 * Taproot (BIP341) test vectors plus a small bech32m round-trip suite.
 *
 * Vectors sourced from:
 *   https://github.com/bitcoin/bips/blob/master/bip-0341/wallet-test-vectors.json
 */

require_once __DIR__ . '/../../app/includes/crypto/taproot.php';

$failures = 0;
$total = 0;

function expectEq(string $name, string $expected, string $got, &$failures): void {
    global $total; $total++;
    if (strtolower($expected) === strtolower($got)) {
        echo "PASS {$name}\n";
    } else {
        echo "FAIL {$name}\n  expected={$expected}\n  got     ={$got}\n";
        $failures++;
    }
}

// ----- Test 1: TapTweak with no script tree (key-path only) -----
// internal_key = d6889cb081036e0faefa3a35157ad71086b123b2b144b649798b494c300a961d
// expected tweaked_pubkey = 53a1f6e454df1aa2776a2814a721372d6258050de330b3c6d10ee8f4e0dda343
$internal = hex2bin('d6889cb081036e0faefa3a35157ad71086b123b2b144b649798b494c300a961d');
[$outKey, $parity] = Taproot::tweakOutputKey($internal, '');
expectEq('tweak key-path only',
    '53a1f6e454df1aa2776a2814a721372d6258050de330b3c6d10ee8f4e0dda343',
    bin2hex($outKey),
    $failures);

// ----- Test 2: P2TR bech32m address encoding from test 1 -----
$addr = Taproot::encodeP2trAddress($outKey, 'mainnet');
expectEq('encode P2TR mainnet (test 1)',
    'bc1p2wsldez5mud2yam29q22wgfh9439spgduvct83k3pm50fcxa5dps59h4z5',
    $addr,
    $failures);

// ----- Test 3: TapTweak with one leaf script -----
// internal_key = 187791b6f712a8ea41c8ecdd0ee77fab3e85263b37e1ec18a3651926b3a6cf27
// script = 20d85a959b0290bf19bb89ed43c916be835475d013da4b362117393e25a48229b8ac
// leaf version 0xc0
// expected leafHash = 5b75adecf53548f3ec6ad7d78383bf84cc57b55a3127c72b9a2481752dd88b21
// expected output_key = 147c9c57132f6e7ecddba9800bb0c4449251c92a1e60371ee77557b6620f3ea3
$script = hex2bin('20d85a959b0290bf19bb89ed43c916be835475d013da4b362117393e25a48229b8ac');
$leafHash = Taproot::tapLeafHash(Taproot::TAPSCRIPT_LEAF_VERSION, $script);
expectEq('tap_leaf_hash',
    '5b75adecf53548f3ec6ad7d78383bf84cc57b55a3127c72b9a2481752dd88b21',
    bin2hex($leafHash),
    $failures);

$internal2 = hex2bin('187791b6f712a8ea41c8ecdd0ee77fab3e85263b37e1ec18a3651926b3a6cf27');
[$outKey2, $parity2] = Taproot::tweakOutputKey($internal2, $leafHash);
expectEq('tweak single-leaf',
    '147c9c57132f6e7ecddba9800bb0c4449251c92a1e60371ee77557b6620f3ea3',
    bin2hex($outKey2),
    $failures);

// ----- Test 4: P2TR address round trip -----
$addrSingle = Taproot::encodeP2trAddress($outKey2, 'mainnet');
expectEq('encode P2TR mainnet (test 3)',
    'bc1pz37fc4cn9ah8anwm4xqqhvxygjf9rjf2resrw8h8w4tmvcs0863sa2e586',
    $addrSingle,
    $failures);

$decoded = Taproot::decodeP2trAddress($addrSingle, 'mainnet');
expectEq('decode P2TR mainnet round-trip',
    bin2hex($outKey2),
    bin2hex($decoded),
    $failures);

// ----- Test 5: tap_branch_hash ordering invariance -----
// branch_hash(a, b) == branch_hash(b, a) (because of lex sort)
$a = hex2bin('aaaa00000000000000000000000000000000000000000000000000000000aaaa');
$b = hex2bin('bbbb00000000000000000000000000000000000000000000000000000000bbbb');
expectEq('tap_branch order-invariant',
    bin2hex(Taproot::tapBranchHash($a, $b)),
    bin2hex(Taproot::tapBranchHash($b, $a)),
    $failures);

// ----- Test 6: bech32m round trip for testnet/regtest HRPs -----
$progHex = '53a1f6e454df1aa2776a2814a721372d6258050de330b3c6d10ee8f4e0dda343';
$prog = hex2bin($progHex);
foreach (['mainnet', 'testnet', 'regtest'] as $net) {
    $a = Taproot::encodeP2trAddress($prog, $net);
    $d = Taproot::decodeP2trAddress($a, $net);
    expectEq("bech32m round-trip {$net}", $progHex, bin2hex($d), $failures);
}

// ----- Test 7: BIP327 KeyAgg vectors -----
// Source: https://github.com/bitcoin/bips/blob/master/bip-0327/vectors/key_agg_vectors.json
$pubs = [
    hex2bin('02F9308A019258C31049344F85F89D5229B531C845836F99B08601F113BCE036F9'),
    hex2bin('03DFF1D77F2A671C5F36183726DB2341BE58FEAE1DA2DECED843240F7B502BA659'),
    hex2bin('023590A94E768F8E1815C2F24B4D80A8E3149316C3518CE7B7AD338368D038CA66'),
];
$cases = [
    [[0,1,2], '90539EEDE565F5D054F32CC0C220126889ED1E5D193BAF15AEF344FE59D4610C'],
    [[2,1,0], '6204DE8B083426DC6EAF9502D27024D53FC826BF7D2012148A0575435DF54B2B'],
    [[0,0,0], 'B436E3BAD62B8CD409969A224731C193D051162D8C5AE8B109306127DA3AA935'],
    [[0,0,1,1], '69BC22BFA5D106306E48A20679DE1D7389386124D07571D0D872686028C26A3E'],
];
foreach ($cases as [$idxs, $expected]) {
    $input = [];
    foreach ($idxs as $i) $input[] = $pubs[$i];
    $out = Taproot::keyAggInternalKey($input);
    expectEq("KeyAgg vector idx=[" . implode(',', $idxs) . "]", $expected, bin2hex($out), $failures);
}

// ----- Test 8: compact size encoding -----
$cases = [
    [0, '00'],
    [252, 'fc'],
    [253, 'fdfd00'],
    [65535, 'fdffff'],
    [65536, 'fe00000100'],
    [4294967295, 'feffffffff'],
];
foreach ($cases as [$n, $hex]) {
    expectEq("compactSize {$n}", $hex, bin2hex(Taproot::compactSize($n)), $failures);
}

echo "\n{$total} tested, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
