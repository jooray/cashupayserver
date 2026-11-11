<?php
/**
 * CashuPayServer — Stuck Funds detection.
 *
 * "Stuck" means: the store has UNSPENT balance in a mint AND that mint has at
 * least one withdrawal failure on record since its last successful melt (i.e.
 * the reliability tracker has it gated by `disabled_pending_success`, or has
 * already escalated it to `permanently_disabled`). Counted on a live read of
 * the reliability flags and the local proof store, so funds that become
 * unstuck (mint recovers + a melt succeeds, or balance drains to zero)
 * automatically stop contributing on the next call.
 *
 * The point of this module is to feed {@see DevFee::computeOwed}: stuck
 * sats are subtracted from the dev-fee bucket first, then hosting, so loss
 * from a misbehaving mint comes out of the operator/dev share rather than
 * the merchant's settlement.
 *
 * Detection is intentionally per-store: the reliability row is global per
 * mint_url, but a mint only counts as stuck *for a given store* if that
 * store actually holds UNSPENT balance in it. A reliability row with no
 * balance behind it costs the operator nothing.
 *
 * Trusted-list-disabled mints are NOT treated as stuck: that flag is a
 * policy decision, not a withdrawal-failure signal. Funds in a trusted-list
 * mint are still meltable, just gated for new invoices.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/rates.php';

class StuckFunds {
    /**
     * Per-store cache keyed by storeId. The deduction is read once per
     * computeOwed call but downstream code (admin endpoints, LNURL gate) may
     * recompute multiple times within a request; the cache keeps it O(1) for
     * those follow-ups.
     */
    private static array $cache = [];

    /**
     * Per-mint stuck balance for a store, in sats.
     *
     * @return array<string, int> mint_url => stuck_sats. Empty when nothing is stuck.
     */
    public static function computeStuckSatsPerMint(string $storeId): array {
        if (isset(self::$cache[$storeId])) {
            return self::$cache[$storeId];
        }

        $result = [];
        $candidates = self::candidateMintsForStore($storeId);
        if (empty($candidates)) {
            self::$cache[$storeId] = $result;
            return $result;
        }

        $providers = Config::getStorePriceProviders($storeId);

        foreach ($candidates as $cand) {
            $mintUrl = $cand['mint_url'];
            $unit = $cand['unit'];
            if (!self::isMintStuck($mintUrl)) {
                continue;
            }
            $balanceMintUnit = self::unspentBalanceForMint($mintUrl, $unit);
            if ($balanceMintUnit <= 0) {
                continue;
            }
            $balanceSats = self::toSats(
                $balanceMintUnit,
                $unit,
                $providers['primary'] ?? null,
                $providers['secondary'] ?? null
            );
            if ($balanceSats <= 0) {
                continue;
            }
            $result[$mintUrl] = ($result[$mintUrl] ?? 0) + $balanceSats;
        }

        self::$cache[$storeId] = $result;
        return $result;
    }

    /** Total stuck sats for a store across all its mints. */
    public static function totalStuckSats(string $storeId): int {
        return array_sum(self::computeStuckSatsPerMint($storeId));
    }

    /**
     * Apply the deduction to the owed buckets, eating dev first, then
     * hosting. Returns the adjusted buckets plus a breakdown of what was
     * absorbed where, so callers (admin UI) can surface it.
     *
     * Caller passes the raw `*_owed` ints; we return them mutated (floored at
     * zero) and a `stuck_*` breakdown.
     *
     * @return array{
     *   dev_owed:int, hosting_owed:int,
     *   stuck_total_sats:int, stuck_absorbed_total:int,
     *   stuck_absorbed_dev:int, stuck_absorbed_hosting:int,
     *   stuck_uncovered:int
     * }
     */
    public static function applyDeduction(
        int $devOwed,
        int $hostingOwed,
        int $stuckSatsTotal
    ): array {
        $remaining = max(0, $stuckSatsTotal);

        $absorbDev = min($remaining, $devOwed);
        $devOwed -= $absorbDev;
        $remaining -= $absorbDev;

        $absorbHosting = min($remaining, $hostingOwed);
        $hostingOwed -= $absorbHosting;
        $remaining -= $absorbHosting;

        return [
            'dev_owed' => max(0, $devOwed),
            'hosting_owed' => max(0, $hostingOwed),
            'stuck_total_sats' => max(0, $stuckSatsTotal),
            'stuck_absorbed_total' => $absorbDev + $absorbHosting,
            'stuck_absorbed_dev' => $absorbDev,
            'stuck_absorbed_hosting' => $absorbHosting,
            'stuck_uncovered' => max(0, $remaining),
        ];
    }

    /**
     * Clear the per-store cache. Used by tests, and by any caller that has
     * just driven a state change (successful melt, admin re-enable) and
     * wants the next read to reflect the new reality within the same request.
     */
    public static function clearCache(?string $storeId = null): void {
        if ($storeId === null) {
            self::$cache = [];
        } else {
            unset(self::$cache[$storeId]);
        }
    }

    // ------------------------------------------------------------------
    // Internal helpers
    // ------------------------------------------------------------------

    /**
     * (mint_url, unit) tuples to inspect for this store. Primary mint first,
     * then enabled backups. We deliberately do NOT walk every wallet_id in
     * cashu_proofs — funds left over in a mint that has been fully removed
     * from the store's config (not primary, not in store_mints) are out of
     * scope for this feature; an operator-level cleanup, not a fee question.
     */
    private static function candidateMintsForStore(string $storeId): array {
        $candidates = [];

        // Use URLs as-stored: reliability rows and cashu_proofs.wallet_id are
        // both keyed on whatever string was passed at write time, and there's
        // a pre-existing inconsistency in this codebase where backups are
        // rtrim'd on insert but the primary mint_url is not. Re-normalizing
        // here would mis-key the lookups. We dedupe loosely on rtrim'd URL +
        // unit so we don't double-count a mint that appears as both primary
        // and backup with different trailing-slash forms.
        $primaryUrl = Config::getStoreMintUrl($storeId);
        $primaryUnit = Config::getStoreMintUnit($storeId);
        $seen = [];
        if ($primaryUrl !== null && $primaryUrl !== '') {
            $candidates[] = ['mint_url' => $primaryUrl, 'unit' => $primaryUnit];
            $seen[rtrim($primaryUrl, '/') . '|' . $primaryUnit] = true;
        }

        $backups = Config::getStoreBackupMints($storeId);
        foreach ($backups as $row) {
            $url = (string)$row['mint_url'];
            $unit = (string)($row['unit'] ?? 'sat');
            $key = rtrim($url, '/') . '|' . $unit;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $candidates[] = ['mint_url' => $url, 'unit' => $unit];
        }
        return $candidates;
    }

    /**
     * "Stuck" check against the global mint_reliability row.
     *
     * We treat the mint as stuck when ANY withdrawal failure has been
     * recorded since the last success — that's exactly what
     * `disabled_pending_success` represents. `permanently_disabled` is the
     * stronger same-axis signal (≥ 5 lifetime failures without a clearing
     * success) and also counts.
     *
     * `trusted_list_disabled` does NOT count: that gate is a policy choice,
     * not a fault, and the mint is still meltable.
     *
     * Missing row → no recorded failures → not stuck.
     */
    private static function isMintStuck(string $mintUrl): bool {
        $row = Database::fetchOne(
            "SELECT disabled_pending_success, permanently_disabled
             FROM mint_reliability WHERE mint_url = ?",
            [$mintUrl]
        );
        if ($row === null) {
            return false;
        }
        return ((int)$row['disabled_pending_success'] === 1)
            || ((int)$row['permanently_disabled'] === 1);
    }

    /**
     * UNSPENT balance in the cashu_proofs wallet for (mint_url, unit). The
     * wallet_id derivation must match WalletStorage::__construct.
     */
    private static function unspentBalanceForMint(string $mintUrl, string $unit): int {
        $walletId = substr(hash('sha256', $mintUrl . ':' . $unit), 0, 16);
        try {
            $row = Database::fetchOne(
                "SELECT COALESCE(SUM(amount), 0) AS s
                 FROM cashu_proofs
                 WHERE wallet_id = ? AND state = 'UNSPENT'",
                [$walletId]
            );
        } catch (\Throwable $e) {
            // cashu_proofs missing on fresh installs that haven't booted a
            // wallet yet — treated as no balance.
            return 0;
        }
        return (int)($row['s'] ?? 0);
    }

    private static function toSats(int $amount, string $unit, ?string $primary, ?string $secondary): int {
        $u = strtolower($unit);
        if ($u === 'sat' || $u === 'sats') {
            return $amount;
        }
        if ($u === 'msat') {
            return (int)ceil($amount / 1000);
        }
        // Fiat — convert via exchange rates. Failures here would let the
        // caller silently lose protection, so we let the exception propagate
        // up to computeOwed and surface there.
        return (int) ExchangeRates::convertMintUnitToSats($amount, $unit, $primary, $secondary);
    }
}
