<?php
/**
 * A backup mint cannot be removed while an order quoted at it may still be paid (S4,
 * 2026-10-28). The payment would be collected through the invoice's own mint into an
 * account the store no longer lists, so the order would settle but the money would
 * never show up in the balance, withdrawals or automatic withdrawal.
 *
 * No network: the guard only reads the database.
 *
 * Run: php tests/backup_mint_removal.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

$dataDir = sys_get_temp_dir() . '/cashupay-backuprm-' . bin2hex(random_bytes(8));
mkdir($dataDir, 0700, true);
define('CASHUPAY_DATA_DIR', $dataDir);
register_shutdown_function(fn() => exec('rm -rf ' . escapeshellarg($dataDir)));

require_once dirname(__DIR__) . '/cashu-wallet-php/CashuWallet.php';
require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/store_safety.php';

function check(bool $cond, string $what): void {
    if (!$cond) { fwrite(STDERR, "FAIL: $what\n"); exit(1); }
    echo "  ok: $what\n";
}

Database::initialize();
Database::insert('stores', [
    'id' => 'store_a', 'name' => 'A', 'wallet_account_id' => Database::generateWalletAccountId(),
    'mint_url' => 'https://new.example', 'mint_unit' => 'sat', 'created_at' => Database::timestamp(),
]);
$backupId = Config::addStoreBackupMint('store_a', 'https://old.example', 'sat');

echo "An empty backup mint can be removed\n";
check(StoreSafety::backupMintHoldings($backupId)['reasons'] === [], 'nothing is held');

echo "An open order quoted at the backup mint blocks removal (S4)\n";
foreach (['New', 'Processing', 'Expired', 'Invalid'] as $status) {
    Database::query('DELETE FROM invoices');
    Database::insert('invoices', [
        'id' => 'inv_1', 'store_id' => 'store_a', 'amount' => '100', 'currency' => 'SAT', 'amount_sats' => 100,
        'status' => $status, 'quote_id' => 'q_old', 'mint_url' => 'https://old.example/', 'mint_unit' => 'sat',
        'expiration_time' => time() - 60, 'created_at' => Database::timestamp(),
    ]);
    $reasons = StoreSafety::backupMintHoldings($backupId)['reasons'];
    check(count($reasons) === 1 && str_contains($reasons[0], 'may still arrive'), "a $status order blocks removal");
}

echo "Resolved or unrelated orders do not block it\n";
Database::update('invoices', ['status' => 'Settled'], 'id = ?', ['inv_1']);
check(StoreSafety::backupMintHoldings($backupId)['reasons'] === [], 'a settled order does not');
Database::update('invoices', ['status' => 'Expired', 'quote_id' => null], 'id = ?', ['inv_1']);
check(StoreSafety::backupMintHoldings($backupId)['reasons'] === [], 'a written-off order does not');
Database::update('invoices', ['quote_id' => 'q_new', 'mint_url' => 'https://new.example'], 'id = ?', ['inv_1']);
check(StoreSafety::backupMintHoldings($backupId)['reasons'] === [], 'an order at the primary mint does not');
Database::update('invoices', ['mint_url' => 'https://old.example', 'mint_unit' => 'usd'], 'id = ?', ['inv_1']);
check(StoreSafety::backupMintHoldings($backupId)['reasons'] === [], 'an order in another unit does not');
Database::update('invoices', ['mint_unit' => null], 'id = ?', ['inv_1']);
check(count(StoreSafety::backupMintHoldings($backupId)['reasons']) === 1, 'an order from before units were recorded does, to be safe');

echo "backup_mint_removal: OK\n";
