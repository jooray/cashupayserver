<?php
/**
 * Swap lifecycle poller: drives swap_attempts (customer flow) and
 * sweep_attempts (auto-melt flow) rows through the Boltz/Zeus reverse-swap
 * status machine, builds + broadcasts the claim transaction when ready,
 * and runs the per-context tail-end action on terminal status.
 *
 * Called from cron.php each tick. Uses last_polled_at as an atomic gate so
 * two concurrent cron invocations don't both work the same row.
 *
 * The same code drives both tables — a SwapSettlementContext supplied by the
 * caller picks the target table and the on-settled / on-invalid handler.
 * When omitted, defaults to the historical customer/swap_attempts flow.
 */

require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../webhook_sender.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/claimer.php';
require_once __DIR__ . '/settlement_context.php';

final class SwapPoller {
    // Statuses we consider terminal — rows in these states are skipped on poll.
    private const TERMINAL_STATUSES = [
        'invoice.settled',
        'swap.expired',
        'transaction.refunded',
        'transaction.failed',
        'invoice.expired',
        'claim.confirmed', // our own terminal
        'error',
    ];

    /**
     * Walk active rows for the supplied context, advance state, attempt
     * claim where possible. Per-row failures are logged and don't halt
     * the loop.
     *
     * @return array{polled:int, errors:int}
     */
    public static function pollPending(
        int $minInterval = 30,
        int $batchLimit = 20,
        ?SwapSettlementContext $ctx = null
    ): array {
        $ctx = $ctx ?? new CustomerSwapSettlement();
        $table = $ctx->tableName();
        $now = time();
        $pdo = Database::getInstance();
        $placeholders = implode(',', array_fill(0, count(self::TERMINAL_STATUSES), '?'));
        // $minInterval is not user input — inline it instead of binding, to
        // avoid a PDO+SQLite quirk where integer parameters bound through an
        // execute() array get coerced to TEXT and break numeric comparisons.
        $mi = (int)$minInterval;
        $params = self::TERMINAL_STATUSES;
        array_push($params, $now, $batchLimit);

        $rows = $pdo->prepare(
            "SELECT * FROM {$table}
             WHERE status NOT IN ({$placeholders})
             AND (last_polled_at IS NULL OR (CAST(? AS INTEGER) - last_polled_at) >= {$mi})
             ORDER BY
                 CASE WHEN last_polled_at IS NULL THEN 0 ELSE 1 END,
                 last_polled_at ASC
             LIMIT ?"
        );
        $rows->execute($params);
        $rowList = $rows->fetchAll(PDO::FETCH_ASSOC);

        $polled = 0;
        $errors = 0;
        foreach ($rowList as $row) {
            $res = self::tryAdvanceRow($row, $ctx, $mi, $now);
            if ($res === null) {
                continue; // another poller has this row
            }
            $polled++;
            if ($res === false) {
                $errors++;
            }
        }
        return ['polled' => $polled, 'errors' => $errors];
    }

    /**
     * Advance a single swap row keyed by its parent invoice. Lets the
     * customer-facing checkout poll drive settlement directly instead of
     * waiting for cron (Task 4c), so the invoice flips to Settled within a
     * checkout poll or two of the provider reporting invoice.settled.
     *
     * Customer flow only (swap_attempts). Shares pollPending()'s atomic
     * last_polled_at gate, so it's safe against the cron task and other
     * concurrent checkout polls touching the same swap — and $minInterval
     * keeps us from hitting the provider API on every 2s checkout tick.
     *
     * @return array{polled:int, errors:int}
     */
    public static function pollByInvoiceId(string $invoiceId, int $minInterval = 8): array {
        $ctx = new CustomerSwapSettlement();
        $table = $ctx->tableName();
        $now = time();
        $mi = (int)$minInterval;
        $pdo = Database::getInstance();
        $placeholders = implode(',', array_fill(0, count(self::TERMINAL_STATUSES), '?'));
        $params = array_merge([$invoiceId], self::TERMINAL_STATUSES);

        $stmt = $pdo->prepare(
            "SELECT * FROM {$table}
              WHERE invoice_id = ?
                AND status NOT IN ({$placeholders})
              ORDER BY id ASC
              LIMIT 1"
        );
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['polled' => 0, 'errors' => 0];
        }

