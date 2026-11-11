<?php
/**
 * FeeRedirect decision logic: candidate assembly + ordering, the pure per-rail
 * coverage check (coveredRails / staticallyCovers), and the null paths of
 * decide() that short-circuit before any network I/O.
 *
 * The positive end-to-end path (real fee-xpub address / fee-LNURL bolt11,
 * customer pays, credit recorded) is exercised by the Playwright e2e; here we
 * pin the pure selection rules: GATE (owed >= invoice), PRECEDENCE (largest
 * owed first) and PER-RAIL coverage (a fee claims only the rails it covers; the
 * rest fall through to the merchant).
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/fee_redirect.php';

// ---- candidates(): ordering + field mapping --------------------------------
$store = [
    'hosting_fee_destination' => 'host@op.com',
    'hosting_fee_onchain_xpub' => 'xpubHOST',
    'hosting_fee_onchain_network' => 'mainnet',
    'hosting_fee_onchain_address_type' => 'P2WPKH',
    'onchain_network' => 'mainnet',
];
$owed = ['dev_owed' => 9000, 'hosting_owed' => 5000];
$cands = FeeRedirect::candidates($store, $owed);
assert_eq(2, count($cands), 'two fee candidates (upstream fee retired)');
// Largest owed first: dev (9000) > hosting (5000).
assert_eq('dev', $cands[0]['key'], 'dev sorts first');
assert_eq('hosting', $cands[1]['key'], 'hosting second');
assert_eq(FEE_NOTE_DEV, $cands[0]['note']);
assert_eq(9000, $cands[0]['owed']);
// Hosting destinations come from the store row.
$hosting = $cands[1];
assert_eq('host@op.com', $hosting['lnurl'], 'hosting lnurl from store');
assert_eq('xpubHOST', $hosting['xpub'], 'hosting xpub from store');
// Dev LNURL comes from the global constant (getbarebits default in tests).
assert_eq((string)CASHUPAY_DEV_FEE_LNURL, $cands[0]['lnurl'], 'dev lnurl from config');
// Dev on-chain destination ships baked in: the BareBits mainnet zpub (BIP84).
// Pin the exact default and its first derived address so a drift in either
// the constant or the SLIP-32 zpub handling is caught here, not in production.
require_once dirname(__DIR__, 2) . '/app/includes/onchain/wallet.php';
assert_eq(
    'zpub6mg9NbdenqrqfD2BbNqa4EdKS8z2yEBxH2PC4NXmXwnTDfukw3w7JLVMeb81xc9i1N8b7ReB9ia4wLoUyLVrVxaPQbJidU8Yn6Gjd95kQmA',
    (string)CASHUPAY_DEV_FEE_ONCHAIN_XPUB,
    'dev onchain xpub defaults to the BareBits zpub'
);
assert_eq('mainnet', $cands[0]['network'], 'dev xpub is a mainnet zpub');
assert_eq('P2WPKH', $cands[0]['type'], 'dev xpub derives P2WPKH');
assert_eq(
    'bc1q586um24k7zr6swxqny5qqgqn8xt43pk4xeeg9g',
    OnchainWallet::deriveAddress((string)CASHUPAY_DEV_FEE_ONCHAIN_XPUB, 'P2WPKH', 'mainnet', 0),
    'dev zpub m/0/0 derives the expected bech32 address'
);
// With the default in place the dev fee covers BOTH rails on a mainnet store
// and only lightning on a testnet store (network-matched xpub rule).
assert_eq(['lightning', 'onchain'],
    FeeRedirect::coveredRails($cands[0], ['lightning', 'onchain'], 'mainnet'),
    'dev fee covers both rails by default on mainnet');
assert_eq(['lightning'],
    FeeRedirect::coveredRails($cands[0], ['lightning', 'onchain'], 'testnet'),
    'dev fee covers only lightning on a testnet store');

// ---- coveredRails(): per-rail truth table ----------------------------------
// The core of the new behaviour: which OFFERED rails a fee can route to itself.
$full = ['lnurl' => 'a@b.com', 'xpub' => 'xpubX', 'network' => 'mainnet'];
assert_eq(['lightning', 'onchain'],
    FeeRedirect::coveredRails($full, ['lightning', 'onchain'], 'mainnet'),
    'lnurl + matching xpub covers both offered rails');
assert_eq(['lightning'],
    FeeRedirect::coveredRails($full, ['lightning'], 'mainnet'),
    'only lightning offered -> only lightning covered');
assert_eq(['onchain'],
    FeeRedirect::coveredRails($full, ['onchain'], 'mainnet'),
    'only onchain offered -> only onchain covered');

// LNURL but no xpub: with BOTH offered, the fee covers lightning ONLY (the new
// per-rail behaviour — previously this disqualified the whole redirect).
$noXpub = ['lnurl' => 'a@b.com', 'xpub' => '', 'network' => 'mainnet'];
assert_eq(['lightning'],
    FeeRedirect::coveredRails($noXpub, ['lightning', 'onchain'], 'mainnet'),
    'no xpub: lightning is covered, on-chain falls through to merchant');
assert_eq([],
    FeeRedirect::coveredRails($noXpub, ['onchain'], 'mainnet'),
    'no xpub and only on-chain offered: nothing covered');

// xpub but no LNURL: covers on-chain only.
$noLnurl = ['lnurl' => '', 'xpub' => 'xpubX', 'network' => 'mainnet'];
assert_eq(['onchain'],
    FeeRedirect::coveredRails($noLnurl, ['lightning', 'onchain'], 'mainnet'),
    'no lnurl: on-chain covered, lightning falls through to merchant');
assert_eq([],
    FeeRedirect::coveredRails($noLnurl, ['lightning'], 'mainnet'),
    'no lnurl and only lightning offered: nothing covered');

// xpub on a different network can't cover on-chain (poller uses store network).
$wrongNet = ['lnurl' => 'a@b.com', 'xpub' => 'xpubX', 'network' => 'testnet'];
assert_eq(['lightning'],
    FeeRedirect::coveredRails($wrongNet, ['lightning', 'onchain'], 'mainnet'),
    'wrong-network xpub: on-chain not covered, lightning still covered');

// ---- staticallyCovers(): all-rails convenience predicate -------------------
assert_true(FeeRedirect::staticallyCovers($full, ['lightning', 'onchain'], 'mainnet'),
    'full destinations cover all offered rails');
assert_true(!FeeRedirect::staticallyCovers($noXpub, ['lightning', 'onchain'], 'mainnet'),
    'no xpub does not cover ALL of a mixed offer');
assert_true(FeeRedirect::staticallyCovers($noXpub, ['lightning'], 'mainnet'),
    'no xpub still covers a lightning-only offer');

// ---- decide(): null paths (offline — must not probe) -----------------------
$sid = 'store_dec';
make_store($sid, 'https://m.example.com');
Config::set('fee_tracking_start_at', 0);
$storeRow = Config::getStore($sid);

// No revenue -> nothing owed -> gate fails for every fee -> no redirect.
assert_null(
    FeeRedirect::decide($sid, $storeRow, 3000, ['lightning', 'onchain']),
    'no revenue: no redirect'
);

// Revenue accrues a dev fee, but the store offers ONLY an on-chain rail on
// TESTNET. The dev xpub default is a mainnet zpub and the hosting xpub is
// empty, so coveredRails is empty for every fee -> no redirect, and crucially
// no LNURL probe (lightning isn't offered) and no address derivation, so this
// path stays offline.
Database::insert('invoices', [
    'id' => 'inv_rev', 'store_id' => $sid, 'status' => 'Settled',
    'amount' => '500000', 'currency' => 'sat', 'amount_sats' => 500000,
    'created_at' => time(), 'expiration_time' => time() + 3600,
]);
$o = DevFee::computeOwed($sid);
assert_true($o['dev_owed'] >= 3000, 'precondition: dev fee owed exceeds the invoice');
$testnetRow = ['onchain_network' => 'testnet'] + $storeRow;
assert_null(
    FeeRedirect::decide($sid, $testnetRow, 3000, ['onchain']),
    'onchain offered on testnet but every fee xpub is mainnet/empty: no redirect'
);

// Gate: an invoice larger than every owed fee never redirects (would overpay).
// Decided before coveredRails/probe, so offline regardless of offered rails.
assert_null(
    FeeRedirect::decide($sid, $storeRow, 100_000_000, ['lightning', 'onchain']),
    'invoice larger than all owed fees: gate blocks redirect'
);

echo "test_fee_redirect_decision: ok\n";
