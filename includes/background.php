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

    /**
     * Trigger background processing without blocking the current request.
     *
     * Fires a non-blocking self-request to cron.php. Uses a short timeout (100ms) so
     * the calling request doesn't wait, and is coalesced so a busy checkout does not
     * start a full cron run per poll.
     */
    public static function trigger(): void {
        // WooCommerce polls invoice status every few seconds during checkout, and each
        // poll used to fire a full cron run. Coalesce them.
        $last = (int)Config::get('last_background_trigger', '0');
        if (time() - $last < self::TRIGGER_INTERVAL) {
            return;
        }
        Config::set('last_background_trigger', (string)time());

        $url = self::selfCronUrl();
        if ($url === null) {
            // No trusted origin to call. Without one, a poisoned Host header would send
            // the internal key to whatever host the attacker named.
            return;
        }

        // Fire-and-forget curl (100ms timeout - enough for localhost self-request)
        $ch = curl_init($url . '?internal=1&key=' . urlencode(self::getInternalKey()));
        curl_setopt_array($ch, [
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
            CURLOPT_PROTOCOLS_STR => 'https,http',
        ]);
        @curl_exec($ch);
        // Note: curl_close() is a no-op since PHP 8.0, handle is auto-closed
    }

    /**
     * The cron URL to call with the internal key, or null when no origin is trusted.
     *
     * Only a configured `base_url` (or WordPress's own `site_url()`) counts. Deriving it
     * from HTTP_HOST means an unauthenticated request with a forged Host header can make
     * this server post its internal credential to the attacker's origin.
     */
    private static function selfCronUrl(): ?string {
        if (Urls::isWordPress()) {
            return Urls::cron();
        }
        if (!Config::get('base_url')) {
            return null;
        }
        return Urls::cron();
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
