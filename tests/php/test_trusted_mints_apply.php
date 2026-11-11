<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
$dir = fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/mint_reliability.php';
require_once dirname(__DIR__, 2) . '/app/includes/trusted_mints.php';
require_once dirname(__DIR__, 2) . '/app/includes/config.php';

// Plant a cached trusted list directly on disk so we can test apply* without
// needing network. Refresh-from-URL is exercised in a separate test.
$list = [
    'version' => 1,
    'mints' => [
        ['url' => 'https://m1.example.com'],
        ['url' => 'https://m2.example.com'],
        ['url' => 'https://compromised.example.com', 'disabled' => true, 'reason' => 'key leaked'],
    ],
];
file_put_contents($dir . '/' . TrustedMints::CACHE_FILENAME, json_encode($list));

// Store created at "setup" time — no mint_url. Should get primary populated
// from the first trusted entry, plus the second as a backup.
make_store('s_setup', null, 'sat', 'setup');
TrustedMints::applyToNewStore('s_setup');

$st = Config::getStore('s_setup');
assert_eq('https://m1.example.com', $st['mint_url'], 'setup-stage store gets primary from list');
assert_eq('trusted_list', $st['primary_mint_source'], 'source tagged as trusted_list');

$backups = Database::fetchAll(
    "SELECT mint_url FROM store_mints WHERE store_id = ? ORDER BY priority ASC",
    ['s_setup']
);
$urls = array_column($backups, 'mint_url');
assert_true(in_array('https://m2.example.com', $urls, true), 'second mint added as backup');
assert_true(!in_array('https://m1.example.com', $urls, true), 'primary not duplicated into backups');
assert_true(!in_array('https://compromised.example.com', $urls, true), 'disabled mint not added as backup');

// Store with manually-set primary — that primary stays untouched, but the
// non-disabled trusted mints become backups.
make_store('s_manual', 'https://operator-picked.example.com', 'sat', 'manual');
TrustedMints::applyToNewStore('s_manual');

$st = Config::getStore('s_manual');
assert_eq('https://operator-picked.example.com', $st['mint_url'], 'manual primary untouched');
assert_eq('manual', $st['primary_mint_source'], 'source still manual');

$backups = Database::fetchAll(
    "SELECT mint_url FROM store_mints WHERE store_id = ? ORDER BY priority ASC",
    ['s_manual']
);
$urls = array_column($backups, 'mint_url');
assert_true(in_array('https://m1.example.com', $urls, true), 'm1 added as backup');
assert_true(in_array('https://m2.example.com', $urls, true), 'm2 added as backup');

// Disabled mint got the trusted_list_disabled flag globally.
$rel = Database::fetchOne(
    "SELECT trusted_list_disabled, trusted_list_disabled_reason FROM mint_reliability WHERE mint_url = ?",
    ['https://compromised.example.com']
);
assert_eq(1, (int)$rel['trusted_list_disabled'], 'compromised mint flagged');
assert_eq('key leaked', $rel['trusted_list_disabled_reason'], 'reason persisted');

// Re-publishing the list without `disabled: true` clears the flag.
$list2 = [
    'version' => 1,
    'mints' => [
        ['url' => 'https://compromised.example.com'],
    ],
];
file_put_contents($dir . '/' . TrustedMints::CACHE_FILENAME, json_encode($list2));
TrustedMints::applyToAllStores();

$rel = Database::fetchOne(
    "SELECT trusted_list_disabled FROM mint_reliability WHERE mint_url = ?",
    ['https://compromised.example.com']
);
assert_eq(0, (int)$rel['trusted_list_disabled'], 'flag cleared when removed from disable list');

echo "trusted_mints_apply: ok\n";
