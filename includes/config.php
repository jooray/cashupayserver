<?php
/**
 * CashuPayServer Configuration Module
 *
 * Load/save configuration from database.
 */

require_once __DIR__ . '/database.php';

// Version
define('CASHUPAY_VERSION', '1.5');

// Development fee — the mandatory BareBits fee assessed on incoming payments,
// settled on the periodic fee settlement cron tick. Defined here (rather than
// only in includes/dev_fee.php) so lightweight callers such as the setup
// wizard's terms screen can display the rate without pulling in the full
// settlement stack. dev_fee.php keeps a guarded fallback define for the same
// value. MANDATORY: intentionally hard-coded and not env-overridable; changing
// it is governed by LICENSE.md (see includes/dev_fee.php for details).
define('CASHUPAY_DEV_FEE_PERCENT', 1);

class Config {
    private static array $cache = [];

    /**
     * Get configuration value
     */
    public static function get(string $key, mixed $default = null): mixed {
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        try {
            $row = Database::fetchOne(
                "SELECT value FROM config WHERE key = ?",
                [$key]
            );
        } catch (PDOException $e) {
            // Before setup.php runs Database::initialize(), the `config` table
            // does not exist yet. Any entrypoint that reads config while the
            // schema is absent (e.g. a pre-setup redirect that builds a URL)
            // would otherwise hit an uncaught "no such table: config" and 500.
            // Treat a not-yet-initialized database as "unconfigured" and fall
            // back to the default. Scope the rescue to that case only — if the
            // schema exists, a query failure is a real error and must surface.
            if (!Database::isInitialized()) {
                return $default;
            }
            throw $e;
        }

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

        // Single atomic upsert. The old SELECT-then-INSERT/UPDATE could throw a
        // PRIMARY KEY violation when two requests set a brand-new key at the
        // same instant (both miss the SELECT, both INSERT). ON CONFLICT keeps
        // created_at and overwrites value/updated_at — identical to the former
        // update branch.
        Database::query(
            "INSERT INTO config (key, value, created_at, updated_at)
             VALUES (?, ?, ?, ?)
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
        try {
            $rows = Database::fetchAll("SELECT key, value FROM config");
        } catch (PDOException $e) {
            // Same not-yet-initialized guard as get(): a missing `config` table
            // means the install has no configuration yet, so return an empty
            // set rather than 500. Real errors on an initialized DB still throw.
            if (!Database::isInitialized()) {
                return [];
            }
            throw $e;
        }
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
        return self::get('invoice_expiration', 3600); // 1 hour default
    }

    /**
     * Get URL mode for standalone deployments.
     *
     * - 'clean'  : extension-less pretty URLs (/admin, /setup, /pay/x) served
     *              through the router.php front controller via a mod_rewrite
     *              catch-all. Preferred when the host supports it.
     * - 'direct' : hit the .php files directly (/admin.php); the shipped
     *              .htaccess still rewrites /api/v1/* to api.php.
     * - 'router' : front-controller prefix (/router.php/...); works with no
     *              URL rewriting at all — the safe universal fallback.
     *
     * @return string 'clean', 'direct', or 'router'
     */
    public static function getUrlMode(): string {
        return self::get('url_mode', 'router'); // Default router for max compatibility
    }

    /**
     * Get base URL for the application
     */
    public static function getBaseUrl(): string {
        // Deployment-time pin (user_config.php), written by installers that
        // know the served URL up front — e.g. the WordPress companion
        // plugin's "install BareBits alongside" flow. A true pin, like
        // CASHUPAY_DATA_DIR: it beats the database value AND auto-detection,
        // so nothing an admin-UI action ever writes can silently defeat the
        // deployment's declared URL — whoever controls user_config.php
        // outranks the UI. It never trusts the Host header and stays correct
        // behind routing setups where SCRIPT_NAME doesn't reflect the public
        // path.
        if (defined('CASHUPAY_BASE_URL') && CASHUPAY_BASE_URL !== '') {
            return rtrim((string)CASHUPAY_BASE_URL, '/');
        }

        $baseUrl = self::get('base_url');
        if ($baseUrl) {
            return rtrim($baseUrl, '/');
        }

        // Auto-detect
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        // dirname() uses '\' separators on Windows ('/router.php' -> '\').
        // A backslash leaking into a Location header breaks strict HTTP
        // clients (.NET/PowerShell refuse to follow the redirect), so
        // normalize before building the URL. Mirrored in upd_base_url().
        $path = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));

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
     * Secret columns of the stores table that must never be serialized into a
     * response payload. seed_phrase is the spendable wallet key (whoever holds
     * it can move every sat of the store's ecash); smtp_password and the raw
     * internal_api_key are credentials; the xpubs let an observer derive every
     * receive address. These are read into memory by the many `SELECT *`
     * dashboard/store reads, so redaction happens at the response boundary
     * (see Config::redactStoreSecrets). Reading the seed for actual signing
     * goes through Config::getStoreSeedPhrase, not these payloads.
     */
    public const STORE_SECRET_COLUMNS = [
        'seed_phrase',
        'internal_api_key',
        'smtp_password',
        'onchain_xpub',
        'hosting_fee_onchain_xpub',
    ];

    /**
     * Strip the store-table secret columns from a single store row before it
     * is returned to a browser. The admin panel is reachable by the lower
     * privilege ROLE_USER (not just admins), so these JSON reads must not leak
     * wallet keys or credentials to a logged-in-but-not-admin user. Any
     * derived non-secret fields already added to the row (e.g. the camelCase
     * `internalApiKey` the dashboard needs for the Request Payment feature)
     * are preserved — only the raw secret columns above are removed.
     *
     * @param array<string,mixed> $store
     * @return array<string,mixed>
     */
    public static function redactStoreSecrets(array $store): array {
        foreach (self::STORE_SECRET_COLUMNS as $col) {
            unset($store[$col]);
        }
        return $store;
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
     * Get store's default display/input currency (sat or fiat code, e.g. USD).
     * Falls back to the mint unit when the column is empty so behavior matches
     * pre-migration installs.
     */
    public static function getStoreDefaultCurrency(string $storeId): string {
        $store = self::getStore($storeId);
        $value = $store['default_currency'] ?? null;
        if (is_string($value) && $value !== '') return strtoupper($value) === 'SATS' ? 'sat' : $value;
        return $store['mint_unit'] ?? 'sat';
    }

    /**
     * Resolve whether the "Subscribe to our newsletter" checkbox on the
     * payment-complete screen should start checked for a given store. The
     * per-store override (stores.newsletter_default_checked, 0/1) wins when set;
     * otherwise fall back to the site-wide default (config key
     * newsletter_default_checked, defaulting to true / checked).
     */
    public static function getNewsletterDefaultChecked(string $storeId): bool {
        $store = self::getStore($storeId);
        $override = $store['newsletter_default_checked'] ?? null;
        if ($override !== null && $override !== '') {
            return (int)$override === 1;
        }
        return self::get('newsletter_default_checked', true) === true;
    }

    /**
     * Whether the payment-complete screen offers the payer email/newsletter
     * capture form (and its send_receipt endpoint accepts POSTs). The
     * explicit setting (config key payer_email_capture_enabled, saved from
     * the admin notifications card) wins; unset falls back to a
     * deployment-shaped default — ON for standalone installs, OFF for
     * managed single-shop installs, where the shop platform (WooCommerce)
     * owns customer emails and a second capture form would be noise.
     */
    public static function isPayerEmailCaptureEnabled(): bool {
        $explicit = self::get('payer_email_capture_enabled');
        if ($explicit !== null && $explicit !== '') {
            return $explicit === true || $explicit === 1 || $explicit === '1';
        }
        require_once __DIR__ . '/managed.php';
        return !ManagedInstall::isManaged();
    }

    /**
     * Currencies that may be offered as a default display/input currency in
     * addition to the mint's native unit.
     */
    public static function getSupportedDisplayCurrencies(): array {
        return ['sat', 'USD', 'EUR', 'GBP', 'CAD', 'AUD', 'JPY', 'CHF'];
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
     * Whether the store has at least one payment rail an invoice could offer:
     * a Cashu mint (isStoreConfigured above), an on-chain destination (xpub or
     * static address, whichever the address mode names), or a Lightning
     * destination (Strike / LNURL address / NWC / noffer). Mirrors the rails
     * gate at the top of Invoice::create.
     *
     * This — not isStoreConfigured, which only reflects the mint rail — is
     * the check for "can this store take payments": the wizard's "run without
     * mints" answer leaves mint_url/seed_phrase NULL on a store that is
     * nevertheless fully operational over its other rails.
     */
    public static function storeHasPaymentRail(string $storeId): bool {
        if (self::isStoreConfigured($storeId)) {
            return true;
        }
        require_once __DIR__ . '/setup_flow.php';
        if (SetupFlow::onchainState($storeId)['configured']) {
            return true;
        }
        require_once __DIR__ . '/store_ln_addresses.php';
        return StoreLnAddresses::addressesForStore($storeId) !== [];
    }

    /**
     * Update store settings
     *
     * Changing mint_url through this method implicitly marks
     * primary_mint_source='manual' unless the caller passes the column
     * explicitly. The trusted-mints code path uses the explicit value to mark
     * its auto-populated primaries differently so they can later be replaced.
     */
    public static function updateStore(string $storeId, array $data): void {
        $allowed = [
            'name', 'mint_url', 'mint_unit', 'seed_phrase',
            'exchange_fee_percent', 'price_provider_primary', 'price_provider_secondary',
            'default_currency',
            'primary_mint_source',
            // Hosting fee (per-store) — see includes/dev_fee.php
            'hosting_fee_percent', 'hosting_fee_destination',
            // On-chain Bitcoin payment settings
            'onchain_xpub', 'onchain_network', 'onchain_address_type',
            'onchain_next_index', 'onchain_min_confs', 'onchain_confirm_timeout_sec',
            'onchain_provider', 'onchain_provider_url',
        ];
        $updateData = array_intersect_key($data, array_flip($allowed));

        if (array_key_exists('mint_url', $updateData) && !array_key_exists('primary_mint_source', $updateData)) {
            $updateData['primary_mint_source'] = 'manual';
        }

        if (!empty($updateData)) {
            Database::update('stores', $updateData, 'id = ?', [$storeId]);
        }
    }

    /**
     * Get the source of the store's currently configured primary mint URL.
     * One of: 'manual' (admin entered), 'trusted_list' (auto-populated from
     * the trusted-mints URL), or 'setup' (left empty during initial setup).
     */
    public static function getStorePrimaryMintSource(string $storeId): string {
        $store = self::getStore($storeId);
        return $store['primary_mint_source'] ?? 'manual';
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
     * Get all mint URLs (primary + backups) for a store, filtered through the
     * reliability gate. Mints with disabled_pending_success, permanently_disabled,
     * or trusted_list_disabled are excluded — including the primary, which
     * cleanly falls through to the highest-priority eligible backup.
     */
    public static function getStoreAllMintUrls(string $storeId): array {
        require_once __DIR__ . '/mint_reliability.php';

        $primary = self::getStoreMintUrl($storeId);
        $unit = self::getStoreMintUnit($storeId);
        $backups = self::getStoreEnabledMints($storeId, $unit);

        $candidates = [];
        if ($primary) {
            $candidates[] = $primary;
        }
        foreach ($backups as $backup) {
            if (!$primary || rtrim($backup, '/') !== rtrim($primary, '/')) {
                $candidates[] = $backup;
            }
        }

        $result = [];
        foreach ($candidates as $mintUrl) {
            if (MintReliability::isAvailableForNewInvoices($mintUrl)) {
                $result[] = $mintUrl;
            }
        }

        if (empty($result) && !empty($candidates)) {
            // Every configured mint is gated, so invoice creation will fail for
            // this store. Emit a loud, distinct alarm so the operator can act
            // (re-enable a mint / add a backup) — previously this returned an
            // empty list with no signal at all.
            //
            // We deliberately do NOT auto-override the gate here: a mint gated
            // by a withdraw failure (LIGHTNING_WALLET_ERROR) must not be
            // re-admitted for NEW invoices, or we'd accept customer deposits
            // into a mint we've already shown we cannot withdraw from.
            // MINT_UNREACHABLE gates already self-heal after a retry interval in
            // MintReliability::isAvailableForNewInvoices().
            error_log("[mint-reliability] ALARM: all " . count($candidates)
                . " mint(s) for store {$storeId} are gated; cannot issue new invoices");
        }

        return $result;
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
