<?php
/**
 * OnchainPayments::allocateFeeAddress derives a fresh address from a fee
 * payee's xpub using its OWN per-xpub index counter (onchain_xpub_state),
 * independent of any store's merchant-receive counter. Two different fee
 * xpubs never collide, and the derived address matches a direct
 * OnchainWallet::deriveAddress at the same index.
 *
 * tipProviderStore is passed null so the allocation does no network I/O (the
 * chain-tip read is best-effort and only used for historical-UTXO filtering).
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/onchain/payments.php';
require_once dirname(__DIR__, 2) . '/app/includes/onchain/wallet.php';

// Sample mainnet xpubs (also used by the on-chain wallet tests).
$xpubA = 'xpub69uEaVYoN1mZyMon8qwRP41YjYyevp3YxJ68ymBGV7qmXZ9rsbMy9kBZnLNPg3TLjKd2EnMw5BtUFQCGrTVDjQok859LowMV2SEooseLCt1';
$xpubB = 'xpub6AKC3u8URPxDojLnFtNdEPFkNsXxHfgRhySvVfEJy9SVvQAn14XQjAoFY48mpjgutJNfA54GbYYRpR26tFEJHTHhfiiZZ2wdBBzydVp12yU';

// First allocation on xpubA -> index 0.
$a0 = OnchainPayments::allocateFeeAddress($xpubA, 'mainnet', 'P2WPKH', null);
assert_eq(0, $a0['index'], 'first fee address derives at index 0');
assert_true(str_starts_with($a0['address'], 'bc1'), 'mainnet P2WPKH is bech32');
assert_null($a0['tip_height'], 'no provider store -> tip best-effort null');

// Second allocation on xpubA -> index 1 (monotonic per xpub).
$a1 = OnchainPayments::allocateFeeAddress($xpubA, 'mainnet', 'P2WPKH', null);
assert_eq(1, $a1['index'], 'second fee address derives at index 1');
assert_neq($a0['address'], $a1['address'], 'distinct address at the next index');

// A different xpub has an independent counter -> starts at 0 again.
$b0 = OnchainPayments::allocateFeeAddress($xpubB, 'mainnet', 'P2WPKH', null);
assert_eq(0, $b0['index'], 'second xpub has its own index stream');
assert_neq($a0['address'], $b0['address'], 'different xpubs derive different addresses');

// Derived address matches a direct derivation at the same path.
$direct = OnchainWallet::deriveAddress($xpubA, 'P2WPKH', 'mainnet', 0);
assert_eq($direct, $a0['address'], 'allocateFeeAddress matches deriveAddress(m/0/0)');

// The fee counter does NOT touch a store's merchant onchain_next_index.
make_store('store_x', 'https://m.example.com');
$store = Config::getStore('store_x');
assert_eq(0, (int)($store['onchain_next_index'] ?? 0), 'fee allocation left store index untouched');

echo "test_fee_redirect_address: ok\n";
