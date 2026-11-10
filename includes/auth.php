<?php
/**
 * CashuPayServer Authentication Module
 *
 * Admin session management and API key validation.
 */

require_once __DIR__ . '/http_status.php';

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/urls.php';

class Auth {
    private const SESSION_NAME = 'cashupay_session';
    private const CSRF_TOKEN_NAME = 'csrf_token';
    public const ROLE_ADMIN = 'admin';
    public const ROLE_USER  = 'user';

    /**
     * Initialize session. Applies hardened cookie params (HttpOnly + SameSite
     * Lax + Secure on HTTPS) before session_start() — they only take effect
     * when set before the cookie is emitted. Idempotent: subsequent calls in
     * the same request are no-ops.
     */
    public static function initSession(): void {
        if (session_status() === PHP_SESSION_NONE) {
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (($_SERVER['SERVER_PORT'] ?? null) == 443)
                || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null) === 'https');
            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
                'secure'   => $isHttps,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_name(self::SESSION_NAME);
            session_start();
        }
    }

    /**
     * Check if a user is logged in (any role).
     */
    public static function isLoggedIn(): bool {
        self::initSession();
        return !empty($_SESSION['user_id']);
    }

    /**
     * Check if the current session is an admin.
     */
    public static function isAdmin(): bool {
        self::initSession();
        return ($_SESSION['user_role'] ?? null) === self::ROLE_ADMIN;
    }

    /**
     * 403 + exit if the current session is not an admin. Use to gate
     * fund-moving actions and store/user/system configuration.
     */
    public static function requireAdmin(): void {
        if (!self::isAdmin()) {
            cashupay_status(403);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Admin role required']);
            exit;
        }
    }

    /**
     * Return the current logged-in user's row (id/username/role), or null
     * if no session. Does NOT return password_hash.
     */
    public static function currentUser(): ?array {
        self::initSession();
        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            return null;
        }
        return self::getUserById($userId);
    }

    /**
     * Re-verify the acting admin's own password, for actions sensitive enough
     * that a hijacked session alone must not be sufficient (currently: reading
     * a store's wallet recovery phrase). Callers must still gate on
     * requireAdmin() first; this only adds the second factor.
     */
    public static function verifyCurrentUserPassword(string $password): bool {
        if ($password === '') {
            return false;
        }
        $self = self::currentUser();
        if (!$self) {
            return false;
        }
        $row = Database::fetchOne(
            "SELECT password_hash FROM users WHERE id = ?",
            [$self['id']]
        );
        return $row !== null && password_verify($password, $row['password_hash']);
    }

    /**
     * A stable identifier for whoever is acting, for audit logging.
     */
    public static function currentActorLabel(): string {
        $self = self::currentUser();
        return $self['id'] ?? 'unknown';
    }

    /**
     * Password sign-in with the lockout reserved BEFORE the password check.
     *
     * Returns 'locked' (no password check happened — the per-address counter
     * or the global sign-in budget is exhausted), 'ok' (session established,
     * counters cleared) or 'invalid'. Callers that used to chain
     * isLockedOut()/login()/recordFailedLogin() should use this instead: that
     * sequence let N parallel guesses all see count < 5 and all get a bcrypt
     * check. Report the wait with Security::getLockoutRemaining($ip, true).
     */
    public static function attemptLogin(string $username, string $password, ?string $ip = null): string {
        $ip = $ip ?? Security::getClientIp();
        if (!Security::reserveLoginAttempt($ip, true)) {
            return 'locked';
        }
        if ($username !== '' && self::login($username, $password)) {
            Security::clearLoginAttempts($ip);
            return 'ok';
        }
        return 'invalid';
    }

    /**
     * Attempt login. Returns true on success.
     */
    public static function login(string $username, string $password): bool {
        $clientIp = Security::getClientIp();
        $user = self::getUserByUsername($username);

        if ($user && password_verify($password, $user['password_hash'])) {
            error_log("CashuPayServer: Login successful for '{$username}' from {$clientIp}");

            self::initSession();
            $_SESSION['user_id']    = $user['id'];
            $_SESSION['user_role']  = $user['role'];
            $_SESSION['login_time'] = time();
            session_regenerate_id(true);
            return true;
        }

        error_log("CashuPayServer: Failed login attempt for '{$username}' from {$clientIp}");
        return false;
    }

    /**
     * Destroy the current session and rotate the session id so any leftover
     * cookie reference held by a tab is unusable.
     */
    public static function logout(): void {
        self::initSession();
        $_SESSION = [];
        // Rotate first (mints a new id that we immediately destroy), then
        // explicitly clear the client cookie so the browser doesn't keep
        // sending the dead SID.
        session_regenerate_id(true);
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                [
                    'expires'  => time() - 42000,
                    'path'     => $params['path'],
                    'domain'   => $params['domain'],
                    'secure'   => $params['secure'],
                    'httponly' => $params['httponly'],
                    'samesite' => $params['samesite'],
                ]
            );
        }
        session_destroy();
    }

    // =========================================================================
    // User management
    // =========================================================================

    /**
     * Set the admin password during the first-run setup wizard.
     * Creates the seed 'admin' user if no admin exists, otherwise updates
     * the existing admin's password. The setup-reentry guard (separate
     * commit) is enforced at the setup.php caller, not here.
     *
     * $email is the optional recovery address collected in the same setup
     * step; it powers the email-link reset mechanism. Pass null to leave it
     * unset (the file-based reset still works without an address).
     */
    public static function setAdminPassword(string $password, ?string $email = null): void {
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $existing = self::getUserByUsername('admin');
        if ($existing) {
            $fields = ['password_hash' => $hash];
            if ($email !== null) {
                $fields['email'] = $email !== '' ? $email : null;
            }
            Database::update('users', $fields, 'id = ?', [$existing['id']]);
        } else {
            Database::insert('users', [
                'id'            => Database::generateId('user'),
                'username'      => 'admin',
                'password_hash' => $hash,
                'email'         => ($email !== null && $email !== '') ? $email : null,
                'role'          => self::ROLE_ADMIN,
                'created_at'    => Database::timestamp(),
            ]);
        }

        // Drop the legacy slot once we own the credential in users.
        Database::delete('config', 'key = ?', ['admin_password_hash']);
    }

    /**
     * Set (or clear, with '') the admin account's e-mail address — the
     * destination of the e-mailed password-reset link. No-op when the admin
     * account does not exist yet.
     */
    public static function setAdminEmail(string $email): void {
        $existing = self::getUserByUsername('admin');
        if (!$existing) {
            return;
        }
        Database::update('users', ['email' => $email !== '' ? $email : null], 'id = ?', [$existing['id']]);
    }

    public static function getUserById(string $userId): ?array {
        $row = Database::fetchOne(
            "SELECT id, username, role, email, created_at FROM users WHERE id = ?",
            [$userId]
        );
        return $row ?: null;
    }

    public static function getUserByUsername(string $username): ?array {
        return Database::fetchOne(
            "SELECT * FROM users WHERE username = ? COLLATE NOCASE",
            [$username]
        );
    }

    /**
     * List all users (no hashes). Ordered admin-first then alphabetical.
     */
    public static function listUsers(): array {
        return Database::fetchAll(
            "SELECT id, username, role, created_at
             FROM users
             ORDER BY (role = 'admin') DESC, LOWER(username) ASC"
        );
    }

    /**
     * Validate a username. Returns null if OK, or an error message string.
     */
    public static function validateUsername(string $username): ?string {
        if (!preg_match('/^[a-zA-Z0-9_-]{3,32}$/', $username)) {
            return 'Username must be 3-32 chars, letters/digits/underscore/dash only';
        }
        return null;
    }

    /**
     * Validate a password. Returns null if OK, or an error message string.
     */
    public static function validatePassword(string $password): ?string {
        if (strlen($password) < 8) {
            return 'Password must be at least 8 characters';
        }
        return null;
    }

    /**
     * Create a new user. Returns the new user id.
     * Throws on duplicate username, invalid input, or invalid role.
     */
    public static function createUser(string $username, string $password, string $role): string {
        if ($role !== self::ROLE_ADMIN && $role !== self::ROLE_USER) {
            throw new \InvalidArgumentException('Invalid role');
        }
        if ($err = self::validateUsername($username))  throw new \InvalidArgumentException($err);
        if ($err = self::validatePassword($password))  throw new \InvalidArgumentException($err);

        if (self::getUserByUsername($username) !== null) {
            throw new \RuntimeException('A user with that name already exists');
        }

        $userId = Database::generateId('user');
        Database::insert('users', [
            'id'            => $userId,
            'username'      => $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role'          => $role,
            'created_at'    => Database::timestamp(),
        ]);
        return $userId;
    }

    /**
     * Delete a user. Refuses to delete the only remaining admin so the
     * install can't be locked out.
     */
    public static function deleteUser(string $userId): void {
        $user = self::getUserById($userId);
        if (!$user) {
            throw new \RuntimeException('User not found');
        }
        if ($user['role'] === self::ROLE_ADMIN) {
            $adminCount = (int)Database::fetchOne(
                "SELECT COUNT(*) AS c FROM users WHERE role = 'admin'"
            )['c'];
            if ($adminCount <= 1) {
                throw new \RuntimeException('Cannot delete the only remaining admin');
            }
        }
        Database::delete('users', 'id = ?', [$userId]);
    }

    /**
     * Set a user's password (admin path: reset another user). The caller
     * is responsible for authorization.
     */
    public static function changePassword(string $userId, string $newPassword): void {
        if ($err = self::validatePassword($newPassword)) {
            throw new \InvalidArgumentException($err);
        }
        if (!self::getUserById($userId)) {
            throw new \RuntimeException('User not found');
        }
        Database::update(
            'users',
            ['password_hash' => password_hash($newPassword, PASSWORD_DEFAULT)],
            'id = ?',
            [$userId]
        );
    }

    // =========================================================================
    // Password recovery
    //
    // Two operator escape hatches for a lost admin password:
    //   1. Email reset link  — createPasswordResetToken + resetPasswordWithToken
    //   2. File-based reset   — fileResetRequested + completeFileReset
    // Both are surfaced from the lock screen "Forgot password?" modal and
    // documented in the README.
    // =========================================================================

    /** Lifetime of an emailed reset link, in seconds (1 hour). */
    public const RESET_TOKEN_TTL = 3600;

    /**
     * Validate an email address. Returns null if OK, or an error message.
     */
    public static function validateEmail(string $email): ?string {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'Enter a valid email address';
        }
        return null;
    }

    /**
     * Set (or clear, with null/'') a user's recovery email. Validates a
     * non-empty address. Caller is responsible for authorization.
     */
    public static function setUserEmail(string $userId, ?string $email): void {
        if (!self::getUserById($userId)) {
            throw new \RuntimeException('User not found');
        }
        $value = null;
        if ($email !== null && trim($email) !== '') {
            $email = trim($email);
            if ($err = self::validateEmail($email)) {
                throw new \InvalidArgumentException($err);
            }
            $value = $email;
        }
        Database::update('users', ['email' => $value], 'id = ?', [$userId]);
    }

    /**
     * The seed 'admin' account's recovery email, or null if unset.
     */
    public static function getAdminEmail(): ?string {
        $row = Database::fetchOne(
            "SELECT email FROM users WHERE username = 'admin' COLLATE NOCASE"
        );
        $email = $row['email'] ?? null;
        return ($email !== null && $email !== '') ? $email : null;
    }

    /**
     * Find the admin user whose recovery email matches (case-insensitive).
     * Returns the full row or null. Used by the reset-link request flow.
     */
    public static function findAdminByEmail(string $email): ?array {
        $email = trim($email);
        if ($email === '') {
            return null;
        }
        return Database::fetchOne(
            "SELECT * FROM users
              WHERE role = 'admin' AND email IS NOT NULL AND email != ''
                AND email = ? COLLATE NOCASE
              LIMIT 1",
            [$email]
        ) ?: null;
    }

    /**
     * Mint a single-use, time-boxed password-reset token for a user. The raw
     * token is returned to the caller (to embed in the emailed link); only its
     * SHA-256 hash is persisted, so a leaked DB can't be used to reset. Any of
     * the user's prior unused tokens are invalidated so only the newest link
     * works.
     */
    public static function createPasswordResetToken(string $userId): string {
        $raw  = bin2hex(random_bytes(32));
        $hash = hash('sha256', $raw);
        $now  = Database::timestamp();

        // Burn older outstanding tokens for this user (newest link wins).
        Database::update(
            'password_reset_tokens',
            ['used_at' => $now],
            'user_id = ? AND used_at IS NULL',
            [$userId]
        );

        Database::insert('password_reset_tokens', [
            'user_id'    => $userId,
            'token_hash' => $hash,
            'created_at' => $now,
            'expires_at' => $now + self::RESET_TOKEN_TTL,
        ]);

        return $raw;
    }

    /**
     * Look up a reset token without consuming it. Returns the user row the
     * token belongs to if the token is valid (exists, unused, unexpired),
     * else null. Used to decide whether to render the set-password form.
     */
    public static function peekPasswordResetToken(string $rawToken): ?array {
        if ($rawToken === '') {
            return null;
        }
        $row = Database::fetchOne(
            "SELECT * FROM password_reset_tokens WHERE token_hash = ?",
            [hash('sha256', $rawToken)]
        );
        if (!$row || $row['used_at'] !== null) {
            return null;
        }
        if ((int)$row['expires_at'] < Database::timestamp()) {
            return null;
        }
        return self::getUserById($row['user_id']);
    }

    /**
     * Consume a reset token and set the new password atomically-ish: the token
     * is marked used first (so a concurrent retry can't reuse it), then the
     * password is updated. Returns true on success, false if the token is
     * invalid/expired/used. Throws InvalidArgumentException on a weak password
     * (caller should surface the message and the token stays usable — we only
     * mark it used once the password validates).
     */
    public static function resetPasswordWithToken(string $rawToken, string $newPassword): bool {
        if ($err = self::validatePassword($newPassword)) {
            throw new \InvalidArgumentException($err);
        }
        if ($rawToken === '') {
            return false;
        }
        $row = Database::fetchOne(
            "SELECT * FROM password_reset_tokens WHERE token_hash = ?",
            [hash('sha256', $rawToken)]
        );
        if (!$row || $row['used_at'] !== null) {
            return false;
        }
        if ((int)$row['expires_at'] < Database::timestamp()) {
            return false;
        }

        // Mark used before changing the password so a racing second submit of
        // the same link can't also go through.
        $affected = Database::update(
            'password_reset_tokens',
            ['used_at' => Database::timestamp()],
            'id = ? AND used_at IS NULL',
            [$row['id']]
        );
        if ($affected < 1) {
            return false; // lost the race
        }

        if (!self::getUserById($row['user_id'])) {
            return false;
        }
        Database::update(
            'users',
            ['password_hash' => password_hash($newPassword, PASSWORD_DEFAULT)],
            'id = ?',
            [$row['user_id']]
        );
        return true;
    }

    /**
     * Absolute path to the file-based reset trigger. Lives in the data
     * directory (gitignored, not web-served), so writing it already proves
     * filesystem access — the authorization for this escape hatch.
     */
    public static function fileResetPath(): string {
        return Database::getDataDir() . '/reset-admin-password';
    }

    /**
     * Whether the operator has dropped the file-based reset trigger.
     */
    public static function fileResetRequested(): bool {
        return is_file(self::fileResetPath());
    }

    /**
     * Complete a file-based reset: set a new password on the seed 'admin'
     * account and delete the trigger file. The old password stays valid until
     * this succeeds, and the trigger file must still be present (operator
     * controls the window). Returns true on success; false if the trigger is
     * gone or there is no 'admin' account. Throws on a weak password.
     */
    public static function completeFileReset(string $newPassword): bool {
        if (!self::fileResetRequested()) {
            return false;
        }
        if ($err = self::validatePassword($newPassword)) {
            throw new \InvalidArgumentException($err);
        }
        $admin = self::getUserByUsername('admin');
        if (!$admin) {
            return false;
        }
        Database::update(
            'users',
            ['password_hash' => password_hash($newPassword, PASSWORD_DEFAULT)],
            'id = ?',
            [$admin['id']]
        );
        @unlink(self::fileResetPath());
        return true;
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
     * The raw Authorization header, wherever the SAPI put it.
     *
     * Apache never exports Authorization into the CGI-style environment for
     * non-Basic schemes: under mod_php $_SERVER['HTTP_AUTHORIZATION'] is
     * simply unset (verified against stock php:8.3-apache), and FastCGI
     * strips it per RFC 3875 unless CGIPassAuth is on. Without this fallback
     * every `Authorization: token …` API call 401s on Apache. Same technique
     * as the WordPress api-bridge (wordpress/api-bridge.php).
     */
    public static function authorizationHeader(): string {
        $header = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';
        if ($header === '' && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            if (is_array($headers)) {
                foreach ($headers as $name => $value) {
                    if (strcasecmp((string) $name, 'Authorization') === 0 && is_string($value)) {
                        $header = $value;
                        break;
                    }
                }
            }
        }
        return is_string($header) ? $header : '';
    }

    /**
     * Validate API request and return store ID
     */
    public static function validateApiRequest(): ?array {
        $authHeader = self::authorizationHeader();

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
            cashupay_status(401);
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
