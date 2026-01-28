<?php
/**
 * CashuPayServer Configuration Module
 *
 * Load/save configuration from database.
 */

require_once __DIR__ . '/database.php';

// Version
define('CASHUPAY_VERSION', '0.1-alpha');

// Donation settings for supporting CashuPayServer development
define('CASHUPAY_DONATION_PERCENT', 1); // 1% donation
define('CASHUPAY_DONATION_SINK_URL', 'https://cypherpunk.today/donation-sink/donation-sink.php');

class Config {
    private static array $cache = [];

    /**
     * Get configuration value
     */
    public static function get(string $key, mixed $default = null): mixed {
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $row = Database::fetchOne(
            "SELECT value FROM config WHERE key = ?",
            [$key]
        );

        if ($row === null) {
            return $default;
        }

        $value = json_decode($row['value'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $value = $row['value'];
        }

        self::$cache[$key] = $value;
        return $value;
    }

    /**
     * Set configuration value
     */
    public static function set(string $key, mixed $value): void {
        $now = Database::timestamp();
        $jsonValue = is_string($value) ? $value : json_encode($value);

        $existing = Database::fetchOne(
            "SELECT key FROM config WHERE key = ?",
            [$key]
        );

        if ($existing) {
            Database::update(
                'config',
                ['value' => $jsonValue, 'updated_at' => $now],
                'key = ?',
                [$key]
            );
        } else {
            Database::insert('config', [
                'key' => $key,
                'value' => $jsonValue,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        self::$cache[$key] = $value;
    }

    /**
     * Delete configuration value
     */
    public static function delete(string $key): void {
        Database::delete('config', 'key = ?', [$key]);
        unset(self::$cache[$key]);
    }

    /**
     * Get all configuration values
     */
    public static function getAll(): array {
        $rows = Database::fetchAll("SELECT key, value FROM config");
        $config = [];

        foreach ($rows as $row) {
            $value = json_decode($row['value'], true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $value = $row['value'];
            }
            $config[$row['key']] = $value;
        }

        return $config;
    }

    /**
     * Check if setup has been completed
     */
    public static function isSetupComplete(): bool {
        return self::get('setup_complete', false) === true;
    }

    /**
     * Get mint URL
     */
    public static function getMintUrl(): ?string {
        return self::get('mint_url');
    }

    /**
     * Get mint unit
     */
    public static function getMintUnit(): string {
        return self::get('mint_unit', 'sat');
    }

    /**
     * Get seed phrase (encrypted)
     */
    public static function getSeedPhrase(): ?string {
        return self::get('seed_phrase');
    }

    /**
     * Get admin password hash
     */
    public static function getAdminPasswordHash(): ?string {
        return self::get('admin_password_hash');
    }

    /**
     * Get accepted currencies
     */
    public static function getAcceptedCurrencies(): array {
        return self::get('accept_currencies', ['BTC', 'sat']);
    }

    /**
     * Get invoice expiration time in seconds
     */
    public static function getInvoiceExpiration(): int {
        return self::get('invoice_expiration', 900); // 15 minutes default
    }

    /**
     * Get URL mode for standalone deployments
     *
     * @return string 'direct' for clean URLs (/api/v1/...) or 'router' for router.php URLs
     */
    public static function getUrlMode(): string {
        return self::get('url_mode', 'router'); // Default router for max compatibility
    }

    /**
     * Get base URL for the application
     */
    public static function getBaseUrl(): string {
        $baseUrl = self::get('base_url');
        if ($baseUrl) {
            return rtrim($baseUrl, '/');
        }

        // Auto-detect
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $path = dirname($_SERVER['SCRIPT_NAME'] ?? '');

        return rtrim($protocol . '://' . $host . $path, '/');
    }

    /**
     * Clear configuration cache
     */
    public static function clearCache(): void {
        self::$cache = [];
    }

    // ========================================================================
    // PER-STORE CONFIGURATION
    // ========================================================================

    /**
     * Get store configuration
     */
    public static function getStore(string $storeId): ?array {
        return Database::fetchOne(
            "SELECT * FROM stores WHERE id = ?",
            [$storeId]
        );
    }

    /**
     * Get store's mint URL
     */
    public static function getStoreMintUrl(string $storeId): ?string {
        $store = self::getStore($storeId);
        return $store['mint_url'] ?? null;
    }

    /**
     * Get store's mint unit
     */
    public static function getStoreMintUnit(string $storeId): string {
        $store = self::getStore($storeId);
        return $store['mint_unit'] ?? 'sat';
    }

    /**
     * Get store's seed phrase
     */
    public static function getStoreSeedPhrase(string $storeId): ?string {
        $store = self::getStore($storeId);
        return $store['seed_phrase'] ?? null;
    }

    /**
     * Get store's exchange fee percentage
     */
    public static function getStoreExchangeFee(string $storeId): float {
        $store = self::getStore($storeId);
        return (float)($store['exchange_fee_percent'] ?? 0);
    }

    /**
     * Get store's price provider settings
     */
    public static function getStorePriceProviders(string $storeId): array {
        $store = self::getStore($storeId);
        return [
            'primary' => $store['price_provider_primary'] ?? 'coingecko',
            'secondary' => $store['price_provider_secondary'] ?? 'binance',
        ];
    }

    /**
     * Check if store is configured (has mint and seed phrase)
     */
    public static function isStoreConfigured(string $storeId): bool {
        $store = self::getStore($storeId);
        return $store !== null
            && !empty($store['mint_url'])
            && !empty($store['seed_phrase']);
    }

    /**
     * Update store settings
     */
    public static function updateStore(string $storeId, array $data): void {
        $allowed = [
            'name', 'mint_url', 'mint_unit', 'seed_phrase',
            'exchange_fee_percent', 'price_provider_primary', 'price_provider_secondary'
        ];
        $updateData = array_intersect_key($data, array_flip($allowed));

        if (!empty($updateData)) {
            Database::update('stores', $updateData, 'id = ?', [$storeId]);
        }
    }

    // ========================================================================
    // PER-STORE BACKUP MINTS MANAGEMENT
    // ========================================================================

    /**
     * Get all backup mints for a store in priority order
     */
    public static function getStoreBackupMints(string $storeId): array {
        return Database::fetchAll(
            "SELECT id, mint_url, unit, priority, enabled, created_at
             FROM store_mints
             WHERE store_id = ?
             ORDER BY priority ASC",
            [$storeId]
        );
    }

    /**
     * Get all enabled backup mints for a store and specific unit
     */
    public static function getStoreEnabledMints(string $storeId, string $unit = 'sat'): array {
        $rows = Database::fetchAll(
            "SELECT mint_url FROM store_mints
             WHERE store_id = ? AND enabled = 1 AND unit = ?
             ORDER BY priority ASC",
            [$storeId, $unit]
        );
        return array_column($rows, 'mint_url');
    }

    /**
     * Get all mint URLs (primary + backups) for a store
     */
    public static function getStoreAllMintUrls(string $storeId): array {
        $primary = self::getStoreMintUrl($storeId);
        if (!$primary) {
            return [];
        }

        $unit = self::getStoreMintUnit($storeId);
        $backups = self::getStoreEnabledMints($storeId, $unit);

        // Primary first, then backups (excluding primary if it's in backups)
        $allMints = [$primary];
        foreach ($backups as $backup) {
            if (rtrim($backup, '/') !== rtrim($primary, '/')) {
                $allMints[] = $backup;
            }
        }

        return $allMints;
    }

    /**
     * Add a backup mint to a store
     */
    public static function addStoreBackupMint(string $storeId, string $mintUrl, string $unit = 'sat', int $priority = 100): int {
        $mintUrl = rtrim($mintUrl, '/');

        return (int) Database::insert('store_mints', [
            'store_id' => $storeId,
            'mint_url' => $mintUrl,
            'unit' => $unit,
            'priority' => $priority,
            'enabled' => 1,
            'created_at' => Database::timestamp(),
        ]);
    }

    /**
     * Update a store's backup mint settings
     */
    public static function updateStoreBackupMint(int $id, array $data): void {
        $allowed = ['priority', 'enabled'];
        $updateData = array_intersect_key($data, array_flip($allowed));

        if (!empty($updateData)) {
            Database::update('store_mints', $updateData, 'id = ?', [$id]);
        }
    }

    /**
     * Remove a backup mint from a store
     */
    public static function removeStoreBackupMint(int $id): void {
        Database::delete('store_mints', 'id = ?', [$id]);
    }

    // ========================================================================
    // UTILITIES
    // ========================================================================

    /**
     * Test connectivity to a mint
     *
     * @param string $mintUrl Mint URL to test
     * @return array{success: bool, error: ?string, info: ?array}
     */
    public static function testMintConnection(string $mintUrl): array {
        try {
            $client = new \Cashu\MintClient(rtrim($mintUrl, '/'));
            $info = $client->get('info');
            return ['success' => true, 'error' => null, 'info' => $info];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'info' => null];
        }
    }
}
