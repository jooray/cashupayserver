<?php
/**
 * CashuPayServer - Safe outbound HTTP helper
 *
 * Centralizes URL validation and curl hardening for every outbound HTTP
 * call in the server. Without this, every call site reinvents (or skips)
 * the same defenses, which is how SSRF gets in: an attacker-controlled URL
 * (webhook target, LNURL callback, mint info endpoint, redirect from a
 * trusted host) points at 169.254.169.254 / 127.0.0.1 / fc00::/7 and the
 * payserver dutifully proxies the response back, leaking cloud metadata
 * or pivoting to internal services.
 *
 * Default posture:
 *   - http/https only (no file://, gopher://, etc.)
 *   - reject hostnames that resolve to private/loopback/link-local IPs
 *   - pin curl to the resolved IP (CURLOPT_RESOLVE) so a follow-up DNS
 *     re-resolve can't swap to a private address mid-connection
 *   - follow redirects only when the caller asks for it, and then ONLY by
 *     re-validating every hop ourselves (curl's own follow is disabled): a
 *     cross-host Location is re-resolved and re-checked against the private-IP
 *     policy, so a 302 -> 169.254.169.254 can't bypass the check the way it
 *     would if curl followed it (the RESOLVE pin covers only the first host)
 *   - cap response size (default 5 MB) so a hostile peer can't OOM us
 *   - verify TLS peer
 *
 * Self-hosters who legitimately run a local mint or local Bitcoin node
 * opt in via:
 *
 *   Config::set('allow_private_endpoints', true);    -- or --
 *   CASHUPAY_ALLOW_PRIVATE_ENDPOINTS=1 environment variable
 *
 * The opt-in is consulted by the call sites that may legitimately point
 * at private IPs (mint client, onchain provider, and — via the global
 * allow_private_endpoints switch — webhook delivery and our own LNURL
 * clients, see includes/webhook_sender.php). The wallet library's own
 * outbound requests are policed by the same rules through CashuGuard
 * (includes/cashu_guard.php), which registers into \Cashu\ConnectGuard.
 */

require_once __DIR__ . '/config.php';

/**
 * Thrown by SafeHttp::validateUrl() when the host could not be resolved at
 * all (resolver down, NXDOMAIN). Distinct from a policy refusal: callers may
 * treat it as "unreachable" (e.g. the offline-acceptance fallback), never as
 * "the policy allowed it".
 */
class SafeHttpResolveException extends \RuntimeException {}

class SafeHttp {
    public const DEFAULT_TIMEOUT_SEC = 15;
    public const DEFAULT_CONNECT_TIMEOUT_SEC = 5;
    public const DEFAULT_MAX_RESPONSE_BYTES = 5 * 1024 * 1024;

