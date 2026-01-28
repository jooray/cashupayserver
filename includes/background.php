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
     */
    public static function trigger(): void {
        $url = Urls::cron() . '?internal=1&key=' . urlencode(self::getInternalKey());

        // Fire-and-forget curl (100ms timeout - enough for localhost self-request)
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => 100,
            CURLOPT_NOSIGNAL => 1,
            CURLOPT_SSL_VERIFYPEER => false, // For local development
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        @curl_exec($ch);
        // Note: curl_close() is a no-op since PHP 8.0, handle is auto-closed
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
