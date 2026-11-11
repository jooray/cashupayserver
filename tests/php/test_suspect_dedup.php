<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/mint_reliability.php';
require_once dirname(__DIR__, 2) . '/app/includes/invoice.php';

$mint = 'https://m.example.com';
$store = 's1';
make_store($store, $mint);

MintReliability::recordWithdrawFailure($mint, 'addr@x.com', $store,
    MintReliability::KIND_LIGHTNING_WALLET_ERROR, 'first');
$first = Database::fetchOne(
    "SELECT opened_at, last_seen_at FROM mint_suspect WHERE mint_url = ? AND address = ?",
    [$mint, 'addr@x.com']
);
assert_not_null($first, 'suspect created');

sleep(1);
MintReliability::recordWithdrawFailure($mint, 'addr@x.com', $store,
    MintReliability::KIND_LIGHTNING_WALLET_ERROR, 'second');

$rows = Database::fetchAll(
    "SELECT * FROM mint_suspect WHERE mint_url = ? AND address = ?",
    [$mint, 'addr@x.com']
);
assert_eq(1, count($rows), 'still exactly one suspect row');
assert_eq((int)$first['opened_at'], (int)$rows[0]['opened_at'], 'opened_at unchanged');
assert_true((int)$rows[0]['last_seen_at'] > (int)$first['last_seen_at'], 'last_seen_at bumped');

// And the lifetime counter is unchanged — repeated polling of the same
// (mint, address) pair is not new evidence.
$r = Database::fetchOne("SELECT total_failures FROM mint_reliability WHERE mint_url = ?", [$mint]);
assert_eq(0, (int)$r['total_failures'], 'repeated failure of same pair does not count');

echo "suspect_dedup: ok\n";