    /**
     * Perform an outbound HTTP request with hardened defaults.
     *
     * Options:
     *   - method:           'GET' (default), 'POST', 'PUT', 'DELETE'
     *   - headers:          string[] (e.g. ['Accept: application/json'])
     *   - body:             string raw request body (POST/PUT)
     *   - timeout:          int seconds, default DEFAULT_TIMEOUT_SEC
     *   - connectTimeout:   int seconds, default DEFAULT_CONNECT_TIMEOUT_SEC
     *   - userAgent:        string, default 'CashuPayServer/1.0'
     *   - allowPrivate:     bool, default false. Override for trusted
     *                       admin-configured destinations (local mint /
     *                       local Bitcoin node / self-call).
     *   - followRedirects:  bool, default false. Off by default because
     *                       a redirect to a private IP is a classic SSRF
     *                       bypass; on-by-default leaks the request.
     *   - maxRedirects:     int, default 3 (only used when following)
     *   - maxBytes:         int max response bytes, default
     *                       DEFAULT_MAX_RESPONSE_BYTES
     *   - verifyTls:        bool, default true
     *
     * Returns:
     *   ['status' => int, 'body' => string, 'error' => string, 'effectiveUrl' => string]
     *
     * On URL-validation failure the request is not made and 'error' is set
     * to a descriptive message. The 'status' is 0 in that case.
     */
    public static function request(string $url, array $opts = []): array {
        $allowPrivate = (bool)($opts['allowPrivate'] ?? false);
        $followRedirects = (bool)($opts['followRedirects'] ?? false);
        $maxRedirects = max(0, (int)($opts['maxRedirects'] ?? 3));

        // We follow redirects MANUALLY (curl's CURLOPT_FOLLOWLOCATION is always
        // off in executeOnce) so that EVERY hop is re-validated against the
        // private-IP policy. curl's own follow would re-resolve a cross-host
        // Location with no isPrivateIp check — a classic SSRF bypass, since the
        // CURLOPT_RESOLVE pin only covers the ORIGINAL host. By looping here and
        // calling validateUrl() on each Location, a redirect to 169.254.169.254
        // (or any private/reserved IP) is rejected just like the initial URL.
        $currentUrl = $url;
        $redirectsLeft = $followRedirects ? $maxRedirects : 0;

        while (true) {
            try {
                $validated = self::validateUrl($currentUrl, $allowPrivate);
            } catch (\Throwable $e) {
                return ['status' => 0, 'body' => '', 'error' => $e->getMessage(), 'effectiveUrl' => $currentUrl];
            }

            $result = self::executeOnce($currentUrl, $validated, $opts);

            $status = $result['status'];
            $location = $result['_redirectLocation'] ?? '';
            unset($result['_redirectLocation']);

            // Not a redirect (or we're not following / out of hops): return.
            if ($redirectsLeft <= 0 || $status < 300 || $status >= 400 || $location === '') {
                return $result;
            }

            $next = self::resolveRedirectTarget($currentUrl, $location);
            if ($next === null) {
                return $result; // unparseable Location -> hand back the 3xx as-is
            }
            $currentUrl = $next;
            $redirectsLeft--;
            // Loop: the next iteration validates $currentUrl (private-IP check)
            // before connecting.
        }
    }

    /**
     * Perform a SINGLE HTTP request (no redirect following) against an already
     * validated URL. Returns the same shape as request() plus a private
     * '_redirectLocation' key carrying the raw Location header (empty when the
     * response was not a redirect), which request() uses to drive its manual,
     * re-validating redirect loop.
     */
    private static function executeOnce(string $url, array $validated, array $opts): array {
        $ch = curl_init($validated['url']);
        if ($ch === false) {
            return ['status' => 0, 'body' => '', 'error' => 'curl_init failed', 'effectiveUrl' => $url, '_redirectLocation' => ''];
        }

        $method = strtoupper((string)($opts['method'] ?? 'GET'));
        $timeout = (int)($opts['timeout'] ?? self::DEFAULT_TIMEOUT_SEC);
        $connectTimeout = (int)($opts['connectTimeout'] ?? self::DEFAULT_CONNECT_TIMEOUT_SEC);
        $userAgent = (string)($opts['userAgent'] ?? 'CashuPayServer/1.0');
        $maxBytes = (int)($opts['maxBytes'] ?? self::DEFAULT_MAX_RESPONSE_BYTES);
        $verifyTls = (bool)($opts['verifyTls'] ?? true);
        $headers = $opts['headers'] ?? [];

        $curlOpts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_USERAGENT => $userAgent,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            // Always off: redirects are followed by request() so each hop is
            // re-validated. Letting curl follow would skip the private-IP check.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => $verifyTls,
            CURLOPT_SSL_VERIFYHOST => $verifyTls ? 2 : 0,
            CURLOPT_NOSIGNAL => 1,
        ];

        // Pin curl to the validated IP so a re-resolve can't swap to a private
        // address between our check and the connect.
        if (!empty($validated['resolve'])) {
            $curlOpts[CURLOPT_RESOLVE] = $validated['resolve'];
        }

        if (!empty($headers)) {
            $curlOpts[CURLOPT_HTTPHEADER] = $headers;
        }

        switch ($method) {
            case 'GET':
                break;
            case 'POST':
                $curlOpts[CURLOPT_POST] = true;
                if (isset($opts['body'])) {
                    $curlOpts[CURLOPT_POSTFIELDS] = (string)$opts['body'];
                }
                break;
            default:
                $curlOpts[CURLOPT_CUSTOMREQUEST] = $method;
                if (isset($opts['body'])) {
                    $curlOpts[CURLOPT_POSTFIELDS] = (string)$opts['body'];
                }
                break;
        }

