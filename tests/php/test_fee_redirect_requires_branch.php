<?php
/**
 * FeeRedirect::decide — the dev fee takes an invoice's ON-CHAIN rail only
 * when this install has its dev-fee branch. Without one (endpoint never
 * reached, or the client disabled) the rail stays with the merchant: no
 * address is ever derived on the shared <zpub>/0/<index> chain, because that
 * is exactly the cross-install collision the branch exists to prevent. The
 * hosting fee (deployer's own xpub) is unaffected and still derives on its
 * plain chain.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/fee_redirect.php';
require_once dirname(__DIR__, 2) . '/app/includes/fee_branch.php';
require_once dirname(__DIR__, 2) . '/app/includes/onchain/wallet.php';

assert_true(FeeBranch::isDisabled(), 'harness pins the endpoint off');

make_store('s1', 'https://m.example.com');
// Revenue so the dev fee (1%) owes 10_000 sats — well over the 5_000-sat
// invoices below (the GATE is owed >= invoice).
Config::set('fee_tracking_start_at', 0);
$now = time();
Database::insert('invoices', [
    'id' => 'seed_rev', 'store_id' => 's1', 'status' => 'Settled', 'amount' => '1000000',
    'currency' => 'sat', 'amount_sats' => 1000000, 'created_at' => $now - 60,
    'expiration_time' => $now + 3600, 'payment_rail' => 'lnaddress', 'settled_rail' => 'lnaddress',
    'paid_at' => $now - 60,
]);
// Fail-fast provider so the best-effort tip read never touches the network.
Database::update('stores', [
    'onchain_network' => 'mainnet', 'onchain_provider' => 'bitcoind-rpc',
    'onchain_provider_url' => 'http://127.0.0.1:1',
], 'id = ?', ['s1']);
$store = Config::getStore('s1');
$owed = DevFee::computeOwed('s1');
assert_eq(10000, (int)$owed['dev_owed'], 'dev fee owes 10k');

// ---- no branch: the dev fee covers the rail by config but builds nothing ----
$cand = FeeRedirect::candidates($store, $owed)[0];
assert_eq('dev', $cand['key']);
assert_null($cand['branch'], 'no branch stored');
assert_eq(['onchain'], FeeRedirect::coveredRails($cand, ['onchain'], 'mainnet'), 'config coverage is unchanged by the branch');
assert_null(FeeRedirect::decide('s1', $store, 5000, ['onchain']), 'no branch -> no on-chain redirect at all');
$state = Database::fetchAll("SELECT * FROM onchain_xpub_state");
assert_eq([], $state, 'nothing was derived on the shared chain');

// ---- branch present: the rail is built under it ---------------------------
Config::set(FeeBranch::CONFIG_BRANCH, 5);
$route = FeeRedirect::decide('s1', $store, 5000, ['onchain']);
assert_not_null($route, 'with a branch the dev fee claims the rail');
assert_eq(FEE_NOTE_DEV, $route['note']);
assert_eq(['onchain'], $route['rails']);
assert_eq(5, $route['onchain']['branch']);
assert_eq(0, $route['onchain']['index']);
assert_eq(
    OnchainWallet::deriveAddress((string)CASHUPAY_DEV_FEE_ONCHAIN_XPUB, 'P2WPKH', 'mainnet', 0, 5),
    $route['onchain']['address'],
    'address derives at <zpub>/5/0/0'
);
assert_eq($route['onchain']['address'], $route['destination']);

// ---- branch gone again (e.g. config wiped): back to no redirect -------------
Config::delete(FeeBranch::CONFIG_BRANCH);
assert_null(FeeRedirect::decide('s1', $store, 5000, ['onchain']), 'branch removed -> no redirect');

// ---- hosting fee: deployer xpub, plain chain, never needs a branch ---------
$xpubHost = 'xpub69uEaVYoN1mZyMon8qwRP41YjYyevp3YxJ68ymBGV7qmXZ9rsbMy9kBZnLNPg3TLjKd2EnMw5BtUFQCGrTVDjQok859LowMV2SEooseLCt1';
Database::update('stores', [
    'hosting_fee_percent' => 10, 'hosting_fee_onchain_xpub' => $xpubHost,
    'hosting_fee_onchain_network' => 'mainnet', 'hosting_fee_onchain_address_type' => 'P2WPKH',
], 'id = ?', ['s1']);
$store = Config::getStore('s1');
$owed = DevFee::computeOwed('s1');
assert_eq(100000, (int)$owed['hosting_owed'], 'hosting owes 10%');
$route = FeeRedirect::decide('s1', $store, 5000, ['onchain']);
assert_not_null($route, 'hosting fee (largest owed) claims the rail without any branch');
assert_eq(FEE_NOTE_HOSTING, $route['note']);
assert_null($route['onchain']['branch'], 'hosting derives on its plain chain');
assert_eq(OnchainWallet::deriveAddress($xpubHost, 'P2WPKH', 'mainnet', 0), $route['onchain']['address']);

echo "test_fee_redirect_requires_branch: ok\n";