        $res = self::tryAdvanceRow($row, $ctx, $mi, $now);
        if ($res === null) {
            return ['polled' => 0, 'errors' => 0]; // gated, or another poller has it
        }
        return ['polled' => 1, 'errors' => $res === false ? 1 : 0];
    }

    /**
     * Atomically claim one swap row via the last_polled_at gate, then drive
     * it through one tick of state. Shared by pollPending() (batch) and
     * pollByInvoiceId() (single). Returns null if another poller holds the
     * row (gate lost), true on a clean tick, false if the tick errored.
     *
     * $mi is a trusted int inlined into SQL (not bound) to dodge a
     * PDO+SQLite quirk that coerces bound integer params to TEXT and breaks
     * the numeric comparison — same reason pollPending() inlines it.
     */
    private static function tryAdvanceRow(array $row, SwapSettlementContext $ctx, int $mi, int $now): ?bool {
        $pdo = Database::getInstance();
        $table = $ctx->tableName();
        // Atomic claim: only proceed if we successfully stamp last_polled_at.
        $upd = $pdo->prepare(
            "UPDATE {$table}
                SET last_polled_at = ?, updated_at = ?
              WHERE id = ?
                AND (last_polled_at IS NULL OR (CAST(? AS INTEGER) - last_polled_at) >= {$mi})"
        );
        $upd->execute([$now, $now, $row['id'], $now]);
        if ($upd->rowCount() !== 1) {
            return null; // another poller has this row
        }

        try {
            self::processRow($row, $ctx);
            return true;
        } catch (Throwable $e) {
            error_log("SwapPoller ({$table}) row {$row['id']}: " . $e->getMessage());
            $pdo->prepare(
                "UPDATE {$table} SET error_message = ?, updated_at = ? WHERE id = ?"
            )->execute([substr($e->getMessage(), 0, 500), time(), $row['id']]);
            return false;
        }
    }

    /**
     * Cancel held HTLCs for customer swap_attempts whose parent invoice has
     * expired locally before the customer paid. Best-effort: errors logged,
     * never thrown. Sets local status to 'invoice.expired' so the row is now
     * terminal.
     *
     * Customer-only: sweep rows don't have a parent invoice to track, and
     * the provider's own swap timeout drives them to a terminal status via
     * pollPending().
     */
    public static function expireStale(): void {
        $rows = Database::fetchAll(
            "SELECT sa.*, i.expiration_time AS inv_expiration, i.status AS inv_status
               FROM swap_attempts sa
               JOIN invoices i ON i.id = sa.invoice_id
              WHERE sa.status = 'swap.created'
                AND i.expiration_time < ?",
            [time()]
        );
        $ctx = new CustomerSwapSettlement();
        foreach ($rows as $row) {
            $provider = SwapProviderFactory::byName($row['provider']);
            if ($provider) {
                try {
                    $provider->cancelInvoice($row['network'], $row['swap_id_external']);
                } catch (Throwable $e) {
                    error_log("SwapPoller cancelInvoice {$row['id']}: " . $e->getMessage());
                }
            }
            self::transitionToInvalid($row, 'invoice.expired', 'Invoice expired before customer payment', $ctx);
        }
    }

    /**
     * Release the held swap invoice(s) of ONE invoice whose Lightning side was
     * re-sourced elsewhere (a long-lived invoice's refresh): nobody is shown
     * the swap's bolt11 any more. Same terminal transition as expireStale,
     * best-effort against the provider.
     */
    public static function cancelOpenAttemptsForInvoice(string $invoiceId, string $message): void {
        $rows = Database::fetchAll(
            "SELECT * FROM swap_attempts WHERE invoice_id = ? AND status = 'swap.created'",
            [$invoiceId]
        );
        $ctx = new CustomerSwapSettlement();
        foreach ($rows as $row) {
            $provider = SwapProviderFactory::byName($row['provider']);
            if ($provider) {
                try {
                    $provider->cancelInvoice($row['network'], $row['swap_id_external']);
                } catch (Throwable $e) {
                    error_log("SwapPoller cancelInvoice {$row['id']}: " . $e->getMessage());
                }
            }
            self::transitionToInvalid($row, 'invoice.expired', $message, $ctx);
        }
    }

    /**
     * Drive a single row through one tick of state.
     */
    private static function processRow(array $row, SwapSettlementContext $ctx): void {
        $provider = SwapProviderFactory::byName($row['provider']);
        if (!$provider) {
            throw new RuntimeException("Unknown provider in DB row: {$row['provider']}");
        }
        $status = $provider->getSwapStatus($row['network'], $row['swap_id_external']);
        if ($status === null) {
            // 404: provider forgot about the swap. Treat as failed.
            self::transitionToInvalid($row, 'transaction.failed', 'Provider returned 404 for swap', $ctx);
            return;
        }

        $table = $ctx->tableName();
        // Mirror status into our row, persisting the preimage if it appeared.
        if ($status->preimage && empty($row['preimage_hex'])) {
            Database::getInstance()->prepare(
                "UPDATE {$table} SET status = ?, preimage_hex = ?, updated_at = ? WHERE id = ?"
            )->execute([$status->status, $status->preimage, time(), $row['id']]);
            $row['preimage_hex'] = $status->preimage;
        } else {
            Database::getInstance()->prepare(
                "UPDATE {$table} SET status = ?, updated_at = ? WHERE id = ?"
            )->execute([$status->status, time(), $row['id']]);
        }
        $row['status'] = $status->status;

        switch ($status->status) {
            case 'swap.created':
            case 'minerfee.paid':
                // waiting for LN payment / setup. No action.
                return;

            case 'transaction.mempool':
            case 'transaction.confirmed':
                if (empty($row['claim_txid'])) {
                    if ($status->lockupTxHex === null) {
                        throw new RuntimeException('Provider returned ' . $status->status . ' without lockup tx hex');
                    }
                    SwapClaimer::buildAndBroadcast($row, $status->lockupTxHex, $ctx);
                }
                return;

            case 'invoice.settled':
                self::transitionToSettled($row, $ctx);
                return;

            case 'invoice.expired':
            case 'swap.expired':
            case 'transaction.refunded':
            case 'transaction.failed':
                self::transitionToInvalid($row, $status->status, "Provider reported {$status->status}", $ctx);
                return;

            default:
                // Unknown / future status string: leave the row alone so it'll
                // be polled again later. Log once so we can spot new states.
                error_log("SwapPoller: unrecognized status '{$status->status}' for {$row['id']}");
                return;
        }
    }

    private static function transitionToSettled(array $row, SwapSettlementContext $ctx): void {
        $pdo = Database::getInstance();
        $table = $ctx->tableName();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                "UPDATE {$table} SET status = 'invoice.settled', updated_at = ? WHERE id = ?"
            )->execute([time(), $row['id']]);
            // Idempotency lives in the context's onSettled(), which only fires
            // InvoiceSettled / the sweep notification when it actually flips the
            // target row (rowCount===1). We can't gate on the swap row's status
            // here because processRow() already mirrored the provider's
            // 'invoice.settled' onto it before we run.
            $ctx->onSettled($row);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private static function transitionToInvalid(
        array $row,
        string $providerStatus,
        string $message,
        SwapSettlementContext $ctx
    ): void {
        $pdo = Database::getInstance();
        $table = $ctx->tableName();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                "UPDATE {$table} SET status = ?, error_message = ?, updated_at = ? WHERE id = ?"
            )->execute([$providerStatus, substr($message, 0, 500), time(), $row['id']]);
            $ctx->onInvalid($row, $providerStatus, $message);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