        // Capture the Location header (last one wins) so request() can follow
        // it after re-validation. Case-insensitive per RFC 7230.
        $redirectLocation = '';
        $curlOpts[CURLOPT_HEADERFUNCTION] = function ($_ch, string $header) use (&$redirectLocation) {
            $len = strlen($header);
            $pos = strpos($header, ':');
            if ($pos !== false && strcasecmp(substr($header, 0, $pos), 'Location') === 0) {
                $redirectLocation = trim(substr($header, $pos + 1));
            }
            return $len;
        };

        // Enforce response size cap. Bail mid-stream as soon as the
        // accumulated body exceeds $maxBytes so a 500 MB hostile response
        // can't pin a PHP-FPM worker's memory.
        $bodyBuf = '';
        $curlOpts[CURLOPT_WRITEFUNCTION] = function ($_ch, string $chunk) use (&$bodyBuf, $maxBytes) {
            $len = strlen($chunk);
            if (strlen($bodyBuf) + $len > $maxBytes) {
                return 0; // abort transfer
            }
            $bodyBuf .= $chunk;
            return $len;
        };

        curl_setopt_array($ch, $curlOpts);

        $execOk = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $effectiveUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $err = (string)curl_error($ch);
        curl_close($ch);

        $error = '';
        if ($execOk === false && $err !== '') {
            // Distinguish "we aborted because of size cap" from a real
            // network error: when the write function returns 0, curl
            // reports CURLE_WRITE_ERROR.
            $error = $err;
        }

