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
    // Address-independent ceiling on password sign-ins. Without it a caller
    // who can choose the address that keys the per-address counter (a botnet,
    // an IPv6 /64, a forged forwarded header behind a mis-set proxy) gets five
    // guesses per address with no limit. Small enough to matter, large enough
    // that a shared office address does not trip it. Trade-off, documented:
    // anyone can pause password sign-in for everyone for LOCKOUT_DURATION;
    // SSO and the reset links do not spend it. Only password sign-ins count
    // (reserveLoginAttempt(..., true)); the authenticated re-auth prompts use
    // the per-address counter alone so a hijacked session cannot burn the
    // operator's sign-in budget.
    private const MAX_GLOBAL_LOGIN_ATTEMPTS = 20;
    private const LOCKOUT_DURATION = 300; // 5 minutes

    /**
     * Check rate limit for an action.
     *
     * Read, increment and write happen under a per-key file lock: separate
     * read/write steps lose counts under concurrency, which is exactly when a
     * limit matters. A limiter that cannot record state fails CLOSED (false)
     * rather than silently allowing everything on an unwritable data dir.
     */
    public static function checkRateLimit(string $action, string $identifier, int $maxAttempts = 60): bool {
        $key = "rate_{$action}_{$identifier}";
        try {
            $data = self::withLock($key, function () use ($key): array {
                $data = self::getCache($key);
                if ($data === null || time() - (int)($data['window_start'] ?? 0) > self::RATE_LIMIT_WINDOW) {
                    $data = ['count' => 1, 'window_start' => time()];
                } else {
                    $data['count'] = (int)($data['count'] ?? 0) + 1;
                }
                self::setCache($key, $data, self::RATE_LIMIT_WINDOW);
                return $data;
            });
        } catch (\Throwable $e) {
            error_log('CashuPayServer: rate limit storage failed: ' . $e->getMessage());
            return false;
        }

        // M4: Log rate limit exceeded
        if ($data['count'] > $maxAttempts) {
            error_log("CashuPayServer: Rate limit exceeded for {$action} from {$identifier}");
        }

        return $data['count'] <= $maxAttempts;
    }

    /**
     * Reserve one login attempt for an identifier, BEFORE the password is
     * checked.
     *
     * Check and increment happen under one exclusive lock, so N parallel
     * guesses cost N attempts: the old isLockedOut / bcrypt / recordFailedLogin
     * sequence let every concurrent request read the same count and get its
     * password checked. Returns false when the identifier (or, with
     * $countGlobal, the global sign-in budget) is locked out; nothing is
     * counted then. On success call clearLoginAttempts(); on a wrong password
     * do nothing more — the attempt is already counted.
     *
     * Fails closed: if the counter cannot be read or written the attempt is
     * refused, never silently allowed.
     */
    public static function reserveLoginAttempt(string $identifier, bool $countGlobal = false): bool {
        $key = self::lockoutKey($identifier);
        try {
            return self::withLock(self::LOGIN_LOCK, function () use ($key, $countGlobal): bool {
                $data = self::getCache($key) ?? ['count' => 0, 'first_attempt' => time()];
                if (self::lockoutEnd($data, self::MAX_LOGIN_ATTEMPTS) > time()) {
                    return false;
                }
                if ($countGlobal) {
                    $globalKey = self::globalLockoutKey();
                    $global = self::getCache($globalKey) ?? ['count' => 0, 'first_attempt' => time()];
                    if (self::lockoutEnd($global, self::MAX_GLOBAL_LOGIN_ATTEMPTS) > time()) {
                        return false;
                    }
                    if ((int)($global['count'] ?? 0) >= self::MAX_GLOBAL_LOGIN_ATTEMPTS) {
                        // The previous global pause has run out: fresh budget.
                        $global = ['count' => 0, 'first_attempt' => time()];
                    }
                    $global['count'] = (int)($global['count'] ?? 0) + 1;
                    $global['last_attempt'] = time();
                    self::setCache($globalKey, $global, self::LOCKOUT_DURATION);
                }
                if ((int)($data['count'] ?? 0) >= self::MAX_LOGIN_ATTEMPTS) {
                    // The previous lockout has run out: start a fresh budget.
                    $data = ['count' => 0, 'first_attempt' => time()];
                }
                $data['count'] = (int)($data['count'] ?? 0) + 1;
                $data['last_attempt'] = time();
                self::setCache($key, $data, self::LOCKOUT_DURATION);
                return true;
            });
        } catch (\Throwable $e) {
            error_log('CashuPayServer: login attempt storage failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Record failed login attempt.
     *
     * Kept for callers that check the password first. New code should use
     * reserveLoginAttempt(), which counts the attempt before the password
     * check; calling both counts a wrong password twice.
     */
    public static function recordFailedLogin(string $identifier): void {
        $key = self::lockoutKey($identifier);
        try {
            self::withLock(self::LOGIN_LOCK, function () use ($key): void {
                $data = self::getCache($key) ?? ['count' => 0, 'first_attempt' => time()];
                $data['count'] = (int)($data['count'] ?? 0) + 1;
                $data['last_attempt'] = time();
                self::setCache($key, $data, self::LOCKOUT_DURATION);
            });
        } catch (\Throwable $e) {
            error_log('CashuPayServer: login attempt storage failed: ' . $e->getMessage());
        }
    }

    /**
     * Check if identifier is locked out
     */
    public static function isLockedOut(string $identifier): bool {
        $data = self::getCache(self::lockoutKey($identifier));
        return $data !== null && self::lockoutEnd($data, self::MAX_LOGIN_ATTEMPTS) > time();
    }

    /**
     * Clear login attempts on successful login.
     *
     * The global budget is cleared too: whoever just proved the password is
     * the operator, and leaving it spent would lock them out for no reason.
     */
    public static function clearLoginAttempts(string $identifier): void {
        self::deleteCache(self::lockoutKey($identifier));
        self::deleteCache(self::globalLockoutKey());
    }

    /**
     * Get remaining lockout time (seconds). With $includeGlobal the global
     * sign-in pause is folded in, for password sign-in prompts.
     */
    public static function getLockoutRemaining(string $identifier, bool $includeGlobal = false): int {
        $remaining = 0;
        $data = self::getCache(self::lockoutKey($identifier));
        if ($data !== null) {
            $remaining = max(0, self::lockoutEnd($data, self::MAX_LOGIN_ATTEMPTS) - time());
        }
        if ($includeGlobal) {
            $global = self::getCache(self::globalLockoutKey());
            if ($global !== null) {
                $remaining = max($remaining, max(0, self::lockoutEnd($global, self::MAX_GLOBAL_LOGIN_ATTEMPTS) - time()));
            }
        }
        return $remaining;
    }

    /** One lock for every login counter: the global budget and the per-address
     *  counter are updated together, so they share a lock. */
    private const LOGIN_LOCK = 'login_attempts';

    /**
     * Cache key of an identifier's login counter. IPv6 clients usually hold a
     * whole /64, so one address per guess would give them billions of fresh
     * budgets: aggregate IPv6 identifiers per /64. Non-IP identifiers (user
     * names, opaque tokens) are keyed verbatim.
     */
    public static function lockoutKey(string $identifier): string {
        $ip = trim($identifier, " \t[]");
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $bin = @inet_pton($ip);
            if ($bin !== false && strlen($bin) === 16) {
                // IPv4-mapped (::ffff:a.b.c.d) is really an IPv4 client: key on
                // the embedded address so the /64 aggregation does not fold the
                // whole mapped range together.
                if (substr($bin, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
                    $identifier = (string)inet_ntop(substr($bin, 12));
                } else {
                    $identifier = inet_ntop(substr($bin, 0, 8) . str_repeat("\0", 8)) . '/64';
                }
            }
        }
        return "login_attempts_{$identifier}";
    }

    /** Cache key of the address-independent sign-in budget. */
    private static function globalLockoutKey(): string {
        return 'login_attempts_global';
    }

    /** When a lockout ends for the given limit, or 0 when the counter is below it. */
    private static function lockoutEnd(array $data, int $limit): int {
        if ((int)($data['count'] ?? 0) < $limit) {
            return 0;
        }
        return (int)($data['last_attempt'] ?? time()) + self::LOCKOUT_DURATION;
    }

    /**
     * Run $fn while holding an exclusive flock on a per-name lock file in the
     * cache directory. The lock file is separate from the data files so a
     * reader that unlinks an expired entry never races the lock itself.
     * Throws when the lock cannot be taken (unwritable data dir) so callers
     * fail closed.
     */
    private static function withLock(string $name, callable $fn): mixed {
        $lockFile = self::getCacheDir() . '/' . md5($name) . '.lock';
        $fh = @fopen($lockFile, 'c');
        if ($fh === false) {
            throw new \RuntimeException("cannot open lock file {$lockFile}");
        }
        try {
            if (!flock($fh, LOCK_EX)) {
                throw new \RuntimeException("cannot lock {$lockFile}");
            }
            try {
                return $fn();
            } finally {
                flock($fh, LOCK_UN);
            }
        } finally {
            fclose($fh);
        }
    }

    /**
     * Sanitize string for output
     */
    public static function escape(string $string): string {
        return htmlspecialchars($string, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Neutralize spreadsheet formula injection for a single CSV cell.
     *
     * A cell whose first character is one of = + - @ (or a leading control
     * character that some parsers strip before evaluating the first visible
     * char) is interpreted as a formula by Excel / Google Sheets / LibreOffice
     * — e.g. a payer-supplied memo or email of `=HYPERLINK(...)` or `=cmd|...`
     * executes when an admin opens the export. Prefixing a single quote forces
     * the value to be shown literally. Non-string scalars are stringified;
     * null becomes an empty string.
     */
    public static function csvCell($value): string {
        $s = ($value === null) ? '' : (string)$value;
        if ($s === '') {
            return $s;
        }
        // Neutralize when the FIRST character is dangerous, OR when the first
        // non-whitespace character is a formula lead-in: some parsers strip
        // leading spaces/tabs/newlines before evaluating, so " =cmd|..." (note
        // the leading space) would otherwise execute despite passing a raw
        // $s[0] check. ltrim of all ASCII whitespace covers space, tab, CR, LF,
        // vertical tab and form feed.
        $firstVisible = ltrim($s, " \t\r\n\v\f");
        $startsDangerous = strpos("=+-@\t\r\n", $s[0]) !== false
            || ($firstVisible !== '' && strpos("=+-@", $firstVisible[0]) !== false);
        if ($startsDangerous) {
            return "'" . $s;
        }
        return $s;
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
     * Get the client IP address.
     *
     * Forwarding headers (CF-Connecting-IP, X-Forwarded-For, X-Real-IP) are
     * CLIENT-CONTROLLED unless the request actually arrived from a reverse
     * proxy we trust. Honoring them unconditionally lets anyone spoof their IP
     * by sending a header — defeating the per-IP login lockout and every rate
     * limit (just rotate the header per request). So we only consult them when
     * REMOTE_ADDR is a configured trusted proxy; otherwise REMOTE_ADDR wins.
     *
     * Configure trusted proxies via Config 'trusted_proxies' or the
     * CASHUPAY_TRUSTED_PROXIES env var — a comma-separated list of IPs and/or
     * CIDR ranges (e.g. "127.0.0.1, ::1, 10.0.0.0/8"). Default: empty, i.e. no
     * proxy is trusted and REMOTE_ADDR is always used. The literal "*" trusts
     * all proxies and should only be set when a fully trusted edge (e.g. a
     * cloud load balancer) is the sole ingress.
     */
    public static function getClientIp(): string {
        $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $remoteValid = filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';

        if (!self::isTrustedProxy($remoteValid)) {
            return $remoteValid;
        }

        // Cloudflare sets CF-Connecting-IP to a single real client IP.
        $cf = trim((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
        if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP)) {
            return $cf;
        }

        // X-Forwarded-For is "client, proxy1, proxy2, ...". Walk RIGHT to LEFT,
        // skipping hops that are themselves trusted proxies; the first untrusted
        // hop is the real client. Never take [0] — the left-most entry is fully
        // attacker-controlled (the client writes it).
        $xff = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($xff !== '') {
            $hops = array_map('trim', explode(',', $xff));
            $leftmostValid = null;
            foreach ($hops as $hop) {
                if ($leftmostValid === null && filter_var($hop, FILTER_VALIDATE_IP)) {
                    $leftmostValid = $hop;
                }
            }
            for ($i = count($hops) - 1; $i >= 0; $i--) {
                $hop = $hops[$i];
                if (!filter_var($hop, FILTER_VALIDATE_IP)) {
                    continue;
                }
                if (self::isTrustedProxy($hop)) {
                    continue;
                }
                return $hop;
            }
            // Every hop was itself a trusted proxy (e.g. the "*" trust-all
            // wildcard, or a chain entirely within trusted ranges): the original
            // client is then the left-most entry.
            if ($leftmostValid !== null) {
                return $leftmostValid;
            }
        }

        $xr = trim((string)($_SERVER['HTTP_X_REAL_IP'] ?? ''));
        if ($xr !== '' && filter_var($xr, FILTER_VALIDATE_IP)) {
            return $xr;
        }

        return $remoteValid;
    }

    /**
     * Comma-separated trusted-proxy list from Config (preferred) or the
     * CASHUPAY_TRUSTED_PROXIES env var. Returns [] (trust nothing) by default.
     */
    private static function trustedProxyList(): array {
        $raw = Config::get('trusted_proxies', null);
        if ($raw === null || $raw === '') {
            $env = getenv('CASHUPAY_TRUSTED_PROXIES');
            $raw = ($env === false) ? '' : $env;
        }
        $raw = (string)$raw;
        if ($raw === '') {
            return [];
        }
        return array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            static fn($s) => $s !== ''
        ));
    }

    /**
     * True if $ip falls within any configured trusted-proxy entry (bare IP,
     * CIDR, or the "*" trust-all sentinel).
     */
    public static function isTrustedProxy(string $ip): bool {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        foreach (self::trustedProxyList() as $entry) {
            if (self::ipInCidr($ip, $entry)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Match an IP against a bare IP, a CIDR range, or "*". Handles both IPv4
     * and IPv6; a family mismatch is a non-match.
     */
    private static function ipInCidr(string $ip, string $entry): bool {
        $entry = trim($entry);
        if ($entry === '') {
            return false;
        }
        if ($entry === '*') {
            return true; // trust-all: use only behind a fully trusted edge
        }

        $ipBin = @inet_pton($ip);
        if ($ipBin === false) {
            return false;
        }

        if (strpos($entry, '/') === false) {
            $entryBin = @inet_pton($entry);
            return $entryBin !== false && $ipBin === $entryBin;
        }

        [$subnet, $bitsStr] = explode('/', $entry, 2);
        $subnetBin = @inet_pton($subnet);
        if ($subnetBin === false || strlen($subnetBin) !== strlen($ipBin)) {
            return false; // unparsable subnet or IPv4-vs-IPv6 mismatch
        }
        $bits = (int)$bitsStr;
        $maxBits = strlen($ipBin) * 8;
        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }
        $fullBytes = intdiv($bits, 8);
        $remBits = $bits % 8;
        if ($fullBytes > 0 && strncmp($ipBin, $subnetBin, $fullBytes) !== 0) {
            return false;
        }
        if ($remBits === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $remBits)) & 0xFF;
        return (ord($ipBin[$fullBytes]) & $mask) === (ord($subnetBin[$fullBytes]) & $mask);
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

        // Content Security Policy (adjust as needed). No CDN entries: all
        // scripts are served locally (see assets/js/qr-shim.js).
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; connect-src 'self'");
    }

    // Simple file-based cache for rate limiting. Lives under the data
    // directory (which honours CASHUPAY_DATA_DIR) rather than the code tree:
    // the WordPress installer keeps data outside the web root, and a
    // read-only or mis-owned code tree used to disable every lockout silently.
    private static function getCacheDir(): string {
        $dir = Database::getDataDir() . '/cache';
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0750, true) && !is_dir($dir)) {
                throw new \RuntimeException("cannot create cache directory {$dir}");
            }
        }
        return $dir;
    }

    private static function getCacheFile(string $key): string {
        return self::getCacheDir() . '/' . md5($key) . '.cache';
    }

    private static function getCache(string $key): ?array {
        try {
            $file = self::getCacheFile($key);
        } catch (\Throwable $e) {
            // Readers are informational; the writers (reserve/check) fail
            // closed on the same condition via setCache/withLock.
            return null;
        }

        if (!file_exists($file)) {
            return null;
        }

        $data = @file_get_contents($file);
        if ($data === false) {
            return null;
        }
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
        if (@file_put_contents($file, $content, LOCK_EX) === false) {
            // Surface the failure so limiters fail closed instead of
            // forgetting the attempt they just granted.
            throw new \RuntimeException("cannot write cache file {$file}");
        }
    }

    private static function deleteCache(string $key): void {
        try {
            $file = self::getCacheFile($key);
        } catch (\Throwable $e) {
            return;
        }
        if (file_exists($file)) {
            @unlink($file);
        }
    }

    /**
     * Clean expired cache files
     */
    public static function cleanCache(): void {
        try {
            $dir = self::getCacheDir();
        } catch (\Throwable $e) {
            return;
        }
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

// Every entry point loads this module (directly or via auth.php), which
// makes it the bootstrap spot for the wallet library's address policy:
// \Cashu\ConnectGuard is wired to SafeHttp so mint and LNURL requests made
// by the library obey the same private-range / DNS-pin rules as our own.
require_once __DIR__ . '/cashu_guard.php';
CashuGuard::install();
