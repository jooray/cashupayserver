<?php

declare(strict_types=1);

$mode = $argv[1] ?? 'single';
$dataDir = sys_get_temp_dir() . '/cashupay-migration-' . bin2hex(random_bytes(8));
mkdir($dataDir, 0700, true);
define('CASHUPAY_DATA_DIR', $dataDir);

require_once dirname(__DIR__) . '/cashu-wallet-php/CashuWallet.php';
require_once dirname(__DIR__) . '/includes/database.php';

use Cashu\WalletStorage;

$dbPath = $dataDir . '/cashupay.sqlite';
$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE config (key TEXT PRIMARY KEY, value TEXT NOT NULL, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL)");
$pdo->exec("CREATE TABLE stores (
    id TEXT PRIMARY KEY, name TEXT NOT NULL, internal_api_key TEXT, mint_url TEXT,
    mint_unit TEXT NOT NULL DEFAULT 'sat', seed_phrase TEXT, exchange_fee_percent REAL NOT NULL DEFAULT 0,
    price_provider_primary TEXT NOT NULL DEFAULT 'coingecko', price_provider_secondary TEXT DEFAULT 'binance',
    auto_melt_enabled INTEGER NOT NULL DEFAULT 0, auto_melt_address TEXT,
    auto_melt_threshold INTEGER NOT NULL DEFAULT 2000, created_at INTEGER NOT NULL
)");
$pdo->exec("CREATE TABLE store_mints (
    id INTEGER PRIMARY KEY AUTOINCREMENT, store_id TEXT NOT NULL, mint_url TEXT NOT NULL,
    unit TEXT NOT NULL DEFAULT 'sat', priority INTEGER NOT NULL DEFAULT 0,
    enabled INTEGER NOT NULL DEFAULT 1, created_at INTEGER NOT NULL
)");
$pdo->exec("CREATE TABLE invoices (id TEXT PRIMARY KEY)");
$pdo->exec("CREATE TABLE webhook_deliveries (id TEXT PRIMARY KEY)");
WalletStorage::initializeSchema($pdo);
$pdo->exec('PRAGMA user_version = 2');

$seedA = 'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about';
$seedB = 'legal winner thank year wave sausage worth useful legal winner thank yellow';
$insertStore = $pdo->prepare("INSERT INTO stores (id, name, mint_url, mint_unit, seed_phrase, created_at) VALUES (?, ?, ?, 'sat', ?, ?)");
$insertStore->execute(['store_a', 'Store A', 'https://mint.example', $seedA, time()]);
if ($mode === 'collision') {
    $insertStore->execute(['store_b', 'Store B', 'https://mint.example', $seedB, time()]);
}

$legacyId = WalletStorage::deriveWalletId('https://mint.example', 'sat');
$proof = $pdo->prepare("INSERT INTO cashu_proofs (wallet_id, keyset_id, amount, secret, C, state, created_at) VALUES (?, ?, 1, 'legacy-secret', ?, 'UNSPENT', ?)");
$proof->execute([$legacyId, '0011223344556677', '02' . str_repeat('11', 32), time()]);
$pdo = null;

try {
    $migrated = Database::getInstance();
    if ($mode === 'collision') {
        throw new RuntimeException('Ambiguous legacy namespace migration did not fail closed');
    }
    $store = $migrated->query("SELECT wallet_account_id FROM stores WHERE id = 'store_a'")->fetch(PDO::FETCH_ASSOC);
    if (empty($store['wallet_account_id'])) {
        throw new RuntimeException('Store account ID was not assigned');
    }
    $newId = WalletStorage::deriveWalletId('https://mint.example', 'sat', $store['wallet_account_id']);
    $stmt = $migrated->prepare('SELECT wallet_id FROM cashu_proofs WHERE secret = ?');
    $stmt->execute(['legacy-secret']);
    if ($stmt->fetchColumn() !== $newId) {
        throw new RuntimeException('Legacy proof was not moved to the account namespace');
    }
    $stmt = $migrated->prepare('SELECT ready FROM cashu_wallet_metadata WHERE wallet_id = ?');
    $stmt->execute([$newId]);
    if ((int)$stmt->fetchColumn() !== 1) {
        throw new RuntimeException('Migrated seed was not marked ready');
    }
    fwrite(STDOUT, "wallet_account_migration single: OK\n");
} catch (Throwable $e) {
    if ($mode !== 'collision' || !str_contains($e->getMessage(), 'shared by multiple stores')) {
        throw $e;
    }
    fwrite(STDOUT, "wallet_account_migration collision: OK\n");
} finally {
    @unlink($dbPath);
    @unlink($dbPath . '-wal');
    @unlink($dbPath . '-shm');
    @rmdir($dataDir);
}
