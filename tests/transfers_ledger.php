<?php
/**
 * Verifies the outgoing-transfer ledger: the v5 -> v6 migration creates the
 * transfers table on an existing install, and Transfer record/read round-trips.
 *
 * Run: php tests/transfers_ledger.php
 */

declare(strict_types=1);

$dataDir = sys_get_temp_dir() . '/cashupay-transfers-' . bin2hex(random_bytes(8));
mkdir($dataDir, 0700, true);
define('CASHUPAY_DATA_DIR', $dataDir);

require_once dirname(__DIR__) . '/cashu-wallet-php/CashuWallet.php';
require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/includes/transfer.php';

use Cashu\WalletStorage;

function check(bool $cond, string $what): void {
    if (!$cond) { fwrite(STDERR, "FAIL: $what\n"); exit(1); }
    echo "  ok: $what\n";
}

// --- Build a v5 database WITHOUT the transfers table (simulates an upgrade) ---
$dbPath = $dataDir . '/cashupay.sqlite';
$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE config (key TEXT PRIMARY KEY, value TEXT NOT NULL, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL)");
$pdo->exec("CREATE TABLE stores (id TEXT PRIMARY KEY, name TEXT NOT NULL, mint_url TEXT, mint_unit TEXT NOT NULL DEFAULT 'sat', seed_phrase TEXT, wallet_account_id TEXT, created_at INTEGER NOT NULL)");
$pdo->exec("CREATE TABLE invoices (id TEXT PRIMARY KEY, mint_url TEXT)");
$pdo->exec("CREATE TABLE webhook_deliveries (id TEXT PRIMARY KEY)");
$pdo->exec("CREATE TABLE webhooks (id TEXT PRIMARY KEY, store_id TEXT, enabled INTEGER DEFAULT 1, created_at INTEGER)");
WalletStorage::initializeSchema($pdo);
$pdo->exec("INSERT INTO stores (id, name, mint_url, mint_unit, wallet_account_id, created_at) VALUES ('store_x', 'X', 'https://m.example', 'sat', 'wa_x', 0)");
$pdo->exec('PRAGMA user_version = 5');
$hasTransfers = $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='transfers'")->fetchColumn();
check(!$hasTransfers, 'v5 database starts without a transfers table');
$pdo = null;

// --- Open through Database: migration must create the table and reach the current schema ---
$migrated = Database::getInstance();
$version = (int)$migrated->query('PRAGMA user_version')->fetchColumn();
check($version >= 6, "migration bumps schema past v6 (now v{$version})");
$hasTransfers = $migrated->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='transfers'")->fetchColumn();
check((bool)$hasTransfers, 'v6 migration creates the transfers table');

// v7 turns transfers into recoverable operations: the bearer token of an export has to
// survive a lost response, and `reference` links a withdrawal to its melt quote.
$transferColumns = array_column(
    $migrated->query('PRAGMA table_info(transfers)')->fetchAll(PDO::FETCH_ASSOC),
    'name'
);
foreach (['token', 'reference', 'updated_at'] as $column) {
    check(in_array($column, $transferColumns, true), "v7 migration adds transfers.$column");
}

// --- Record + read back ------------------------------------------------------
Transfer::record('store_x', Transfer::TYPE_LIGHTNING, 1000, 3, 'sat', 'user@wallet.com', 'completed', 'preimage123');
Transfer::record('store_x', Transfer::TYPE_TOKEN_EXPORT, 500, 0, 'sat', null, 'completed', 'Cashu token');
Transfer::record('store_x', Transfer::TYPE_DONATION, 10, 0, 'sat', 'CashuPayServer donation', 'completed', null);

$rows = Transfer::getByStore('store_x', 10);
check(count($rows) === 3, 'three transfers recorded and read back');
check($rows[0]['type'] === Transfer::TYPE_DONATION, 'most recent transfer first');

$api = Transfer::formatForApi($rows[0]);
check($api['amount'] === 10 && $api['unit'] === 'sat' && $api['type'] === 'donation', 'formatForApi shapes the row');

$ln = null;
foreach ($rows as $r) { if ($r['type'] === Transfer::TYPE_LIGHTNING) { $ln = $r; } }
check($ln !== null && (int)$ln['amount'] === 1000 && (int)$ln['fee'] === 3 && $ln['destination'] === 'user@wallet.com',
    'lightning transfer preserves amount, fee and destination');

// record() must never throw, even on a bad store (FK off in sqlite by default here).
Transfer::record('nonexistent', Transfer::TYPE_LIGHTNING, 1, 0, 'sat', null, 'completed', null);
check(true, 'record() is resilient and never throws');

exec('rm -rf ' . escapeshellarg($dataDir));
echo "transfers_ledger: OK\n";
