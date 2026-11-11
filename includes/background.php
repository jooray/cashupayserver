<?php
/**
 * CashuPayServer - Background Task System
 *
 * Non-blocking background task triggering for shared hosting without cron.
 * Background tasks run opportunistically via self-requests.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/urls.php';

class Background {
    /**
     * Trigger background processing without blocking the current request
     *
     * Fires a non-blocking self-request to cron.php to process background tasks.
     * Uses a short timeout (100ms) so the calling request doesn't wait.
     * cron.php itself serialises runs (cron.lock), so overlapping triggers
     * cost one refused request, never a second full pass.
     *
     * The request carries the internal key, so it must only ever reach THIS
     * server: see selfRequest() for how the target is chosen. Redirects are
     * never followed (curl re-sends custom headers to a cross-host Location)
     * and TLS is verified unless the target is loopback.
     */
    public static function trigger(): void {
        $target = self::selfRequest('/cron.php?internal=1');
        if ($target === null) {
            // No origin we can trust: without one a poisoned Host header
            // would send the internal key to whatever host the attacker named.
            return;
        }

        // Internal key travels in a header (not the query string) so it
        // doesn't leak through webserver access logs.
        // Fire-and-forget curl (100ms timeout - enough for localhost self-request)
        $ch = curl_init($target['url']);
        curl_setopt_array($ch, $target['options'] + [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => 100,
            CURLOPT_NOSIGNAL => 1,
            CURLOPT_HTTPHEADER => [
                'X-CRON-KEY: ' . self::getInternalKey(),
            ],
        ]);
        @curl_exec($ch);
        // Note: curl_close() is a no-op since PHP 8.0, handle is auto-closed
    }

    // ========================================================================
    // Self-request origin
    //
    // Several code paths make an HTTP request to this same installation
    // (opportunistic cron, the updater's self-check, the data-directory
    // exposure probe). The naive target — Config::getBaseUrl(), which falls
    // back to the request's Host header — lets an unauthenticated visitor
    // with `Host: attacker.example` make the server connect to attacker.example
    // carrying X-CRON-KEY. The rules below make that impossible:
    //
    //   1. A pinned base URL (CASHUPAY_BASE_URL or the DB `base_url`) is used
    //      verbatim: it is operator configuration, not request data.
    //   2. Otherwise the URL is still built from the request (so paths and
    //      scheme match the deployment), but the connection is pinned via
    //      CURLOPT_RESOLVE to the address this very request arrived on
    //      (SERVER_ADDR:SERVER_PORT), so whatever the Host header says, the
    //      bytes only ever reach our own server. Hosts that fail the plain
    //      `host[:port]` / `[v6]:port` syntax are refused outright, and when
    //      no server address is known the self-request is skipped.
    //   3. Never follow redirects, http/https only, verify TLS off-loopback.
    //
    // Residual: on a shared IP where an attacker owns another vhost, a
    // Host-derived target still lands on that vhost of the same server.
    // Operators in that position should pin `base_url`.
    // ========================================================================

    /**
     * Build a self-request for $pathAndQuery (e.g. '/cron.php?internal=1').
     * Returns ['url' => string, 'options' => array<int,mixed>] (curl options
     * to merge: RESOLVE pin, redirect/protocol/TLS policy), or null when no
     * safe target exists.
     */
    public static function selfRequest(string $pathAndQuery): ?array {
        $pinnedBase = self::pinnedBaseUrl();
        $base = $pinnedBase ?? Config::getBaseUrl();
        $url = rtrim($base, '/') . $pathAndQuery;

        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return null;
        }
        $scheme = strtolower((string)($parts['scheme'] ?? 'http'));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        if ($pinnedBase === null) {
            // Request-derived origin: refuse odd Host syntax and pin the
            // connection to the address:port the request came in on. The
            // port is rewritten too (SERVER_PORT is the socket this request
            // arrived on; the Host header's port is what the client typed).
            $hostHeader = (string)($_SERVER['HTTP_HOST'] ?? '');
            if (!self::isPlainHostHeader($hostHeader)) {
                return null;
            }
            $addr = self::serverAddress();
            if ($addr === null) {
                return null;
            }
            $defaultPort = $scheme === 'https' ? 443 : 80;
            $port = (int)($parts['port'] ?? $defaultPort);
            $serverPort = (int)($_SERVER['SERVER_PORT'] ?? 0);
            if ($serverPort > 0 && $serverPort <= 65535) {
                $port = $serverPort;
            }
            $hostForPin = $parts['host'];
            if (strlen($hostForPin) >= 2 && $hostForPin[0] === '[' && substr($hostForPin, -1) === ']') {
                $hostForPin = substr($hostForPin, 1, -1);
            }
            $authority = $parts['host'] . ($port === $defaultPort ? '' : ':' . $port);
            $url = $scheme . '://' . $authority
                . ($parts['path'] ?? '')
                . (isset($parts['query']) ? '?' . $parts['query'] : '');
            $options = self::baseCurlOptions($url);
            $options[CURLOPT_RESOLVE] = [$hostForPin . ':' . $port . ':' . $addr];
            return ['url' => $url, 'options' => $options];
        }

        return ['url' => $url, 'options' => self::baseCurlOptions($url)];
    }

    /** The operator-pinned base URL, or null when only auto-detection exists. */
    public static function pinnedBaseUrl(): ?string {
        if (defined('CASHUPAY_BASE_URL') && CASHUPAY_BASE_URL !== '') {
            return rtrim((string)CASHUPAY_BASE_URL, '/');
        }
        $db = Config::get('base_url');
        if (is_string($db) && $db !== '') {
            return rtrim($db, '/');
        }
        return null;
    }

    /**
     * Curl policy shared by every self-request: no redirects (the key would
     * be re-sent to the Location target), http/https only, TLS verified
     * unless the target is loopback (self-signed development certificates).
     */
    public static function baseCurlOptions(string $url): array {
        $loopback = self::isLoopbackUrl($url);
        return [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => !$loopback,
            CURLOPT_SSL_VERIFYHOST => $loopback ? 0 : 2,
        ];
    }

    /** `host`, `host:port`, `[v6]` or `[v6]:port` — nothing else. */
    public static function isPlainHostHeader(string $host): bool {
        if ($host === '') {
            return false;
        }
        if (preg_match('/^[A-Za-z0-9.\-]+(:[0-9]{1,5})?$/D', $host)) {
            return true;
        }
        if (preg_match('/^\[([0-9A-Fa-f:.]+)\](:[0-9]{1,5})?$/D', $host, $m)) {
            return filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        }
        return false;
    }

    /**
     * The address this request arrived on. Apache/nginx/FastCGI set
     * SERVER_ADDR; PHP's built-in server does not, but its SERVER_NAME is
     * the bind address (an IP literal, "0.0.0.0" meaning any — loopback then).
     */
    public static function serverAddress(): ?string {
        $addr = trim((string)($_SERVER['SERVER_ADDR'] ?? ''), '[]');
        if ($addr === '') {
            $name = trim((string)($_SERVER['SERVER_NAME'] ?? ''), '[]');
            if (filter_var($name, FILTER_VALIDATE_IP)) {
                $addr = $name;
            }
        }
        if ($addr === '' || !filter_var($addr, FILTER_VALIDATE_IP)) {
            return null;
        }
        if ($addr === '0.0.0.0') {
            return '127.0.0.1';
        }
        if ($addr === '::') {
            return '::1';
        }
        return $addr;
    }

    public static function isLoopbackUrl(string $url): bool {
        $host = strtolower(trim((string)parse_url($url, PHP_URL_HOST), '[]'));
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || $host === '::1') {
            return true;
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return str_starts_with($host, '127.');
        }
        return false;
    }

    /**
     * Get internal key for self-calls (prevents abuse)
     *
     * This key is auto-generated and stored in config.
     * It's used to authenticate internal background requests.
     */
    public static function getInternalKey(): string {
        $key = Config::get('internal_background_key');
        if (!$key) {
            $key = bin2hex(random_bytes(16));
            Config::set('internal_background_key', $key);
        }
        return $key;
    }

    /**
     * Verify an internal request key
     */
    public static function verifyInternalKey(string $providedKey): bool {
        $storedKey = Config::get('internal_background_key');
        if (!$storedKey) {
            return false;
        }
        return hash_equals($storedKey, $providedKey);
    }

    /**
     * Check if proof sync should be performed
     *
     * Returns true if sync hasn't been done in the last 5 minutes.
     */
    public static function shouldSync(): bool {
        $lastSync = Config::get('last_proof_sync', 0);
        return (time() - $lastSync) > 300; // 5 minutes
    }

    // ========================================================================
    // External-cron staleness detection
    //
    // External cron (the operator's `* * * * * curl /cron.php` entry) is
    // optional but recommended — it makes invoice polling, fee settlement,
    // and auto-melt much more responsive than the opportunistic admin /
    // checkout triggers we fall back to. cron.php stamps
    // `last_external_cron_at` on every non-internal invocation; the dashboard
    // checks the gap here. Fresh installs are grandfathered for 24h so
    // operators don't see the warning before they've had a chance to set
    // cron up.
    // ========================================================================

    public const CRON_STALE_THRESHOLD_SECS = 86400;

    // Window during which a recent `last_external_cron_at` stamp is taken as
    // proof that the operator's real cron is wired up and ticking. When an
    // internal (page-load-triggered) self-request lands inside this window,
    // cron.php runs only the latency-sensitive "essential" tasks and lets the
    // real cron pick up the housekeeping next tick. One hour is generous
    // enough that a cron with a long cadence (or a transient miss) doesn't
    // accidentally fall back to running everything on every page load.
    public const EXTERNAL_CRON_FRESH_THRESHOLD_SECS = 3600;

    /**
     * True when `last_external_cron_at` was stamped within the fresh window.
     * Used by cron.php to decide whether to skip non-essential tasks on an
     * internal self-request. Fresh installs (stamp never set) return false so
     * the opportunistic page-load triggers keep doing the full work until the
     * operator's cron proves itself.
     */
    public static function isExternalCronFresh(): bool {
        $lastExternal = (int) Config::get('last_external_cron_at', 0);
        if ($lastExternal === 0) {
            return false;
        }
        return (time() - $lastExternal) < self::EXTERNAL_CRON_FRESH_THRESHOLD_SECS;
    }

    /**
     * Return the cron-staleness warning state for the dashboard, or null
     * if the warning should not be shown (fresh install, recent external
     * cron run, or the operator has dismissed it since the last real run).
     */
    public static function cronStaleWarning(): ?array {
        $now = time();
        $installedAt = (int) Config::get('installed_at', 0);
        if ($installedAt === 0 || ($now - $installedAt) < self::CRON_STALE_THRESHOLD_SECS) {
            return null;
        }

        $lastExternal = (int) Config::get('last_external_cron_at', 0);
        if ($lastExternal > 0 && ($now - $lastExternal) < self::CRON_STALE_THRESHOLD_SECS) {
            return null;
        }

        // Dismissal is sticky only until the next real external cron run —
        // i.e. once a fresh `last_external_cron_at` arrives that is newer
        // than `cron_warning_dismissed_at`, the dismissal is implicitly
        // cleared and the warning can fire again on the next stale window.
        $dismissedAt = (int) Config::get('cron_warning_dismissed_at', 0);
        if ($dismissedAt > 0 && $dismissedAt >= $lastExternal) {
            return null;
        }

        return [
            'lastExternalCronAt' => $lastExternal > 0 ? $lastExternal : null,
            'thresholdSecs' => self::CRON_STALE_THRESHOLD_SECS,
        ];
    }

    /**
     * Record the operator's dismissal of the stale-cron warning. The
     * dismissal expires automatically when the next external cron call
     * lands (see cronStaleWarning()).
     */
    public static function dismissCronWarning(): void {
        Config::set('cron_warning_dismissed_at', time());
    }

    /**
     * Dashboard state for the "data directory is on a network filesystem"
     * warning (Database::networkFilesystemWarning), or null when there is
     * nothing to show. A dismissal is remembered against the evidence that
     * was dismissed — mount type + mount point, or the refused journal mode
     * — so it stays quiet for that setup but fires again if the data
     * directory later moves to a different share.
     */
    public static function networkFsWarning(): ?array {
        $warning = Database::networkFilesystemWarning();
        if ($warning === null) {
            return null;
        }
        $signature = self::networkFsSignature($warning);
        $dismissed = (string) Config::get('network_fs_warning_dismissed', '');
        if ($dismissed !== '' && hash_equals($dismissed, $signature)) {
            return null;
        }
        return $warning;
    }

    /**
     * Record the operator's dismissal of the network-filesystem warning for
     * the currently detected setup (see networkFsWarning()).
     */
    public static function dismissNetworkFsWarning(): void {
        $warning = Database::networkFilesystemWarning();
        if ($warning === null) {
            return;
        }
        Config::set('network_fs_warning_dismissed', self::networkFsSignature($warning));
    }

    private static function networkFsSignature(array $warning): string {
        return implode('|', [
            $warning['reason'],
            (string) ($warning['fsType'] ?? ''),
            (string) ($warning['mountPoint'] ?? ''),
            (string) ($warning['journalMode'] ?? ''),
        ]);
    }

    /**
     * Mark proof sync as completed
     */
    public static function markSynced(): void {
        Config::set('last_proof_sync', time());
    }

    /**
     * Get time since last sync in seconds
     */
    public static function getTimeSinceLastSync(): int {
        $lastSync = Config::get('last_proof_sync', 0);
        return time() - $lastSync;
    }
}
