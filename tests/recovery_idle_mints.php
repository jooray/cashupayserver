<?php
/**
 * Recovery of interrupted wallet operations contacts a mint only when something is
 * journaled there. Asking every mint of every store on each run got a backup mint
 * (mint.lnvoltz.com) to answer "Rate limit exceeded", which the dashboard then showed
 * as a failing task and an unreachable mint.
 *
 * Run: php tests/recovery_idle_mints.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

$root = dirname(__DIR__);
$dataDir = sys_get_temp_dir() . '/cashupay-idle-' . bin2hex(random_bytes(8));
mkdir($dataDir, 0700, true);
define('CASHUPAY_DATA_DIR', $dataDir);
register_shutdown_function(fn() => exec('rm -rf ' . escapeshellarg($dataDir)));

require_once $root . '/cashu-wallet-php/CashuWallet.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/config.php';
require_once $root . '/includes/invoice.php';

function check(bool $cond, string $what): void {
    if (!$cond) { fwrite(STDERR, "FAIL: $what\n"); exit(1); }
    echo "  ok: $what\n";
}

// Nothing listens on these ports: any contact with a mint is an error.
$primary = 'http://127.0.0.1:9';
$backup = 'http://127.0.0.1:7';

Database::initialize();
$accountId = Database::generateWalletAccountId();
Database::insert('stores', [
    'id' => 'store_1', 'name' => 'Shop', 'wallet_account_id' => $accountId,
    'mint_url' => $primary, 'mint_unit' => 'sat',
    'seed_phrase' => 'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about',
    'created_at' => Database::timestamp(),
]);
Database::insert('stores', [
    'id' => 'store_2', 'name' => 'Other shop', 'wallet_account_id' => Database::generateWalletAccountId(),
    'mint_url' => $primary, 'mint_unit' => 'sat',
    'seed_phrase' => 'legal winner thank year wave sausage worth useful legal winner thank yellow',
    'created_at' => Database::timestamp(),
]);
Config::addStoreBackupMint('store_1', $backup, 'sat');
Config::addStoreBackupMint('store_2', $backup, 'sat');

echo "Accounts with nothing unfinished\n";
$result = Invoice::recoverPendingWalletOperations();
check($result['errors'] === [], 'no mint is contacted (' . json_encode($result['errors']) . ')');

echo "An account with an unfinished operation\n";
$wallet = new \Cashu\Wallet($backup, 'sat', Database::getDbPath(), $accountId);
$wallet->getStorage()->savePendingOperation('swap:test', 'swap', ['input_secrets' => []]);
$result = Invoice::recoverPendingWalletOperations();
check(array_keys($result['errors']) === ["store_1|$backup|sat"],
    'only that store\'s backup mint is contacted (' . implode(', ', array_keys($result['errors'])) . ')');

echo "recovery_idle_mints: OK\n";
