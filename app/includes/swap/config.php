<?php
/**
 * Per-store configuration helpers for submarine swaps.
 *
 * All swap settings live on the stores table — there is no site-wide layer.
 * (Historic installs stored site defaults in config keys like `swaps_enabled`;
 * those keys are simply ignored now, and legacy tri-state -1 "inherit" rows
 * resolve to the built-in default for each setting.)
 *
 * Store columns:
 *   stores.swaps_enabled                  — 0 off / 1 on (legacy -1 → off)
 *   stores.strict_no_mint_fallback        — 0 allow mint fallback / 1 strict
 *                                           (legacy -1 → allow). The onboarding
 *                                           wizard sets it to 1 when the
 *                                           operator declines cashu mints, so
 *                                           that store never acquires a mint
 *                                           rail even if another store on the
 *                                           same install uses one.
 *   stores.swaps_provider_order           — JSON array of lowercase provider
 *                                           names in preference order; first
 *                                           reachable one wins. NULL → the
 *                                           built-in default ["zeus","boltz"].
 *   stores.swaps_auto_select_cheapest     — 0/1; NULL → on. When on, fetch
 *                                           quotes from every enabled provider
 *                                           in parallel and pick the cheapest
 *                                           when it beats the priority leader
 *                                           by more than the threshold.
 *   stores.swaps_auto_select_threshold_pct — int 1..90; NULL → 10. Percent the
 *                                           cheapest must undercut the priority
 *                                           leader to win.
 *   stores.swaps_minimum_target_sats      — optional UX-guard floor above the
 *                                           provider's own minimum; NULL → none.
 *   stores.swaps_fee_fallback_max_pct     — float; if a prospective swap's
 *                                           total cost exceeds this percent of
 *                                           the invoice amount, fall back to a
 *                                           mint-issued LN invoice. NULL →
 *                                           inherit the config-file constant;
 *                                           0 disables the check.
 *   stores.swaps_fee_fallback_max_sats    — int; same, as an absolute sats cap.
 *
 * Config-file constants (deployment-level, not UI settings):
 *   CASHUPAY_SWAPS_FEE_FALLBACK_MAX_PCT / _MAX_SATS — fee-fallback defaults.
 *   swaps_boltz_regtest_url config key — required for the boltz provider on
 *   regtest networks (dev/test knob, no UI).
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';

final class SwapsConfig {
    /** Legacy tri-state sentinel still present in pre-store-only rows. */
    public const INHERIT = -1;
    public const FORCE_OFF = 0;
    public const FORCE_ON = 1;

    public const DEFAULT_PROVIDER_ORDER = ['zeus', 'boltz'];
    public const DEFAULT_AUTO_SELECT_THRESHOLD_PCT = 10;

    /**
     * Provider preference order for a store. NULL / unparseable column (and a
     * null storeId, used by callers with no store context) → the built-in
     * default order.
     *
     * @return string[]
     */
    public static function providerOrderForStore(?string $storeId): array {
        if ($storeId === null) return self::DEFAULT_PROVIDER_ORDER;
        $row = Database::fetchOne(
            "SELECT swaps_provider_order FROM stores WHERE id = ?",
            [$storeId]
        );
        if (!$row || $row['swaps_provider_order'] === null) {
            return self::DEFAULT_PROVIDER_ORDER;
        }
        $raw = json_decode((string)$row['swaps_provider_order'], true);
        if (!is_array($raw)) return self::DEFAULT_PROVIDER_ORDER;
        $out = [];
        foreach ($raw as $name) {
            if (is_string($name) && $name !== '') {
                $out[] = strtolower($name);
            }
        }
        return $out !== [] ? $out : self::DEFAULT_PROVIDER_ORDER;
    }

    /**
     * @param string[] $providers Empty list resets to the built-in default.
     */
    public static function setStoreProviderOrder(string $storeId, array $providers): void {
        $clean = array_values(array_filter(
            array_map(fn($p) => is_string($p) ? strtolower(trim($p)) : '', $providers),
            fn($p) => $p !== ''
        ));
        Database::query(
            "UPDATE stores SET swaps_provider_order = ? WHERE id = ?",
            [$clean === [] ? null : json_encode($clean), $storeId]
        );
    }

    /**
     * Effective strict-no-mint-fallback for one store. Legacy -1 rows resolve
     * to the default (mint fallback allowed).
     */
    public static function strictNoMintFallbackForStore(string $storeId): bool {
        $row = Database::fetchOne(
            "SELECT strict_no_mint_fallback FROM stores WHERE id = ?",
            [$storeId]
        );
        return $row && (int)($row['strict_no_mint_fallback'] ?? 0) === self::FORCE_ON;
    }

    /**
     * Persist the per-store strict-no-mint-fallback flag. Written via a
     * direct UPDATE — the column is intentionally kept outside
     * Config::updateStore's allowlist.
     */
    public static function setStoreStrictOverride(string $storeId, int $strict): void {
        if (!in_array($strict, [self::FORCE_OFF, self::FORCE_ON], true)) {
            throw new InvalidArgumentException("Invalid strict_no_mint_fallback value: {$strict}");
        }
        Database::query(
            "UPDATE stores SET strict_no_mint_fallback = ? WHERE id = ?",
            [$strict, $storeId]
        );
    }

    /** Optional per-store swap floor in sats; null = no local floor (and for a
     *  null storeId, callers with no store context). */
    public static function minimumTargetSatsForStore(?string $storeId): ?int {
        if ($storeId === null) return null;
        $row = Database::fetchOne(
            "SELECT swaps_minimum_target_sats FROM stores WHERE id = ?",
            [$storeId]
        );
        if (!$row || !is_numeric($row['swaps_minimum_target_sats'])) return null;
        return max(0, (int)$row['swaps_minimum_target_sats']);
    }

    public static function setStoreMinimumTargetSats(string $storeId, ?int $sats): void {
        Database::query(
            "UPDATE stores SET swaps_minimum_target_sats = ? WHERE id = ?",
            [$sats === null ? null : max(0, $sats), $storeId]
        );
    }

    /** Auto-select-cheapest for a store; NULL column (or null storeId) → on. */
    public static function autoSelectCheapestForStore(?string $storeId): bool {
        if ($storeId === null) return true;
        $row = Database::fetchOne(
            "SELECT swaps_auto_select_cheapest FROM stores WHERE id = ?",
            [$storeId]
        );
        if (!$row || $row['swaps_auto_select_cheapest'] === null) return true;
        return (int)$row['swaps_auto_select_cheapest'] === 1;
    }

    public static function setStoreAutoSelectCheapest(string $storeId, bool $enabled): void {
        Database::query(
            "UPDATE stores SET swaps_auto_select_cheapest = ? WHERE id = ?",
            [$enabled ? 1 : 0, $storeId]
        );
    }

    /** Auto-select threshold percent, clamped 1..90; NULL column (or null
     *  storeId) → the built-in default. */
    public static function autoSelectThresholdPctForStore(?string $storeId): int {
        if ($storeId === null) return self::DEFAULT_AUTO_SELECT_THRESHOLD_PCT;
        $row = Database::fetchOne(
            "SELECT swaps_auto_select_threshold_pct FROM stores WHERE id = ?",
            [$storeId]
        );
        if (!$row || !is_numeric($row['swaps_auto_select_threshold_pct'])) {
            return self::DEFAULT_AUTO_SELECT_THRESHOLD_PCT;
        }
        return max(1, min(90, (int)$row['swaps_auto_select_threshold_pct']));
    }

    public static function setStoreAutoSelectThresholdPct(string $storeId, int $pct): void {
        Database::query(
            "UPDATE stores SET swaps_auto_select_threshold_pct = ? WHERE id = ?",
            [max(1, min(90, $pct)), $storeId]
        );
    }

    /* ---- Fee-too-high → mint fallback thresholds --------------------------
     *
     * When a store has a cashu mint enabled (and strict-no-mint-fallback is
     * OFF), a prospective submarine swap whose *total cost* — percent fee +
     * lockup miner fee + claim miner-fee estimate, i.e.
     * SwapQuoteFetcher::totalCostSats() — exceeds EITHER threshold is skipped,
     * and the invoice falls back to a mint-issued Lightning invoice.
     *
     * Both thresholds layer two ways: per-store column (NULL = inherit) →
     * config-file constant → 0. A value of 0 disables that particular check;
     * with both at 0 there is no fee-based fallback.
     */

    /** Config-file default for the percent threshold (0 = disabled). */
    public static function configFileFeeFallbackMaxPct(): float {
        if (defined('CASHUPAY_SWAPS_FEE_FALLBACK_MAX_PCT')) {
            $v = (float)CASHUPAY_SWAPS_FEE_FALLBACK_MAX_PCT;
            if ($v > 0) return $v;
        }
        return 0.0;
    }

    /** Config-file default for the absolute sats threshold (0 = disabled). */
    public static function configFileFeeFallbackMaxSats(): int {
        if (defined('CASHUPAY_SWAPS_FEE_FALLBACK_MAX_SATS')) {
            $v = (int)CASHUPAY_SWAPS_FEE_FALLBACK_MAX_SATS;
            if ($v > 0) return $v;
        }
        return 0;
    }

    /**
     * Resolve the effective fee-fallback thresholds for a store: a non-null
     * per-store column wins, else the config-file constant, else 0 (off).
     *
     * @return array{pct: float, sats: int}
     */
    public static function effectiveFeeFallbackForStore(string $storeId): array {
        $row = Database::fetchOne(
            "SELECT swaps_fee_fallback_max_pct, swaps_fee_fallback_max_sats FROM stores WHERE id = ?",
            [$storeId]
        );
        $pct = ($row && $row['swaps_fee_fallback_max_pct'] !== null)
            ? max(0.0, (float)$row['swaps_fee_fallback_max_pct'])
            : self::configFileFeeFallbackMaxPct();
        $sats = ($row && $row['swaps_fee_fallback_max_sats'] !== null)
            ? max(0, (int)$row['swaps_fee_fallback_max_sats'])
            : self::configFileFeeFallbackMaxSats();
        return ['pct' => $pct, 'sats' => $sats];
    }

    /**
     * Persist per-store fee-fallback overrides. NULL on a value clears that
     * override so the store inherits the config-file value. Written via a
     * direct UPDATE — these columns are intentionally kept outside
     * Config::updateStore's allowlist.
     */
    public static function setStoreFeeFallback(string $storeId, ?float $pct, ?int $sats): void {
        Database::query(
            "UPDATE stores SET swaps_fee_fallback_max_pct = ?, swaps_fee_fallback_max_sats = ? WHERE id = ?",
            [
                $pct === null ? null : max(0.0, $pct),
                $sats === null ? null : max(0, $sats),
                $storeId,
            ]
        );
    }

    /**
     * Does a prospective swap's total cost exceed either active threshold?
     * A threshold of 0 disables that particular check; with both at 0 this
     * always returns false (no fee-based fallback). OR semantics: either the
     * sats cap or the percent cap being exceeded triggers the fallback.
     */
    public static function swapFeeExceedsThreshold(
        int $totalCostSats, int $targetSats, float $maxPct, int $maxSats
    ): bool {
        if ($maxSats > 0 && $totalCostSats > $maxSats) {
            return true;
        }
        if ($maxPct > 0 && $targetSats > 0 && ($totalCostSats * 100.0) > ($targetSats * $maxPct)) {
            return true;
        }
        return false;
    }

    /**
     * Whether swaps are enabled for a store. Requires the flag to be ON
     * (legacy -1 "inherit" rows resolve to off, matching the old site default)
     * and an on-chain xpub — submarine swaps need an on-chain destination.
     */
    public static function isEnabledForStore(string $storeId): bool {
        $row = Database::fetchOne(
            "SELECT swaps_enabled, onchain_xpub FROM stores WHERE id = ?",
            [$storeId]
        );
        if (!$row) return false;
        if (empty($row['onchain_xpub'])) return false;
        return isset($row['swaps_enabled']) && (int)$row['swaps_enabled'] === self::FORCE_ON;
    }

    /**
     * Persist the per-store enabled flag (0/1).
     */
    public static function setStoreOverride(string $storeId, int $enabled): void {
        if (!in_array($enabled, [self::FORCE_OFF, self::FORCE_ON], true)) {
            throw new InvalidArgumentException("Invalid swaps_enabled value: {$enabled}");
        }
        Database::query(
            "UPDATE stores SET swaps_enabled = ? WHERE id = ?",
            [$enabled, $storeId]
        );
    }
}
