<?php
/**
 * CashuPayServer - Store deletion safety
 *
 * Deleting a store cascades its invoices, transfers, webhooks and keys and removes the
 * only copy of its seed. A zero spendable balance is not enough to make that safe: a
 * paid-but-unminted quote, an in-flight melt, an exported token nobody redeemed or ecash
 * left on a previous mint all still have value, and after deletion nothing can collect
 * it. The dashboard and the Greenfield API both ask this one function (OPUS-007,
 * DS41-16, DS41-17, SPB-CORE-008).
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/transfer.php';
require_once __DIR__ . '/../cashu-wallet-php/CashuWallet.php';

use Cashu\ProofState;
use Cashu\Wallet;
use Cashu\WalletStorage;

class StoreSafety {
    /** Units tried when matching an old wallet namespace to a mint (as Invoice does). */
    private const KNOWN_UNITS = ['sat', 'usd', 'eur', 'usdt', 'msat'];

    /**
     * Reasons a store is not safe to delete, in plain language.
     *
     * Covers everything except the spendable balance, which callers check (and word)
     * themselves: unresolved Lightning quotes, pending outgoing transfers, undelivered
     * webhooks, and — for *every* wallet account of the store, backups included —
     * in-flight wallet operations and unredeemed exported tokens; plus ecash left in a
     * namespace of a previous mint that only this store's wallet account can unlock.
     *
     * Read-only and offline: no mint is contacted.
     *
     * @param bool $includeStranded Also look for ecash left on previous mints. A mint
     *                              switch does not make those worse, so that check can
     *                              skip it; deletion must not.
     * @return string[] Empty when nothing is outstanding
     */
    public static function deletionBlockers(string $storeId, bool $includeStranded = true): array {
        $blockers = [];

        $unresolvedQuotes = (int)(Database::fetchOne(
            "SELECT COUNT(*) AS cnt FROM invoices
             WHERE store_id = ? AND quote_id IS NOT NULL AND status IN ('New', 'Processing', 'Expired', 'Invalid')",
            [$storeId]
        )['cnt'] ?? 0);
        if ($unresolvedQuotes > 0) {
            $blockers[] = "{$unresolvedQuotes} invoice(s) whose Lightning quote may still be paid";
        }

        $pendingTransfers = (int)(Database::fetchOne(
            "SELECT COUNT(*) AS cnt FROM transfers WHERE store_id = ? AND status = ?",
            [$storeId, Transfer::STATUS_PENDING]
        )['cnt'] ?? 0);
        if ($pendingTransfers > 0) {
            $blockers[] = "{$pendingTransfers} outgoing transfer(s) with an unknown outcome";
        }

        $undelivered = (int)(Database::fetchOne(
            "SELECT COUNT(*) AS cnt FROM webhook_deliveries d
             JOIN webhooks w ON w.id = d.webhook_id
             WHERE w.store_id = ? AND d.delivered_at IS NULL",
            [$storeId]
        )['cnt'] ?? 0);
        if ($undelivered > 0) {
            $blockers[] = "{$undelivered} webhook event(s) the shop has not acknowledged";
        }

        $store = Config::getStore($storeId);
        if ($store === null) {
            return $blockers;
        }
        $account = !empty($store['wallet_account_id']) ? (string)$store['wallet_account_id'] : null;

        // Every account, not just the primary: journals and exports on a backup mint are
        // just as live, and recovery visits them all.
        foreach (Config::getStoreWalletAccounts($storeId) as $acc) {
            $label = self::mintLabel($acc['mint_url']);
            try {
                $storage = new WalletStorage(Database::getDbPath(), $acc['mint_url'], $acc['unit'], $account);

                $journals = count($storage->getPendingOperations());
                if ($journals > 0) {
                    $blockers[] = "{$journals} in-flight wallet operation(s) at {$label}";
                }

                $exported = Wallet::sumProofs($storage->getProofsAsObjects(ProofState::EXPORTED));
                if ($exported > 0) {
                    $blockers[] = "{$exported} {$acc['unit']} in exported tokens from {$label} that have not been redeemed";
                }
            } catch (Throwable $e) {
                error_log("CashuPayServer: could not inspect wallet {$label} of store {$storeId}: " . $e->getMessage());
                $blockers[] = "the wallet at {$label} could not be checked";
            }
        }

        if ($includeStranded) {
            try {
                foreach (self::strandedForStore($storeId) as $stranded) {
                    if ($stranded['mint_url'] !== null) {
                        $blockers[] = "{$stranded['amount']} {$stranded['unit']} of ecash left on a previous mint ("
                            . self::mintLabel($stranded['mint_url']) . '); recover it from the previous-mint screen first';
                    } else {
                        $blockers[] = "{$stranded['amount']} of ecash from an earlier mint that could not be matched to a store "
                            . '(it may belong to this one); recover it from the previous-mint screen first';
                    }
                }
            } catch (Throwable $e) {
                error_log("CashuPayServer: could not scan old wallets for store {$storeId}: " . $e->getMessage());
                $blockers[] = 'ecash from previous mints could not be checked';
            }
        }

        return $blockers;
    }

    /**
     * Old wallet namespaces that deleting this store would make unrecoverable.
     *
     * A namespace is hash(mint_url, unit, wallet_account_id). One that no store's
     * current accounts reach is "stranded"; the previous-mint screen can still export it
     * as long as some store's wallet_account_id re-derives it. Deleting the store whose
     * account derives it removes that key for good (DS41-16). Namespaces that cannot be
     * matched to any account are reported too: they may well be this store's, and the
     * deletion is the last moment anyone could still find out.
     *
     * @return array<int, array{wallet_id: string, mint_url: ?string, unit: ?string, amount: int}>
     */
    public static function strandedForStore(string $storeId): array {
        $store = Config::getStore($storeId);
        if ($store === null) {
            return [];
        }
        $ownAccount = !empty($store['wallet_account_id']) ? (string)$store['wallet_account_id'] : null;

        // Value-bearing namespaces: proofs that are or may become spendable, and journals.
        $holdings = [];
        foreach (Database::fetchAll(
            "SELECT wallet_id, COALESCE(SUM(amount), 0) AS s FROM cashu_proofs
             WHERE state IN (?, ?, ?) GROUP BY wallet_id",
            [ProofState::UNSPENT, ProofState::PENDING, ProofState::EXPORTED]
        ) as $row) {
            $holdings[$row['wallet_id']] = (int)$row['s'];
        }
        foreach (Database::fetchAll("SELECT DISTINCT wallet_id FROM cashu_pending_operations") as $row) {
            $holdings[$row['wallet_id']] = $holdings[$row['wallet_id']] ?? 0;
        }
        if (empty($holdings)) {
            return [];
        }

        // Namespaces any store reaches today, and every account and mint URL we know.
        $live = [];
        $accounts = [];
        $mints = [];
        foreach (Database::fetchAll("SELECT id, wallet_account_id FROM stores") as $st) {
            $acct = !empty($st['wallet_account_id']) ? (string)$st['wallet_account_id'] : null;
            if ($acct !== null) {
                $accounts[$acct] = $st['id'];
            }
            foreach (Config::getStoreWalletAccounts($st['id']) as $acc) {
                $mints[rtrim($acc['mint_url'], '/')] = true;
                $live[WalletStorage::deriveWalletId($acc['mint_url'], $acc['unit'], $acct)] = true;
            }
        }
        foreach (Database::fetchAll(
            "SELECT DISTINCT mint_url FROM invoices WHERE mint_url IS NOT NULL AND mint_url <> ''
             UNION SELECT DISTINCT mint_url FROM store_mints WHERE mint_url IS NOT NULL AND mint_url <> ''"
        ) as $row) {
            $mints[rtrim($row['mint_url'], '/')] = true;
        }

        $out = [];
        foreach ($holdings as $walletId => $amount) {
            if (isset($live[$walletId])) {
                continue;
            }
            $match = self::identify((string)$walletId, array_keys($mints), array_merge([null], array_keys($accounts)));
            if ($match !== null) {
                // Recoverable after deletion only if another account (or none) derives it.
                if ($match['account'] !== $ownAccount) {
                    continue;
                }
                $out[] = ['wallet_id' => (string)$walletId, 'mint_url' => $match['mint_url'],
                          'unit' => $match['unit'], 'amount' => $amount];
                continue;
            }
            if ($amount > 0) {
                $out[] = ['wallet_id' => (string)$walletId, 'mint_url' => null, 'unit' => null, 'amount' => $amount];
            }
        }
        return $out;
    }

    /** @return array{mint_url: string, unit: string, account: ?string}|null */
    private static function identify(string $walletId, array $mints, array $accounts): ?array {
        foreach ($mints as $mint) {
            foreach (self::KNOWN_UNITS as $unit) {
                foreach ($accounts as $acct) {
                    if (WalletStorage::deriveWalletId($mint, $unit, $acct) === $walletId) {
                        return ['mint_url' => $mint, 'unit' => $unit, 'account' => $acct];
                    }
                }
            }
        }
        return null;
    }

    /** A mint as the operator would recognise it: its host name. */
    private static function mintLabel(string $mintUrl): string {
        $host = parse_url($mintUrl, PHP_URL_HOST);
        return is_string($host) && $host !== '' ? $host : $mintUrl;
    }
}
