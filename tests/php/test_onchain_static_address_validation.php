<?php
/**
 * Static-address mode lets a merchant paste a single receive address. The
 * validator (OnchainWallet::validateAddress) historically rejected bech32m
 * Taproot outputs (bc1p…/tb1p…/bcrt1p…) because bitwasp's AddressCreator
 * predates bech32m — yet Taproot is the default receive type in most modern
 * wallets, so pasting a perfectly valid mainnet address failed with
 * "Invalid address for mainnet".
 *
 * These assert that:
 *   - legacy/P2SH/segwit-v0 addresses still validate (no regression), and
 *   - Taproot addresses now validate on the matching network, and
 *   - an address for the wrong network is still rejected (no cross-network
 *     leak — a mainnet Taproot address must not save on a testnet store).
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require_once dirname(__DIR__, 2) . '/app/includes/onchain/wallet.php';
require_once dirname(__DIR__, 2) . '/app/includes/crypto/taproot.php';

// --- non-Taproot types keep working (regression guard) ---
assert_true(
    OnchainWallet::validateAddress('17VZNX1SN5NtKa8UQFxwQbFeFc3iqRYhem', 'mainnet')['valid'],
    'legacy P2PKH 1… valid on mainnet'
);
assert_true(
    OnchainWallet::validateAddress('3J98t1WpEZ73CNmQviecrnyiWrnqRhWNLy', 'mainnet')['valid'],
    'P2SH 3… valid on mainnet'
);
assert_true(
    OnchainWallet::validateAddress('bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4', 'mainnet')['valid'],
    'segwit-v0 bc1q… valid on mainnet'
);

// --- Taproot (bech32m) now validates on the matching network ---
$mainnetTaproot = 'bc1p0xlxvlhemja6c4dqv22uapctqupfhlxm9h8z3k2e72q4k9hcz7vqzk5jj0';
$res = OnchainWallet::validateAddress($mainnetTaproot, 'mainnet');
assert_true($res['valid'], 'mainnet Taproot bc1p… valid (was the reported bug): ' . ($res['error'] ?? ''));

// Re-encode the same witness program for each network so we test real,
// checksum-correct Taproot addresses rather than hand-typed vectors.
$program = Taproot::decodeP2trAddress($mainnetTaproot, 'mainnet');
assert_not_null($program, 'decoded 32-byte Taproot program from mainnet vector');

foreach (['mainnet', 'testnet', 'signet', 'regtest'] as $net) {
    $addr = Taproot::encodeP2trAddress($program, $net);
    assert_true(
        OnchainWallet::validateAddress($addr, $net)['valid'],
        "Taproot {$addr} valid on {$net}"
    );
    // Wrong-network must reject: pair mainnet<->testnet (HRP differs).
    $other = $net === 'mainnet' ? 'testnet' : 'mainnet';
    if ($net === 'signet' || $net === 'regtest') {
        // signet/regtest HRPs differ from mainnet's 'bc'; check against mainnet.
        $other = 'mainnet';
    }
    assert_false(
        OnchainWallet::validateAddress($addr, $other)['valid'],
        "Taproot {$net} address rejected on {$other} (no cross-network leak)"
    );
}

// --- garbage still rejected with a helpful hint ---
$bad = OnchainWallet::validateAddress('notanaddress', 'mainnet');
assert_false($bad['valid'], 'garbage rejected');
assert_true(
    str_contains((string)$bad['error'], 'bc1p'),
    'mainnet hint now mentions bc1p (Taproot) so operators know it is accepted'
);

echo "test_onchain_static_address_validation: OK\n";
