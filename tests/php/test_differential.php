<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/mint_reliability.php';
require_once dirname(__DIR__, 2) . '/app/includes/invoice.php';

$store = 's1';
$mintA = 'https://mint-a.example.com';
$mintB = 'https://mint-b.example.com';
make_store($store, $mintA);

// Mint A fails to address X with a wallet-side error → suspect opens, but
// the lifetime counter is NOT incremented (LIGHTNING_WALLET_ERROR is unverified).
MintReliability::recordWithdrawFailure($mintA, 'user@addr.com', $store,
    MintReliability::KIND_LIGHTNING_WALLET_ERROR, 'Lightning payment failed');

$ra = Database::fetchOne("SELECT * FROM mint_reliability WHERE mint_url = ?", [$mintA]);
assert_eq(0, (int)$ra['total_failures'], 'unverified wallet error does not count');
assert_eq(1, (int)$ra['disabled_pending_success'], 'inflows still stopped');
$suspect = Database::fetchOne(
    "SELECT * FROM mint_suspect WHERE mint_url = ? AND address = ?",
    [$mintA, 'user@addr.com']
);
assert_not_null($suspect, 'suspect row opened');

// Mint B then successfully melts to the SAME address → confirms Mint A at fault.
MintReliability::recordWithdrawSuccess($mintB, 'user@addr.com', $store);

$ra = Database::fetchOne("SELECT * FROM mint_reliability WHERE mint_url = ?", [$mintA]);
assert_eq(1, (int)$ra['total_failures'], 'differential resolution counted the failure');
$suspect = Database::fetchOne(
    "SELECT * FROM mint_suspect WHERE mint_url = ? AND address = ?",
    [$mintA, 'user@addr.com']
);
assert_null($suspect, 'suspect cleared');

// Mint B is fine (and wasn't penalized).
$rb = Database::fetchOne("SELECT * FROM mint_reliability WHERE mint_url = ?", [$mintB]);
assert_eq(0, (int)$rb['total_failures'], 'B not penalized');
assert_not_null($rb['last_success_at'], 'B success recorded');

// Inverse: Mint A fails address Y, then Mint B also fails address Y →
// suspect cleared (address-side fault). Neither lifetime counter changes.
MintReliability::recordWithdrawFailure($mintA, 'broken@addr.com', $store,
    MintReliability::KIND_LIGHTNING_WALLET_ERROR, 'Lightning payment failed');
MintReliability::recordWithdrawFailure($mintB, 'broken@addr.com', $store,
    MintReliability::KIND_LIGHTNING_WALLET_ERROR, 'Lightning payment failed');

$open = Database::fetchAll(
    "SELECT mint_url FROM mint_suspect WHERE address = ?",
    ['broken@addr.com']
);
assert_eq(0, count($open), 'both suspects on the same address resolved as no-fault');

$ra = Database::fetchOne("SELECT * FROM mint_reliability WHERE mint_url = ?", [$mintA]);
$rb = Database::fetchOne("SELECT * FROM mint_reliability WHERE mint_url = ?", [$mintB]);
assert_eq(1, (int)$ra['total_failures'], 'A counter unchanged by address-side fault');
assert_eq(0, (int)$rb['total_failures'], 'B counter unchanged by address-side fault');

// Both still have disabled_pending_success set (no successful melt to clear it).
assert_eq(1, (int)$ra['disabled_pending_success'], 'A still gated until next success');
assert_eq(1, (int)$rb['disabled_pending_success'], 'B still gated until next success');

echo "differential: ok\n";
