<?php
/**
 * CashuPayServer - Background task runner
 *
 * One task list, shared by the HTTP cron endpoint, WP-Cron and the admin "Run now"
 * button, so every deployment mode does the same work.
 *
 * Two properties matter on shared hosting:
 *
 *  - A **lease**, so two overlapping runs (a real cron tick and an API-triggered
 *    self-request, say) never both attempt an auto-withdrawal.
 *  - A **time budget with a persisted cursor**. Tasks run in round-robin order and stop
 *    when the budget is spent, so a slow mint at the head of the list cannot starve the
 *    webhook outbox at the tail forever — the next run starts where this one stopped.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/config.php';
// Used by the sync task. cron.php happens to load this first; WP-Cron does not, so
// without an explicit require sync_proofs fataled on every WordPress run.
require_once __DIR__ . '/background.php';
require_once __DIR__ . '/invoice.php';
require_once __DIR__ . '/lightning_address.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/transfer.php';
require_once __DIR__ . '/payment_request.php';
require_once __DIR__ . '/webhook_sender.php';

class BackgroundRunner {
    /** Wall-clock seconds one run may spend before yielding to the next. */
    const DEFAULT_BUDGET = 20;

    /** How long a run holds the lease before another run may take over. */
    const LEASE_SECONDS = 120;

    /**
     * Tasks that run on *every* tick, before the round-robin remainder.
     *
     * Round-robin stops a slow task starving the others, but applying it to the payment
     * path means a customer's settlement waits for whichever housekeeping happens to be
     * next in line. These three are what turn a paid invoice into a completed order, so
     * they never wait their turn: recovery of unresolved outgoing operations, the quote
     * poll that marks an invoice Settled, and the outbox that tells the shop about it.
     */
    private const ALWAYS_RUN = ['recover_wallet_operations', 'poll_quotes', 'webhook_outbox'];

    /**
     * Tasks in a fixed order. Recovery of ambiguous outgoing operations always runs
     * first — starting new money movement while an old one is unresolved is how a
     * payment gets made twice.
     *
     * @return array<string, callable(): mixed>
     */
    public static function tasks(): array {
        return [
            'recover_wallet_operations' => fn() => Invoice::recoverPendingWalletOperations(),
            'reconcile_transfers' => fn() => self::reconcileTransfers(),
            'poll_quotes' => function () {
                Invoice::pollPendingQuotes();
                return 'success';
            },
            'webhook_outbox' => function () {
                $attempted = WebhookSender::deliverPending();
                return $attempted > 0 ? "attempted {$attempted}" : 'none';
            },
            'recover_orphaned' => function () {
                $count = count(Invoice::recoverOrphanedInvoices());
                return $count > 0 ? "recovered {$count}" : 'none';
            },
            'recover_expired_paid' => function () {
                $count = count(Invoice::recoverExpiredPaidInvoices());
                return $count > 0 ? "recovered {$count}" : 'none';
            },
            'expire_invoices' => function () {
                $expired = Invoice::markExpiredInvoices();
                return "expired {$expired} invoices";
            },
            'auto_melt' => fn() => self::autoMelt(),
            'sync_proofs' => fn() => self::syncProofs(),
            'rotate_keyset_proofs' => fn() => self::rotateKeysetProofs(),
            'clean_cache' => function () {
                Security::cleanCache();
                return 'success';
            },
            'expire_old_invoices' => function () {
                $veryOld = Database::query(
                    "UPDATE invoices SET status = 'Expired'
                     WHERE status = 'New' AND created_at < ?",
                    [time() - 30 * 24 * 3600]
                )->rowCount();
                return "expired {$veryOld} old invoices";
            },
            'cleanup_invoices' => fn() => self::cleanupInvoices(),
            'cleanup_pending_ops' => fn() => self::cleanupPendingOperations(),
            'cleanup_webhooks' => fn() => self::cleanupWebhooks(),
            'cleanup_payment_requests' => function () {
                // Only unpaid ones: a paid request keeps its stored result so a sender
                // retrying after a lost response gets the original answer back.
                $removed = PaymentRequest::cleanup();
                return $removed > 0 ? "removed {$removed} unpaid requests" : 'none';
            },
        ];
    }

    /**
     * Run as many tasks as the budget allows, starting where the last run stopped.
     *
     * @param int $budgetSeconds Wall-clock budget for this run
     * @return array Result document (also recorded as the heartbeat)
     */
    public static function run(int $budgetSeconds = self::DEFAULT_BUDGET): array {
        $lease = self::acquireLease();
        if ($lease === null) {
            return ['timestamp' => time(), 'skipped' => 'another run holds the lease', 'tasks' => []];
        }

        $started = microtime(true);
        $results = ['timestamp' => time(), 'tasks' => []];
        $tasks = self::tasks();
        // Config::get() already decodes JSON, so this comes back as an array. Decoding
        // it a second time yielded [] every run and quietly discarded the health of
        // every task that did not run in this cycle.
        $health = Config::get('cron_task_health', []);
        if (!is_array($health)) {
            $health = [];
        }

        // Everything except the payment path takes its turn.
        $rotating = array_values(array_diff(array_keys($tasks), self::ALWAYS_RUN));
        $cursor = (int)Config::get('cron_task_cursor', '0');
        $count = count($rotating);
        $ran = 0;

        $runTask = function (string $name) use ($tasks, &$results, &$health): void {
            try {
                $results['tasks'][$name] = $tasks[$name]();
                $health[$name] = ['last_ok' => time()];
            } catch (Throwable $e) {
                $results['tasks'][$name] = 'error: ' . $e->getMessage();
                $health[$name] = [
                    'last_error' => time(),
                    'message' => mb_substr($e->getMessage(), 0, 300),
                ];
                error_log("CashuPayServer cron task {$name} failed: " . $e->getMessage());
            }
        };

        try {
            // The payment path runs first and is not subject to the budget: a customer's
            // settlement must never be skipped because housekeeping ran long.
            foreach (self::ALWAYS_RUN as $name) {
                if (isset($tasks[$name])) {
                    $runTask($name);
                }
            }

            for ($i = 0; $i < $count; $i++) {
                if (microtime(true) - $started > $budgetSeconds) {
                    break;
                }
                $runTask($rotating[($cursor + $i) % $count]);
                $ran++;
            }

            $results['ran'] = count($results['tasks']);
            $results['durationMs'] = (int)round((microtime(true) - $started) * 1000);
            Config::set('cron_task_cursor', (string)($count > 0 ? ($cursor + $ran) % $count : 0));
            Config::set('cron_task_health', $health);
            Config::set('cron_last_run', (string)time());
            Config::set('cron_last_result', $results);
        } finally {
            self::releaseLease($lease);
        }

        return $results;
    }

    /** Latest heartbeat for the dashboard's diagnostics view. */
    public static function status(): array {
        $lastRun = (int)Config::get('cron_last_run', '0');
        $health = Config::get('cron_task_health', []);
        $lastResult = Config::get('cron_last_result');

        return [
            'lastRun' => $lastRun ?: null,
            'secondsAgo' => $lastRun ? time() - $lastRun : null,
            'taskHealth' => is_array($health) ? $health : [],
            'lastResult' => is_array($lastResult) ? $lastResult : null,
        ];
    }

    // --- lease ------------------------------------------------------------

    private static function acquireLease(): ?string {
        $token = bin2hex(random_bytes(16));
        $pdo = Database::getInstance();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $until = (int)Config::get('cron_lease_until', '0');
            if ($until > time()) {
                $pdo->exec('COMMIT');
                return null;
            }
            Config::set('cron_lease_until', (string)(time() + self::LEASE_SECONDS));
            Config::set('cron_lease_token', $token);
            $pdo->exec('COMMIT');
            return $token;
        } catch (Throwable $e) {
            try { $pdo->exec('ROLLBACK'); } catch (Throwable $ignored) {}
            throw $e;
        }
    }

    private static function releaseLease(string $token): void {
        try {
            if (hash_equals((string)Config::get('cron_lease_token', ''), $token)) {
                Config::set('cron_lease_until', '0');
            }
        } catch (Throwable $e) {
            error_log('CashuPayServer: failed to release cron lease: ' . $e->getMessage());
        }
    }

    // --- individual tasks -------------------------------------------------

    /** Every configured store, as id rows. */
    private static function stores(): array {
        return Database::fetchAll(
            "SELECT id FROM stores WHERE mint_url IS NOT NULL AND seed_phrase IS NOT NULL"
        );
    }

    /**
     * Resolve outgoing operations that never reached a terminal state.
     *
     * Withdrawals are matched by the melt quote recorded in `reference`; once the
     * library's recovery has settled that quote the ledger row can follow. Donations
     * whose token is still held are re-sent.
     */
    private static function reconcileTransfers(): array {
        $result = ['checked' => 0, 'completed' => 0, 'failed' => 0, 'still_pending' => 0];

        foreach (Transfer::getPending() as $row) {
            $result['checked']++;
            try {
                if ($row['type'] === Transfer::TYPE_DONATION) {
                    if (empty($row['token'])) {
                        $result['still_pending']++;
                        continue;
                    }
                    if (Donation::postTokenToSink($row['token'])) {
                        Transfer::complete($row['id'], (int)$row['amount']);
                        if (!empty($row['reference'])) {
                            Invoice::markProofsSpent($row['store_id'], explode(',', $row['reference']));
                        }
                        $result['completed']++;
                    } else {
                        $result['still_pending']++;
                    }
                    continue;
                }

                if ($row['type'] === Transfer::TYPE_TOKEN_EXPORT) {
                    // An export is resolved by its recipient, not by us. It stays pending
                    // until the proofs show up SPENT at the mint.
                    $result['still_pending']++;
                    continue;
                }

                // Lightning withdrawal: the melt quote is authoritative.
                $quoteId = $row['reference'] ?? '';
                if ($quoteId === '') {
                    $result['still_pending']++;
                    continue;
                }
                $wallet = Invoice::getWalletInstance($row['store_id']);
                if ($wallet->getStorage()->getPendingOperationById('melt:' . $quoteId) !== null) {
                    $result['still_pending']++;
                    continue;
                }
                $quote = $wallet->checkMeltQuote($quoteId);
                if ($quote->isPaid()) {
                    Transfer::complete($row['id'], (int)$row['amount'], 0, $quote->paymentPreimage);
                    $result['completed']++;
                } elseif ($quote->isUnpaid() && $quote->expiry !== null && $quote->expiry < time()) {
                    Transfer::fail($row['id'], 'Melt quote expired unpaid; inputs released');
                    $result['failed']++;
                } else {
                    $result['still_pending']++;
                }
            } catch (Throwable $e) {
                $result['still_pending']++;
                error_log("CashuPayServer: transfer {$row['id']} reconciliation failed: " . $e->getMessage());
            }
        }

        return $result;
    }

    /** checkAutoMelt() returns one entry per store, not a single result. */
    private static function autoMelt() {
        $meltResults = LightningAddress::checkAutoMelt();
        if (empty($meltResults)) {
            return 'skipped';
        }
        return array_map(
            fn($entry) => is_array($entry)
                ? ['store' => $entry['store'] ?? null, 'amount' => $entry['amountPaid'] ?? null, 'error' => $entry['error'] ?? null]
                : $entry,
            $meltResults
        );
    }

    /**
     * Ask the mint which locally-unspent proofs it already considers spent.
     *
     * This task used to instantiate each wallet and count it as synced without calling
     * syncProofStates(), so a balance could stay wrong until a withdrawal failed.
     */
    private static function syncProofs(): string {
        if (!Background::shouldSync()) {
            return 'skipped (recently synced)';
        }

        $checked = 0;
        $updated = 0;
        $errors = 0;
        foreach (self::stores() as $store) {
            try {
                $wallet = Invoice::getWalletInstance($store['id']);
                if (!$wallet->hasStorage()) {
                    continue;
                }
                $sync = $wallet->syncProofStates();
                $checked += (int)($sync['checked'] ?? 0);
                $updated += (int)($sync['updated'] ?? 0);
                $errors += (int)($sync['errors'] ?? 0);
            } catch (Throwable $e) {
                $errors++;
                error_log("Sync failed for store {$store['id']}: " . $e->getMessage());
            }
        }
        Background::markSynced();

        return "checked {$checked} proofs, marked {$updated} spent, {$errors} errors";
    }

    private static function rotateKeysetProofs(): string {
        $lastRotation = (int)Config::get('last_keyset_rotation_check', '0');
        if (time() - $lastRotation < 6 * 3600) {
            return 'skipped (recently checked)';
        }

        $rotated = 0;
        $checked = 0;
        foreach (self::stores() as $store) {
            try {
                $wallet = Invoice::getWalletInstance($store['id']);
                if ($wallet->hasStorage()) {
                    $rotation = $wallet->rotateProofs();
                    $rotated += $rotation['rotated'];
                    $checked += $rotation['checked'];
                    foreach ($rotation['errors'] as $rotationError) {
                        error_log("Keyset rotation error for store {$store['id']}: {$rotationError}");
                    }
                }
            } catch (Throwable $e) {
                error_log("Keyset rotation failed for store {$store['id']}: " . $e->getMessage());
            }
        }
        Config::set('last_keyset_rotation_check', (string)time());

        return "checked {$checked} proofs, rotated {$rotated}";
    }

    /**
     * Delete only invoices whose payment claim is genuinely settled.
     *
     * An invoice row carries the quote ID needed to mint a late payment. Deleting one
     * whose quote was never resolved makes those sats unmintable, so age alone is not a
     * reason to drop it.
     */
    private static function cleanupInvoices(): string {
        $deleted = Database::query(
            "DELETE FROM invoices
             WHERE created_at < ?
               AND (
                 status = 'Settled'
                 OR (status IN ('Expired', 'Invalid') AND (quote_id IS NULL OR quote_id = ''))
               )",
            [time() - 90 * 24 * 3600]
        )->rowCount();

        return "deleted {$deleted} old invoices";
    }

    private static function cleanupPendingOperations(): string {
        $totalCleaned = 0;
        foreach (self::stores() as $store) {
            try {
                $wallet = Invoice::getWalletInstance($store['id']);
                if ($wallet->hasStorage()) {
                    $totalCleaned += $wallet->getStorage()->cleanExpiredPendingOperations();
                }
            } catch (Throwable $e) {
                error_log("Cleanup failed for store {$store['id']}: " . $e->getMessage());
            }
        }
        return "cleaned {$totalCleaned}";
    }

    /**
     * Trim delivered webhook history, never unresolved work.
     *
     * The old query also removed rows with attempts >= 4, which is precisely the set an
     * operator still needs to see and redeliver.
     */
    private static function cleanupWebhooks(): string {
        $countResult = Database::fetchOne(
            "SELECT COUNT(*) as cnt FROM webhook_deliveries WHERE delivered_at IS NOT NULL"
        );
        $totalCount = (int)($countResult['cnt'] ?? 0);
        if ($totalCount <= 1000) {
            return 'skipped (under limit)';
        }

        $deleteCount = $totalCount - 1000;
        Database::query("
            DELETE FROM webhook_deliveries
            WHERE id IN (
                SELECT id FROM webhook_deliveries
                WHERE delivered_at IS NOT NULL
                ORDER BY created_at ASC LIMIT ?
            )
        ", [$deleteCount]);

        return "deleted {$deleteCount} delivered records";
    }
}
