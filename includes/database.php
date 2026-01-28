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

use Cashu\WalletStorage;

class Database {
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

        -- Indexes for performance
        CREATE INDEX IF NOT EXISTS idx_invoices_store ON invoices(store_id);
        CREATE INDEX IF NOT EXISTS idx_invoices_status ON invoices(status);
        CREATE INDEX IF NOT EXISTS idx_invoices_quote ON invoices(quote_id);
        CREATE INDEX IF NOT EXISTS idx_api_keys_store ON api_keys(store_id);
        CREATE INDEX IF NOT EXISTS idx_webhooks_store ON webhooks(store_id);
        CREATE INDEX IF NOT EXISTS idx_store_mints_store ON store_mints(store_id);
        CREATE INDEX IF NOT EXISTS idx_store_mints_priority ON store_mints(store_id, priority);
        ";

        $pdo->exec($schema);

        // Initialize wallet storage schema (for cashu-wallet-php library)
        WalletStorage::initializeSchema($pdo);
    }

    /**
     * Generate a unique ID
     */
    public static function generateId(string $prefix = ''): string {
        $bytes = random_bytes(12);
        $id = bin2hex($bytes);
        return $prefix ? $prefix . '_' . $id : $id;
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
