<?php
/**
 * CashuPayServer - Security Module
 *
 * Rate limiting, CSRF protection, and security utilities.
 */

require_once __DIR__ . '/database.php';

class Security {
    private const RATE_LIMIT_WINDOW = 60; // seconds
    private const MAX_LOGIN_ATTEMPTS = 5;
    private const LOCKOUT_DURATION = 300; // 5 minutes

    /**
     * Check rate limit for an action
     */
    public static function checkRateLimit(string $action, string $identifier, int $maxAttempts = 60): bool {
        $key = "rate_{$action}_{$identifier}";
        $data = self::getCache($key);

        if ($data === null) {
            self::setCache($key, ['count' => 1, 'window_start' => time()], self::RATE_LIMIT_WINDOW);
            return true;
        }

        // Check if window has expired
        if (time() - $data['window_start'] > self::RATE_LIMIT_WINDOW) {
            self::setCache($key, ['count' => 1, 'window_start' => time()], self::RATE_LIMIT_WINDOW);
            return true;
        }

        // Increment count
        $data['count']++;
        self::setCache($key, $data, self::RATE_LIMIT_WINDOW);

        // M4: Log rate limit exceeded
        if ($data['count'] > $maxAttempts) {
            error_log("CashuPayServer: Rate limit exceeded for {$action} from {$identifier}");
        }

        return $data['count'] <= $maxAttempts;
    }

    /**
     * Record failed login attempt
     */
    public static function recordFailedLogin(string $identifier): void {
        $key = "login_attempts_{$identifier}";
        $data = self::getCache($key);

        if ($data === null) {
            $data = ['count' => 0, 'first_attempt' => time()];
        }

        $data['count']++;
        $data['last_attempt'] = time();

        self::setCache($key, $data, self::LOCKOUT_DURATION);
    }

    /**
     * Check if identifier is locked out
     */
    public static function isLockedOut(string $identifier): bool {
        $key = "login_attempts_{$identifier}";
        $data = self::getCache($key);

        if ($data === null) {
            return false;
        }

        if ($data['count'] >= self::MAX_LOGIN_ATTEMPTS) {
            $lockoutEnd = ($data['last_attempt'] ?? time()) + self::LOCKOUT_DURATION;
            return time() < $lockoutEnd;
        }

        return false;
    }

    /**
     * Clear login attempts on successful login
     */
    public static function clearLoginAttempts(string $identifier): void {
        $key = "login_attempts_{$identifier}";
        self::deleteCache($key);
    }

    /**
     * Get remaining lockout time
     */
    public static function getLockoutRemaining(string $identifier): int {
        $key = "login_attempts_{$identifier}";
        $data = self::getCache($key);

        if ($data === null || $data['count'] < self::MAX_LOGIN_ATTEMPTS) {
            return 0;
        }

        $lockoutEnd = ($data['last_attempt'] ?? time()) + self::LOCKOUT_DURATION;
        return max(0, $lockoutEnd - time());
    }

    /**
     * Sanitize string for output
     */
    public static function escape(string $string): string {
        return htmlspecialchars($string, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Validate and sanitize URL
     */
    public static function sanitizeUrl(string $url): ?string {
        $url = filter_var($url, FILTER_SANITIZE_URL);

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        // Only allow http and https
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!in_array(strtolower($scheme), ['http', 'https'])) {
            return null;
        }

        return $url;
    }

    /**
     * Generate secure random token
     */
    public static function generateToken(int $length = 32): string {
        return bin2hex(random_bytes($length));
    }

    /**
     * Constant-time string comparison
     */
    public static function secureCompare(string $a, string $b): bool {
        return hash_equals($a, $b);
    }

    /**
     * Get client IP address
     */
    public static function getClientIp(): string {
        // Check for proxied IP
        $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                // Handle comma-separated IPs (X-Forwarded-For)
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }

    /**
     * Set security headers
     */
    public static function setSecurityHeaders(): void {
        // Prevent clickjacking
        header('X-Frame-Options: SAMEORIGIN');

        // Prevent MIME type sniffing
        header('X-Content-Type-Options: nosniff');

        // XSS protection
        header('X-XSS-Protection: 1; mode=block');

        // Referrer policy
        header('Referrer-Policy: strict-origin-when-cross-origin');

        // Content Security Policy (adjust as needed)
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; connect-src 'self'");
    }

    // Simple file-based cache for rate limiting
    private static function getCacheDir(): string {
        $dir = __DIR__ . '/../data/cache';
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        return $dir;
    }

    private static function getCacheFile(string $key): string {
        return self::getCacheDir() . '/' . md5($key) . '.cache';
    }

    private static function getCache(string $key): ?array {
        $file = self::getCacheFile($key);

        if (!file_exists($file)) {
            return null;
        }

        $data = file_get_contents($file);
        $decoded = json_decode($data, true);

        if ($decoded === null || !isset($decoded['expires']) || $decoded['expires'] < time()) {
            @unlink($file);
            return null;
        }

        return $decoded['data'];
    }

    private static function setCache(string $key, array $data, int $ttl): void {
        $file = self::getCacheFile($key);
        $content = json_encode([
            'data' => $data,
            'expires' => time() + $ttl,
        ]);
        file_put_contents($file, $content, LOCK_EX);
    }

    private static function deleteCache(string $key): void {
        $file = self::getCacheFile($key);
        if (file_exists($file)) {
            @unlink($file);
        }
    }

    /**
     * Clean expired cache files
     */
    public static function cleanCache(): void {
        $dir = self::getCacheDir();
        if (!is_dir($dir)) {
            return;
        }

        foreach (glob($dir . '/*.cache') as $file) {
            $data = file_get_contents($file);
            $decoded = json_decode($data, true);

            if ($decoded === null || !isset($decoded['expires']) || $decoded['expires'] < time()) {
                @unlink($file);
            }
        }
    }
}
