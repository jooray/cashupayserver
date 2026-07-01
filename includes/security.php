<?php
/**
 * CashuPayServer - Security Module
 *
 * Rate limiting, CSRF protection, and security utilities.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/config.php';

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
     * Validate that a URL is a public http(s) URL safe to make an outbound request to.
     *
     * Rejects non-http(s) schemes (file://, gopher://, etc.) and hosts that resolve to
     * loopback / private / link-local / reserved ranges. This is the anti-SSRF gate used
     * before the server fetches an operator- or API-supplied URL (webhooks, mint URLs,
     * Lightning-address hosts). See FABLE-SECURITY-AUDIT (CRIT-4, MED-2).
     *
     * @param bool $allowLocalhost Permit localhost/127.0.0.1 (only for explicit dev/self-calls)
     */
    public static function isSafePublicHttpUrl(string $url, bool $allowLocalhost = false): bool {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return false;
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $host = $parts['host'];

        // Resolve host to IPs (IPv4 + IPv6) and reject any private/reserved address.
        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            $v4 = @gethostbynamel($host);
            if ($v4) {
                $ips = array_merge($ips, $v4);
            }
            $aaaa = @dns_get_record($host, DNS_AAAA);
            if ($aaaa) {
                foreach ($aaaa as $rec) {
                    if (!empty($rec['ipv6'])) {
                        $ips[] = $rec['ipv6'];
                    }
                }
            }
        }

        // Could not resolve -> treat as unsafe (fail closed).
        if (empty($ips)) {
            return false;
        }

        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        foreach ($ips as $ip) {
            if ($allowLocalhost && in_array($ip, ['127.0.0.1', '::1'], true)) {
                continue;
            }
            if (!filter_var($ip, FILTER_VALIDATE_IP, $flags)) {
                return false; // private, loopback, link-local or reserved
            }
        }

        return true;
    }

    /**
     * Constant-time string comparison
     */
    public static function secureCompare(string $a, string $b): bool {
        return hash_equals($a, $b);
    }

    /**
     * Get client IP address.
     *
     * Forwarded headers (X-Forwarded-For, X-Real-IP, CF-Connecting-IP) are trusted ONLY
     * when the direct peer (REMOTE_ADDR) is a configured trusted proxy. Otherwise they are
     * spoofable and would let an attacker bypass login lockout / rate limits by rotating the
     * header value. Configure `trusted_proxies` (array of IPs) if the app is behind a
     * reverse proxy / CDN. See FABLE-SECURITY-AUDIT (MED-1).
     */
    public static function getClientIp(): string {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        $trusted = Config::get('trusted_proxies', []);
        if (!is_array($trusted)) {
            $trusted = [];
        }

        // Auto-trust the common "reverse proxy on the same host / private network" case:
        // if the immediate peer is loopback or a private/reserved address, the real client
        // arrived via that proxy and its IP is in X-Forwarded-For. An external attacker
        // cannot forge a loopback/private REMOTE_ADDR, so this stays safe. Explicit
        // trusted_proxies still works for public-IP proxies.
        $peerIsLocalProxy = !filter_var(
            $remote,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );

        // Only consult forwarded headers when the immediate peer is trusted.
        if (in_array($remote, $trusted, true) || $peerIsLocalProxy) {
            $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'];
            foreach ($headers as $header) {
                if (!empty($_SERVER[$header])) {
                    $ip = $_SERVER[$header];
                    // X-Forwarded-For may be a comma list; take the first (client) hop.
                    if (strpos($ip, ',') !== false) {
                        $ip = trim(explode(',', $ip)[0]);
                    }
                    if (filter_var($ip, FILTER_VALIDATE_IP)) {
                        return $ip;
                    }
                }
            }
        }

        if (filter_var($remote, FILTER_VALIDATE_IP)) {
            return $remote;
        }

        return '0.0.0.0';
    }

    /**
     * Set security headers.
     *
     * @param string|null $csp Explicit Content-Security-Policy. If null a strict default
     *                         is used. Pass a custom policy for pages with external needs
     *                         (e.g. the admin SPA loads Nostr relays over wss).
     *                         Pass '' to omit the CSP header entirely.
     */
    public static function setSecurityHeaders(?string $csp = null): void {
        // Prevent clickjacking
        header('X-Frame-Options: SAMEORIGIN');

        // Prevent MIME type sniffing
        header('X-Content-Type-Options: nosniff');

        // XSS protection (legacy browsers)
        header('X-XSS-Protection: 1; mode=block');

        // Referrer policy
        header('Referrer-Policy: strict-origin-when-cross-origin');

        if ($csp === null) {
            // Strict default: self-hosted + jsdelivr (QR lib); no external data egress.
            $csp = "default-src 'self'; "
                 . "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; "
                 . "style-src 'self' 'unsafe-inline'; "
                 . "img-src 'self' data: https:; "
                 . "connect-src 'self'; "
                 . "object-src 'none'; base-uri 'self'; frame-ancestors 'self'";
        }
        if ($csp !== '') {
            header('Content-Security-Policy: ' . $csp);
        }
    }

    /**
     * CSP tuned for the admin SPA, which must reach Nostr relays (wss) and two CDNs for
     * mint discovery. connect-src is necessarily broad; the primary XSS defences are output
     * escaping and not shipping secrets to the browser (see FABLE-SECURITY-AUDIT HIGH-1/2/3).
     */
    public static function adminCsp(): string {
        return "default-src 'self'; "
             . "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdn.skypack.dev; "
             . "style-src 'self' 'unsafe-inline'; "
             . "img-src 'self' data: https:; "
             . "connect-src 'self' https: wss:; "
             . "object-src 'none'; base-uri 'self'; frame-ancestors 'self'";
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
