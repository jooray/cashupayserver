<?php
/**
 * On-chain payment-offering configuration.
 *
 * Whether the on-chain rail is OFFERED to customers is deliberately separate
 * from whether an on-chain destination is CONFIGURED. A store can keep an xpub
 * — which submarine swaps require, since a swap settles on-chain to it — while
 * presenting a Lightning-only checkout (some merchants prefer Lightning for
 * speed). Callers still gate on "is a destination configured?" separately; this
 * class only answers "should we show the pay-to-address to customers?".
 *
 * The setting is per-store only: stores.onchain_offer_enabled, 0 off / 1 on.
 * Default on (NULL and legacy -1 "inherit" rows — from when a site-wide
 * default existed — resolve to on, so stores keep offering on-chain).
 */
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../config.php';

class OnchainConfig {
    /** Legacy sentinel still present in pre-store-only rows; resolves to on. */
    public const INHERIT = -1;
    public const FORCE_OFF = 0;
    public const FORCE_ON = 1;

    /** Raw per-store value (0 off / 1 on / legacy -1). Defaults to INHERIT
     *  when the column is NULL (older rows) or the store is missing. */
    public static function storeOverride(string $storeId): int {
        $row = Database::fetchOne(
            "SELECT onchain_offer_enabled FROM stores WHERE id = ?",
            [$storeId]
        );
        return ($row && $row['onchain_offer_enabled'] !== null)
            ? (int)$row['onchain_offer_enabled']
            : self::INHERIT;
    }

    /**
     * Whether the on-chain rail should be OFFERED to customers for this store.
     * Only an explicit 0 turns it off; NULL / legacy -1 resolve to on.
     * Independent of whether an xpub/static address is actually configured —
     * the caller gates on that separately (a store with no destination has
     * nothing to offer).
     */
    public static function isEnabledForStore(string $storeId): bool {
        return self::storeOverride($storeId) !== self::FORCE_OFF;
    }

    /**
     * Persist the per-store flag (0/1). Routed through a direct UPDATE (NOT
     * Config::updateStore — its allowlist stays tight).
     */
    public static function setStoreOverride(string $storeId, int $enabled): void {
        if (!in_array($enabled, [self::FORCE_OFF, self::FORCE_ON], true)) {
            throw new InvalidArgumentException("Invalid onchain_offer_enabled value: {$enabled}");
        }
        Database::query(
            "UPDATE stores SET onchain_offer_enabled = ? WHERE id = ?",
            [$enabled, $storeId]
        );
    }

    /**
     * Whether the store also mints its on-chain receive addresses in the
     * merchant's Strike account (stores.onchain_strike_enabled, default off).
     * This is only the operator's wish — Invoice::create additionally
     * requires a configured Strike key and a mainnet on-chain network before
     * a Strike address is actually attempted, and falls back to the store's
     * xpub / static address when the Strike API call fails.
     */
    public static function strikeEnabledForStore(string $storeId): bool {
        $row = Database::fetchOne(
            "SELECT onchain_strike_enabled FROM stores WHERE id = ?",
            [$storeId]
        );
        return $row !== null && (int)($row['onchain_strike_enabled'] ?? 0) === 1;
    }

    /**
     * Shared enable gate for the Strike on-chain option, run BEFORE anything
     * is persisted (used by the setup wizard and both admin save paths).
     *
     * $chainStrikeKeys      Strike keys that will be stored after this save,
     *                       in chain priority order.
     * $previouslyStoredKeys map of raw key => true for Strike keys stored
     *                       before the save (grandfathering).
     * $wasEnabled           the flag's state before the save.
     *
     * Refuses (RuntimeException, operator-facing message, never the key):
     *   - no Strike key in the chain (nothing could mint an address);
     *   - a non-mainnet on-chain network (Strike is mainnet-only, and the
     *     chain watcher follows the store's network/provider config);
     *   - a key that fails the receive-request probe. Probes run on the
     *     off->on transition and for any NEW key while the option is on;
     *     unchanged stored keys with the flag already on are grandfathered
     *     (each probe leaves a 1-sat receive request in the merchant's
     *     Strike dashboard, so re-probing on every unrelated edit is noise).
     */
    public static function gateStrikeOnchainEnable(
        string $storeId,
        array $chainStrikeKeys,
        array $previouslyStoredKeys,
        bool $wasEnabled
    ): void {
        require_once __DIR__ . '/../strike/client.php';
        if ($chainStrikeKeys === []) {
            throw new RuntimeException(
                'To accept on-chain payments via Strike, add a Strike API key first '
                . '(or untick the on-chain checkbox).'
            );
        }
        $row = Database::fetchOne("SELECT onchain_network FROM stores WHERE id = ?", [$storeId]);
        $network = (string)($row['onchain_network'] ?? '') ?: 'mainnet';
        if ($network !== 'mainnet') {
            throw new RuntimeException(
                'Strike can only receive on-chain payments on Bitcoin mainnet, but this '
                . "store's on-chain wallet is configured for {$network}. "
                . 'Use a mainnet wallet, or untick the on-chain checkbox.'
            );
        }
        foreach ($chainStrikeKeys as $key) {
            if ($wasEnabled && isset($previouslyStoredKeys[$key])) {
                continue;
            }
            $probe = StrikeClient::probeOnchainKey($key);
            if (!$probe['ok']) {
                throw new RuntimeException(
                    'Can\'t enable on-chain payments via Strike: ' . $probe['error']
                    . '. Add the "create receive requests" scope to the key in the Strike '
                    . 'dashboard (or create a new key with all four scopes), then try again.'
                );
            }
        }
    }

    /**
     * Persist the Strike on-chain flag (0/1). Same direct-UPDATE routing as
     * setStoreOverride — the Config::updateStore allowlist stays tight.
     * Callers gate the off→on transition behind StrikeClient::probeOnchainKey
     * so a key without the receive-request scope is refused at enable time.
     */
    public static function setStrikeEnabled(string $storeId, int $enabled): void {
        if (!in_array($enabled, [0, 1], true)) {
            throw new InvalidArgumentException("Invalid onchain_strike_enabled value: {$enabled}");
        }
        Database::query(
            "UPDATE stores SET onchain_strike_enabled = ? WHERE id = ?",
            [$enabled, $storeId]
        );
    }
}
