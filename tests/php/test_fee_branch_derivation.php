<?php
/**
 * Branched derivation: OnchainWallet::deriveAddress(..., $branch) derives at
 * <xpub>/<branch>/0/<index>, distinct from the plain <xpub>/0/<index> chain
 * and from every other branch; range validation; allocateFeeAddress keeps an
 * independent index stream per (xpub, branch) and reports the branch; the
 * fee-redirect candidate list threads the stored branch to the dev fee only;
 * and the descriptor script's import list covers exactly the chains the
 * install derives on (checksum algorithm pinned against bitcoind-verified
 * values).
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/onchain/wallet.php';
require_once dirname(__DIR__, 2) . '/app/includes/onchain/payments.php';
require_once dirname(__DIR__, 2) . '/app/includes/fee_redirect.php';
require_once dirname(__DIR__, 2) . '/scripts/fee-branch-descriptors.php';

use BitWasp\Bitcoin\Key\Factory\HierarchicalKeyFactory;
use BitWasp\Bitcoin\Network\NetworkFactory;
use BitWasp\Bitcoin\Address\SegwitAddress;
use BitWasp\Bitcoin\Script\WitnessProgram;

$zpub = (string)CASHUPAY_DEV_FEE_ONCHAIN_XPUB;
$xpubA = 'xpub69uEaVYoN1mZyMon8qwRP41YjYyevp3YxJ68ymBGV7qmXZ9rsbMy9kBZnLNPg3TLjKd2EnMw5BtUFQCGrTVDjQok859LowMV2SEooseLCt1';

// ---- deriveAddress: branch inserts one level above the receive chain --------
$plain0   = OnchainWallet::deriveAddress($zpub, 'P2WPKH', 'mainnet', 0);
$b1_0     = OnchainWallet::deriveAddress($zpub, 'P2WPKH', 'mainnet', 0, 1);
$b1_1     = OnchainWallet::deriveAddress($zpub, 'P2WPKH', 'mainnet', 1, 1);
$b2_0     = OnchainWallet::deriveAddress($zpub, 'P2WPKH', 'mainnet', 0, 2);
$bMax_0   = OnchainWallet::deriveAddress($zpub, 'P2WPKH', 'mainnet', 0, 2147483647);
assert_eq('bc1q586um24k7zr6swxqny5qqgqn8xt43pk4xeeg9g', $plain0, 'plain chain unchanged (m/0/0)');
assert_eq($plain0, OnchainWallet::deriveAddress($zpub, 'P2WPKH', 'mainnet', 0, null), 'null branch == plain chain');
assert_eq(5, count(array_unique([$plain0, $b1_0, $b1_1, $b2_0, $bMax_0])), 'every (branch,index) pair is a distinct address');

// Reference derivation straight through bitwasp at the documented path.
$prev = error_reporting(); error_reporting($prev & ~E_DEPRECATED);
$hk = (new HierarchicalKeyFactory())->fromExtended(OnchainWallet::toCanonicalXpub($zpub, 'mainnet'), NetworkFactory::bitcoin());
$ref = (new SegwitAddress(WitnessProgram::v0($hk->derivePath('1/0/0')->getPublicKey()->getPubKeyHash())))->getAddress(NetworkFactory::bitcoin());
error_reporting($prev);
assert_eq($ref, $b1_0, 'branch 1 index 0 == bitwasp m/1/0/0');
$prev = error_reporting(); error_reporting($prev & ~E_DEPRECATED);
$ref = (new SegwitAddress(WitnessProgram::v0($hk->derivePath('2147483647/0/0')->getPublicKey()->getPubKeyHash())))->getAddress(NetworkFactory::bitcoin());
error_reporting($prev);
assert_eq($ref, $bMax_0, 'top-of-range branch derives at m/2147483647/0/0');

// P2SH-P2WPKH honours the branch as well.
$sh = OnchainWallet::deriveAddress($zpub, 'P2SH-P2WPKH', 'mainnet', 0, 1);
assert_true(str_starts_with($sh, '3'), 'wrapped segwit under a branch');
assert_neq($sh, OnchainWallet::deriveAddress($zpub, 'P2SH-P2WPKH', 'mainnet', 0), 'wrapped: branch differs from plain');

// Range validation: 0 is reserved, negatives and hardened-range values rejected.
foreach ([0, -1, 2147483648] as $bad) {
    try {
        OnchainWallet::deriveAddress($zpub, 'P2WPKH', 'mainnet', 0, $bad);
        fail("branch {$bad} accepted");
    } catch (InvalidArgumentException $e) {
        assert_true(str_contains($e->getMessage(), 'branch'), "branch {$bad} rejected: " . $e->getMessage());
    }
}

// ---- allocateFeeAddress: independent counters per (xpub, branch) -------------
$p0 = OnchainPayments::allocateFeeAddress($xpubA, 'mainnet', 'P2WPKH', null);
$p1 = OnchainPayments::allocateFeeAddress($xpubA, 'mainnet', 'P2WPKH', null);
assert_eq([0, 1], [$p0['index'], $p1['index']], 'plain chain counts 0,1');
assert_null($p0['branch'], 'plain allocation reports no branch');
$a0 = OnchainPayments::allocateFeeAddress($xpubA, 'mainnet', 'P2WPKH', null, 7);
$a1 = OnchainPayments::allocateFeeAddress($xpubA, 'mainnet', 'P2WPKH', null, 7);
assert_eq([0, 1], [$a0['index'], $a1['index']], 'branch 7 starts its own stream at 0');
assert_eq(7, $a0['branch'], 'allocation reports its branch');
assert_eq(OnchainWallet::deriveAddress($xpubA, 'P2WPKH', 'mainnet', 0, 7), $a0['address'], 'branched allocation matches deriveAddress');
$b0 = OnchainPayments::allocateFeeAddress($xpubA, 'mainnet', 'P2WPKH', null, 8);
assert_eq(0, $b0['index'], 'branch 8 is independent of branch 7');
$p2 = OnchainPayments::allocateFeeAddress($xpubA, 'mainnet', 'P2WPKH', null);
assert_eq(2, $p2['index'], 'plain chain counter untouched by branched allocations');
assert_eq(6, count(array_unique(array_column([$p0, $p1, $a0, $a1, $b0, $p2], 'address'))), 'six distinct addresses');
// The branched stream starts at 0 regardless of the plain stream's position
// (installs that predate branches advanced it) — by design, the sweep wallet
// scans each chain from 0.
$row = Database::fetchOne("SELECT next_index FROM onchain_xpub_state WHERE xpub_hash = ?", [hash('sha256', $xpubA . '/7')]);
assert_eq(2, (int)$row['next_index'], 'branched counter keyed by xpub/branch');

// ---- fee-redirect candidates thread the stored branch to the dev fee only ----
require_once dirname(__DIR__, 2) . '/app/includes/fee_branch.php';
Config::set(FeeBranch::CONFIG_BRANCH, 33);
$store = [
    'hosting_fee_destination' => 'host@op.com', 'hosting_fee_onchain_xpub' => $xpubA,
    'hosting_fee_onchain_network' => 'mainnet', 'hosting_fee_onchain_address_type' => 'P2WPKH',
    'onchain_network' => 'mainnet',
];
$cands = FeeRedirect::candidates($store, ['dev_owed' => 9, 'hosting_owed' => 5]);
assert_eq('dev', $cands[0]['key']);
assert_eq(33, $cands[0]['branch'], 'dev candidate carries the stored branch');
assert_null($cands[1]['branch'], 'hosting candidate never gets a branch');
// coveredRails is unaffected by the branch (pure config coverage).
assert_eq(['lightning', 'onchain'], FeeRedirect::coveredRails($cands[0], ['lightning', 'onchain'], 'mainnet'));

// ---- descriptor script: import list + checksums --------------------------------
// Checksums pinned against `bitcoin-cli getdescriptorinfo` (Core 28):
$tpub = 'tpubDCqtwidUGiMWjK5JoxycqmrbE6vAK6pvFPPN1EsrhUMiTs536xc2acgYrsdUZ7cTTQVYZe9XYDyr2WGoUXJBiq7z2yo1VLd9KWaaf2htk9U';
assert_eq('527drsh6', descriptor_checksum("wpkh({$tpub}/7/0/*)"), 'wpkh checksum matches bitcoind');
assert_eq('n8lzx569', descriptor_checksum("sh(wpkh({$tpub}/0/*))"), 'sh(wpkh) checksum matches bitcoind');
assert_eq('8fhd9pwu', descriptor_checksum('pkh(02c6047f9441ed7d6d3045406e95c07cd85c778e4b8cef3ca7abac09b95c709ee5)'), 'Core docs vector');
$xpubMain = OnchainWallet::toCanonicalXpub($zpub, 'mainnet');
assert_true(str_starts_with($xpubMain, 'xpub'), 'zpub normalises to xpub for Core');
assert_eq("wpkh({$xpubMain}/0/*)#cev6yuu6", fee_branch_descriptor($xpubMain, null, 'P2WPKH'), 'plain-chain descriptor (bitcoind-verified checksum)');
$list = fee_branch_import_list($xpubMain, 3, 0, 'P2WPKH', 50, 'now');
assert_eq(4, count($list), 'plain chain + branches 1..3');
assert_eq("wpkh({$xpubMain}/0/*)#cev6yuu6", $list[0]['desc']);
assert_eq("wpkh({$xpubMain}/2/0/*)#" . descriptor_checksum("wpkh({$xpubMain}/2/0/*)"), $list[2]['desc']);
assert_eq([0, 50], $list[1]['range']);
assert_eq('now', $list[1]['timestamp']);
assert_false($list[1]['active']);
$tail = fee_branch_import_list($xpubMain, 5, 4, 'P2SH-P2WPKH', 10, 0);
assert_eq(2, count($tail), '--from 4 --highest 5 = two branches, no plain chain');
assert_true(str_starts_with($tail[0]['desc'], "sh(wpkh({$xpubMain}/4/0/*))#"), 'wrapped descriptors for P2SH-P2WPKH');
assert_eq(0, $tail[0]['timestamp'], 'numeric timestamp passes through for rescans');
try {
    descriptor_checksum("wpkh(\n)");
    fail('checksum accepted an invalid character');
} catch (InvalidArgumentException $e) {
    assert_true(true);
}

echo "test_fee_branch_derivation: ok\n";
