<?php
/**
 * CashuPayServer Authentication Module
 *
 * Admin session management and API key validation.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/urls.php';

class Auth {
    private const SESSION_NAME = 'cashupay_session';
    private const CSRF_TOKEN_NAME = 'csrf_token';

    /**
     * Initialize session
     */
    public static function initSession(): void {
        if (session_status() === PHP_SESSION_NONE) {
            // Store sessions in an app-controlled, web-inaccessible directory (inside the
            // protected data dir). This makes admin login work regardless of the shared
            // system session path's permissions, and isolates sessions from other vhosts.
            $sessDir = Database::getDataDir() . '/sessions';
            if (!is_dir($sessDir)) {
                @mkdir($sessDir, 0770, true);
            }
            if (is_dir($sessDir) && is_writable($sessDir)) {
                session_save_path($sessDir);
            }

            // Harden the session cookie without needing php.ini access (shared hosting).
            $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
            session_set_cookie_params([
                'httponly' => true,     // not readable from JS (limits XSS -> session theft)
                'secure'   => $secure,  // HTTPS-only when served over TLS
                'samesite' => 'Lax',    // mitigates cross-site POST/CSRF
                'path'     => '/',
            ]);
            session_name(self::SESSION_NAME);
            session_start();

            // Absolute session timeout (24h) using the stored login_time.
            if (isset($_SESSION['admin_logged_in'], $_SESSION['login_time'])
                && (time() - (int)$_SESSION['login_time']) > 86400) {
                $_SESSION = [];
                session_destroy();
                session_start();
            }
        }
    }

    /**
     * Check if admin is logged in
     */
    public static function isLoggedIn(): bool {
        if (defined('CASHUPAY_WORDPRESS') && CASHUPAY_WORDPRESS) {
            return function_exists('current_user_can')
                && current_user_can('manage_options');
        }
        self::initSession();
        if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
            return false;
        }
        // A password change or "log out everywhere" retires every session issued
        // before it, including one sitting in a stolen cookie (SPB-SEC-008).
        if (!hash_equals(self::passwordVersion(), (string)($_SESSION['password_version'] ?? ''))) {
            $_SESSION = []; // may run mid-page, so no session_regenerate_id() here
            return false;
        }
        return true;
    }

    /**
     * Attempt admin login
     */
    public static function login(string $password): bool {
        $hash = Config::getAdminPasswordHash();
        if ($hash === null) {
            return false;
        }

        $clientIp = Security::getClientIp();

        if (password_verify($password, $hash)) {
            // M4: Log successful login
            error_log("CashuPayServer: Admin login successful from {$clientIp}");

            self::initSession();
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['login_time'] = time();
            $_SESSION['password_version'] = self::passwordVersion();
            session_regenerate_id(true);
            return true;
        }

        // M4: Log failed login attempt
        error_log("CashuPayServer: Failed admin login attempt from {$clientIp}");
        return false;
    }

    /**
     * Log in with the lockout applied atomically.
     *
     * The attempt is counted *before* the (slow) password check, so parallel guesses
     * cannot all slip in under the limit (DS41-26, SPB-SEC-006).
     *
     * @return string 'ok', 'locked' (too many attempts; see
     *                Security::getLockoutRemaining()) or 'invalid'
     */
    public static function attemptLogin(string $password, ?string $clientIp = null): string {
        $clientIp = $clientIp ?? Security::getClientIp();
        if (!Security::reserveLoginAttempt($clientIp)) {
            return 'locked';
        }
        if (self::login($password)) {
            Security::clearLoginAttempts($clientIp);
            return 'ok';
        }
        return 'invalid';
    }

    /**
     * Re-check the admin password without touching the session.
     *
     * Used to gate a re-display of the wallet seed, so an unattended dashboard is not
     * enough to walk away with the keys to the money.
     */
    public static function verifyAdminPassword(string $password): bool {
        $hash = Config::getAdminPasswordHash();
        if ($hash === null) {
            // WordPress mode has no standalone password; manage_options is the gate.
            return Urls::isWordPress() && function_exists('current_user_can')
                && current_user_can('manage_options');
        }
        return password_verify($password, $hash);
    }

    /**
     * Logout admin
     */
    public static function logout(): void {
        self::initSession();
        $_SESSION = [];
        session_destroy();
    }

    /**
     * Set admin password (during setup)
     */
    public static function setAdminPassword(string $password): void {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        Config::set('admin_password_hash', $hash);
    }

    /** Same rule as the setup wizard. */
    public const MIN_PASSWORD_LENGTH = 8;

    /**
     * Change the admin password (SPB-SEC-008).
     *
     * Every other session is logged out; the one making the change stays logged in.
     * Throws InvalidArgumentException with a message fit to show the operator.
     */
    public static function changeAdminPassword(string $current, string $new): void {
        $hash = Config::getAdminPasswordHash();
        if ($hash === null) {
            throw new \InvalidArgumentException(
                'This installation has no separate admin password (in WordPress, change your WordPress password instead).'
            );
        }
        if (!password_verify($current, $hash)) {
            throw new \InvalidArgumentException('The current password is not correct.');
        }
        if (strlen($new) < self::MIN_PASSWORD_LENGTH) {
            throw new \InvalidArgumentException(
                'The new password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters long.'
            );
        }

        self::setAdminPassword($new);
        $version = self::bumpPasswordVersion();

        if (session_status() === PHP_SESSION_ACTIVE && ($_SESSION['admin_logged_in'] ?? false) === true) {
            $_SESSION['password_version'] = $version;
            session_regenerate_id(true);
        }
        error_log('CashuPayServer: admin password changed from ' . Security::getClientIp());
    }

    /**
     * End every admin session, this one included — for a lost laptop or a cookie that
     * may have leaked. The password stays the same.
     */
    public static function logoutEverywhere(): void {
        self::bumpPasswordVersion();
        error_log('CashuPayServer: all admin sessions ended from ' . Security::getClientIp());
        self::logout();
    }

    /**
     * Current session generation. Empty until the first change, so sessions from before
     * this check existed stay valid.
     */
    private static function passwordVersion(): string {
        $version = Config::get('admin_password_version');
        return is_string($version) ? $version : '';
    }

    private static function bumpPasswordVersion(): string {
        // Random rather than a counter: two concurrent bumps can never agree on a value.
        // The prefix keeps Config's JSON decoding from reading it as a number.
        $version = 'pv_' . bin2hex(random_bytes(12));
        Config::set('admin_password_version', $version);
        return $version;
    }

    /**
     * Generate CSRF token
     */
    public static function generateCsrfToken(): string {
        self::initSession();
        if (!isset($_SESSION[self::CSRF_TOKEN_NAME])) {
            $_SESSION[self::CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::CSRF_TOKEN_NAME];
    }

    /**
     * Validate CSRF token
     */
    public static function validateCsrfToken(string $token): bool {
        self::initSession();
        return isset($_SESSION[self::CSRF_TOKEN_NAME]) &&
               hash_equals($_SESSION[self::CSRF_TOKEN_NAME], $token);
    }

    /**
     * Require admin login (redirect if not logged in)
     */
    public static function requireLogin(): void {
        if (!self::isLoggedIn()) {
            header('Location: ' . Urls::admin() . '?action=login');
            exit;
        }
    }

    // =========================================================================
    // API Authentication
    // =========================================================================

    /**
     * Validate API request and return store ID
     */
    public static function validateApiRequest(): ?array {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        // BTCPay format: "token API_KEY"
        if (preg_match('/^token\s+(.+)$/i', $authHeader, $matches)) {
            $apiKey = $matches[1];
            return self::validateApiKey($apiKey);
        }

        // Also support Bearer format
        if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            $apiKey = $matches[1];
            return self::validateApiKey($apiKey);
        }

        return null;
    }

    /**
     * Validate API key and return associated data
     */
    public static function validateApiKey(string $apiKey): ?array {
        $keyHash = hash('sha256', $apiKey);

        $row = Database::fetchOne(
            "SELECT ak.*, s.name as store_name
             FROM api_keys ak
             JOIN stores s ON s.id = ak.store_id
             WHERE ak.key_hash = ?",
            [$keyHash]
        );

        if ($row === null) {
            return null;
        }

        return [
            'key_id' => $row['id'],
            'store_id' => $row['store_id'],
            'store_name' => $row['store_name'],
            'permissions' => json_decode($row['permissions'], true) ?? [],
        ];
    }

    /**
     * What each BTCPay permission implies, as BTCPay applies it (OPUS-014).
     *
     * Store settings management is the store owner's permission and covers every
     * store-level one. Modifying invoices covers viewing, creating and changing their
     * status (BTCPay 2.4.5 split the last one out as canmanageinvoicestatus).
     */
    private const PERMISSION_IMPLIES = [
        'btcpay.store.canmodifyinvoices' => [
            'btcpay.store.canviewinvoices',
            'btcpay.store.cancreateinvoice',
            'btcpay.store.canmanageinvoicestatus',
        ],
        'btcpay.store.canviewstoresettings' => [
            'btcpay.store.canviewinvoices',
        ],
    ];

    /**
     * Check if API key has specific permission
     */
    public static function hasPermission(array $authData, string $permission): bool {
        $perms = $authData['permissions'] ?? [];
        if (!is_array($perms)) {
            return false;
        }

        // Wildcard (admin-created keys) grants everything.
        if (in_array('*', $perms, true)) {
            return true;
        }

        foreach ($perms as $held) {
            if (!is_string($held)) {
                continue;
            }
            // BTCPay Greenfield stores store-scoped permissions as "perm:storeId".
            // Treat the base permission as held regardless of the scope suffix (the
            // store scope itself is enforced separately by requireStore()).
            $held = explode(':', $held, 2)[0];
            if (self::permissionImplies($held, $permission)) {
                return true;
            }
        }

        return false;
    }

    /** Whether holding $held grants $wanted under BTCPay's hierarchy. */
    public static function permissionImplies(string $held, string $wanted): bool {
        if ($held === $wanted) {
            return true;
        }
        $isStorePermission = strpos($wanted, 'btcpay.store.') === 0;
        // BTCPay's server-settings permission implies store-settings management.
        if ($held === 'btcpay.server.canmodifyserversettings' && $isStorePermission) {
            return true;
        }
        if ($held === 'btcpay.store.canmodifystoresettings' && $isStorePermission) {
            return true;
        }
        foreach (self::PERMISSION_IMPLIES[$held] ?? [] as $child) {
            if (self::permissionImplies($child, $wanted)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Create a new API key
     */
    public static function createApiKey(
        string $storeId,
        string $label = '',
        array $permissions = ['*'],
        ?string $applicationIdentifier = null,
        ?string $redirectHost = null
    ): array {
        if (self::isReservedKeyLabel($label)) {
            throw new \InvalidArgumentException(
                'The name "' . self::INTERNAL_KEY_LABEL . '" is reserved for the dashboard\'s own key. Please choose another name.'
            );
        }

        $rawKey = bin2hex(random_bytes(32));
        $keyHash = hash('sha256', $rawKey);
        $keyId = Database::generateId('key');

        Database::insert('api_keys', [
            'id' => $keyId,
            'key_hash' => $keyHash,
            'store_id' => $storeId,
            'label' => $label,
            'permissions' => json_encode($permissions),
            'application_identifier' => $applicationIdentifier,
            'redirect_host' => $redirectHost,
            'created_at' => Database::timestamp(),
        ]);

        return [
            'id' => $keyId,
            'key' => $rawKey, // Only returned once!
            'store_id' => $storeId,
            'label' => $label,
            'permissions' => $permissions,
        ];
    }

    /**
     * Find existing API key by application identifier (for pairing flow reuse)
     */
    public static function findApiKeyByAppIdentifier(
        string $storeId,
        string $applicationIdentifier,
        string $redirectHost,
        array $requiredPermissions
    ): ?array {
        $key = Database::fetchOne(
            "SELECT * FROM api_keys
             WHERE store_id = ? AND application_identifier = ? AND redirect_host = ?",
            [$storeId, $applicationIdentifier, $redirectHost]
        );

        if (!$key) {
            return null;
        }

        // Check if existing key has all required permissions
        $existingPerms = json_decode($key['permissions'], true) ?? [];
        if (in_array('*', $existingPerms)) {
            // Wildcard permission covers everything
            return $key;
        }

        foreach ($requiredPermissions as $perm) {
            if (!in_array($perm, $existingPerms)) {
                return null; // Missing required permission
            }
        }

        return $key;
    }

    /**
     * Get API keys for a store
     */
    public static function getApiKeys(string $storeId): array {
        return Database::fetchAll(
            "SELECT id, store_id, label, permissions, created_at FROM api_keys WHERE store_id = ?",
            [$storeId]
        );
    }

    /** Label of the key the dashboard itself uses (see getOrCreateInternalApiKey()). */
    public const INTERNAL_KEY_LABEL = 'Internal (Dashboard)';

    /**
     * Whether a caller-chosen key name collides with the dashboard key's label, ignoring
     * case and spacing. A look-alike label used to make a key undeletable (SPB-SEC-009).
     */
    public static function isReservedKeyLabel(string $label): bool {
        $normalize = static fn(string $s) => strtolower((string)preg_replace('/\s+/u', '', $s));
        return $normalize($label) === $normalize(self::INTERNAL_KEY_LABEL);
    }

    /**
     * Whether a key is the dashboard's own, judged by the key itself (its hash matches
     * the store's internal_api_key), never by its label text.
     */
    public static function isInternalApiKey(string $keyId): bool {
        $row = Database::fetchOne(
            "SELECT ak.key_hash, s.internal_api_key
             FROM api_keys ak JOIN stores s ON s.id = ak.store_id
             WHERE ak.id = ?",
            [$keyId]
        );
        if ($row === null || empty($row['internal_api_key'])) {
            return false;
        }
        return hash_equals((string)$row['key_hash'], hash('sha256', (string)$row['internal_api_key']));
    }

    /**
     * Delete API key
     *
     * @throws \RuntimeException for the dashboard's own key
     */
    public static function deleteApiKey(string $keyId): bool {
        if (self::isInternalApiKey($keyId)) {
            throw new \RuntimeException('This key is used by the dashboard itself and cannot be deleted.');
        }

        return Database::delete('api_keys', 'id = ?', [$keyId]) > 0;
    }

    /**
     * Get or create internal API key for a store
     *
     * Internal API keys are used for admin dashboard features that need
     * to use the Greenfield API (like the Request button). They are stored
     * in the stores table and have a corresponding hash in api_keys.
     */
    public static function getOrCreateInternalApiKey(string $storeId): ?string {
        // Check if store exists and has internal key
        $store = Database::fetchOne(
            "SELECT internal_api_key FROM stores WHERE id = ?",
            [$storeId]
        );

        if ($store === null) {
            return null; // Store doesn't exist
        }

        // If internal key exists and is valid, return it
        if (!empty($store['internal_api_key'])) {
            // Verify the corresponding api_keys entry exists
            $keyHash = hash('sha256', $store['internal_api_key']);
            $exists = Database::fetchOne(
                "SELECT id FROM api_keys WHERE key_hash = ?",
                [$keyHash]
            );
            if ($exists) {
                return $store['internal_api_key'];
            }
            // Key is orphaned, will recreate below
        }

        // Generate new internal API key
        $rawKey = bin2hex(random_bytes(32));
        $keyHash = hash('sha256', $rawKey);
        $keyId = Database::generateId('key');

        // Store the key hash in api_keys table
        Database::insert('api_keys', [
            'id' => $keyId,
            'key_hash' => $keyHash,
            'store_id' => $storeId,
            'label' => self::INTERNAL_KEY_LABEL,
            'permissions' => json_encode(['btcpay.store.cancreateinvoice']),
            'application_identifier' => null,
            'redirect_host' => null,
            'created_at' => Database::timestamp(),
        ]);

        // Store the raw key in stores table
        Database::update(
            'stores',
            ['internal_api_key' => $rawKey],
            'id = ?',
            [$storeId]
        );

        return $rawKey;
    }

    /**
     * Require API authentication (send error response if not authenticated)
     */
    public static function requireApiAuth(): array {
        $auth = self::validateApiRequest();
        if ($auth === null) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode([
                'code' => 'unauthenticated',
                'message' => 'Authentication required. Use Authorization: token YOUR_API_KEY'
            ]);
            exit;
        }
        return $auth;
    }
}
