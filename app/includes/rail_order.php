<?php
/**
 * CashuPayServer - configurable payment-rail ordering.
 *
 * Two per-store orderings, both stored as comma-separated lists on the
 * stores table (NULL = built-in default, so pre-existing stores keep the
 * historical behavior):
 *
 *   stores.ln_rail_order — the order the four Lightning destination TYPES
 *   are tried when generating an invoice (and, symmetrically, when the
 *   auto-cashout / settle-and-forward melts walk the chain): 'strike',
 *   'lnaddress', 'nwc', 'noffer'. Ordering is by type only; entries of the
 *   same type keep their relative priority from the per-type lists in the
 *   Lightning payments card. Applied at read time by
 *   StoreLnAddresses::listForStore, so changing the order never rewrites
 *   the stored chain.
 *
 *   stores.onchain_source_order — where an invoice's on-chain address
 *   comes from first: 'strike' (a receive request minted in the merchant's
 *   Strike account) or 'local' (the store's xpub-derived / static
 *   address). Whichever is listed first is tried first; the other is the
 *   fallback. Note xpub vs static is a mode (onchain_address_mode), not a
 *   chain — 'local' means whichever of the two the store is configured
 *   for. Walked by Invoice::create's on-chain block.
 */

require_once __DIR__ . '/database.php';

class RailOrder {
    /** Historical (and default) Lightning type order — see chainFromLists. */
    public const LN_DEFAULT = ['strike', 'lnaddress', 'nwc', 'noffer'];
    /** Historical (and default) on-chain source order. */
    public const ONCHAIN_DEFAULT = ['strike', 'local'];

    /**
     * Parse a stored (or posted) CSV order against a known-type universe.
     * Defensive by design: unknown entries are dropped, duplicates
     * collapse to their first occurrence, and any missing types are
     * appended in default order — so a value written by a newer/older
     * version can never make a type silently unreachable. NULL/empty
     * yields the default.
     *
     * @param string[] $default the full type universe in default order
     * @return string[] a permutation of $default
     */
    private static function normalize(?string $csv, array $default): array {
        $out = [];
        foreach (explode(',', strtolower((string)$csv)) as $part) {
            $part = trim($part);
            if ($part !== '' && in_array($part, $default, true) && !in_array($part, $out, true)) {
                $out[] = $part;
            }
        }
        foreach ($default as $type) {
            if (!in_array($type, $out, true)) {
                $out[] = $type;
            }
        }
        return $out;
    }

    /**
     * Strict save-time validation: the posted CSV must be exactly a
     * permutation of the known types (no unknowns, no duplicates, none
     * missing). Returns the parsed order; throws the operator-facing
     * message otherwise. Normalization stays lenient for reads; writes
     * are strict so a UI/API bug surfaces instead of half-applying.
     *
     * @param string[] $default the full type universe in default order
     * @return string[]
     */
    private static function parseStrict(string $csv, array $default, string $label): array {
        $parts = array_map('trim', explode(',', strtolower($csv)));
        $parts = array_values(array_filter($parts, static fn(string $p): bool => $p !== ''));
        $sorted = $parts;
        $canonical = $default;
        sort($sorted);
        sort($canonical);
        if ($sorted !== $canonical) {
            throw new InvalidArgumentException(
                "Invalid {$label} order: expected exactly the types "
                . implode(', ', $default) . ' in some order.'
            );
        }
        return $parts;
    }

    /**
     * Strictly parse a posted Lightning type order (see parseStrict). Lets a
     * save handler validate up front — before probes / other writes — and
     * persist via setLnOrder only once the rest of the save succeeded.
     */
    public static function parseLnOrder(string $csv): array {
        return self::parseStrict($csv, self::LN_DEFAULT, 'Lightning payment path');
    }

    /** Strictly parse a posted on-chain source order (see parseStrict). */
    public static function parseOnchainOrder(string $csv): array {
        return self::parseStrict($csv, self::ONCHAIN_DEFAULT, 'on-chain address source');
    }

    /** Effective Lightning type order for a store row (as read by getStore). */
    public static function lnOrderFromRow(?array $store): array {
        return self::normalize($store['ln_rail_order'] ?? null, self::LN_DEFAULT);
    }

    /** Effective Lightning type order for a store id. */
    public static function lnOrderForStore(string $storeId): array {
        $row = Database::fetchOne(
            "SELECT ln_rail_order FROM stores WHERE id = ?", [$storeId]
        );
        return self::normalize($row['ln_rail_order'] ?? null, self::LN_DEFAULT);
    }

    /**
     * The raw stored Lightning order CSV, or null when the store has never
     * set one. The distinction matters to listForStore: an EXPLICIT order
     * type-sorts the chain, while null keeps the stored per-row order
     * verbatim — the exact historical behavior, which for chains saved
     * through the admin UI is the default type grouping anyway, but for
     * chains written through the legacy shape-classified API contract may
     * interleave types deliberately.
     */
    public static function storedLnOrderCsv(string $storeId): ?string {
        $row = Database::fetchOne(
            "SELECT ln_rail_order FROM stores WHERE id = ?", [$storeId]
        );
        $csv = $row['ln_rail_order'] ?? null;
        return ($csv === null || trim((string)$csv) === '') ? null : (string)$csv;
    }

    /** Effective on-chain source order for a store row (as read by getStore). */
    public static function onchainOrderFromRow(?array $store): array {
        return self::normalize($store['onchain_source_order'] ?? null, self::ONCHAIN_DEFAULT);
    }

    /** Effective on-chain source order for a store id. */
    public static function onchainOrderForStore(string $storeId): array {
        $row = Database::fetchOne(
            "SELECT onchain_source_order FROM stores WHERE id = ?", [$storeId]
        );
        return self::normalize($row['onchain_source_order'] ?? null, self::ONCHAIN_DEFAULT);
    }

    /**
     * Persist the Lightning type order from a posted CSV. Same direct-UPDATE
     * routing as OnchainConfig::setStoreOverride — the Config::updateStore
     * allowlist stays tight.
     */
    public static function setLnOrder(string $storeId, string $csv): void {
        $order = self::parseStrict($csv, self::LN_DEFAULT, 'Lightning payment path');
        Database::query(
            "UPDATE stores SET ln_rail_order = ? WHERE id = ?",
            [implode(',', $order), $storeId]
        );
    }

    /** Persist the on-chain source order from a posted CSV. */
    public static function setOnchainOrder(string $storeId, string $csv): void {
        $order = self::parseStrict($csv, self::ONCHAIN_DEFAULT, 'on-chain address source');
        Database::query(
            "UPDATE stores SET onchain_source_order = ? WHERE id = ?",
            [implode(',', $order), $storeId]
        );
    }

    /**
     * Stable-sort store_ln_addresses rows (each carrying a 'type') by the
     * store's configured type order. Rows of the same type keep their
     * relative (position) order. Rows with an unexpected type sort last,
     * in their stored order.
     *
     * @param array[] $rows
     * @param string[] $order permutation of LN_DEFAULT
     * @return array[]
     */
    public static function sortDestinations(array $rows, array $order): array {
        $rank = array_flip($order);
        $fallbackRank = count($order);
        $keyed = [];
        foreach ($rows as $i => $row) {
            $keyed[] = [$rank[$row['type'] ?? ''] ?? $fallbackRank, $i, $row];
        }
        usort($keyed, static function (array $a, array $b): int {
            return $a[0] <=> $b[0] ?: $a[1] <=> $b[1];
        });
        return array_map(static fn(array $k): array => $k[2], $keyed);
    }
}
