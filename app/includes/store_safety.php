<?php
/**
 * CashuPayServer - Store money-safety helpers
 *
 * Small, dependency-light helpers shared by the export / withdraw / mint-change
 * paths:
 *
 *   - proof-state transitions with an explicit "from" set (CAS on the
 *     cashu_proofs row, so a proof the cron just spent is never exported);
 *   - the set of input secrets reserved by in-flight melt/swap journals;
 *   - fee-aware proof selection for exports;
 *   - fail-closed NUT-07 checkstate parsing (a short, empty or re-ordered
 *     reply is an error, never "spent");
 *   - what a wallet namespace holds, and what blocks a mint change or a
 *     backup-mint removal.
 *
 * Everything money-moving goes through the wallet library's own primitives
 * (ProofState::EXPORTED, WalletStorage::transitionProofStates(),
 * getReservedInputSecrets(), Wallet::selectProofsWithFees(),
 * Wallet::checkProofState()); the helpers here only add the server's
 * namespace lookup and plain-language reasons.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../cashu-wallet-php/CashuWallet.php';

use Cashu\Wallet;
use Cashu\WalletStorage;
use Cashu\ProofState;

class StoreSafety {
    /** Swap/sweep attempt statuses that are finished (mirrors SwapPoller). */
    private const SWAP_TERMINAL = [
        'invoice.settled', 'swap.expired', 'transaction.refunded', 'transaction.failed',
        'invoice.expired', 'claim.confirmed', 'error',
    ];

    /**
     * Proof state for "handed to a third party as a token" — distinct from
     * PENDING (reserved by an in-flight mint operation) on purpose: the mint
     * reporting an exported proof UNSPENT only means nobody has redeemed it
     * yet, so nothing may put it back into the spendable balance on its own.
     */
    public static function exportedState(): string {
        return ProofState::EXPORTED;
    }

    /**
     * The wallet namespace id for a mint + unit, exactly as the library derives
     * it (Wallet trims the trailing slash before building its storage).
     */
    public static function walletId(string $mintUrl, string $unit): string {
        return WalletStorage::deriveWalletId(rtrim($mintUrl, '/'), $unit);
    }

    public static function storage(string $mintUrl, string $unit): WalletStorage {
        return new WalletStorage(Database::getDbPath(), rtrim($mintUrl, '/'), $unit);
    }

    /**
     * Move proofs from one of the $from states to $to, atomically, and report
     * how many rows actually changed.
     *
     * With $requireAll, nothing changes unless EVERY secret is currently in a
     * $from state — the caller is about to hand these proofs out and must not
     * do so when another request already reserved or spent one of them.
     *
     * @param string[] $secrets
     * @param string[] $from
     */
    public static function transitionProofStates(
        string $mintUrl,
        string $unit,
        array $secrets,
        string $to,
        array $from,
        bool $requireAll = false
    ): int {
        $secrets = array_values(array_unique(array_filter(array_map('strval', $secrets), 'strlen')));
        if (empty($secrets) || empty($from)) {
            return 0;
        }
        $storage = self::storage($mintUrl, $unit);
        if ($requireAll) {
            $states = $storage->getProofsStatesBySecrets($secrets);
            foreach ($secrets as $s) {
                if (!isset($states[$s]) || !in_array($states[$s], $from, true)) {
                    return 0;
                }
            }
        }
        // Inputs an unfinished melt/swap journal owns are never moved by the
        // server: the library's recovery settles them against the mint.
        $exclude = array_flip(self::reservedInputSecrets($storage));
        return (int)$storage->transitionProofStates($secrets, $to, $from, $exclude);
    }

    /**
     * Input secrets that an unfinished melt or swap journal still owns. The
     * mint may briefly report them UNSPENT after a timeout even though the
     * original request is queued; only the library's recovery may touch them.
     *
     * @return string[]
     */
    public static function reservedInputSecrets(WalletStorage $storage): array {
        return array_values(array_map('strval', $storage->getReservedInputSecrets()));
    }

    /**
     * Select the smallest input set that still covers $amount plus the NUT-02
     * input fee of the inputs themselves (the library iterates until the
     * selection covers its own cost).
     *
     * @param \Cashu\Proof[] $proofs
     * @return \Cashu\Proof[]
     * @throws \Cashu\InsufficientBalanceException
     */
    public static function selectProofsWithFees(Wallet $wallet, array $proofs, int $amount): array {
        return $wallet->selectProofsWithFees($proofs, $amount);
    }

    /**
     * Normalise a NUT-07 checkstate reply, failing closed.
     *
     * @param string[] $Ys hex Y values, in request order
     * @param mixed $response decoded mint reply
     * @return string[] upper-cased states, one per Y, in request order
     * @throws \Exception when the reply is missing, short, long or re-ordered
     */
    public static function validateStates(array $Ys, $response): array {
        $states = is_array($response) ? ($response['states'] ?? null) : null;
        if (!is_array($states) || count($states) !== count($Ys)) {
            throw new \Exception('Mint returned an incomplete proof state response');
        }
        $out = [];
        $i = 0;
        foreach ($states as $state) {
            if (!is_array($state)) {
                throw new \Exception('Mint returned a malformed proof state response');
            }
            if (isset($state['Y']) && !hash_equals(strtolower((string)$Ys[$i]), strtolower((string)$state['Y']))) {
                throw new \Exception('Mint returned proof states in an unexpected order');
            }
            $out[] = strtoupper((string)($state['state'] ?? ''));
            $i++;
        }
        return $out;
    }

    /**
     * "All spent" is a claim that the recipient redeemed the token, so it must
     * be proved for every proof — never assumed from an empty or short reply.
     */
    public static function proofStatesAllSpent(array $Ys, $response): bool {
        if (empty($Ys)) {
            return false;
        }
        foreach (self::validateStates($Ys, $response) as $state) {
            if ($state !== ProofState::SPENT) {
                return false;
            }
        }
        return true;
    }

    /**
     * Ask a mint what it thinks of the given proofs (NUT-07), through the
     * library's fail-closed checkProofState(): one entry per proof, Y-matched,
     * UNKNOWN when the mint sent no state; a short, empty, duplicated or
     * re-ordered reply throws. No seed or storage is involved.
     *
     * @param \Cashu\Proof[] $proofs
     * @return array<string, string> Y (as the proof carries it) => upper-cased state
     * @throws \Cashu\CashuException
     */
    public static function checkProofStates(string $mintUrl, string $unit, array $proofs): array {
        $proofs = array_values($proofs);
        if (empty($proofs)) {
            return [];
        }
        $wallet = new Wallet(rtrim($mintUrl, '/'), $unit);
        $entries = $wallet->checkProofState($proofs);
        $out = [];
        foreach ($proofs as $i => $proof) {
            $out[$proof->Y] = (string)($entries[$i]['state'] ?? ProofState::UNKNOWN);
        }
        return $out;
    }

    /**
     * Same, for proofs known only by secret: the rows are read from the
     * namespace that holds them so the mint is asked about real proofs.
     *
     * @param string[] $secrets
     * @return array<string, string> secret => upper-cased state
     * @throws \Exception when a secret is not in the namespace
     */
    public static function checkStatesBySecrets(string $mintUrl, string $unit, array $secrets): array {
        $secrets = array_values(array_unique(array_filter(array_map('strval', $secrets), 'strlen')));
        if (empty($secrets)) {
            return [];
        }
        $proofs = self::storage($mintUrl, $unit)->getProofsBySecretsAsObjects($secrets);
        if (count($proofs) !== count($secrets)) {
            throw new \Exception('Some of these proofs are not in the wallet');
        }
        $byY = self::checkProofStates($mintUrl, $unit, $proofs);
        $out = [];
        foreach ($proofs as $proof) {
            $out[$proof->secret] = $byY[$proof->Y] ?? ProofState::UNKNOWN;
        }
        return $out;
    }

    /**
     * Does the namespace hold anything a store could still need: proofs in
     * any state, a NUT-13 counter, or an unfinished journal?
     */
    public static function namespaceHasData(string $mintUrl, string $unit): bool {
        if (rtrim($mintUrl, '/') === '') {
            return false;
        }
        $walletId = self::walletId($mintUrl, $unit);
        foreach (['cashu_proofs', 'cashu_counters', 'cashu_pending_operations'] as $table) {
            $exists = Database::fetchOne(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?",
                [$table]
            );
            if (!$exists) {
                continue;
            }
            $row = Database::fetchOne("SELECT 1 AS x FROM {$table} WHERE wallet_id = ? LIMIT 1", [$walletId]);
            if ($row) {
                return true;
            }
        }
        return false;
    }

    /**
     * What a namespace holds right now.
     *
     * @return array{balance: int, exported: int, pending: int, journals: int}
     */
    public static function namespaceHoldings(string $mintUrl, string $unit): array {
        $storage = self::storage($mintUrl, $unit);
        $exported = 0;
        foreach ($storage->getProofs(self::exportedState()) as $row) {
            $exported += (int)$row['amount'];
        }
        $pending = 0;
        foreach ($storage->getProofs(ProofState::PENDING) as $row) {
            $pending += (int)$row['amount'];
        }
        return [
            'balance' => $storage->getBalance(),
            'exported' => $exported,
            'pending' => $pending,
            'journals' => count($storage->getPendingOperations()),
        ];
    }

    /**
     * Is this mint+unit still on the store's backup list (enabled or not)?
     * A mint that stays listed is still enumerated by getStoreWalletAccounts(),
     * so its funds stay visible and reconciled after a primary switch.
     */
    public static function isMintRetainedAsBackup(string $storeId, string $mintUrl, string $unit): bool {
        $mintUrl = rtrim($mintUrl, '/');
        $unit = strtolower($unit);
        if ($mintUrl === '') {
            return false;
        }
        foreach (Config::getStoreBackupMints($storeId) as $backup) {
            if (rtrim((string)$backup['mint_url'], '/') === $mintUrl
                && strtolower((string)($backup['unit'] ?? 'sat')) === $unit) {
                return true;
            }
        }
        return false;
    }

    /**
     * Reasons a store's primary mint must not be switched right now, in plain
     * language. Empty when nothing is outstanding.
     *
     * @return string[]
     */
    public static function storeChangeBlockers(string $storeId): array {
        $reasons = [];

        $openQuotes = Database::fetchOne(
            "SELECT COUNT(*) AS c FROM invoices
             WHERE store_id = ? AND status IN ('New', 'Processing')
               AND quote_id IS NOT NULL AND quote_id <> ''",
            [$storeId]
        );
        if ((int)($openQuotes['c'] ?? 0) > 0) {
            $reasons[] = (int)$openQuotes['c'] . ' invoice(s) still waiting for payment at the current mint';
        }

        $pendingMelts = Database::fetchOne(
            "SELECT COUNT(*) AS c FROM melts WHERE store_id = ? AND status = 'pending'",
            [$storeId]
        );
        if ((int)($pendingMelts['c'] ?? 0) > 0) {
            $reasons[] = (int)$pendingMelts['c'] . ' fee payment(s) still being settled';
        }

        $pendingHooks = Database::fetchOne(
            "SELECT COUNT(*) AS c FROM webhook_deliveries wd
             JOIN webhooks w ON w.id = wd.webhook_id
             WHERE w.store_id = ? AND wd.status = 'pending'",
            [$storeId]
        );
        if ((int)($pendingHooks['c'] ?? 0) > 0) {
            $reasons[] = (int)$pendingHooks['c'] . ' webhook delivery(ies) not yet delivered';
        }

        $swaps = Database::fetchOne(
            "SELECT COUNT(*) AS c FROM swap_attempts
             WHERE store_id = ? AND status NOT IN (" . self::swapTerminalPlaceholders() . ")",
            array_merge([$storeId], self::SWAP_TERMINAL)
        );
        if ((int)($swaps['c'] ?? 0) > 0) {
            $reasons[] = (int)$swaps['c'] . ' customer swap(s) in flight';
        }
        $sweeps = Database::fetchOne(
            "SELECT COUNT(*) AS c FROM sweep_attempts
             WHERE store_id = ? AND status NOT IN (" . self::swapTerminalPlaceholders() . ")",
            array_merge([$storeId], self::SWAP_TERMINAL)
        );
        if ((int)($sweeps['c'] ?? 0) > 0) {
            $reasons[] = (int)$sweeps['c'] . ' cashout swap(s) in flight';
        }

        foreach (Config::getStoreWalletAccounts($storeId) as $account) {
            try {
                $held = self::namespaceHoldings($account['mint_url'], $account['unit']);
            } catch (\Throwable $e) {
                $reasons[] = 'funds at ' . $account['mint_url'] . ' that could not be checked (' . $e->getMessage() . ')';
                continue;
            }
            $label = $account['primary'] ? 'the current mint' : 'backup mint ' . $account['mint_url'];
            if ($held['journals'] > 0) {
                $reasons[] = $held['journals'] . ' withdrawal(s) or swap(s) still in progress at ' . $label;
            }
            if ($held['exported'] > 0) {
                $reasons[] = $held['exported'] . ' ' . $account['unit'] . ' in exported tokens nobody has cashed in at ' . $label;
            }
        }

        return $reasons;
    }

    /**
     * What a backup mint account still holds, in plain language.
     *
     * @return array{reasons: string[]}|null null when the row is unknown
     */
    public static function backupMintHoldings(int $backupMintId): ?array {
        $row = Database::fetchOne('SELECT store_id, mint_url, unit FROM store_mints WHERE id = ?', [$backupMintId]);
        if (!$row) {
            return null;
        }
        $unit = strtolower((string)($row['unit'] ?? 'sat'));
        try {
            $held = self::namespaceHoldings((string)$row['mint_url'], $unit);
        } catch (\Throwable $e) {
            // Cannot verify: treat as "holds something" rather than silently
            // allowing the removal.
            return ['reasons' => ['funds that could not be checked (' . $e->getMessage() . ')']];
        }
        $reasons = [];
        if ($held['balance'] > 0) {
            $reasons[] = "{$held['balance']} {$unit} of ecash";
        }
        if ($held['exported'] > 0) {
            $reasons[] = "{$held['exported']} {$unit} in exported tokens nobody has cashed in";
        }
        if ($held['pending'] > 0 || $held['journals'] > 0) {
            $reasons[] = max($held['journals'], 1) . ' transfer(s) still in progress';
        }
        return ['reasons' => $reasons];
    }

    private static function swapTerminalPlaceholders(): string {
        return implode(',', array_fill(0, count(self::SWAP_TERMINAL), '?'));
    }
}
