<?php
/**
 * CashuPayServer Configuration Module
 *
 * Load/save configuration from database.
 */

require_once __DIR__ . '/database.php';

// Version
require_once __DIR__ . '/http.php';

define('CASHUPAY_VERSION', '0.5.7-alpha');

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
if (!defined('CASHUPAY_DONATION_PERCENT')) {
    define('CASHUPAY_DONATION_PERCENT', 1); // 1% donation; config.local.php may set 0
}
define('CASHUPAY_DONATION_SINK_URL', 'https://cypherpunk.today/donation-sink/donation-sink.php');
// Which project the sink credits these donations to. The sink accepts any
// alphanumeric name without being configured for it, and ignores the field
// entirely if it is running a version that does not know about projects, so
// this is safe to send either way. A fork pointing at its own sink should
// change it; setting it to '' sends nothing.
define('CASHUPAY_DONATION_SINK_PROJECT', 'cashupayserver');

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

        // One statement: a SELECT-then-INSERT let two first writers of the same key
        // both insert, and the loser died on the primary key (a 500 on checkout).
        Database::query(
            "INSERT INTO config (key, value, created_at, updated_at) VALUES (?, ?, ?, ?)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at",
            [$key, $jsonValue, $now, $now]
        );

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
     * The store (other than $excludeStoreId) that already uses this seed phrase, or null.
     *
     * NUT-13 derives every secret from seed + keyset + counter alone, so two stores on
     * one seed at one mint generate identical outputs: one money, two ledgers, and
     * whoever spends first leaves the other holding dead proofs. Compared canonically
     * (case and whitespace), because the wallet normalizes both before deriving.
     * Every writer of a seed must ask this first.
     */
    public static function storeUsingSeed(string $seedPhrase, ?string $excludeStoreId = null): ?array {
        $canonical = static fn(string $seed): string =>
            (string)preg_replace('/\s+/', ' ', mb_strtolower(trim($seed)));
        $candidate = $canonical($seedPhrase);
        if ($candidate === '') {
            return null;
        }
        foreach (Database::fetchAll("SELECT id, name, seed_phrase FROM stores WHERE seed_phrase IS NOT NULL AND seed_phrase != ''") as $row) {
            if ($excludeStoreId !== null && $row['id'] === $excludeStoreId) {
                continue;
            }
            if (hash_equals($canonical((string)$row['seed_phrase']), $candidate)) {
                return ['id' => $row['id'], 'name' => $row['name']];
            }
        }
        return null;
    }

    /**
     * Get the global seed phrase (legacy; stored in plain text like the per-store seeds,
     * so the data directory must never be web-readable).
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
        // Before setup there is no config table yet. The very first visit to the site
        // root builds the setup link from here, and used to die with "no such table"
        // and a blank page instead of sending the operator to setup.
        $baseUrl = Database::isInitialized() ? self::get('base_url') : null;
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
            if ($old !== '' && $old !== $new) {
                // The seed is the store's recovery key and is never changeable in place,
                // whether or not the current namespace happens to be initialized: a mint
                // or unit change that could not reach its new mint leaves the namespace
                // empty, and replacing the seed there would destroy the only key to the
                // money still at the old mint (MINIMAX 4.24).
                if ($field === 'seed_phrase') {
                    throw new Exception('This store already has its recovery phrase, and it cannot be replaced. To use a different phrase, create a new store and move the money across.');
                }
                if (self::walletAccountIsInitialized($store) && !$allowMintChange) {
                    // The mint/unit MAY be changed with explicit operator consent
                    // ($allowMintChange): the old mint's funds are only stranded, not
                    // lost, and stay recoverable via Invoice::scanStrandedNamespaces().
                    throw new Exception('Wallet seed, mint, and unit are immutable after wallet initialization. Create a new store wallet instead.');
                }
            }
        }
        if (array_key_exists('mint_url', $data) && (string)$data['mint_url'] !== '') {
            $data['mint_url'] = self::normalizeMintUrl((string)$data['mint_url'], $store['mint_url'] ?? null);
        }
        if (array_key_exists('mint_unit', $data)) {
            $data['mint_unit'] = self::validUnit((string)$data['mint_unit']);
        }
        if (array_key_exists('seed_phrase', $data) && (string)$data['seed_phrase'] !== '') {
            $clash = self::storeUsingSeed((string)$data['seed_phrase'], $storeId);
            if ($clash) {
                throw new Exception("This seed phrase is already used by store: {$clash['name']}. Using the same seed for multiple stores can result in lost funds.");
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

    /**
     * A mint URL in the one spelling the wallet will use.
     *
     * The wallet's identity is a hash of the URL string, so `https://Mint.Example/`
     * and `https://mint.example` would be two wallets for one mint — a second wallet
     * starting at counter 0 for a seed the mint has already signed. New input is
     * canonicalized; when it names the mint already in $existing (any spelling), the
     * existing string is kept so an established wallet does not move.
     *
     * @throws Exception If the URL cannot be used as a mint endpoint
     */
    public static function normalizeMintUrl(string $mintUrl, ?string ...$existing): string {
        require_once __DIR__ . '/../cashu-wallet-php/CashuWallet.php';
        try {
            $canonical = \Cashu\MintClient::canonicalizeMintUrl($mintUrl);
        } catch (\Throwable $e) {
            throw new Exception('That is not a valid mint address: ' . $e->getMessage());
        }
        foreach ($existing as $known) {
            if ($known === null || $known === '') {
                continue;
            }
            try {
                if (\Cashu\MintClient::canonicalizeMintUrl($known) === $canonical) {
                    return rtrim($known, '/');
                }
            } catch (\Throwable $e) {
                // An unparseable stored value cannot be the same mint.
            }
        }
        return $canonical;
    }

    /**
     * How many decimals a mint unit's smallest amount has, for display and input.
     *
     * The one rule every screen and the pricing code (ExchangeRates::toSmallestUnit)
     * share: sat and msat are whole numbers, btc counts satoshis (8 decimals), and any
     * other unit — a fiat currency or a dollar stablecoin — counts cents.
     */
    public static function unitDecimals(string $unit): int {
        return match (strtolower(trim($unit))) {
            'sat', 'sats', 'msat' => 0,
            'btc' => 8,
            default => 2,
        };
    }

    /** The library's Unit for this mint unit, with this application's decimals. */
    public static function unitFor(string $unit): \Cashu\Unit {
        require_once __DIR__ . '/../cashu-wallet-php/CashuWallet.php';
        return \Cashu\Unit::fromCode($unit, self::unitDecimals($unit));
    }

    /**
     * A mint unit as stored: lowercase letters and digits only.
     *
     * The unit comes from the mint's /v1/keysets (a stranger) and is rendered in the
     * dashboard, so it must never carry markup.
     */
    public static function validUnit(string $unit): string {
        $unit = strtolower(trim($unit));
        if (!preg_match('/^[a-z0-9]{1,16}$/', $unit)) {
            throw new Exception('Unsupported mint unit.');
        }
        return $unit;
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
     * Is this mint/unit already on the store's backup list?
     *
     * Answers the one question that decides whether switching the primary mint can
     * strand funds: getStoreWalletAccounts() enumerates primary *and* backups, so a
     * mint that stays on the backup list is still recovered from and reconciled.
     * Disabled backups count — they hold recovery state too.
     */
    public static function isMintRetainedAsBackup(string $storeId, string $mintUrl, string $unit): bool {
        $mintUrl = rtrim($mintUrl, '/');
        $unit = strtolower($unit);
        if ($mintUrl === '') {
            return false;
        }
        foreach (self::getStoreBackupMints($storeId) as $backup) {
            if (rtrim((string)$backup['mint_url'], '/') === $mintUrl
                && strtolower((string)$backup['unit']) === $unit) {
                return true;
            }
        }
        return false;
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
        $store = self::getStore($storeId);
        $known = array_merge(
            [$store['mint_url'] ?? null],
            array_column(Database::fetchAll("SELECT mint_url FROM store_mints WHERE store_id = ?", [$storeId]), 'mint_url')
        );
        $mintUrl = self::normalizeMintUrl($mintUrl, ...$known);
        $unit = self::validUnit($unit);

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
        require_once __DIR__ . '/mint_helpers.php';
        if (Security::sanitizeUrl($mintUrl) === null) {
            return ['success' => false, 'error' => 'Mint URL must be a valid http(s) URL', 'info' => null];
        }
        // The private-address policy belongs here too, so no caller can skip it (OPUS C7).
        try {
            $mintUrl = MintHelpers::assertSafeMintUrl($mintUrl);
        } catch (\InvalidArgumentException $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'info' => null];
        }
        try {
            $client = new \Cashu\MintClient(rtrim($mintUrl, '/'));
            $info = $client->get('info');
            return ['success' => true, 'error' => null, 'info' => $info];
        } catch (\Exception $e) {
            // The raw cURL text tells an open port from a closed one; keep it in the log.
            error_log('CashuPayServer: mint connection test failed: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Could not reach the mint. Check the address, or try again in a few minutes.',
                'info' => null,
            ];
        }
    }
}

// Every outbound wallet request (mint API calls, LNURL lookups) goes through the
// application's address policy: checked at connect time and pinned to the checked
// addresses (OPUS N1, N2). Registered here because every entry point that touches the
// wallet loads this file. The policy itself lives in MintHelpers::connectGuard().
if (class_exists('Cashu\\ConnectGuard')) {
    \Cashu\ConnectGuard::set(static function (string $url, string $purpose): array {
        require_once __DIR__ . '/security.php';
        require_once __DIR__ . '/mint_helpers.php';
        return MintHelpers::connectGuard($url, $purpose);
    });
}
