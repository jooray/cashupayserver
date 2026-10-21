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
require_once __DIR__ . '/update_check.php';
require_once __DIR__ . '/transfer.php';
require_once __DIR__ . '/payment_request.php';
require_once __DIR__ . '/webhook_sender.php';

class BackgroundRunner {
    /** Wall-clock seconds one run may spend before yielding to the next. */
    const DEFAULT_BUDGET = 20;

    /** Largest budget a caller may ask for (cron.php's ?budget=). */
    const MAX_BUDGET = 300;

    /**
     * How long a run holds the lease before another run may take over. Renewed before
     * every task, and longer than the largest budget plus a slow task, so a run that is
     * still working never loses it; a run that died releases it within this time.
     */
    const LEASE_SECONDS = 360;

    /** Backoff for a task that keeps throwing: 1 min, doubling, capped. */
    const BACKOFF_BASE = 60;
    const BACKOFF_CAP = 3600;
    /** The payment path is retried sooner: a paid order is waiting on it. */
    const BACKOFF_CAP_ALWAYS_RUN = 900;

    /**
     * Plain-language names for the diagnostics view, and what it means for the shop
     * when the task keeps failing.
     */
    private const TASK_LABELS = [
        'update_check' => ['name' => 'Checking for updates', 'impact' => 'You may not hear about a new version or an urgent security notice.'],
        'recover_wallet_operations' => ['name' => 'Finishing interrupted payments', 'impact' => 'A withdrawal or payment that was cut off part-way is not being finished, so some money may not show in your balance yet.'],
        'ready_store_wallets' => ['name' => 'Preparing your wallet at a mint', 'impact' => 'New payments to this wallet cannot be collected until this finishes.'],
        'poll_quotes' => ['name' => 'Checking for customer payments', 'impact' => 'Paid orders may not be marked paid.'],
        'webhook_outbox' => ['name' => 'Telling your shop about payments', 'impact' => 'Your shop may not learn that an order was paid.'],
        'reconcile_transfers' => ['name' => 'Confirming withdrawals and exported tokens', 'impact' => 'Some outgoing transfers stay "in progress" longer than they should.'],
        'recover_orphaned' => ['name' => 'Rescuing interrupted invoices', 'impact' => 'A payment that was interrupted part-way may not be credited.'],
        'recover_expired_paid' => ['name' => 'Collecting late payments', 'impact' => 'A customer who paid just after the invoice expired may not be credited.'],
        'expire_invoices' => ['name' => 'Expiring unpaid invoices', 'impact' => 'Old unpaid invoices stay open longer than they should.'],
        'auto_melt' => ['name' => 'Automatic withdrawal', 'impact' => 'Your balance is not being sent to your Lightning address automatically.'],
        'sync_proofs' => ['name' => 'Checking your balance with the mint', 'impact' => 'Your balance may be shown higher than what you can actually spend.'],
        'rotate_keyset_proofs' => ['name' => 'Refreshing ecash the mint is retiring', 'impact' => 'Some of your balance may become harder to spend if the mint retires it.'],
        'clean_cache' => ['name' => 'Cleaning up temporary files', 'impact' => 'Temporary files are piling up. Nothing urgent.'],
        'cleanup_sessions' => ['name' => 'Removing old login sessions', 'impact' => 'Old login session files are piling up in the data folder. Nothing urgent.'],
        'expire_old_invoices' => ['name' => 'Expiring very old invoices', 'impact' => 'Very old unpaid invoices stay open. Nothing urgent.'],
        'cleanup_invoices' => ['name' => 'Removing old invoices', 'impact' => 'Old invoices are not being cleaned up. Nothing urgent.'],
        'cleanup_pending_ops' => ['name' => 'Clearing finished wallet records', 'impact' => 'Old wallet records are not being cleaned up. Nothing urgent.'],
        'cleanup_webhooks' => ['name' => 'Trimming the shop notification history', 'impact' => 'The notification history keeps growing. Nothing urgent.'],
        'finish_payment_requests' => ['name' => 'Confirming payments to payment requests', 'impact' => 'A payment-request QR that was paid may keep showing as unpaid.'],
        'cleanup_payment_requests' => ['name' => 'Removing old payment requests', 'impact' => 'Old payment requests are not being cleaned up. Nothing urgent.'],
    ];

