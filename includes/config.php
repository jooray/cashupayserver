<?php
/**
 * CashuPayServer Configuration Module
 *
 * Load/save configuration from database.
 */

require_once __DIR__ . '/database.php';

// Version
define('CASHUPAY_VERSION', '0.5.1-alpha');

/**
 * The BTCPay Server version reported to Greenfield clients.
 *
 * E-commerce plugins treat /server/info `version` as BTCPay's version and gate features
 * on it, so it must look like a BTCPay release rather than ours. Bump it only after
 * testing the clients that care; CashuPayServer's own version travels alongside it as
 * `cashuPayServerVersion`.
 */
define('BTCPAY_COMPAT_VERSION', '1.0.0');

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

        // Auto-detect (fallback only). NOTE: HTTP_HOST is attacker-controlled; it is
        // sanitized here so it cannot be used to redirect internal self-requests (e.g. the
        // background cron trigger, which carries an internal key) to an arbitrary host.
        // Operators behind a proxy should set an explicit `base_url`. See FABLE-SECURITY-AUDIT (HIGH-5).
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        // Allow only valid host[:port] characters.
        if (!preg_match('/^[A-Za-z0-9.\-]+(:[0-9]+)?$/', $host)) {
            $host = $_SERVER['SERVER_NAME'] ?? 'localhost';
        }
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
            && !empty($store['seed_phrase'])
            && !empty($store['wallet_account_id']);
    }

    public static function getStoreWalletAccountId(string $storeId): ?string {
        $store = self::getStore($storeId);
        return $store['wallet_account_id'] ?? null;
    }

    /**
     * Update store settings
     */
    public static function updateStore(string $storeId, array $data, bool $allowMintChange = false): void {
        $store = self::getStore($storeId);
        if (!$store) {
            throw new Exception('Store not found');
        }
        foreach (['mint_url', 'mint_unit', 'seed_phrase'] as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $old = (string)($store[$field] ?? '');
            $new = (string)($data[$field] ?? '');
            if ($field === 'mint_url') {
                $old = rtrim($old, '/');
                $new = rtrim($new, '/');
            }
            if ($old !== '' && $old !== $new && self::walletAccountIsInitialized($store)) {
                // The seed is never changeable in place — a different seed can't
                // derive the existing proofs' secrets and breaks recovery.
                // The mint/unit MAY be changed with explicit operator consent
                // ($allowMintChange): the old mint's funds are only stranded, not
                // lost, and stay recoverable via Invoice::scanStrandedNamespaces().
                if ($field === 'seed_phrase' || !$allowMintChange) {
                    throw new Exception('Wallet seed, mint, and unit are immutable after wallet initialization. Create a new store wallet instead.');
                }
            }
        }
        $allowed = [
            'name', 'mint_url', 'mint_unit', 'seed_phrase',
            'exchange_fee_percent', 'price_provider_primary', 'price_provider_secondary'
        ];
        $updateData = array_intersect_key($data, array_flip($allowed));

        if (!empty($updateData)) {
            Database::update('stores', $updateData, 'id = ?', [$storeId]);
        }
    }

    /** Whether a store's wallet has been initialized (holds a seed fingerprint
     *  or proofs) — i.e. changing its mint/unit would strand funds. */
    public static function isStoreWalletInitialized(string $storeId): bool {
        $store = self::getStore($storeId);
        return $store ? self::walletAccountIsInitialized($store) : false;
    }

    private static function walletAccountIsInitialized(array $store): bool {
        if (empty($store['wallet_account_id']) || empty($store['mint_url'])) {
            return false;
        }
        $storage = new \Cashu\WalletStorage(
            Database::getDbPath(),
            $store['mint_url'],
            $store['mint_unit'] ?? 'sat',
            $store['wallet_account_id']
        );
        return $storage->getSeedFingerprint() !== null || $storage->hasWalletData();
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

    /** Include disabled backups because they may still contain recovery state. */
    public static function getStoreWalletAccounts(string $storeId): array {
        $store = self::getStore($storeId);
        if (!$store) {
            return [];
        }
        $accounts = [];
        if (!empty($store['mint_url'])) {
            $accounts[] = [
                'mint_url' => rtrim($store['mint_url'], '/'),
                'unit' => strtolower($store['mint_unit'] ?? 'sat'),
                'primary' => true,
                'enabled' => true,
            ];
        }
        foreach (self::getStoreBackupMints($storeId) as $mint) {
            $key = rtrim($mint['mint_url'], '/') . '|' . strtolower($mint['unit'] ?? 'sat');
            $exists = false;
            foreach ($accounts as $account) {
                if ($key === $account['mint_url'] . '|' . $account['unit']) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $accounts[] = [
                    'mint_url' => rtrim($mint['mint_url'], '/'),
                    'unit' => strtolower($mint['unit'] ?? 'sat'),
                    'primary' => false,
                    'enabled' => (bool)$mint['enabled'],
                ];
            }
        }
        return $accounts;
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
        // Only allow http(s) mint URLs (blocks file:// and other schemes at the single
        // point every mint-URL entry path flows through). See FABLE-SECURITY-AUDIT (MED-2).
        require_once __DIR__ . '/security.php';
        if (Security::sanitizeUrl($mintUrl) === null) {
            return ['success' => false, 'error' => 'Mint URL must be a valid http(s) URL', 'info' => null];
        }
        try {
            $client = new \Cashu\MintClient(rtrim($mintUrl, '/'));
            $info = $client->get('info');
            return ['success' => true, 'error' => null, 'info' => $info];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'info' => null];
        }
    }
}
