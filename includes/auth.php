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
            session_name(self::SESSION_NAME);
            session_start();
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
        return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
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
            session_regenerate_id(true);
            return true;
        }

        // M4: Log failed login attempt
        error_log("CashuPayServer: Failed admin login attempt from {$clientIp}");
        return false;
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
     * Check if API key has specific permission
     */
    public static function hasPermission(array $authData, string $permission): bool {
        // Check for wildcard permission
        if (in_array('*', $authData['permissions'])) {
            return true;
        }

        // Check for specific permission
        return in_array($permission, $authData['permissions']);
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

    /**
     * Delete API key
     */
    public static function deleteApiKey(string $keyId): bool {
        // Check if this is an internal dashboard key
        $key = Database::fetchOne(
            "SELECT label FROM api_keys WHERE id = ?",
            [$keyId]
        );

        if ($key && $key['label'] === 'Internal (Dashboard)') {
            throw new \Exception('Cannot delete the internal dashboard API key');
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
            'label' => 'Internal (Dashboard)',
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
