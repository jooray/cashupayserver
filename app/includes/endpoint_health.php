<?php
/**
 * CashuPayServer - Endpoint failure cooldown tracker.
 *
 * Shared by the exchange-rate providers and the Esplora blockchain provider:
 * after an endpoint fails (network error, HTTP error, malformed body), callers
 * record a short cooldown so subsequent requests skip the dead endpoint instead
 * of each paying a full connect/read timeout. This keeps checkout fast during a
 * provider outage — only the first request eats the timeout; later requests go
 * straight to the next provider or the cached data.
 *
 * Callers must treat a cooldown as an ORDERING hint, never a hard block: when
 * every endpoint in a set is cooling down and no cached data is available, try
 * them all anyway (a request that might succeed always beats a guaranteed
 * failure). Both call sites implement that all-cooled fallback.
 *
 * State lives in the config table (like the rate cache). Every method swallows
 * storage errors: health tracking must never break the request it is guarding.
 */

require_once __DIR__ . '/config.php';

final class EndpointHealth {
    /** Seconds an endpoint is skipped after a recorded failure. */
    public const DEFAULT_COOLDOWN_SEC = 120;

    private static function key(string $endpoint): string {
        // Config keys stay readable for operator debugging; collapse anything
        // outside a safe charset (URLs contain ':' and '/').
        return 'endpoint_health_' . preg_replace('/[^a-zA-Z0-9._-]+/', '_', $endpoint);
    }

    /**
     * True while $endpoint is inside a failure cooldown window.
     */
    public static function isCoolingDown(string $endpoint): bool {
        try {
            $data = Config::get(self::key($endpoint));
            return is_array($data) && time() < (int)($data['until'] ?? 0);
        } catch (\Throwable $_) {
            return false;
        }
    }

    /**
     * Record a failure: skip this endpoint for $cooldownSec seconds.
     */
    public static function recordFailure(string $endpoint, int $cooldownSec = self::DEFAULT_COOLDOWN_SEC): void {
        try {
            Config::set(self::key($endpoint), [
                'failed_at' => time(),
                'until' => time() + max(1, $cooldownSec),
            ]);
        } catch (\Throwable $e) {
            error_log("EndpointHealth::recordFailure({$endpoint}): " . $e->getMessage());
        }
    }

    /**
     * Record a success: clear any active cooldown. Reads before writing so the
     * common all-healthy path costs no DB write.
     */
    public static function recordSuccess(string $endpoint): void {
        try {
            if (Config::get(self::key($endpoint)) !== null) {
                Config::delete(self::key($endpoint));
            }
        } catch (\Throwable $e) {
            error_log("EndpointHealth::recordSuccess({$endpoint}): " . $e->getMessage());
        }
    }
}
