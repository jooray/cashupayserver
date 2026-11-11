<?php
/**
 * AddressCheck is the pure-PHP (no GMP) address validator behind
 * OnchainWallet::validateAddress's fallback path. Shared WordPress hosts
 * frequently run PHP without the GMP extension, and before this validator
 * existed a perfectly valid pasted address was rejected there with "we could
 * not read that as a Bitcoin address".
 *
 * These assert that, using only pure PHP:
 *   - every accepted encoding validates on its network (base58 P2PKH/P2SH,
 *     bech32 v0 P2WPKH/P2WSH, bech32m v1 P2TR),
 *   - checksum damage of a single character is caught for each encoding,
 *   - network confusion is rejected (mainnet address on testnet and vice
 *     versa), and
 *   - BIP173 case rules hold (all-upper OK, mixed-case rejected).
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require_once dirname(__DIR__, 2) . '/app/includes/onchain/address_check.php';

// --- valid addresses per network ---
$valid = [
    // [address, network, label]
    ['17VZNX1SN5NtKa8UQFxwQbFeFc3iqRYhem', 'mainnet', 'P2PKH'],
    ['3J98t1WpEZ73CNmQviecrnyiWrnqRhWNLy', 'mainnet', 'P2SH'],
    ['bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4', 'mainnet', 'P2WPKH'],
    // The user-reported address from the original bug:
    ['bc1qar0srrr7xfkvy5l643lydnw9re59gtzzwf5mdq', 'mainnet', 'P2WPKH (reported bug)'],
    // BIP173 P2WSH vector:
    ['bc1qc7slrfxkknqcq2jevvvkdgvrt8080852dfjewde450xdlk4ugp7szw5tk9', 'mainnet', 'P2WSH'],
    // BIP350 P2TR vector:
    ['bc1p0xlxvlhemja6c4dqv22uapctqupfhlxm9h8z3k2e72q4k9hcz7vqzk5jj0', 'mainnet', 'P2TR'],
    ['mipcBbFg9gMiCh81Kj8tqqdgoZub1ZJRfn', 'testnet', 'testnet P2PKH'],
    ['2MzQwSSnBHWHqSAqtTVQ6v47XtaisrJa1Vc', 'testnet', 'testnet P2SH'],
    ['tb1qw508d6qejxtdg4y5r3zarvary0c5xw7kxpjzsx', 'testnet', 'testnet P2WPKH'],
    ['tb1qw508d6qejxtdg4y5r3zarvary0c5xw7kxpjzsx', 'signet', 'signet shares tb1'],
    ['mipcBbFg9gMiCh81Kj8tqqdgoZub1ZJRfn', 'regtest', 'regtest P2PKH (testnet bytes)'],
    ['bcrt1qw508d6qejxtdg4y5r3zarvary0c5xw7kygt080', 'regtest', 'regtest P2WPKH'],
    // BIP173: all-uppercase form is legal.
    ['BC1QW508D6QEJXTDG4Y5R3ZARVARY0C5XW7KV8F3T4', 'mainnet', 'uppercase P2WPKH'],
];
foreach ($valid as [$addr, $net, $label]) {
    $r = AddressCheck::validate($addr, $net);
    assert_true($r['valid'], "$label valid on $net: " . ($r['error'] ?? ''));
}

// --- single-character damage is caught for each encoding ---
$damaged = [
    ['17VZNX1SN5NtKa8UQFxwQbFeFc3iqRYhen', 'mainnet', 'P2PKH checksum'],
    ['3J98t1WpEZ73CNmQviecrnyiWrnqRhWNLz', 'mainnet', 'P2SH checksum'],
    ['bc1qar0srrr7xfkvy5l643lydnw9re59gtzzwf5mdp', 'mainnet', 'P2WPKH checksum'],
    ['bc1p0xlxvlhemja6c4dqv22uapctqupfhlxm9h8z3k2e72q4k9hcz7vqzk5jj1', 'mainnet', 'P2TR checksum'],
];
foreach ($damaged as [$addr, $net, $label]) {
    assert_false(AddressCheck::validate($addr, $net)['valid'], "$label damage rejected");
}

// --- network confusion rejected ---
$confused = [
    ['bc1qar0srrr7xfkvy5l643lydnw9re59gtzzwf5mdq', 'testnet'],
    ['bc1qar0srrr7xfkvy5l643lydnw9re59gtzzwf5mdq', 'regtest'],
    ['tb1qw508d6qejxtdg4y5r3zarvary0c5xw7kxpjzsx', 'mainnet'],
    ['bcrt1qw508d6qejxtdg4y5r3zarvary0c5xw7kygt080', 'testnet'],
    ['17VZNX1SN5NtKa8UQFxwQbFeFc3iqRYhem', 'testnet'],
    ['mipcBbFg9gMiCh81Kj8tqqdgoZub1ZJRfn', 'mainnet'],
];
foreach ($confused as [$addr, $net]) {
    assert_false(AddressCheck::validate($addr, $net)['valid'], "$addr rejected on $net");
}

// --- malformed input ---
assert_false(AddressCheck::validate('', 'mainnet')['valid'], 'empty rejected');
assert_false(AddressCheck::validate('notanaddress', 'mainnet')['valid'], 'garbage rejected');
assert_false(AddressCheck::validate('bc1qar0srrr', 'mainnet')['valid'], 'truncated bech32 rejected');
assert_false(
    AddressCheck::validate('bC1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4', 'mainnet')['valid'],
    'mixed-case bech32 rejected (BIP173)'
);
assert_false(
    AddressCheck::validate(str_repeat('bc1q', 30), 'mainnet')['valid'],
    'over-length string rejected'
);
assert_false(AddressCheck::validate('xyz', 'nosuchnet')['valid'], 'unknown network rejected');

// Unknown/future witness version: re-encoded v2 program must not validate —
// we cannot watch an output type we don't understand.
assert_false(
    AddressCheck::validate('bc1zw508d6qejxtdg4y5r3zarvaryvaxxpcs', 'mainnet')['valid'],
    'witness v2 (bc1z…) rejected'
);

echo "test_onchain_address_check_pure: OK\n";
