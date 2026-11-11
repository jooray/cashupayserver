<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/mint_reliability.php';
require_once dirname(__DIR__, 2) . '/app/includes/config.php';
require_once dirname(__DIR__, 2) . '/app/includes/invoice.php';

$store = 's1';
$primary = 'https://primary.example.com';
$backup1 = 'https://backup1.example.com';
$backup2 = 'https://backup2.example.com';
make_store($store, $primary);
add_backup_mint($store, $backup1, 10);
add_backup_mint($store, $backup2, 20);

// Healthy state: all three returned, primary first.
$mints = Config::getStoreAllMintUrls($store);
assert_eq([$primary, $backup1, $backup2], $mints, 'baseline order');

// Disable primary via reliability: backup1 takes the head, no error.
MintReliability::setTrustedListDisabled($primary, 'test');
$mints = Config::getStoreAllMintUrls($store);
assert_eq([$backup1, $backup2], $mints, 'disabled primary falls through to backups');

// Disable backup1 by failure-suspect path (LIGHTNING_WALLET_ERROR sets
// disabled_pending_success directly).
MintReliability::recordWithdrawFailure($backup1, 'a@b.com', $store,
    MintReliability::KIND_LIGHTNING_WALLET_ERROR, 'fail');
$mints = Config::getStoreAllMintUrls($store);
assert_eq([$backup2], $mints, 'failed backup gated out');

// Permanent disable on backup2 → empty list.
for ($i = 0; $i < 6; $i++) {
    MintReliability::recordWithdrawFailure($backup2, null, $store,
        MintReliability::KIND_MINT_PROTOCOL_ERROR, 'fail');
}
$mints = Config::getStoreAllMintUrls($store);
assert_eq([], $mints, 'all gated out → empty');

echo "filter_in_getallmints: ok\n";
