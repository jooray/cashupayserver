<?php
/**
 * Switching a store's primary mint is normally blocked while payments or transfers
 * are in flight, because the outgoing mint would stop being visited and its funds
 * would be stranded. Demoting the old mint to a *backup* removes that risk: backups
 * stay in getStoreWalletAccounts(), so recovery still reaches them.
 *
 * Config::isMintRetainedAsBackup() is the predicate that distinguishes the two, and
 * admin.php's save_store relaxes the hard block when it is true. This asserts the
 * predicate itself, including the normalisation the comparison depends on.
 *
 * Run: php tests/mint_retention.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

$dataDir = sys_get_temp_dir() . '/cashupay-mintret-' . bin2hex(random_bytes(8));
mkdir($dataDir, 0700, true);
define('CASHUPAY_DATA_DIR', $dataDir);

require_once dirname(__DIR__) . '/cashu-wallet-php/CashuWallet.php';
require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/includes/config.php';

function check(bool $cond, string $what): void {
    if (!$cond) { fwrite(STDERR, "FAIL: $what\n"); exit(1); }
    echo "  ok: $what\n";
}

Database::initialize();
Database::insert('stores', [
    'id' => 'store_a',
    'name' => 'A',
    'mint_url' => 'https://old.example',
    'mint_unit' => 'sat',
    'wallet_account_id' => 'wa_a',
    'created_at' => Database::timestamp(),
]);

echo "Before demoting the old mint\n";
check(!Config::isMintRetainedAsBackup('store_a', 'https://old.example', 'sat'),
    'the outgoing mint is not retained, so the change can strand funds');

Config::addStoreBackupMint('store_a', 'https://old.example', 'sat', 10);

echo "After demoting the old mint to a backup\n";
check(Config::isMintRetainedAsBackup('store_a', 'https://old.example', 'sat'),
    'the outgoing mint is retained');
check(Config::isMintRetainedAsBackup('store_a', 'https://old.example/', 'sat'),
    'a trailing slash still matches (addStoreBackupMint strips it on the way in)');
check(Config::isMintRetainedAsBackup('store_a', 'https://old.example', 'SAT'),
    'the unit comparison is case-insensitive');

echo "Mints that are not the one being demoted\n";
check(!Config::isMintRetainedAsBackup('store_a', 'https://other.example', 'sat'),
    'an unrelated mint does not count as retained');
check(!Config::isMintRetainedAsBackup('store_a', 'https://old.example', 'eur'),
    'the same mint in a different unit is a different account, so it does not count');
check(!Config::isMintRetainedAsBackup('store_a', '', 'sat'),
    'an empty mint url never counts as retained');
check(!Config::isMintRetainedAsBackup('store_b', 'https://old.example', 'sat'),
    'backups belong to one store only');

// A disabled backup still holds recovery state: getStoreWalletAccounts() returns it,
// so recovery visits it and nothing is stranded.
$backups = Config::getStoreBackupMints('store_a');
Config::updateStoreBackupMint((int)$backups[0]['id'], ['enabled' => 0]);
echo "After disabling that backup\n";
check(Config::isMintRetainedAsBackup('store_a', 'https://old.example', 'sat'),
    'a disabled backup still counts as retained');

$accounts = array_map(
    static fn(array $a): string => $a['mint_url'] . '/' . $a['unit'],
    Config::getStoreWalletAccounts('store_a')
);
check(in_array('https://old.example/sat', $accounts, true),
    'and it is still enumerated as a wallet account, which is why nothing is stranded');

echo "\nAll mint-retention checks passed.\n";

foreach (glob($dataDir . '/*') ?: [] as $f) { @unlink($f); }
@rmdir($dataDir);
