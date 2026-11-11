<?php
/**
 * CashuPayServer - Trusted Mints Module
 *
 * Pulls a JSON-formatted list of trusted mints from a configured URL and
 * applies it to all stores. Two effects:
 *
 *   - `mints[].url` entries that aren't already configured for a store get
 *     added as backup mints (priority just below existing backups).
 *   - `mints[].disabled: true` entries get the trusted_list_disabled flag
 *     in mint_reliability so they're filtered out of new-invoice selection
 *     for every store.
 *
 * The primary mint for a store is left alone unless primary_mint_source is
 * 'setup' (untouched at install) — in which case the first non-disabled
 * trusted mint becomes the primary (tagged 'trusted_list' so a later refresh
 * can replace it).
 *
 * Configuration:
 *   - URL stored in config.trusted_mints_url, overridden by env
 *     CASHUPAY_TRUSTED_MINTS_URL when set.
 *   - Refresh interval (minutes) in config.trusted_mints_refresh_minutes,
 *     overridden by env CASHUPAY_TRUSTED_MINTS_REFRESH_MINUTES. Default 1440.
 *   - Last fetch timestamp + cached JSON live alongside the SQLite DB in
 *     data/trusted_mints.json so a failed refresh never wipes the prior list.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mint_reliability.php';
require_once __DIR__ . '/safe_http.php';

class TrustedMints {
    const DEFAULT_REFRESH_MINUTES = 1440; // 24h
    const FETCH_TIMEOUT_SEC = 15;
    const CACHE_FILENAME = 'trusted_mints.json';
    const CONFIG_URL_KEY = 'trusted_mints_url';
    const CONFIG_INTERVAL_KEY = 'trusted_mints_refresh_minutes';
    const CONFIG_LAST_FETCH_KEY = 'trusted_mints_last_fetch_at';
    const CONFIG_LAST_ERROR_KEY = 'trusted_mints_last_error';
    const CONFIG_LAST_OK_KEY = 'trusted_mints_last_ok_at';

    /** Configured URL, env override taking precedence over the DB value. */
    public static function getUrl(): ?string {
        $env = getenv('CASHUPAY_TRUSTED_MINTS_URL');
        if (is_string($env) && trim($env) !== '') {
            return trim($env);
        }
        $stored = Config::get(self::CONFIG_URL_KEY, null);
        return is_string($stored) && trim($stored) !== '' ? trim($stored) : null;
    }

    /** True iff the env var is setting the URL (so the admin UI knows the DB value is shadowed). */
    public static function isUrlFromEnv(): bool {
        $env = getenv('CASHUPAY_TRUSTED_MINTS_URL');
        return is_string($env) && trim($env) !== '';
    }

    public static function getRefreshMinutes(): int {
        $env = getenv('CASHUPAY_TRUSTED_MINTS_REFRESH_MINUTES');
        if (is_string($env) && ctype_digit($env) && (int)$env > 0) {
            return (int)$env;
        }
        $stored = Config::get(self::CONFIG_INTERVAL_KEY, null);
        if (is_numeric($stored) && (int)$stored > 0) {
            return (int)$stored;
        }
        return self::DEFAULT_REFRESH_MINUTES;
    }

    public static function isRefreshIntervalFromEnv(): bool {
        $env = getenv('CASHUPAY_TRUSTED_MINTS_REFRESH_MINUTES');
        return is_string($env) && ctype_digit($env) && (int)$env > 0;
    }

    public static function getCachePath(): string {
        return Database::getDataDir() . '/' . self::CACHE_FILENAME;
    }

    /** Cached parsed JSON, or null if no cache exists. */
    public static function getCachedList(): ?array {
        $path = self::getCachePath();
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }
        return self::validate($decoded) ? $decoded : null;
    }

    public static function getLastFetchAt(): ?int {
        $v = Config::get(self::CONFIG_LAST_FETCH_KEY, null);
        return is_numeric($v) ? (int)$v : null;
    }

    public static function getLastOkAt(): ?int {
        $v = Config::get(self::CONFIG_LAST_OK_KEY, null);
        return is_numeric($v) ? (int)$v : null;
    }

    public static function getLastError(): ?string {
        $v = Config::get(self::CONFIG_LAST_ERROR_KEY, null);
        return is_string($v) && $v !== '' ? $v : null;
    }

    /**
     * Refresh the trusted list if either $force is true or enough time has
     * elapsed since the last refresh. Returns true if a refresh happened (the
     * cache file may or may not have changed). On any fetch/parse failure the
     * prior cache is kept and an error is recorded.
     */
    public static function refresh(bool $force = false): bool {
        $url = self::getUrl();
        if ($url === null) {
            return false;
        }
        if (!$force) {
            $last = self::getLastFetchAt();
            $intervalSec = self::getRefreshMinutes() * 60;
            if ($last !== null && (Database::timestamp() - $last) < $intervalSec) {
                return false;
            }
        }
        Config::set(self::CONFIG_LAST_FETCH_KEY, Database::timestamp());

        try {
            $raw = self::fetchUrl($url);
            $decoded = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException('Invalid JSON: ' . json_last_error_msg());
            }
            if (!self::validate($decoded)) {
                throw new \RuntimeException('Trusted list failed schema validation');
            }
        } catch (\Throwable $e) {
            Config::set(self::CONFIG_LAST_ERROR_KEY, $e->getMessage());
            error_log('TrustedMints refresh failed: ' . $e->getMessage());
            return true;
        }

        // Write atomically so a partial write never corrupts the cache.
        $path = self::getCachePath();
        $tmp = $path . '.tmp';
        if (file_put_contents($tmp, json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
            Config::set(self::CONFIG_LAST_ERROR_KEY, 'Failed to write cache file: ' . $tmp);
            return true;
        }
        rename($tmp, $path);

        Config::delete(self::CONFIG_LAST_ERROR_KEY);
        Config::set(self::CONFIG_LAST_OK_KEY, Database::timestamp());
        return true;
    }

    /**
     * Apply the cached trusted list to every store. Idempotent: re-running
     * with no list changes makes no DB writes beyond the reliability rows that
     * already exist.
     */
    public static function applyToAllStores(): void {
        $list = self::getCachedList();
        if ($list === null) {
            return;
        }
        $stores = Database::fetchAll("SELECT id FROM stores");
        foreach ($stores as $row) {
            self::applyToStore($row['id'], $list);
        }
        self::applyGlobalDisableFlags($list);
    }

    /** Apply the list to a single (typically just-created) store. */
    public static function applyToNewStore(string $storeId): void {
        $list = self::getCachedList();
        if ($list === null) {
            return;
        }
        self::applyToStore($storeId, $list);
        self::applyGlobalDisableFlags($list);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private static function fetchUrl(string $url): string {
        $result = \SafeHttp::request($url, [
            'method' => 'GET',
            'timeout' => self::FETCH_TIMEOUT_SEC,
            'connectTimeout' => 5,
            'userAgent' => 'CashuPayServer/TrustedMints',
            'headers' => ['Accept: application/json'],
            'allowPrivate' => false,
            'followRedirects' => true,
            'maxRedirects' => 3,
        ]);
        if ($result['error'] !== '') {
            throw new \RuntimeException('HTTP fetch failed: ' . $result['error']);
        }
        $code = $result['status'];
        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException("HTTP $code from trusted list URL");
        }
        return $result['body'];
    }

    /**
     * Minimal schema validation. Accepts { mints: [{url, disabled?, reason?}] }.
     * `version` is optional; we ignore it for now but reject if it's a value we
     * don't understand (forward-compat).
     */
    private static function validate($decoded): bool {
        if (!is_array($decoded) || !isset($decoded['mints']) || !is_array($decoded['mints'])) {
            return false;
        }
        if (isset($decoded['version']) && !in_array($decoded['version'], [1, '1'], true)) {
            return false;
        }
        foreach ($decoded['mints'] as $entry) {
            if (!is_array($entry)) {
                return false;
            }
            if (!isset($entry['url']) || !is_string($entry['url']) || trim($entry['url']) === '') {
                return false;
            }
            if (!preg_match('#^https?://#i', $entry['url'])) {
                return false;
            }
            if (isset($entry['disabled']) && !is_bool($entry['disabled'])) {
                return false;
            }
        }
        return true;
    }

    private static function applyToStore(string $storeId, array $list): void {
        $store = Config::getStore($storeId);
        if ($store === null) {
            return;
        }
        $storeUnit = $store['mint_unit'] ?? 'sat';
        $source = $store['primary_mint_source'] ?? 'manual';

        $existingBackups = Database::fetchAll(
            "SELECT mint_url FROM store_mints WHERE store_id = ?",
            [$storeId]
        );
        $existingSet = [];
        foreach ($existingBackups as $row) {
            $existingSet[rtrim($row['mint_url'], '/')] = true;
        }

        $maxPriority = (int)(Database::fetchOne(
            "SELECT COALESCE(MAX(priority), 0) AS p FROM store_mints WHERE store_id = ?",
            [$storeId]
        )['p'] ?? 0);

        $enabledTrusted = [];
        foreach ($list['mints'] as $entry) {
            $url = rtrim($entry['url'], '/');
            $disabled = !empty($entry['disabled']);
            if (!$disabled) {
                $enabledTrusted[] = $url;
            }
        }

        // Primary-mint handling: only auto-populate if it was never set (source
        // == 'setup' and mint_url is empty). 'manual' and 'trusted_list' both
        // leave the primary alone — that's the admin's choice, or a previously
        // applied trusted-list value that we don't churn on refresh.
        if (empty($store['mint_url']) && $source === 'setup' && !empty($enabledTrusted)) {
            self::setStorePrimaryFromTrustedList($storeId, $enabledTrusted[0], $storeUnit);
            // Mark existing list head so we don't also re-add it as a backup.
            $existingSet[rtrim($enabledTrusted[0], '/')] = true;
        }

        $primaryNormalized = $store['mint_url'] ? rtrim($store['mint_url'], '/') : null;
        foreach ($enabledTrusted as $url) {
            if (isset($existingSet[$url])) {
                continue;
            }
            if ($primaryNormalized !== null && $url === $primaryNormalized) {
                continue;
            }
            $maxPriority += 10;
            Config::addStoreBackupMint($storeId, $url, $storeUnit, $maxPriority);
            $existingSet[$url] = true;
        }
    }

    /**
     * Set or clear the trusted_list_disabled flag on every mint mentioned in
     * the list. Mints with `disabled: true` get the flag; mints with the flag
     * set but no longer disabled (or no longer present) get it cleared.
     */
    private static function applyGlobalDisableFlags(array $list): void {
        $shouldBeDisabled = [];
        foreach ($list['mints'] as $entry) {
            if (!empty($entry['disabled'])) {
                $url = rtrim($entry['url'], '/');
                $shouldBeDisabled[$url] = $entry['reason'] ?? null;
            }
        }

        foreach ($shouldBeDisabled as $url => $reason) {
            MintReliability::setTrustedListDisabled($url, $reason);
        }

        // Clear stale flags: mints that were previously trusted-list-disabled
        // but no longer appear with disabled=true.
        $currentlyFlagged = Database::fetchAll(
            "SELECT mint_url FROM mint_reliability WHERE trusted_list_disabled = 1"
        );
        foreach ($currentlyFlagged as $row) {
            if (!isset($shouldBeDisabled[rtrim($row['mint_url'], '/')])) {
                MintReliability::clearTrustedListDisabled($row['mint_url']);
            }
        }
    }

    private static function setStorePrimaryFromTrustedList(string $storeId, string $mintUrl, string $unit): void {
        Config::updateStore($storeId, [
            'mint_url' => rtrim($mintUrl, '/'),
            'primary_mint_source' => 'trusted_list',
            'mint_unit' => $unit,
        ]);
    }
}
