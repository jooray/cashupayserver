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

        // Read, increment and write under one write transaction: separate read/write
        // steps lose counts under concurrency, which is exactly when a limit matters.
        $pdo = Database::getInstance();
        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) {
            $pdo->exec('BEGIN IMMEDIATE');
        }
        try {
            $data = self::getCache($key);
            if ($data === null || time() - ($data['window_start'] ?? 0) > self::RATE_LIMIT_WINDOW) {
                $data = ['count' => 1, 'window_start' => time()];
            } else {
                $data['count']++;
            }
            self::setCache($key, $data, self::RATE_LIMIT_WINDOW);
            if ($ownTransaction) {
                $pdo->exec('COMMIT');
            }
        } catch (Throwable $e) {
            if ($ownTransaction) {
                try { $pdo->exec('ROLLBACK'); } catch (Throwable $ignored) {}
            }
            // A limiter that cannot record state must not silently allow everything.
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
     * Reserve one login attempt for an identifier, before the password is checked.
     *
     * Check and increment happen in one write transaction, so N parallel guesses cost N
     * attempts: the old read / bcrypt / write sequence let every concurrent request see
     * the same count and get its password checked. Returns false when the identifier is
     * locked out (nothing is counted then). On success call clearLoginAttempts(); on a
     * wrong password do nothing more — the attempt is already counted.
     */
    public static function reserveLoginAttempt(string $identifier): bool {
        $key = "login_attempts_{$identifier}";
        try {
            return self::withWriteLock(function () use ($key): bool {
                $data = self::getCache($key) ?? ['count' => 0, 'first_attempt' => time()];
                if (self::lockoutEnd($data) > time()) {
                    return false;
                }
                if (($data['count'] ?? 0) >= self::MAX_LOGIN_ATTEMPTS) {
                    // The previous lockout has run out: start a fresh budget.
                    $data = ['count' => 0, 'first_attempt' => time()];
                }
                $data['count']++;
                $data['last_attempt'] = time();
                self::setCache($key, $data, self::LOCKOUT_DURATION);
                return true;
            });
        } catch (Throwable $e) {
            // A lockout that cannot record state must not silently allow everything.
            error_log('CashuPayServer: login attempt storage failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Record failed login attempt.
     *
     * Kept for callers that check the password first. New code should use
     * reserveLoginAttempt(), which counts the attempt before the password check; calling
     * both counts a wrong password twice.
     */
    public static function recordFailedLogin(string $identifier): void {
        $key = "login_attempts_{$identifier}";
        try {
            self::withWriteLock(function () use ($key): void {
                $data = self::getCache($key) ?? ['count' => 0, 'first_attempt' => time()];
                $data['count'] = (int)($data['count'] ?? 0) + 1;
                $data['last_attempt'] = time();
                self::setCache($key, $data, self::LOCKOUT_DURATION);
            });
        } catch (Throwable $e) {
            error_log('CashuPayServer: login attempt storage failed: ' . $e->getMessage());
        }
    }

    /**
     * Check if identifier is locked out
     */
    public static function isLockedOut(string $identifier): bool {
        $data = self::getCache("login_attempts_{$identifier}");
        return $data !== null && self::lockoutEnd($data) > time();
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
        $data = self::getCache("login_attempts_{$identifier}");
        return $data === null ? 0 : max(0, self::lockoutEnd($data) - time());
    }

    /** When a lockout ends, or 0 when the counter is below the limit. */
    private static function lockoutEnd(array $data): int {
        if ((int)($data['count'] ?? 0) < self::MAX_LOGIN_ATTEMPTS) {
            return 0;
        }
        return (int)($data['last_attempt'] ?? time()) + self::LOCKOUT_DURATION;
    }

    /**
     * Run a read-modify-write on the limiter table under SQLite's write lock.
     *
     * BEGIN IMMEDIATE takes the lock before the read, so a concurrent request waits
     * (busy_timeout) instead of reading the same value. Inside a caller's transaction
     * the caller already holds whatever it holds; nesting is not possible in SQLite.
     */
    private static function withWriteLock(callable $fn): mixed {
        $pdo = Database::getInstance();
        self::ensureCacheTable();
        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) {
            $pdo->exec('BEGIN IMMEDIATE');
        }
        try {
            $result = $fn();
            if ($ownTransaction) {
                $pdo->exec('COMMIT');
            }
            return $result;
        } catch (Throwable $e) {
            if ($ownTransaction) {
                try { $pdo->exec('ROLLBACK'); } catch (Throwable $ignored) {}
            }
            throw $e;
        }
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
     * A yes here says nothing about the *next* lookup of the same name. Code that goes on
     * to connect should use resolvePublicTarget() and pin the addresses it returns.
     *
     * @param bool $allowLocalhost Permit localhost/127.0.0.1 (only for explicit dev/self-calls)
     */
    public static function isSafePublicHttpUrl(string $url, bool $allowLocalhost = false): bool {
        return self::resolvePublicTarget($url, $allowLocalhost) !== null;
    }

    /**
     * Resolve a URL's host once and vet every address it resolves to.
     *
     * Returns the addresses that were checked, ready to pin the connection to with
     * CURLOPT_RESOLVE, or null when the URL is unusable or any address is non-public.
     * Resolving here and letting cURL resolve again would leave a rebinding window: a
     * TTL-0 name can answer the check with a public address and the connection with
     * 127.0.0.1 (DS41-03, SPB-SEC-005, OPUS-008).
     *
     * @return array{host: string, port: int, ips: string[], resolve: string[]}|null
     */
    public static function resolvePublicTarget(string $url, bool $allowLocalhost = false): ?array {
        $target = self::resolveTarget($url);
        if ($target === null) {
            return null;
        }
        foreach ($target['ips'] as $ip) {
            if ($allowLocalhost && in_array($ip, ['127.0.0.1', '::1'], true)) {
                continue;
            }
            if (!self::isPublicIp($ip)) {
                return null; // private, loopback, link-local, CGNAT or reserved
            }
        }
        return $target;
    }

    /**
     * Resolve a URL's host once, without judging the addresses.
     *
     * For targets that are allowed to be private (this installation's own origin, or an
     * operator who opted in): the connection is still pinned to what was resolved here.
     *
     * @return array{host: string, port: int, ips: string[], resolve: string[]}|null
     */
    public static function resolveTarget(string $url): ?array {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $host = $parts['host'];
        $port = (int)($parts['port'] ?? ($scheme === 'http' ? 80 : 443));
        $literal = trim($host, '[]');
        if (filter_var($literal, FILTER_VALIDATE_IP)) {
            // Nothing to resolve, nothing to pin: cURL connects to the literal.
            return ['host' => $host, 'port' => $port, 'ips' => [$literal], 'resolve' => []];
        }

        $ips = self::resolveHost($host);
        if (empty($ips)) {
            return null; // could not resolve -> treat as unsafe (fail closed)
        }
        $pinned = array_map(
            static fn(string $ip) => str_contains($ip, ':') ? '[' . $ip . ']' : $ip,
            $ips
        );
        return [
            'host' => $host,
            'port' => $port,
            'ips' => $ips,
            'resolve' => [$host . ':' . $port . ':' . implode(',', $pinned)],
        ];
    }

    /**
     * Whether an address is publicly routable.
     *
     * PHP's FILTER_FLAG_NO_RES_RANGE covers only a few reserved blocks; carrier-grade NAT
     * (100.64.0.0/10, the address space of many VPS and container networks), the
     * benchmarking range, multicast and the IPv6 transition prefixes that embed an IPv4
     * address all pass it (SPB-SEC-004, DS41-19).
     */
    public static function isPublicIp(string $ip): bool {
        $ip = self::normalizeIp($ip);
        if ($ip === null) {
            return false;
        }
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        foreach (self::NON_PUBLIC_RANGES as $range) {
            if (self::ipInRange($ip, $range)) {
                return false;
            }
        }
        return true;
    }

    /** Ranges PHP's filter flags let through but that must never be an outbound target. */
    private const NON_PUBLIC_RANGES = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
        '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16',
        '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
        '::/128', '::1/128', '64:ff9b::/96', '64:ff9b:1::/48', '100::/64', '2001::/23',
        '2001:db8::/32', '2002::/16', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8',
    ];

    /**
     * Whether an address falls in a range given as CIDR ("10.0.0.0/8", "2400:cb00::/32")
     * or as a single address. Mismatched families never match.
     */
    public static function ipInRange(string $ip, string $range): bool {
        $ip = self::normalizeIp($ip);
        if ($ip === null) {
            return false;
        }
        $range = trim($range);
        $bits = null;
        if (str_contains($range, '/')) {
            [$range, $bitsRaw] = explode('/', $range, 2);
            if (!ctype_digit($bitsRaw)) {
                return false;
            }
            $bits = (int)$bitsRaw;
        }
        $net = self::normalizeIp($range);
        if ($net === null) {
            return false;
        }
        $ipBin = inet_pton($ip);
        $netBin = inet_pton($net);
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
            return false;
        }
        $max = strlen($ipBin) * 8;
        $bits = $bits === null ? $max : $bits;
        if ($bits < 0 || $bits > $max) {
            return false;
        }
        $bytes = intdiv($bits, 8);
        if (substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
            return false;
        }
        $rem = $bits % 8;
        if ($rem === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rem)) & 0xFF;
        return (ord($ipBin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
    }

    /** A valid address in canonical form, with IPv4-mapped IPv6 unwrapped to IPv4. */
    private static function normalizeIp(string $ip): ?string {
        $ip = trim($ip, " \t[]");
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }
        $bin = inet_pton($ip);
        if ($bin === false) {
            return null;
        }
        if (strlen($bin) === 16 && substr($bin, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            return inet_ntop(substr($bin, 12));
        }
        return inet_ntop($bin);
    }

    /** Test hook: replaces DNS resolution (callable(string $host): string[]). */
    private static $resolver = null;

    public static function setResolverForTesting(?callable $resolver): void {
        self::$resolver = $resolver;
    }

    /**
     * Resolved addresses for a host, or an empty array when resolution fails.
     *
     * @return string[]
     */
    public static function resolveHost(string $host): array {
        $literal = trim($host, '[]');
        if (filter_var($literal, FILTER_VALIDATE_IP)) {
            return [$literal];
        }
        if (self::$resolver !== null) {
            return array_values(array_filter((array)(self::$resolver)($host), 'is_string'));
        }
        $ips = @gethostbynamel($host) ?: [];
        $aaaa = @dns_get_record($host, DNS_AAAA);
        if ($aaaa) {
            foreach ($aaaa as $rec) {
                if (!empty($rec['ipv6'])) {
                    $ips[] = $rec['ipv6'];
                }
            }
        }
        return $ips;
    }

    /**
     * CURLOPT_RESOLVE entries pinning a URL to vetted public addresses.
     *
     * Resolves once and validates what it pins; an empty array means "do not connect"
     * for a host name (a literal IP needs no pinning, so callers must still have checked
     * the URL). Prefer resolvePublicTarget() and reuse its result.
     *
     * @return string[]
     */
    public static function resolveOptionFor(string $url): array {
        return self::resolvePublicTarget($url)['resolve'] ?? [];
    }

    /**
     * Whether a URL is acceptable as this application's own base URL.
     *
     * Unlike outbound targets, our own origin may legitimately be localhost or a LAN
     * address; what matters is that it is a plain HTTP(S) URL with no credentials.
     */
    public static function isSafeAppBaseUrl(string $url): bool {
        $parts = parse_url($url);
        if (!$parts || empty($parts['host']) || empty($parts['scheme'])) {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }
        return in_array(strtolower($parts['scheme']), ['http', 'https'], true);
    }

    /**
     * Whether a stored URL is safe to hand to a browser as a navigation target.
     *
     * Only http(s) and site-relative paths. Notably not `javascript:` (in any casing, or
     * with control characters mixed in to defeat a naive prefix test) and not `data:`.
     * HTML-escaping does not help here: the value becomes an anchor href and a
     * `window.location` assignment, both of which honour the scheme.
     *
     * Browsers treat a backslash as a slash in http(s) URLs, so `/\evil.com` is the
     * protocol-relative `//evil.com` (SPB-SEC-010). Backslashes and control characters
     * are refused outright rather than stripped, and a relative candidate is resolved
     * against a dummy origin to prove it stays on it.
     */
    public static function isSafeBrowserRedirect(string $url): bool {
        if ($url === '' || preg_match('/[\x00-\x1F\x7F\\\\]/', $url)) {
            return false;
        }
        $candidate = trim($url, ' '); // browsers drop surrounding spaces too
        if ($candidate === '') {
            return false;
        }

        if (str_starts_with($candidate, '/')) {
            if (str_starts_with($candidate, '//')) {
                return false; // protocol-relative: another host
            }
            // Resolve against a fixed origin: the result must keep that origin.
            $parts = parse_url('https://origin.invalid' . $candidate);
            return $parts !== false
                && strtolower($parts['host'] ?? '') === 'origin.invalid'
                && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['port']);
        }

        $parts = parse_url($candidate);
        if ($parts === false || empty($parts['host'])) {
            return false;
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        return in_array($scheme, ['http', 'https'], true)
            && preg_match('#^https?://#i', $candidate) === 1;
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
     * Forwarded headers are consulted ONLY when the direct peer (REMOTE_ADDR) is a trusted
     * proxy: loopback (a reverse proxy on the same host), an entry of `trusted_proxies`
     * (single addresses or CIDR ranges), or a Cloudflare edge when the operator said the
     * site is behind Cloudflare (`behind_cloudflare`). See FABLE-SECURITY-AUDIT (MED-1).
     *
     * Proxies *append* to X-Forwarded-For, so its leftmost entry is whatever the client
     * sent. The client is the rightmost entry that is not itself a trusted proxy
     * (DS41-04, OPUS-006). CF-Connecting-IP is believed only when a Cloudflare address
     * is the hop that delivered the request, and only when the operator configured
     * Cloudflare: any other proxy passes a client-sent CF-Connecting-IP through untouched
     * (SPB-SEC-007). X-Real-IP is a last resort for proxies that set no X-Forwarded-For.
     *
     * Which hops are trusted is the dashboard's "Visitor addresses" setting (proxy_mode):
     *   auto (default)  loopback, private-network peers (a host's own load balancer or
     *                   cache, e.g. nginx/Varnish/HAProxy on another box) and genuine
     *                   Cloudflare edges. Works on typical hosting with no setup.
     *   direct          no proxy: forwarded headers are ignored entirely.
     *   custom          loopback plus the listed addresses/CIDRs (trusted_proxies),
     *                   and Cloudflare only if behind_cloudflare is ticked.
     * Trusting a Cloudflare edge is safe without configuration: Cloudflare overwrites
     * CF-Connecting-IP itself, and nobody else can connect from its addresses.
     * The CASHUPAY_TRUSTED_PROXIES / CASHUPAY_BEHIND_CLOUDFLARE constants still work.
     */
    public static function getClientIp(): string {
        $remote = self::normalizeIp((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        if ($remote === null) {
            return '0.0.0.0';
        }
        if (!self::isTrustedProxy($remote)) {
            return $remote;
        }

        // Walk the chain from our side outwards. Each trusted hop vouches for the entry
        // to its left; the first untrusted entry is the client.
        $cloudflareDelivered = self::trustsCloudflare() && self::isCloudflareIp($remote);
        $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if (is_string($xff) && $xff !== '') {
            $hops = array_reverse(array_map('trim', explode(',', $xff)));
            foreach ($hops as $hop) {
                $ip = self::normalizeIp($hop);
                if ($ip === null) {
                    break; // garbage from here on cannot be vouched for
                }
                if (!self::isTrustedProxy($ip)) {
                    if ($cloudflareDelivered && ($cf = self::cloudflareClientIp()) !== null) {
                        return $cf;
                    }
                    return $ip;
                }
                if (self::trustsCloudflare() && self::isCloudflareIp($ip)) {
                    $cloudflareDelivered = true;
                }
            }
        }

        if ($cloudflareDelivered && ($cf = self::cloudflareClientIp()) !== null) {
            return $cf;
        }

        // RFC 7239 `Forwarded: for=...` (some proxies send only this), then X-Real-IP.
        $forwarded = self::forwardedFor();
        if ($forwarded !== null) {
            return $forwarded;
        }
        $real = self::normalizeIp((string)($_SERVER['HTTP_X_REAL_IP'] ?? ''));
        if ($real !== null) {
            return $real;
        }

        return $remote;
    }

    /** The rightmost untrusted `for=` of an RFC 7239 Forwarded header, if any. */
    private static function forwardedFor(): ?string {
        $header = $_SERVER['HTTP_FORWARDED'] ?? '';
        if (!is_string($header) || $header === '') {
            return null;
        }
        $hops = [];
        foreach (explode(',', $header) as $element) {
            if (preg_match('/(?:^|;)\s*for\s*=\s*"?\[?([0-9a-fA-F:.]+)\]?(?::\d+)?"?/i', $element, $m)) {
                $hops[] = $m[1];
            }
        }
        foreach (array_reverse($hops) as $hop) {
            $ip = self::normalizeIp($hop);
            if ($ip === null) {
                return null;
            }
            if (!self::isTrustedProxy($ip)) {
                return $ip;
            }
        }
        return null;
    }

    /** 'auto' (default), 'direct' or 'custom' — see getClientIp(). */
    public static function proxyMode(): string {
        $mode = self::configValue('proxy_mode');
        return in_array($mode, ['auto', 'direct', 'custom'], true) ? $mode : 'auto';
    }

    /** Whether CF-Connecting-IP from a genuine Cloudflare edge is believed. */
    private static function trustsCloudflare(): bool {
        $mode = self::proxyMode();
        return $mode === 'auto' || ($mode === 'custom' && self::behindCloudflare());
    }

    /** Private-network ranges a host's own load balancer or cache connects from. */
    private const PRIVATE_PROXY_RANGES = [
        '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '100.64.0.0/10', 'fc00::/7',
    ];

    /**
     * What this server sees about the current request, for the dashboard's
     * "Visitor addresses" card: the operator checks that "your address" is really theirs.
     *
     * @return array{seenAs: string, peer: string, forwardedFor: ?string, peerIsCloudflare: bool,
     *               cloudflareHeader: bool, mode: string, proxied: bool}
     */
    public static function describeClientIp(): array {
        $peer = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
        return [
            'seenAs' => self::getClientIp(),
            'peer' => $peer,
            'forwardedFor' => is_string($xff) && $xff !== '' ? mb_substr($xff, 0, 300) : null,
            'peerIsCloudflare' => self::normalizeIp($peer) !== null && self::isCloudflareIp(self::normalizeIp($peer)),
            'cloudflareHeader' => isset($_SERVER['HTTP_CF_RAY']) || isset($_SERVER['HTTP_CF_CONNECTING_IP']),
            'mode' => self::proxyMode(),
            'proxied' => is_string($xff) && $xff !== '' || isset($_SERVER['HTTP_X_REAL_IP']) || isset($_SERVER['HTTP_FORWARDED']),
        ];
    }

    /** Validate one trusted-proxy entry (an IP or CIDR range); null if invalid. */
    public static function normalizeProxyEntry(string $entry): ?string {
        $entry = trim($entry);
        if (strtolower($entry) === 'cloudflare') {
            return 'cloudflare';
        }
        if (str_contains($entry, '/')) {
            [$ip, $bits] = explode('/', $entry, 2);
            $ip = self::normalizeIp($ip);
            if ($ip === null || !ctype_digit($bits)) {
                return null;
            }
            $max = str_contains($ip, ':') ? 128 : 32;
            return (int)$bits <= $max ? $ip . '/' . (int)$bits : null;
        }
        return self::normalizeIp($entry);
    }

    /** Whether an address is a proxy whose forwarding headers we believe. */
    public static function isTrustedProxy(string $ip): bool {
        $ip = self::normalizeIp($ip);
        if ($ip === null) {
            return false;
        }
        $mode = self::proxyMode();
        if ($mode === 'direct') {
            return false;
        }
        // A loopback peer really is our own proxy: it cannot be forged from outside.
        if (self::ipInRange($ip, '127.0.0.0/8') || $ip === '::1') {
            return true;
        }
        foreach (self::trustedProxyRanges() as $range) {
            if ($range !== 'cloudflare' && self::ipInRange($ip, $range)) {
                return true;
            }
        }
        if ($mode === 'auto') {
            // Shared hosting puts caches and load balancers on private networks. The
            // trade-off: on an instance exposed to a LAN, a neighbour could pick its
            // own address for the login limit; "Not behind a proxy" closes that.
            foreach (self::PRIVATE_PROXY_RANGES as $range) {
                if (self::ipInRange($ip, $range)) {
                    return true;
                }
            }
        }
        return self::trustsCloudflare() && self::isCloudflareIp($ip);
    }

    /** @return string[] operator-configured proxy addresses and CIDR ranges */
    private static function trustedProxyRanges(): array {
        $ranges = [];
        foreach ([self::configValue('trusted_proxies'), defined('CASHUPAY_TRUSTED_PROXIES') ? CASHUPAY_TRUSTED_PROXIES : null] as $value) {
            if (is_string($value)) {
                $value = explode(',', $value);
            }
            if (is_array($value)) {
                foreach ($value as $entry) {
                    if (is_string($entry) && trim($entry) !== '') {
                        $ranges[] = trim($entry);
                    }
                }
            }
        }
        return $ranges;
    }

    private static function behindCloudflare(): bool {
        if (defined('CASHUPAY_BEHIND_CLOUDFLARE') && CASHUPAY_BEHIND_CLOUDFLARE) {
            return true;
        }
        $flag = self::configValue('behind_cloudflare');
        if ($flag === true || $flag === 1 || $flag === '1' || $flag === 'true') {
            return true;
        }
        // Writing "cloudflare" into trusted_proxies says the same thing.
        return in_array('cloudflare', array_map('strtolower', self::trustedProxyRanges()), true);
    }

    private static function cloudflareClientIp(): ?string {
        $value = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';
        return is_string($value) ? self::normalizeIp($value) : null;
    }

    public static function isCloudflareIp(string $ip): bool {
        foreach (self::CLOUDFLARE_RANGES as $range) {
            if (self::ipInRange($ip, $range)) {
                return true;
            }
        }
        return false;
    }

    /** Cloudflare's published edge ranges (https://www.cloudflare.com/ips/, 2026-10). */
    private const CLOUDFLARE_RANGES = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    /** Config lookup that never throws: client-IP logic runs before setup completes. */
    private static function configValue(string $key): mixed {
        try {
            return Config::get($key);
        } catch (Throwable $e) {
            return null;
        }
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
            // Strict default: scripts only from this server (the QR and bc-ur libraries are
            // vendored in assets/js/vendor/); no external data egress.
            $csp = "default-src 'self'; "
                 . "script-src 'self' 'unsafe-inline'; "
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
     * CSP tuned for the admin SPA, which must reach Nostr relays (wss) and mints (https)
     * for mint discovery. Scripts come only from this server. connect-src is necessarily
     * broad; the primary XSS defences are output
     * escaping and not shipping secrets to the browser (see FABLE-SECURITY-AUDIT HIGH-1/2/3).
     */
    public static function adminCsp(): string {
        return "default-src 'self'; "
             . "script-src 'self' 'unsafe-inline'; "
             . "style-src 'self' 'unsafe-inline'; "
             . "img-src 'self' data: https:; "
             . "connect-src 'self' https: wss:; "
             . "object-src 'none'; base-uri 'self'; frame-ancestors 'self'";
    }

    /**
     * Rate-limit and lockout state, kept in SQLite next to the rest of the data.
     *
     * It used to live in files under the *code* tree (`includes/../data/cache`), which
     * ignores CASHUPAY_DATA_DIR and is read-only on some deployments — a silently
     * disabled limiter. Reads and writes were also separate operations, so concurrent
     * requests lost counts.
     */
    private static function ensureCacheTable(): void {
        static $ready = false;
        if ($ready) {
            return;
        }
        Database::getInstance()->exec(
            "CREATE TABLE IF NOT EXISTS rate_limits (
                key TEXT PRIMARY KEY,
                data TEXT NOT NULL,
                expires INTEGER NOT NULL
            )"
        );
        $ready = true;
    }

    private static function getCache(string $key): ?array {
        self::ensureCacheTable();
        $row = Database::fetchOne(
            'SELECT data, expires FROM rate_limits WHERE key = ?',
            [$key]
        );
        if (!$row || (int)$row['expires'] < time()) {
            return null;
        }
        $decoded = json_decode((string)$row['data'], true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function setCache(string $key, array $data, int $ttl): void {
        self::ensureCacheTable();
        Database::query(
            "INSERT INTO rate_limits (key, data, expires) VALUES (?, ?, ?)
             ON CONFLICT(key) DO UPDATE SET data = excluded.data, expires = excluded.expires",
            [$key, json_encode($data), time() + $ttl]
        );
    }

    private static function deleteCache(string $key): void {
        self::ensureCacheTable();
        Database::query('DELETE FROM rate_limits WHERE key = ?', [$key]);
    }

    /**
     * Clean expired cache files
     */
    public static function cleanCache(): void {
        self::ensureCacheTable();
        Database::query('DELETE FROM rate_limits WHERE expires < ?', [time()]);

        // Sweep the pre-SQLite file cache away on first run after an upgrade.
        $legacyDir = __DIR__ . '/../data/cache';
        if (is_dir($legacyDir)) {
            foreach (glob($legacyDir . '/*.cache') ?: [] as $file) {
                @unlink($file);
            }
        }
    }
}