        return [
            'status' => $status,
            'body' => $bodyBuf,
            'error' => $error,
            'effectiveUrl' => $effectiveUrl !== '' ? $effectiveUrl : $url,
            '_redirectLocation' => $redirectLocation,
        ];
    }

    /**
     * Resolve a (possibly relative) Location header against the URL it came
     * from, per the common cases of RFC 3986 reference resolution: absolute
     * URLs are returned as-is; protocol-relative (//host/path), absolute-path
     * (/path) and relative-path references are resolved against the base.
     * Returns null when the result can't be formed.
     */
    public static function resolveRedirectTarget(string $baseUrl, string $location): ?string {
        $location = trim($location);
        if ($location === '') {
            return null;
        }
        $base = parse_url($baseUrl);
        if ($base === false || empty($base['scheme']) || empty($base['host'])) {
            return null;
        }

        $loc = parse_url($location);
        if ($loc === false) {
            return null;
        }

        // Absolute URL with its own scheme: use as-is.
        if (!empty($loc['scheme'])) {
            return $location;
        }

        $scheme = $base['scheme'];
        $authority = $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '');

        // Protocol-relative: //host/path
        if (strpos($location, '//') === 0) {
            return $scheme . ':' . $location;
        }

        // Authority inherited from base; resolve the path.
        if (isset($location[0]) && $location[0] === '/') {
            // Absolute path on the same authority.
            return $scheme . '://' . $authority . $location;
        }

        // Relative path: resolve against the base path's directory.
        $basePath = $base['path'] ?? '/';
        $dir = substr($basePath, 0, strrpos($basePath, '/') + 1);
        if ($dir === '') {
            $dir = '/';
        }
        return $scheme . '://' . $authority . $dir . $location;
    }

    /**
     * Validate a URL and resolve its host to an IP. Returns:
     *   ['url' => $url, 'resolve' => ['host:port:ip']]
     *
     * The 'resolve' entry is meant for CURLOPT_RESOLVE so curl will skip
     * its own DNS lookup and use our validated IP. Throws on rejection.
     */
    public static function validateUrl(string $url, bool $allowPrivate = false): array {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            throw new \RuntimeException('Invalid URL');
        }

        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new \RuntimeException("Disallowed scheme '{$scheme}'");
        }

        // Userinfo is refused outright: `https://user:pw@host/` is how a
        // credential-bearing URL ends up in logs, and libcurl and parse_url
        // can disagree on where the authority ends when '@' is in play.
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \RuntimeException('URL must not contain credentials');
        }

        $host = $parts['host'];
        // Strip IPv6 brackets if present
        $hostForResolve = $host;
        if (strlen($host) >= 2 && $host[0] === '[' && substr($host, -1) === ']') {
            $hostForResolve = substr($host, 1, -1);
        }

        // The CURLOPT_RESOLVE pin is keyed on the host string we hand curl.
        // A host with a trailing dot, a percent escape, a backslash or
        // non-ASCII would be normalised/decoded by libcurl to a DIFFERENT
        // string, the pin would silently not apply, and curl would do its
        // own (unchecked) lookup. Only plain LDH hosts and IP literals get
        // through, so the pin always matches.
        if (!self::isPlainHostSyntax($hostForResolve)) {
            throw new \RuntimeException("Invalid host '{$hostForResolve}'");
        }

        $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        // Resolve host -> IPs. If host is already an IP literal, skip DNS.
        $ips = [];
        if (filter_var($hostForResolve, FILTER_VALIDATE_IP)) {
            $ips = [$hostForResolve];
        } else {
            $ips = self::resolveHost($hostForResolve);
            if (empty($ips)) {
                throw new SafeHttpResolveException("DNS resolution failed for '{$hostForResolve}'");
            }
        }

        // If any resolved IP is private, reject (defense against multi-A
        // DNS rebinding tricks where the first IP is public and a later
        // one is private).
        if (!$allowPrivate) {
            foreach ($ips as $ip) {
                if (self::isPrivateIp($ip)) {
                    throw new \RuntimeException("Host '{$hostForResolve}' resolves to a private/reserved IP ({$ip})");
                }
            }
        }

        // Pin to the first resolved IP. curl_setopt(CURLOPT_RESOLVE)
        // format is "host:port:ip".
        $resolveLines = [$hostForResolve . ':' . $port . ':' . $ips[0]];

        return [
            'url' => $url,
            'resolve' => $resolveLines,
            'ips' => $ips,
            'host' => $hostForResolve,
            'port' => $port,
        ];
    }

    /**
     * Address blocks that are never a legitimate unsolicited-HTTP target.
     *
     * PHP's FILTER_FLAG_NO_PRIV_RANGE / NO_RES_RANGE cover only a few
     * blocks: carrier-grade NAT (100.64.0.0/10 — the address space of many
     * VPS/container networks and Tailscale), the benchmarking range,
     * multicast, 192.0.0.0/24 and the IPv6 transition prefixes that embed an
     * IPv4 address (NAT64, 6to4, Teredo, SIIT) all pass them. The explicit
     * table closes those. IPv4-mapped addresses (::ffff:a.b.c.d) are
     * normalised to IPv4 first so the IPv4 rows apply.
     */
    private const NON_PUBLIC_RANGES = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
        '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16',
        '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
        '::/128', '::1/128', '64:ff9b::/96', '64:ff9b:1::/48', '100::/64', '2001::/23',
        '2001:db8::/32', '2002::/16', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8',
        '::ffff:0:0:0/96', // SIIT IPv4-translated (RFC 6145): embeds an IPv4 address
    ];

    /**
     * Returns true if the IP address falls in a range we never want to
     * send unsolicited HTTP to: RFC1918, loopback, link-local, multicast,
     * reserved, carrier-grade NAT, benchmarking, and the IPv6 transition
     * prefixes (NAT64 / 6to4 / Teredo / SIIT) that embed an IPv4 address.
     *
     * PHP's FILTER_VALIDATE_IP flags are the first pass; the explicit CIDR
     * table in NON_PUBLIC_RANGES covers what they miss.
     */
    public static function isPrivateIp(string $ip): bool {
        $ip = self::normalizeIp($ip);
        if ($ip === null) {
            return true; // unparsable -> refuse
        }
        // FILTER_FLAG_NO_PRIV_RANGE blocks 10/8, 172.16/12, 192.168/16, fc00::/7
        // FILTER_FLAG_NO_RES_RANGE blocks 0/8, 127/8, 169.254/16, 192.0.2/24,
        //   240/4, ::1, fe80::/10, etc.
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return true;
        }
        foreach (self::NON_PUBLIC_RANGES as $range) {
            if (self::ipInRange($ip, $range)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Canonical textual form of an IP literal, with IPv4-mapped IPv6
     * (::ffff:a.b.c.d) unwrapped to the IPv4 it embeds. Null when not an IP.
     */
    public static function normalizeIp(string $ip): ?string {
        $ip = trim($ip, " \t[]");
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return null;
        }
        if (strlen($bin) === 16 && substr($bin, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            return inet_ntop(substr($bin, 12));
        }
        return inet_ntop($bin);
    }

    /**
     * Whether an address falls in a range given as CIDR ("10.0.0.0/8",
     * "64:ff9b::/96") or as a single address. Mismatched families never match.
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
        // The range's network address is compared in its own family: do NOT
        // unwrap an IPv4-mapped network ('::ffff:0:0:0/96' is an IPv6 row).
        $netBin = @inet_pton(trim($range, " \t[]"));
        $ipBin = @inet_pton($ip);
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
            return false;
        }
        $max = strlen($ipBin) * 8;
        $bits = $bits ?? $max;
        if ($bits < 0 || $bits > $max) {
            return false;
        }
        $fullBytes = intdiv($bits, 8);
        if ($fullBytes > 0 && strncmp($ipBin, $netBin, $fullBytes) !== 0) {
            return false;
        }
        $remBits = $bits % 8;
        if ($remBits === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $remBits)) & 0xFF;
        return (ord($ipBin[$fullBytes]) & $mask) === (ord($netBin[$fullBytes]) & $mask);
    }

    /**
     * Plain host syntax: an IPv4/IPv6 literal, or LDH labels only (letters,
     * digits, hyphen; '_' tolerated for Docker/Umbrel service names) — no
     * empty label, no trailing dot, no percent escape, no non-ASCII, and a
     * last label that is not numeric (URL parsers would read that as an IPv4
     * address and libcurl would disagree with our pin).
     */
    public static function isPlainHostSyntax(string $host): bool {
        if ($host === '') {
            return false;
        }
        if (str_starts_with($host, '[')) {
            return str_ends_with($host, ']')
                && filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }
        if (strlen($host) > 253) {
            return false;
        }
        $labels = explode('.', $host);
        foreach ($labels as $label) {
            if (!preg_match('/^[A-Za-z0-9_](?:[A-Za-z0-9_-]{0,61}[A-Za-z0-9_])?$/D', $label)) {
                return false;
            }
        }
        return !preg_match('/^(?:[0-9]+|0x[0-9a-f]*)$/iD', (string)end($labels));
    }

    /**
     * Test hook: replaces DNS resolution (callable(string $host): string[]).
     * Lets tests exercise the resolve-then-pin path without a network.
     */
    public static $resolverForTesting = null;

    /**
     * Resolve A and AAAA records for a hostname.
     *
     * @return string[] list of IP literals
     */
    private static function resolveHost(string $host): array {
        if (self::$resolverForTesting !== null) {
            $out = [];
            foreach ((array)call_user_func(self::$resolverForTesting, $host) as $ip) {
                if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP)) {
                    $out[] = $ip;
                }
            }
            return array_values(array_unique($out));
        }

        $ips = [];

        // gethostbynamel returns IPv4 A records as an array
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            foreach ($v4 as $ip) {
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $ips[] = $ip;
                }
            }
        }

        // dns_get_record for AAAA
        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $r) {
                    if (!empty($r['ipv6']) && filter_var($r['ipv6'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                        $ips[] = $r['ipv6'];
                    }
                }
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * Returns true if the operator has opted in to allowing private/
     * loopback destinations (for self-hosted mints or local Bitcoin nodes).
     *
     * Config value wins; env var is a fallback for ephemeral test rigs
     * (tests/scripts/iterate.py, CI) that don't have a persisted config.
     */
    public static function privateEndpointsAllowed(): bool {
        $cfg = Config::get('allow_private_endpoints', null);
        if ($cfg !== null) {
            return (bool)$cfg;
        }
        $env = getenv('CASHUPAY_ALLOW_PRIVATE_ENDPOINTS');
        if ($env === false || $env === '') {
            return false;
        }
        return in_array(strtolower($env), ['1', 'true', 'yes', 'on'], true);
    }
}

// Wire the same policy into the wallet library's outbound requests. Done
// here (and in security.php, which every entry point loads) so no entry
// point that uses the library can run without the guard.
require_once __DIR__ . '/cashu_guard.php';
CashuGuard::install();
