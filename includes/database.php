<?php
/**
 * CashuPayServer Database Module
 *
 * PDO wrapper for SQLite database operations.
 *
 * CUSTOM DATA PATH:
 * For better security, you can store data outside the web root.
 * Create a file at includes/config.local.php with:
 *
 *   <?php
 *   define('CASHUPAY_DATA_DIR', '/path/outside/webroot/cashupay-data');
 *
 * The directory will be created automatically with proper permissions.
 */

require_once __DIR__ . '/../cashu-wallet-php/CashuWallet.php';

// Load custom config if exists
if (file_exists(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}

use Cashu\Wallet;
use Cashu\WalletStorage;

class Database {
    private const SCHEMA_VERSION = 6;

    private static ?PDO $instance = null;
    private static ?string $dbPath = null;
    private static ?string $dataDir = null;

    /**
     * Get the data directory path
     */
    public static function getDataDir(): string {
        if (self::$dataDir === null) {
            // Check for custom path
            if (defined('CASHUPAY_DATA_DIR')) {
                self::$dataDir = rtrim(CASHUPAY_DATA_DIR, '/');
            } else {
                self::$dataDir = __DIR__ . '/../data';
            }
        }
        return self::$dataDir;
    }

    /**
     * Get the database file path
     */
    public static function getDbPath(): string {
        if (self::$dbPath === null) {
            self::$dbPath = self::getDataDir() . '/cashupay.sqlite';
        }
        return self::$dbPath;
    }

    /**
     * Check if data directory is outside document root (more secure)
     */
    public static function isDataDirOutsideWebroot(): bool {
        $dataDir = realpath(self::getDataDir()) ?: self::getDataDir();
        $docRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? __DIR__ . '/..') ?: '';

        if (empty($docRoot)) {
            return false;
        }

        return strpos($dataDir, $docRoot) !== 0;
    }

    /**
     * Get PDO instance (singleton)
     */
    public static function getInstance(): PDO {
        if (self::$instance === null) {
            self::$instance = self::connect();
            self::ensureCurrentSchema(self::$instance);
        }
        return self::$instance;
    }

    /**
     * Create database connection
     */
    private static function connect(): PDO {
        $dir = self::getDataDir();
        if (!is_dir($dir)) {
            self::createDataDirectory($dir);
        }

        $pdo = new PDO('sqlite:' . self::getDbPath());
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000'); // Wait up to 5 seconds for locks

        return $pdo;
    }

    /**
     * Create data directory with .htaccess protection
     */
    private static function createDataDirectory(string $dir): void {
        // Create directory
        if (!mkdir($dir, 0750, true)) {
            throw new Exception("Failed to create data directory: $dir");
        }

        // Create .htaccess for Apache protection
        $htaccess = $dir . '/.htaccess';
        $htaccessContent = <<<'HTACCESS'
# Deny all access to this directory
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order deny,allow
    Deny from all
</IfModule>
HTACCESS;
        file_put_contents($htaccess, $htaccessContent);

        // Create index.php as additional protection
        $indexPhp = $dir . '/index.php';
        file_put_contents($indexPhp, "<?php http_response_code(403); exit('Forbidden');");
    }

    /**
     * Check if database exists and has been initialized
     */
    public static function isInitialized(): bool {
        if (!file_exists(self::getDbPath())) {
            return false;
        }

        try {
            $pdo = self::getInstance();
            $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='config'");
            return $stmt->fetch() !== false;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Ensure the database exists (creates directory, .htaccess, and empty DB)
     */
    public static function ensureExists(): void {
        $dir = self::getDataDir();
        if (!is_dir($dir)) {
            self::createDataDirectory($dir);
        }

        // Touch the database file to ensure it exists
        if (!file_exists(self::getDbPath())) {
            self::getInstance(); // This creates the DB
        }
    }

    /**
     * Initialize database schema
     */
    public static function initialize(): void {
        $pdo = self::getInstance();

        $schema = "
        -- Core configuration
        CREATE TABLE IF NOT EXISTS config (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL,
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL
        );

        -- Stores (per-store configuration with own mint and wallet)
        CREATE TABLE IF NOT EXISTS stores (
            id TEXT PRIMARY KEY,
            name TEXT NOT NULL,
            internal_api_key TEXT,
            -- Mint configuration (required for store to be active)
            mint_url TEXT,
            mint_unit TEXT NOT NULL DEFAULT 'sat',
            seed_phrase TEXT,
            wallet_account_id TEXT,
            -- Exchange settings
            exchange_fee_percent REAL NOT NULL DEFAULT 0,
            price_provider_primary TEXT NOT NULL DEFAULT 'coingecko',
            price_provider_secondary TEXT DEFAULT 'binance',
            -- Auto-withdraw settings (per-store)
            auto_melt_enabled INTEGER NOT NULL DEFAULT 0,
            auto_melt_address TEXT,
            auto_melt_threshold INTEGER NOT NULL DEFAULT 2000,
            -- Timestamps
            created_at INTEGER NOT NULL
        );

        -- API keys
        CREATE TABLE IF NOT EXISTS api_keys (
            id TEXT PRIMARY KEY,
            key_hash TEXT NOT NULL UNIQUE,
            store_id TEXT NOT NULL,
            label TEXT,
            permissions TEXT NOT NULL,
            application_identifier TEXT,
            redirect_host TEXT,
            created_at INTEGER NOT NULL,
            FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE
        );

        CREATE INDEX IF NOT EXISTS idx_api_keys_app_id
            ON api_keys(store_id, application_identifier, redirect_host);

        -- Invoices (BTCPay compatible)
        CREATE TABLE IF NOT EXISTS invoices (
            id TEXT PRIMARY KEY,
            store_id TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'New',
            additional_status TEXT DEFAULT 'None',
            amount TEXT NOT NULL,
            currency TEXT NOT NULL,
            amount_sats INTEGER,
            exchange_rate REAL,
            quote_id TEXT,
            bolt11 TEXT,
            mint_url TEXT,
            metadata TEXT,
            checkout_config TEXT,
            created_at INTEGER NOT NULL,
            expiration_time INTEGER NOT NULL,
            last_polled_at INTEGER DEFAULT NULL,
            FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE
        );

        -- Webhooks
        CREATE TABLE IF NOT EXISTS webhooks (
            id TEXT PRIMARY KEY,
            store_id TEXT NOT NULL,
            url TEXT NOT NULL,
            secret TEXT NOT NULL,
            events TEXT NOT NULL,
            enabled INTEGER NOT NULL DEFAULT 1,
            deleted_at INTEGER,
            created_at INTEGER NOT NULL,
            FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE
        );

        -- Webhook deliveries (for retry/debug)
        CREATE TABLE IF NOT EXISTS webhook_deliveries (
            id TEXT PRIMARY KEY,
            webhook_id TEXT NOT NULL,
            invoice_id TEXT,
            event_type TEXT NOT NULL,
            payload TEXT NOT NULL,
            status_code INTEGER,
            response TEXT,
            created_at INTEGER NOT NULL,
            attempts INTEGER NOT NULL DEFAULT 0,
            next_attempt_at INTEGER NOT NULL DEFAULT 0,
            leased_until INTEGER,
            lease_token TEXT,
            last_attempt_at INTEGER,
            delivered_at INTEGER,
            idempotency_key TEXT,
            target_url TEXT,
            signing_secret TEXT,
            FOREIGN KEY (webhook_id) REFERENCES webhooks(id) ON DELETE CASCADE
        );

        -- Per-store backup mints for failover
        CREATE TABLE IF NOT EXISTS store_mints (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            store_id TEXT NOT NULL,
            mint_url TEXT NOT NULL,
            unit TEXT NOT NULL DEFAULT 'sat',
            priority INTEGER NOT NULL DEFAULT 0,
            enabled INTEGER NOT NULL DEFAULT 1,
            created_at INTEGER NOT NULL,
            FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE,
            UNIQUE(store_id, mint_url)
        );

        -- Outgoing transfers ledger: Lightning withdrawals, auto-withdrawals,
        -- token exports and donations. Accountability record of funds leaving.
        CREATE TABLE IF NOT EXISTS transfers (
            id TEXT PRIMARY KEY,
            store_id TEXT NOT NULL,
            type TEXT NOT NULL,
            amount INTEGER NOT NULL,
            fee INTEGER NOT NULL DEFAULT 0,
            unit TEXT NOT NULL DEFAULT 'sat',
            destination TEXT,
            status TEXT NOT NULL DEFAULT 'completed',
            detail TEXT,
            created_at INTEGER NOT NULL,
            FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE
        );

        -- Indexes for performance
        CREATE INDEX IF NOT EXISTS idx_invoices_store ON invoices(store_id);
        CREATE INDEX IF NOT EXISTS idx_invoices_status ON invoices(status);
        CREATE INDEX IF NOT EXISTS idx_invoices_quote ON invoices(quote_id);
        CREATE INDEX IF NOT EXISTS idx_api_keys_store ON api_keys(store_id);
        CREATE INDEX IF NOT EXISTS idx_webhooks_store ON webhooks(store_id);
        CREATE INDEX IF NOT EXISTS idx_store_mints_store ON store_mints(store_id);
        CREATE INDEX IF NOT EXISTS idx_store_mints_priority ON store_mints(store_id, priority);
        CREATE INDEX IF NOT EXISTS idx_transfers_store ON transfers(store_id, created_at);
        ";

        $pdo->exec($schema);

        // Initialize wallet storage schema (for cashu-wallet-php library)
        WalletStorage::initializeSchema($pdo);
        self::ensureCurrentSchema($pdo);
    }

    /**
     * Apply idempotent schema migrations for existing databases.
     */
    public static function ensureCurrentSchema(?\PDO $pdo = null): void {
        $pdo = $pdo ?? self::getInstance();

        // An empty database belongs to setup.php; do not make isInitialized() true here.
        $table = $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'config'")->fetchColumn();
        if (!$table || (int)$pdo->query('PRAGMA user_version')->fetchColumn() >= self::SCHEMA_VERSION) {
            return;
        }

        // Wallet tables must exist before the account-identity migration runs.
        WalletStorage::initializeSchema($pdo);
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $version = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
            if ($version < 1) {
                self::addColumnIfMissing($pdo, 'invoices', 'mint_url', 'TEXT');
                self::addColumnIfMissing($pdo, 'invoices', 'last_polled_at', 'INTEGER DEFAULT NULL');
                self::addColumnIfMissing($pdo, 'invoices', 'processing_since', 'INTEGER DEFAULT NULL');
                $pdo->exec('PRAGMA user_version = 1');
            }
            if ($version < 2) {
                self::addColumnIfMissing($pdo, 'webhook_deliveries', 'attempts', 'INTEGER NOT NULL DEFAULT 0');
                self::addColumnIfMissing($pdo, 'webhook_deliveries', 'next_attempt_at', 'INTEGER NOT NULL DEFAULT 0');
                self::addColumnIfMissing($pdo, 'webhook_deliveries', 'leased_until', 'INTEGER');
                self::addColumnIfMissing($pdo, 'webhook_deliveries', 'lease_token', 'TEXT');
                self::addColumnIfMissing($pdo, 'webhook_deliveries', 'last_attempt_at', 'INTEGER');
                self::addColumnIfMissing($pdo, 'webhook_deliveries', 'delivered_at', 'INTEGER');
                self::addColumnIfMissing($pdo, 'webhook_deliveries', 'idempotency_key', 'TEXT');
                // Rows from the old synchronous log are history, not pending outbox work.
                $pdo->exec("UPDATE webhook_deliveries SET delivered_at = created_at WHERE delivered_at IS NULL");
                $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_webhook_delivery_idempotency ON webhook_deliveries(idempotency_key) WHERE idempotency_key IS NOT NULL');
                $pdo->exec('CREATE INDEX IF NOT EXISTS idx_webhook_outbox_due ON webhook_deliveries(delivered_at, next_attempt_at, leased_until)');
                $pdo->exec('PRAGMA user_version = 2');
            }
            if ($version < 3) {
                self::addColumnIfMissing($pdo, 'stores', 'wallet_account_id', 'TEXT');
                self::migrateWalletAccounts($pdo);
                $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_stores_wallet_account_id ON stores(wallet_account_id) WHERE wallet_account_id IS NOT NULL');
                $pdo->exec('PRAGMA user_version = 3');
            }
            if ($version < 4) {
                self::addColumnIfMissing($pdo, 'webhook_deliveries', 'target_url', 'TEXT');
                self::addColumnIfMissing($pdo, 'webhook_deliveries', 'signing_secret', 'TEXT');
                if (self::tableExists($pdo, 'webhooks')) {
                    $pdo->exec(
                        "UPDATE webhook_deliveries
                         SET target_url = (SELECT url FROM webhooks WHERE webhooks.id = webhook_deliveries.webhook_id),
                             signing_secret = (SELECT secret FROM webhooks WHERE webhooks.id = webhook_deliveries.webhook_id)
                         WHERE target_url IS NULL OR signing_secret IS NULL"
                    );
                }
                $pdo->exec('PRAGMA user_version = 4');
            }
            if ($version < 5) {
                if (self::tableExists($pdo, 'webhooks')) {
                    self::addColumnIfMissing($pdo, 'webhooks', 'deleted_at', 'INTEGER');
                }
                $pdo->exec('PRAGMA user_version = 5');
            }
            if ($version < 6) {
                // Outgoing-transfer ledger (Lightning/auto withdrawals, exports, donations).
                $pdo->exec("CREATE TABLE IF NOT EXISTS transfers (
                    id TEXT PRIMARY KEY,
                    store_id TEXT NOT NULL,
                    type TEXT NOT NULL,
                    amount INTEGER NOT NULL,
                    fee INTEGER NOT NULL DEFAULT 0,
                    unit TEXT NOT NULL DEFAULT 'sat',
                    destination TEXT,
                    status TEXT NOT NULL DEFAULT 'completed',
                    detail TEXT,
                    created_at INTEGER NOT NULL,
                    FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE
                )");
                $pdo->exec("CREATE INDEX IF NOT EXISTS idx_transfers_store ON transfers(store_id, created_at)");
                $pdo->exec('PRAGMA user_version = 6');
            }
            // The transaction was opened with exec('BEGIN IMMEDIATE'), which PDO's
            // internal transaction flag does not track before PHP 8.4 — commit()
            // and rollBack() would throw "There is no active transaction".
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            try {
                $pdo->exec('ROLLBACK');
            } catch (Throwable $rollbackError) {
                // No transaction left to roll back (e.g. the failure was the COMMIT).
            }
            throw $e;
        }
    }

    private static function addColumnIfMissing(\PDO $pdo, string $table, string $column, string $definition): void {
        if (!self::columnExists($pdo, $table, $column)) {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        }
    }

    private static function columnExists(\PDO $pdo, string $table, string $column): bool {
        $stmt = $pdo->query("PRAGMA table_info(" . $table . ")");
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (($row['name'] ?? null) === $column) {
                return true;
            }
        }
        return false;
    }

    private static function tableExists(\PDO $pdo, string $table): bool {
        $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
        $stmt->execute([$table]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Move legacy mint/unit namespaces to immutable per-store account namespaces.
     * Populated namespaces with multiple owners are not attributable safely, so abort.
     */
    private static function migrateWalletAccounts(\PDO $pdo): void {
        $stores = $pdo->query('SELECT id, name, mint_url, mint_unit, seed_phrase, wallet_account_id FROM stores')->fetchAll(\PDO::FETCH_ASSOC);
        $updateAccount = $pdo->prepare('UPDATE stores SET wallet_account_id = ? WHERE id = ?');
        foreach ($stores as &$store) {
            if (empty($store['wallet_account_id'])) {
                $store['wallet_account_id'] = self::generateWalletAccountId();
                $updateAccount->execute([$store['wallet_account_id'], $store['id']]);
            }
        }
        unset($store);

        $candidates = [];
        foreach ($stores as $store) {
            if (!empty($store['mint_url'])) {
                $candidates[] = [
                    'store' => $store,
                    'mint_url' => rtrim($store['mint_url'], '/'),
                    'unit' => strtolower($store['mint_unit'] ?: 'sat'),
                ];
            }
            $stmt = $pdo->prepare('SELECT mint_url, unit FROM store_mints WHERE store_id = ?');
            $stmt->execute([$store['id']]);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $mint) {
                $candidates[] = [
                    'store' => $store,
                    'mint_url' => rtrim($mint['mint_url'], '/'),
                    'unit' => strtolower($mint['unit'] ?: 'sat'),
                ];
            }
        }

        $byLegacy = [];
        foreach ($candidates as $candidate) {
            $legacyId = WalletStorage::deriveWalletId($candidate['mint_url'], $candidate['unit']);
            $byLegacy[$legacyId][$candidate['store']['id']] = $candidate;
        }

        foreach ($byLegacy as $legacyId => $owners) {
            $hasData = self::walletNamespaceHasData($pdo, $legacyId);
            if ($hasData && count($owners) > 1) {
                $names = array_map(fn($owner) => $owner['store']['name'] . ' (' . $owner['store']['id'] . ')', array_values($owners));
                throw new RuntimeException(
                    'Wallet migration blocked: legacy wallet data is shared by multiple stores: ' . implode(', ', $names)
                );
            }

            foreach ($owners as $candidate) {
                $store = $candidate['store'];
                $newId = WalletStorage::deriveWalletId(
                    $candidate['mint_url'],
                    $candidate['unit'],
                    $store['wallet_account_id']
                );
                if ($hasData) {
                    if (self::walletNamespaceHasData($pdo, $newId)) {
                        throw new RuntimeException("Wallet migration blocked: destination namespace already contains data for store {$store['id']}");
                    }
                    foreach (['cashu_proofs', 'cashu_counters'] as $table) {
                        $stmt = $pdo->prepare("UPDATE {$table} SET wallet_id = ? WHERE wallet_id = ?");
                        $stmt->execute([$newId, $legacyId]);
                    }
                    $stmt = $pdo->prepare(
                        "UPDATE cashu_pending_operations
                         SET id = CASE WHEN id LIKE ? THEN ? || substr(id, ?) ELSE id END, wallet_id = ?
                         WHERE wallet_id = ?"
                    );
                    $stmt->execute([$legacyId . ':%', $newId . ':', strlen($legacyId) + 2, $newId, $legacyId]);
                    $stmt = $pdo->prepare('UPDATE cashu_wallet_metadata SET wallet_id = ? WHERE wallet_id = ?');
                    $stmt->execute([$newId, $legacyId]);
                }

                if (!empty($store['seed_phrase'])) {
                    $fingerprint = Wallet::calculateSeedFingerprint($store['seed_phrase']);
                    $stmt = $pdo->prepare(
                        'INSERT OR IGNORE INTO cashu_wallet_metadata (wallet_id, seed_fingerprint, ready, created_at) VALUES (?, ?, 1, ?)'
                    );
                    $stmt->execute([$newId, $fingerprint, time()]);
                    $existing = $pdo->prepare('SELECT seed_fingerprint FROM cashu_wallet_metadata WHERE wallet_id = ?');
                    $existing->execute([$newId]);
                    if (!hash_equals($fingerprint, (string)$existing->fetchColumn())) {
                        throw new RuntimeException("Wallet migration blocked: seed fingerprint mismatch for store {$store['id']}");
                    }
                }
            }
        }
    }

    private static function walletNamespaceHasData(\PDO $pdo, string $walletId): bool {
        foreach (['cashu_proofs', 'cashu_counters', 'cashu_pending_operations', 'cashu_wallet_metadata'] as $table) {
            $stmt = $pdo->prepare("SELECT 1 FROM {$table} WHERE wallet_id = ? LIMIT 1");
            $stmt->execute([$walletId]);
            if ($stmt->fetchColumn() !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Generate a unique ID
     */
    public static function generateId(string $prefix = ''): string {
        $bytes = random_bytes(12);
        $id = bin2hex($bytes);
        return $prefix ? $prefix . '_' . $id : $id;
    }

    public static function generateWalletAccountId(): string {
        return 'wa_' . bin2hex(random_bytes(16));
    }

    /**
     * Get current Unix timestamp
     */
    public static function timestamp(): int {
        return time();
    }

    /**
     * Begin transaction
     */
    public static function beginTransaction(): bool {
        return self::getInstance()->beginTransaction();
    }

    /**
     * Commit transaction
     */
    public static function commit(): bool {
        return self::getInstance()->commit();
    }

    /**
     * Rollback transaction
     */
    public static function rollback(): bool {
        return self::getInstance()->rollBack();
    }

    /**
     * Execute query with parameters
     */
    public static function query(string $sql, array $params = []): PDOStatement {
        $stmt = self::getInstance()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Fetch single row
     */
    public static function fetchOne(string $sql, array $params = []): ?array {
        $result = self::query($sql, $params)->fetch();
        return $result ?: null;
    }

    /**
     * Fetch all rows
     */
    public static function fetchAll(string $sql, array $params = []): array {
        return self::query($sql, $params)->fetchAll();
    }

    /**
     * Insert row and return ID
     */
    public static function insert(string $table, array $data): string|int {
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        $sql = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";
        self::query($sql, array_values($data));

        return self::getInstance()->lastInsertId();
    }

    /**
     * Update rows
     */
    public static function update(string $table, array $data, string $where, array $whereParams = []): int {
        $set = implode(' = ?, ', array_keys($data)) . ' = ?';
        $sql = "UPDATE {$table} SET {$set} WHERE {$where}";

        $stmt = self::query($sql, array_merge(array_values($data), $whereParams));
        return $stmt->rowCount();
    }

    /**
     * Delete rows
     */
    public static function delete(string $table, string $where, array $params = []): int {
        $sql = "DELETE FROM {$table} WHERE {$where}";
        $stmt = self::query($sql, $params);
        return $stmt->rowCount();
    }
}
