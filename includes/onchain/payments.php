<?php
/**
 * CashuPayServer - On-chain payment lifecycle.
 *
 * Address allocation, provider polling, state-machine transitions and
 * webhook firing for invoices configured with an on-chain payment method.
 */

require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../webhook_sender.php';
require_once __DIR__ . '/wallet.php';
require_once __DIR__ . '/provider.php';

class OnchainPayments {
    /**
     * Atomically allocate the next receive address for a store.
     *
     * @return array{address:string, index:int}|null  null if store has no xpub configured
     */
    public static function allocateAddress(string $storeId): ?array {
        $pdo = Database::getInstance();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                "SELECT * FROM stores WHERE id = ?"
            );
            $stmt->execute([$storeId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row || empty($row['onchain_xpub'])) {
                $pdo->commit();
                return null;
            }

            // Counter is keyed by xpub, not by store, so two stores sharing
            // the same xpub never derive the same address, and re-pasting a
            // previously used xpub resumes from where it left off.
            $xpubHash = hash('sha256', $row['onchain_xpub']);
            $now = time();
            $pdo->prepare(
                "INSERT INTO onchain_xpub_state (xpub_hash, next_index, updated_at)
                 VALUES (?, 0, ?) ON CONFLICT(xpub_hash) DO NOTHING"
            )->execute([$xpubHash, $now]);
            $sel = $pdo->prepare(
                "SELECT next_index FROM onchain_xpub_state WHERE xpub_hash = ?"
            );
            $sel->execute([$xpubHash]);
            $index = (int)$sel->fetchColumn();
            $pdo->prepare(
                "UPDATE onchain_xpub_state SET next_index = next_index + 1, updated_at = ?
                  WHERE xpub_hash = ?"
            )->execute([$now, $xpubHash]);

            // Mirror the index back to the stores row too, for backwards-
            // compat reporting (admin dashboard surfaces it as nextIndex).
            $pdo->prepare(
                "UPDATE stores SET onchain_next_index = ? WHERE id = ?"
            )->execute([$index + 1, $storeId]);

            $address = OnchainWallet::deriveAddress(
                $row['onchain_xpub'],
                $row['onchain_address_type'] ?: 'P2WPKH',
                $row['onchain_network'] ?: 'mainnet',
                $index
            );

            // Capture the chain tip at allocation time. The poller compares
            // each observation's block_height against this to discard payments
            // that existed on a re-used address BEFORE the invoice was created.
            // Provider call is best-effort: if it fails, we just leave the
            // tip NULL and skip filtering (old behavior).
            $tip = null;
            try {
                $provider = OnchainProviderFactory::forStore($row);
                $tip = $provider->currentTipHeight();
            } catch (Throwable $_) {
                // Logged on the first real poll; no need to spam here.
            }

            $pdo->commit();
            return ['address' => $address, 'index' => $index, 'tip_height' => $tip];
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Poll the configured provider for an invoice's address and apply any
     * resulting lifecycle transitions.
     *
     * @return array{
     *   total_confirmed:int, total_pending:int, observation_count:int,
     *   status:string, transition:?string
     * }
     */
    public static function pollInvoice(string $invoiceId): array {
        $invoice = Database::fetchOne("SELECT * FROM invoices WHERE id = ?", [$invoiceId]);
        if (!$invoice) {
            throw new Exception("Invoice not found: {$invoiceId}");
        }
        $address = $invoice['onchain_address'] ?? null;
        if (!$address) {
            return ['total_confirmed' => 0, 'total_pending' => 0, 'observation_count' => 0,
                    'status' => $invoice['status'], 'transition' => null];
        }
        $store = Database::fetchOne("SELECT * FROM stores WHERE id = ?", [$invoice['store_id']]);
        if (!$store) {
            throw new Exception("Store not found: {$invoice['store_id']}");
        }

        $provider = OnchainProviderFactory::forStore($store);
        $observations = $provider->addressTransactions($address);

        $minConfs = (int)($store['onchain_min_confs'] ?? 1);
        $now = time();

        // Filter out historical UTXOs on a re-used address — any tx confirmed
        // strictly before the invoice was created. onchain_created_tip_height
        // is the chain tip at allocation time; an observation with
        // block_height < that height was mined before the invoice existed.
        // Mempool observations (block_height === null) are always kept.
        // Legacy invoices without a recorded tip skip the filter.
        $createdTip = $invoice['onchain_created_tip_height'] ?? null;
        if ($createdTip !== null) {
            $observations = array_values(array_filter($observations, function ($obs) use ($createdTip) {
                return $obs->blockHeight === null || $obs->blockHeight >= (int)$createdTip;
            }));
        }

        // Upsert observations into onchain_payments.
        foreach ($observations as $obs) {
            self::upsertObservation($invoiceId, $obs, $now);
        }

        // Recompute totals from the persisted state (canonical source).
        $rows = Database::fetchAll(
            "SELECT amount_sat, confirmations FROM onchain_payments WHERE invoice_id = ?",
            [$invoiceId]
        );
        $totalConfirmed = 0;
        $totalPending = 0;
        foreach ($rows as $r) {
            if ((int)$r['confirmations'] >= $minConfs) {
                $totalConfirmed += (int)$r['amount_sat'];
            } else {
                $totalPending += (int)$r['amount_sat'];
            }
        }

        $transition = self::applyTransitions(
            $invoice,
            $store,
            totalConfirmed: $totalConfirmed,
            totalPending: $totalPending,
            anyObservation: count($rows) > 0,
            now: $now
        );

        return [
            'total_confirmed' => $totalConfirmed,
            'total_pending' => $totalPending,
            'observation_count' => count($rows),
            'status' => $transition['status'] ?? $invoice['status'],
            'transition' => $transition['fired'] ?? null,
        ];
    }

    /**
     * Build the BTC-OnChain payment-method block for the Greenfield API.
     */
    public static function formatPaymentMethod(array $invoice): ?array {
        $address = $invoice['onchain_address'] ?? null;
        if (!$address) {
            return null;
        }
        $amountSat = (int)($invoice['onchain_amount_sat'] ?? 0);
        $btc = bcdiv((string)$amountSat, '100000000', 8);
        // Strip trailing zeros from the BTC amount for the BIP21 URI.
        $btcDisplay = rtrim(rtrim($btc, '0'), '.');
        $btcDisplay = $btcDisplay === '' ? '0' : $btcDisplay;
        return [
            'destination' => $address,
            'paymentLink' => "bitcoin:{$address}?amount={$btcDisplay}",
            'amount' => (string)$amountSat,
            'due' => (string)$amountSat,
            'rate' => $invoice['exchange_rate'] ?? null,
        ];
    }

    /**
     * Run pollInvoice() for every New/Processing invoice that has an
     * on-chain address. Used by the cron task.
     *
     * @param int $minIntervalSec Minimum seconds between consecutive polls of the same invoice
     * @param int $batchLimit Maximum invoices to poll per call
     */
    public static function pollPending(int $minIntervalSec = 60, int $batchLimit = 20): array {
        $now = time();
        $rows = Database::fetchAll(
            "SELECT id FROM invoices
              WHERE onchain_address IS NOT NULL
                AND status IN ('New', 'Processing')
                AND (last_polled_at IS NULL OR (? - last_polled_at) >= ?)
              ORDER BY last_polled_at ASC NULLS FIRST
              LIMIT ?",
            [$now, $minIntervalSec, $batchLimit]
        );
        $results = [];
        foreach ($rows as $r) {
            try {
                Database::query(
                    "UPDATE invoices SET last_polled_at = ? WHERE id = ?",
                    [$now, $r['id']]
                );
                $results[$r['id']] = self::pollInvoice($r['id']);
            } catch (Throwable $e) {
                error_log("on-chain poll failed for {$r['id']}: " . $e->getMessage());
                $results[$r['id']] = ['error' => $e->getMessage()];
            }
        }
        return $results;
    }

    // ---------- internals ----------

    private static function upsertObservation(string $invoiceId, OnchainTxObservation $obs, int $now): void {
        $existing = Database::fetchOne(
            "SELECT id, first_seen_at FROM onchain_payments WHERE txid = ? AND vout = ?",
            [$obs->txid, $obs->vout]
        );
        if ($existing) {
            Database::query(
                "UPDATE onchain_payments
                    SET confirmations = ?, block_height = ?, last_seen_at = ?
                  WHERE id = ?",
                [$obs->confirmations, $obs->blockHeight, $now, $existing['id']]
            );
            return;
        }
        Database::insert('onchain_payments', [
            'id' => Database::generateId('och'),
            'invoice_id' => $invoiceId,
            'txid' => $obs->txid,
            'vout' => $obs->vout,
            'amount_sat' => $obs->amountSat,
            'confirmations' => $obs->confirmations,
            'block_height' => $obs->blockHeight,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
        ]);
    }

    /**
     * Walk the on-chain state machine and fire webhooks for any transition.
     *
     * @return array{status:?string, fired:?string}
     */
    private static function applyTransitions(
        array $invoice,
        array $store,
        int $totalConfirmed,
        int $totalPending,
        bool $anyObservation,
        int $now
    ): array {
        $amount = (int)($invoice['onchain_amount_sat'] ?? 0);
        $status = $invoice['status'];
        $fired = null;

        // First mempool sighting flips New -> Processing and starts TTCW.
        if ($status === 'New' && $anyObservation) {
            require_once __DIR__ . '/../invoice.php';
            Invoice::updateStatus($invoice['id'], 'Processing');
            Database::query(
                "UPDATE invoices SET onchain_first_seen_at = COALESCE(onchain_first_seen_at, ?) WHERE id = ?",
                [$now, $invoice['id']]
            );
            $status = 'Processing';
            $fired = 'InvoiceReceivedPayment';
            $invoice = Database::fetchOne("SELECT * FROM invoices WHERE id = ?", [$invoice['id']]);
        }

        // Settled when total confirmed reaches the invoice amount.
        if ($status !== 'Settled' && $totalConfirmed >= $amount && $amount > 0) {
            require_once __DIR__ . '/../invoice.php';
            Invoice::updateStatus($invoice['id'], 'Settled');
            return ['status' => 'Settled', 'fired' => $fired ?: 'InvoiceSettled'];
        }

        // Confirmation window expired without crossing the threshold.
        $firstSeen = (int)($invoice['onchain_first_seen_at'] ?? 0);
        $timeout = (int)($store['onchain_confirm_timeout_sec'] ?? 86400);
        if ($status === 'Processing' && $firstSeen > 0 && ($now - $firstSeen) >= $timeout && $totalConfirmed < $amount) {
            require_once __DIR__ . '/../invoice.php';
            Invoice::updateStatus($invoice['id'], 'Invalid');
            return ['status' => 'Invalid', 'fired' => 'InvoiceInvalid'];
        }

        return ['status' => $status, 'fired' => $fired];
    }
}