    /**
     * Tasks that run on *every* tick, before the round-robin remainder.
     *
     * Round-robin stops a slow task starving the others, but applying it to the payment
     * path means a customer's settlement waits for whichever housekeeping happens to be
     * next in line. These are what turn a paid invoice into a completed order, so they
     * never wait their turn: recovery of unresolved outgoing operations, readying an
     * account that cannot mint yet (after a mint change), the quote poll that marks an
     * invoice Settled, and the outbox that tells the shop about it.
     *
     * The update check goes first. It is a no-op except every few hours, and it carries
     * the signed emergency notice: left in the rotation it could be starved for as long
     * as payment work filled the budget, however urgent the notice.
     */
    private const ALWAYS_RUN = ['update_check', 'recover_wallet_operations', 'ready_store_wallets', 'poll_quotes', 'webhook_outbox'];

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
            'finish_payment_requests' => fn() => PaymentRequest::reconcileReceiving(),
            'update_check' => fn() => UpdateCheck::run(),
            // Before poll_quotes: a paid invoice on an account that is not ready to
            // spend cannot be minted, so the order would never be marked paid.
            'ready_store_wallets' => fn() => Invoice::ensurePrimaryWalletsReady(),
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
                // Through the same path as expire_invoices, so the shop gets InvoiceExpired.
                $veryOld = Invoice::expireInvoicesCreatedBefore(time() - 30 * 24 * 3600);
                return "expired {$veryOld} old invoices";
            },
            'cleanup_sessions' => function () {
                $sessions = self::cleanupSessions();
                $backups = self::cleanupBackups();
                $parts = [];
                if ($sessions > 0) { $parts[] = "removed {$sessions} old sessions"; }
                if ($backups > 0) { $parts[] = "removed {$backups} leftover backups"; }
                return $parts === [] ? 'none' : implode(', ', $parts);
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
     * The budget covers the rotating tasks only and starts after the always-run ones,
     * and at least one rotating task runs every time: when the payment path alone took
     * longer than the budget, the rotation (auto-withdrawal, reconciliation, cleanup)
     * used to be skipped on every run, forever.
     *
     * @param int $budgetSeconds Wall-clock budget for the rotating tasks of this run
     * @return array Result document (also recorded as the heartbeat)
     */
    public static function run(int $budgetSeconds = self::DEFAULT_BUDGET): array {
        // Shut down by a signed emergency notice: no task runs (includes/safe_mode.php).
        if (SafeMode::shutdown() !== null) {
            return ['timestamp' => time(), 'skipped' => 'emergency shutdown', 'tasks' => []];
        }
        $budgetSeconds = max(1, min(self::MAX_BUDGET, $budgetSeconds));
        $lease = self::acquireLease();
        if ($lease === null) {
            return ['timestamp' => time(), 'skipped' => 'another run holds the lease', 'tasks' => []];
        }

        // A fatal error (memory exhaustion, max_execution_time) is not a Throwable: the
        // finally below never runs and the lease stays held for its full term, so every
        // later tick says "another run holds the lease" (MINIMAX 4.8/4.10). The shutdown
        // hook runs on that path; releaseLease() checks the token, so it cannot release
        // a lease another run now owns.
        register_shutdown_function(static function () use ($lease): void {
            self::releaseLease($lease);
        });

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
        $visited = 0;
        $leaseLost = false;

        // Returns false when this run no longer owns the lease and must stop.
        $runTask = function (string $name) use ($tasks, $lease, &$results, &$health, &$leaseLost): bool {
            // The update check may have just received an emergency notice; nothing
            // after it may move money in the same run.
            if (SafeMode::shutdown() !== null) {
                $results['tasks'][$name] = 'skipped (emergency shutdown)';
                return true;
            }
            // Renew before every task, so a run that is legitimately slow keeps the
            // lease, and one whose lease was taken over (it overran LEASE_SECONDS)
            // stops instead of running the same tasks alongside the new owner.
            if (!self::renewLease($lease)) {
                $leaseLost = true;
                return false;
            }
            $previous = is_array($health[$name] ?? null) ? $health[$name] : [];
            $retryAt = (int)($previous['retry_after'] ?? 0);
            if ($retryAt > time()) {
                $results['tasks'][$name] = 'skipped (failed ' . (int)($previous['failures'] ?? 1)
                    . ' times in a row; retrying in ' . ($retryAt - time()) . 's)';
                return true;
            }
            try {
                $result = $tasks[$name]();
                $results['tasks'][$name] = $result;
                // A task that reports a failure without throwing is not healthy. It did
                // its work, though (one mint down among several, one store among many),
                // so it is reported but not backed off.
                $failure = self::failureOf($result);
                $health[$name] = $failure === null
                    ? ['last_ok' => time()]
                    : self::failedHealth($previous, $failure, false, in_array($name, self::ALWAYS_RUN, true));
            } catch (Throwable $e) {
                $results['tasks'][$name] = 'error: ' . $e->getMessage();
                $health[$name] = self::failedHealth($previous, $e->getMessage(), true, in_array($name, self::ALWAYS_RUN, true));
                error_log("CashuPayServer cron task {$name} failed: " . $e->getMessage());
            }
            return true;
        };

        try {
            // The payment path runs first and is not subject to the budget: a customer's
            // settlement must never be skipped because housekeeping ran long.
            foreach (self::ALWAYS_RUN as $name) {
                if (isset($tasks[$name]) && !$runTask($name)) {
                    break;
                }
            }

            $rotationStarted = microtime(true);
            for ($i = 0; $i < $count && !$leaseLost; $i++) {
                if ($i > 0 && microtime(true) - $rotationStarted > $budgetSeconds) {
                    break;
                }
                if (!$runTask($rotating[($cursor + $i) % $count])) {
                    break;
                }
                $visited++;
            }

            $results['ran'] = count($results['tasks']);
            $results['durationMs'] = (int)round((microtime(true) - $started) * 1000);
            if ($leaseLost) {
                // Another run owns the bookkeeping now; do not overwrite its cursor/health.
                $results['stopped'] = 'lease taken over by another run';
                return $results;
            }
            Config::set('cron_task_cursor', (string)($count > 0 ? ($cursor + $visited) % $count : 0));
            Config::set('cron_task_health', $health);
            Config::set('cron_last_run', (string)time());
            Config::set('cron_last_result', $results);
        } finally {
            self::releaseLease($lease);
        }

        return $results;
    }

    /**
     * The failure a task result describes, or null when it describes success.
     *
     * Tasks report problems in several shapes: an "error …"/"unreachable" string, a
     * "… N errors" summary, an `errors` list or count, a `failed` count, or (auto_melt)
     * a list of per-store entries carrying `error`. Treating only the first as failure
     * recorded `last_ok` for tasks that had in fact failed.
     */
    public static function failureOf($result): ?string {
        if (is_string($result)) {
            if (preg_match('/^(error|unreachable)/i', $result)) {
                return $result;
            }
            if (preg_match('/\b([1-9]\d*) errors?\b/i', $result)) {
                return $result;
            }
            return null;
        }
        if (!is_array($result)) {
            return null;
        }
        $messages = [];
        if (isset($result['errors'])) {
            if (is_array($result['errors']) && !empty($result['errors'])) {
                foreach ($result['errors'] as $label => $error) {
                    $text = is_array($error) ? implode('; ', array_map('strval', $error)) : (string)$error;
                    $messages[] = is_string($label) ? "{$label}: {$text}" : $text;
                }
            } elseif (is_int($result['errors']) && $result['errors'] > 0) {
                $messages[] = "{$result['errors']} errors";
            }
        }
        if (isset($result['failed']) && is_int($result['failed']) && $result['failed'] > 0) {
            $messages[] = "{$result['failed']} failed";
        }
        if (isset($result['error']) && is_string($result['error']) && $result['error'] !== '') {
            $messages[] = $result['error'];
        }
        if (array_is_list($result)) {
            foreach ($result as $entry) {
                if (is_array($entry) && !empty($entry['error'])) {
                    $who = $entry['store'] ?? $entry['store_id'] ?? null;
                    $messages[] = ($who !== null ? "{$who}: " : '') . (string)$entry['error'];
                }
            }
        }
        return empty($messages) ? null : mb_substr(implode(' | ', $messages), 0, 300);
    }

    /**
     * Health record for a failed task, with exponential backoff when it threw.
     *
     * A task that throws every time (a dead mint, a broken migration) used to be retried
     * on every tick forever. Its failures are counted and it waits 1, 2, 4 … minutes,
     * up to an hour (a quarter of an hour for the payment path), before the next try.
     * A partial failure is only counted: the task did useful work and keeps running.
     */
    private static function failedHealth(array $previous, string $message, bool $threw, bool $alwaysRun): array {
        $failures = (int)($previous['failures'] ?? 0) + 1;
        $record = [
            'last_error' => time(),
            'message' => mb_substr($message, 0, 300),
            'failures' => $failures,
            'failing_since' => (int)($previous['failing_since'] ?? 0) ?: time(),
        ];
        if (isset($previous['last_ok'])) {
            $record['last_ok'] = (int)$previous['last_ok'];
        }
        if ($threw) {
            $cap = $alwaysRun ? self::BACKOFF_CAP_ALWAYS_RUN : self::BACKOFF_CAP;
            $record['retry_after'] = time() + min($cap, self::BACKOFF_BASE * 2 ** min(10, $failures - 1));
        }
        return $record;
    }

    /** Latest heartbeat for the dashboard's diagnostics view. */
    public static function status(): array {
        $lastRun = (int)Config::get('cron_last_run', '0');
        $health = Config::get('cron_task_health', []);
        $health = is_array($health) ? $health : [];
        $lastResult = Config::get('cron_last_result');

        $failing = [];
        foreach ($health as $task => $record) {
            if (!is_array($record) || !isset($record['last_error'])) {
                continue;
            }
            $label = self::TASK_LABELS[$task] ?? ['name' => $task, 'impact' => 'Some background maintenance is not running.'];
            $retryAt = isset($record['retry_after']) ? (int)$record['retry_after'] : null;
            $failing[] = [
                'task' => $task,
                'name' => $label['name'],
                'impact' => $label['impact'],
                'message' => (string)($record['message'] ?? ''),
                'failures' => (int)($record['failures'] ?? 1),
                'since' => (int)($record['failing_since'] ?? $record['last_error']),
                'lastError' => (int)$record['last_error'],
                'lastOk' => isset($record['last_ok']) ? (int)$record['last_ok'] : null,
                'retryAt' => $retryAt !== null && $retryAt > time() ? $retryAt : null,
                'paymentPath' => in_array($task, self::ALWAYS_RUN, true),
            ];
        }
        // Payment-path problems first, then the longest-running ones.
        usort($failing, fn($a, $b) => [$b['paymentPath'], $a['since']] <=> [$a['paymentPath'], $b['since']]);

        return [
            'lastRun' => $lastRun ?: null,
            'secondsAgo' => $lastRun ? time() - $lastRun : null,
            'taskHealth' => $health,
            'failing' => $failing,
            'lastResult' => is_array($lastResult) ? $lastResult : null,
        ];
    }

    /** Session files older than this are dead: logins expire after 24 h at the latest. */
    const SESSION_FILE_MAX_AGE = 25 * 3600;

    /**
     * Delete stale session files from the data folder's sessions directory.
     *
     * Every visitor gets a session file there, and PHP's own garbage collection is off
     * on many hosts (Debian/Ubuntu ship session.gc_probability = 0 and clean only the
     * system session path from cron), so without this they pile up forever.
     */
    public static function cleanupSessions(?int $now = null): int {
        $now = $now ?? time();
        $removed = 0;
        foreach (glob(Database::getDataDir() . '/sessions/sess_*') ?: [] as $file) {
            $mtime = @filemtime($file);
            if (is_file($file) && $mtime !== false && $mtime < $now - self::SESSION_FILE_MAX_AGE && @unlink($file)) {
                $removed++;
            }
        }
        return $removed;
    }

    /** How long an interrupted backup snapshot may stay in the data folder. */
    const BACKUP_FILE_MAX_AGE = 2 * 3600;

    /**
     * Delete leftover database-backup snapshots.
     *
     * The download handler removes its snapshot on every normal exit, including a
     * shutdown hook, but a killed worker or a host restart can still leave one. A
     * snapshot is a complete unencrypted wallet (MINIMAX 4.2), so it must not sit there.
     */
    public static function cleanupBackups(?int $now = null): int {
        $now = $now ?? time();
        $removed = 0;
        foreach (glob(Database::getDataDir() . '/backup-*.sqlite') ?: [] as $file) {
            $mtime = @filemtime($file);
            if (is_file($file) && $mtime !== false && $mtime < $now - self::BACKUP_FILE_MAX_AGE && @unlink($file)) {
                $removed++;
            }
        }
        return $removed;
    }

    // --- lease ------------------------------------------------------------

    private static function acquireLease(): ?string {
        $token = bin2hex(random_bytes(16));
        $pdo = Database::getInstance();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $until = (int)self::leaseValue('cron_lease_until');
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

    /**
     * Extend the lease, if this run still holds it.
     *
     * @return bool false when another run has taken the lease over
     */
    private static function renewLease(string $token): bool {
        $pdo = Database::getInstance();
        try {
            $pdo->exec('BEGIN IMMEDIATE');
        } catch (Throwable $e) {
            // Could not even take the write lock: keep going on the current lease
            // rather than abandon a run over a busy database.
            error_log('CashuPayServer: could not renew cron lease: ' . $e->getMessage());
            return true;
        }
        try {
            if (!hash_equals(self::leaseValue('cron_lease_token'), $token)) {
                $pdo->exec('COMMIT');
                return false;
            }
            Config::set('cron_lease_until', (string)(time() + self::LEASE_SECONDS));
            $pdo->exec('COMMIT');
            return true;
        } catch (Throwable $e) {
            try { $pdo->exec('ROLLBACK'); } catch (Throwable $ignored) {}
            error_log('CashuPayServer: could not renew cron lease: ' . $e->getMessage());
            return true;
        }
    }

    private static function releaseLease(string $token): void {
        try {
            if (hash_equals(self::leaseValue('cron_lease_token'), $token)) {
                Config::set('cron_lease_until', '0');
            }
        } catch (Throwable $e) {
            error_log('CashuPayServer: failed to release cron lease: ' . $e->getMessage());
        }
    }

    /**
     * A lease field as stored right now. Read past Config's per-request cache: the
     * point is to see what *another* process wrote since this one started.
     */
    private static function leaseValue(string $key): string {
        $row = Database::fetchOne('SELECT value FROM config WHERE key = ?', [$key]);
        return $row === null ? '' : (string)$row['value'];
    }

    // --- individual tasks -------------------------------------------------

    /** Every configured store, as id rows. */
    private static function stores(): array {
        return Database::fetchAll(
            "SELECT id FROM stores WHERE mint_url IS NOT NULL AND seed_phrase IS NOT NULL"
        );
    }

    /** Seconds reconcile_transfers may spend before leaving the rest for the next run. */
    const RECONCILE_BUDGET = 15.0;

    /**
     * Resolve outgoing operations that never reached a terminal state.
     *
     * Withdrawals come first, all of them: their outcome is unknown money. Token rows
     * (exports, donations) then follow from a persisted cursor, a page at a time within
     * the time budget, so months of unclaimed exports cannot hide a newer one — or a
     * withdrawal — behind a fixed window of the oldest rows.
     *
     * Each row is resolved at the mint it was made at (`mint_url`, `unit`), not at the
     * store's current primary.
     */
    private static function reconcileTransfers(): array {
        $result = ['checked' => 0, 'completed' => 0, 'marked_failed' => 0, 'still_pending' => 0, 'errors' => 0];
        $deadline = microtime(true) + self::RECONCILE_BUDGET;

        $after = null;
        do {
            $page = Transfer::getPendingPage(Transfer::WITHDRAWAL_TYPES, $after, 50);
            foreach ($page as $row) {
                $after = [(int)$row['created_at'], (string)$row['id']];
                $result['checked']++;
                $result[self::reconcileOne($row)]++;
                if (microtime(true) > $deadline) {
                    return $result;
                }
            }
        } while (count($page) === 50);

        // Token rows: continue after where the last run stopped, wrap around to the
        // oldest once, and stop on reaching the starting point again.
        $cursor = Config::get('reconcile_token_cursor');
        $start = is_array($cursor) && count($cursor) === 2 ? [(int)$cursor[0], (string)$cursor[1]] : null;
        $after = $start;
        $wrapped = $start === null;
        while (microtime(true) <= $deadline) {
            $page = Transfer::getPendingPage(Transfer::TOKEN_TYPES, $after, 25);
            if (empty($page)) {
                $after = null;
                if ($wrapped) {
                    break;
                }
                $wrapped = true;
                continue;
            }
            foreach ($page as $row) {
                $key = [(int)$row['created_at'], (string)$row['id']];
                if ($wrapped && $start !== null && $key >= $start) {
                    // Back where this run began: every pending token row was visited.
                    break 2;
                }
                $after = $key;
                $result['checked']++;
                $result[self::reconcileOne($row)]++;
                if (microtime(true) > $deadline) {
                    break 2;
                }
            }
        }
        Config::set('reconcile_token_cursor', $after);

        return $result;
    }

    /** @return string One of the reconcileTransfers() result keys */
    private static function reconcileOne(array $row): string {
        try {
            // Anything handed out as a token — an export or a donation — is resolved
            // by whoever received it. The mint, not our own retry log, is what knows
            // whether that happened.
            if (in_array($row['type'], Transfer::TOKEN_TYPES, true)) {
                return self::reconcileTokenTransfer($row);
            }
            return self::reconcileWithdrawal($row);
        } catch (Throwable $e) {
            error_log("CashuPayServer: transfer {$row['id']} reconciliation failed: " . $e->getMessage());
            return 'errors';
        }
    }

    /**
     * Resolve a Lightning withdrawal. The melt quote is authoritative while the mint
     * knows it; once it does not, the inputs the withdrawal spent are.
     */
    private static function reconcileWithdrawal(array $row): string {
        $quoteId = Transfer::meltQuoteId($row);
        if ($quoteId === '') {
            return 'still_pending';
        }
        $wallet = Invoice::getWalletForStore(
            $row['store_id'],
            ($row['mint_url'] ?? '') !== '' ? $row['mint_url'] : null,
            ($row['unit'] ?? '') !== '' ? $row['unit'] : null
        );
        // The library's journal is still open: its recovery owns the outcome.
        if ($wallet->getStorage()->getPendingOperationById('melt:' . $quoteId) !== null) {
            return 'still_pending';
        }
        try {
            $quote = $wallet->checkMeltQuote($quoteId);
        } catch (\Cashu\CashuProtocolException $e) {
            if (!$e->isQuoteNotFound()) {
                throw $e;
            }
            return self::settleForgottenMelt($row, $wallet);
        }
        if ($quote->isPaid()) {
            Transfer::complete($row['id'], (int)$row['amount'], (int)$row['fee'], $quote->paymentPreimage);
            return 'completed';
        }
        if ($quote->isUnpaid() && $quote->expiry !== null && $quote->expiry < time()) {
            Transfer::fail($row['id'], 'The payment did not go through and the money stayed in your balance.');
            return 'marked_failed';
        }
        return 'still_pending';
    }

    /**
     * The mint no longer knows this withdrawal's quote (restart, pruning). The library
     * has already settled its own journal from the inputs' state and deleted it; reach
     * the same answer from the inputs recorded on the row. All spent: the payment went
     * out. All unspent: it cannot have — a mint cannot pay a quote it has forgotten
     * with inputs it never consumed. Anything else waits.
     */
    private static function settleForgottenMelt(array $row, \Cashu\Wallet $wallet): string {
        $secrets = Transfer::meltInputSecrets($row);
        if (empty($secrets)) {
            // Recorded before inputs were kept: nothing to decide by. Say so once.
            if (!str_contains((string)($row['detail'] ?? ''), 'no longer has a record')) {
                Transfer::note(
                    $row['id'],
                    'The mint no longer has a record of this withdrawal, so whether it was paid '
                    . 'cannot be confirmed automatically. Check the destination wallet before sending again.'
                );
            }
            return 'still_pending';
        }
        $proofs = $wallet->getStorage()->getProofsBySecretsAsObjects($secrets);
        if (count($proofs) !== count($secrets)) {
            return 'still_pending';
        }
        $states = array_values(array_unique(array_map(
            fn($state) => strtoupper((string)($state['state'] ?? '')),
            $wallet->checkProofState($proofs)
        )));
        if ($states === [\Cashu\ProofState::SPENT]) {
            Transfer::complete($row['id'], (int)$row['amount'], (int)$row['fee'], 'Paid (confirmed from the money it spent)');
            return 'completed';
        }
        if ($states === [\Cashu\ProofState::UNSPENT]) {
            Transfer::fail($row['id'], 'The payment did not go through and the money stayed in your balance.');
            return 'marked_failed';
        }
        return 'still_pending';
    }

    /**
     * Resolve a transfer whose value left as a token.
     *
     * Asking the mint is what makes this reliable: a POST to the donation sink can
     * succeed while its response is lost, and a recipient can cash a token in without
     * telling us. If the proofs are spent, somebody got the money and the transfer is
     * done — regardless of what our own delivery attempt reported.
     *
     * @return string One of the reconcileTransfers() result keys
     */
    private static function reconcileTokenTransfer(array $row): string {
        $secrets = array_values(array_filter(explode(',', (string)($row['reference'] ?? ''))));
        if (empty($secrets)) {
            return 'still_pending';
        }

        $states = Invoice::checkProofStatesBySecrets($row['store_id'], $secrets);
        $spent = 0;
        $unspent = 0;
        foreach ($secrets as $secret) {
            $state = $states[$secret] ?? null;
            if ($state === \Cashu\ProofState::SPENT) {
                $spent++;
            } elseif ($state === \Cashu\ProofState::UNSPENT) {
                $unspent++;
            }
        }

        // Fully redeemed: the recipient (or the sink) has the money.
        if ($spent === count($secrets)) {
            Transfer::complete($row['id'], (int)$row['amount']);
            Invoice::markProofsSpent($row['store_id'], $secrets);
            return 'completed';
        }

        // Nobody has taken it yet. A donation is ours to keep trying; an export is simply
        // waiting for its recipient and is not a problem to be solved.
        if ($unspent === count($secrets) && $row['type'] === Transfer::TYPE_DONATION
            && !empty($row['token'])) {
            // ...unless nobody *can* take it. A mint that charges per input proof can make
            // a small donation cost more to redeem than it is worth, and then no amount of
            // retrying will ever deliver it. Say so once and stop, instead of retrying for
            // as long as the server runs.
            // The operator's switch stops pending retries too, not only new donations.
            if (!defined('CASHUPAY_DONATION_PERCENT') || (float)CASHUPAY_DONATION_PERCENT <= 0) {
                if (!str_contains((string)($row['detail'] ?? ''), 'Donations are switched off')) {
                    Transfer::note(
                        $row['id'],
                        'Donations are switched off, so this one was not sent again. '
                        . 'The ecash is still yours — you can take it back under Transfers.'
                    );
                }
                return 'still_pending';
            }

            if (self::isBelowMintFee((string)$row['token'])) {
                Transfer::note(
                    $row['id'],
                    'This donation is too small for this mint to be worth cashing in — the '
                    . 'mint\'s charge for moving it is as large as the donation itself, so it '
                    . 'can never arrive. Take it back under Transfers to clear it.'
                );
                return 'still_pending';
            }

            if (Donation::postTokenToSink($row['token'])) {
                // Believe the mint, not the HTTP status: confirm on the next pass.
                Transfer::note($row['id'], 'Re-sent to the donation sink; awaiting confirmation');
            } else {
                Transfer::note(
                    $row['id'],
                    'The donation service is not accepting this token. The ecash is still '
                    . 'yours — you can take it back from Transfers.'
                );
            }
        }

        return 'still_pending';
    }

    /**
     * Can this token ever be worth redeeming?
     *
     * The fee is charged per *input proof* and rounded up once for the whole swap
     * (NUT-02: ceil(sum(input_fee_ppk) / 1000)), so it is a property of the token's
     * own proofs at the mint that issued them, and of nothing else. Asking the store
     * instead was the bug: a store whose configured mint charges nothing was told
     * every donation was worth sending, while the token itself sat at a mint charging
     * 100 ppk, where a single proof rounds up to a whole satoshi. One 1-sat donation
     * was re-posted every minute for eight months on the strength of that answer.
     *
     * Read from the token: its mint, its unit, its actual proofs. The wallet built
     * here is given no database, so it reads keysets without creating storage,
     * binding a seed or touching a proof.
     *
     * The answer is cached against the token, because replacing a pointless POST
     * every minute with a pointless keyset fetch every minute is not a fix. A token
     * is immutable and mint fees change only on a keyset rotation, which a week's
     * staleness survives; after that it is asked again in case the fee dropped.
     */
    private static function isBelowMintFee(string $tokenString): bool
    {
        if ($tokenString === '') {
            return false;
        }

        $key = 'dust_token:' . substr(hash('sha256', $tokenString), 0, 24);
        $cached = Config::get($key);
        if (is_array($cached) && (int)($cached['at'] ?? 0) > time() - 604800) {
            return (bool)$cached['below'];
        }

        try {
            $token = \Cashu\TokenSerializer::deserialize($tokenString);
            $amount = $token->getAmount();
            if ($amount <= 0) {
                return true;
            }
            $wallet = new \Cashu\Wallet($token->mint, $token->unit);   // no dbPath: read-only
            $wallet->loadMint();
            $wallet->resolveShortKeysetIds($token->proofs);
            $below = $wallet->calculateFee($token->proofs) >= $amount;
        } catch (Throwable $e) {
            // Can't tell — keep trying rather than give up on real money, and do not
            // cache an answer we did not get.
            return false;
        }

        Config::set($key, ['below' => $below, 'at' => time()]);
        return $below;
    }

    /**
     * checkAutoMelt() returns one entry per store (and mint) attempted or skipped.
     * Keep the keys it actually returns — store_name/store_id and amount — so a failed
     * auto-withdrawal names the store and the amount.
     */
    private static function autoMelt() {
        $meltResults = LightningAddress::checkAutoMelt();
        if (empty($meltResults)) {
            return 'skipped';
        }
        return array_map(
            fn($entry) => is_array($entry)
                ? array_filter([
                    'store' => $entry['store_name'] ?? $entry['store_id'] ?? null,
                    'store_id' => $entry['store_id'] ?? null,
                    'mint' => $entry['mint_url'] ?? null,
                    'amount' => $entry['amount'] ?? null,
                    'skipped' => $entry['skipped'] ?? null,
                    'error' => $entry['error'] ?? null,
                ], fn($v) => $v !== null)
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
        $errors = 0;
        foreach (self::stores() as $store) {
            try {
                $wallet = Invoice::getWalletInstance($store['id']);
                if ($wallet->hasStorage()) {
                    $rotation = $wallet->rotateProofs();
                    $rotated += $rotation['rotated'];
                    $checked += $rotation['checked'];
                    foreach ($rotation['errors'] as $rotationError) {
                        $errors++;
                        error_log("Keyset rotation error for store {$store['id']}: {$rotationError}");
                    }
                }
            } catch (Throwable $e) {
                $errors++;
                error_log("Keyset rotation failed for store {$store['id']}: " . $e->getMessage());
            }
        }
        Config::set('last_keyset_rotation_check', (string)time());

        return "checked {$checked} proofs, rotated {$rotated}, {$errors} errors";
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
        $errors = 0;
        foreach (self::stores() as $store) {
            try {
                $wallet = Invoice::getWalletInstance($store['id']);
                if ($wallet->hasStorage()) {
                    $totalCleaned += $wallet->getStorage()->cleanExpiredPendingOperations();
                }
            } catch (Throwable $e) {
                $errors++;
                error_log("Cleanup failed for store {$store['id']}: " . $e->getMessage());
            }
        }
        return "cleaned {$totalCleaned}, {$errors} errors";
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
