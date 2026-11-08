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
    /** Shortest interval between two API-triggered background runs. */
    const TRIGGER_INTERVAL = 30;
    /** Shortest interval between two urgent runs (a payment just settled). */
    const URGENT_TRIGGER_INTERVAL = 3;
    /** Budget of a run done in the visitor's request after the response was sent. */
    const IN_PROCESS_BUDGET = 20;

    /** One after-response run per request, however many triggers fire in it. */
    private static bool $shutdownRegistered = false;

    /** Whether this SAPI can send the response and keep running. */
    private static function canFinishResponse(): bool {
        // WordPress has WP-Cron and its own shutdown handlers; finishing its response
        // early from here could cut those short. Leave it to the self-request path.
        if (defined('CASHUPAY_NO_IN_PROCESS_BACKGROUND') || Urls::isWordPress()) {
            return false;
        }
        return function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request');
    }

    /** Shutdown handler: send the response, then run the background tasks. */
    public static function runAfterResponse(): void {
        try {
            // Release the session lock first, or the visitor's next request (the page's
            // next status poll) would wait for this run.
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                litespeed_finish_request();
            }
            ignore_user_abort(true);
            @set_time_limit(self::IN_PROCESS_BUDGET + 40);
            require_once __DIR__ . '/background_runner.php';
            BackgroundRunner::run(self::IN_PROCESS_BUDGET);
        } catch (Throwable $e) {
            error_log('CashuPayServer: background run after response failed: ' . $e->getMessage());
        }
    }

    /**
     * Trigger background processing without blocking the current request.
     *
     * Fires a non-blocking self-request to cron.php. Uses a short timeout (100ms) so
     * the calling request doesn't wait, and is coalesced so a busy checkout does not
     * start a full cron run per poll.
     */
    public static function trigger(bool $urgent = false): void {
        // WooCommerce polls invoice status every few seconds during checkout, and each
        // poll used to fire a full cron run. Coalesce them. An urgent trigger (a payment
        // just settled, so the shop is waiting for its webhook) only waits a few seconds:
        // the 30 s window usually swallowed it, and on a quiet shop the webhook then sat
        // until the next visitor, hours later (R3, 2026-10-28).
        $key = $urgent ? 'last_urgent_background_trigger' : 'last_background_trigger';
        $last = (int)Config::get($key, '0');
        if (time() - $last < ($urgent ? self::URGENT_TRIGGER_INTERVAL : self::TRIGGER_INTERVAL)) {
            return;
        }
        Config::set($key, (string)time());
        Config::set('last_background_trigger', (string)time());

        // Where the server can finish the response and keep working (PHP-FPM, LiteSpeed),
        // run the tasks right here after the visitor has their page. The self-request
        // below gives up after 100 ms, which a cold TLS handshake to the site's own name
        // often exceeds, and some hosts block such requests altogether; then nothing in
        // the background ever ran (R4, 2026-10-28).
        if (self::canFinishResponse()) {
            // A normal and an urgent trigger in one request (each past its own
            // coalescing window) used to register two full runs back to back.
            if (!self::$shutdownRegistered) {
                self::$shutdownRegistered = true;
                register_shutdown_function([self::class, 'runAfterResponse']);
            }
            return;
        }

        $url = self::selfCronUrl();
        if ($url === null) {
            // No trusted origin to call. Without one, a poisoned Host header would send
            // the internal key to whatever host the attacker named.
            return;
        }

        // Fire-and-forget curl (100ms timeout - enough for localhost self-request)
        // The key travels in a header, not the query string: URLs end up in web server
        // access logs, and anyone who can read those could then trigger runs at will.
        $ch = curl_init($url . '?internal=1');
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ['X-Cron-Key: ' . self::getInternalKey()],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => 100,
            CURLOPT_NOSIGNAL => 1,
            // Verify TLS unless the target is loopback, where a self-signed development
            // certificate is normal and there is no network to intercept.
            CURLOPT_SSL_VERIFYPEER => !self::isLoopbackUrl($url),
            CURLOPT_SSL_VERIFYHOST => self::isLoopbackUrl($url) ? 0 : 2,
            // Do NOT follow redirects: this request carries the internal key, and a
            // redirect could leak it to another host. See FABLE-SECURITY-AUDIT (HIGH-5).
            CURLOPT_FOLLOWLOCATION => false,
        ] + cashupay_curl_protocol_options());
        @curl_exec($ch);
        // Note: curl_close() is a no-op since PHP 8.0, handle is auto-closed
    }

    /**
     * The cron URL to call with the internal key, or null when no origin is trusted.
     *
     * Only a configured `base_url` counts. Deriving it from HTTP_HOST means an
     * unauthenticated request with a forged Host header can make this server post its
     * internal credential to the attacker's origin.
     *
     * In WordPress, site_url() is trusted only while it still matches the base_url
     * pinned at activation: some wp-config.php files build WP_SITEURL from the request's
     * Host header, and then site_url() names whatever host the visitor sent (OPUS N4).
     */
    private static function selfCronUrl(): ?string {
        if (Urls::isWordPress()) {
            return self::sameOrigin(Urls::cron(), (string)Config::get('base_url')) ? Urls::cron() : null;
        }
        if (!Config::get('base_url')) {
            return null;
        }
        return Urls::cron();
    }

    /**
     * Whether two URLs name the same host and explicit port. The scheme is ignored, as
     * in wordpress/bootstrap.php: a site moved from http to https after activation
     * still has its old base_url, and a forged Host header changes the host anyway.
     */
    public static function sameOrigin(string $a, string $b): bool {
        $origin = static function (string $url): ?string {
            $parts = parse_url($url);
            if (!$parts || empty($parts['host']) || empty($parts['scheme'])) {
                return null;
            }
            return strtolower($parts['host']) . ':' . ($parts['port'] ?? '');
        };
        $originA = $origin($a);
        return $originA !== null && $originA === $origin($b);
    }

    private static function isLoopbackUrl(string $url): bool {
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        return $host === 'localhost'
            || $host === '127.0.0.1'
            || $host === '::1'
            || str_ends_with($host, '.localhost');
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
