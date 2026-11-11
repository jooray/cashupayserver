<?php
/**
 * CashuPayServer - Mint Reliability Module
 *
 * Tracks per-mint failure/success history and gates which mints are eligible
 * for new invoices. State machine and design are documented in the PR; in
 * brief:
 *
 *   - Reliability is GLOBAL per mint_url (one row in `mint_reliability`).
 *   - On a mint-side failure (MINT_UNREACHABLE / MINT_PROTOCOL_ERROR) we
 *     immediately increment the lifetime counter; > 5 → permanent disable.
 *   - On a Lightning-wallet-side failure we don't penalize directly; we open
 *     a SUSPECT and resolve it via either protocol-state introspection (#2)
 *     or cross-mint comparison to the same address (#1). Per-tick re-attempts
 *     of the same (mint, address) pair are deliberately not counted as new
 *     evidence — see the low-volume edge-case discussion in the PR.
 *   - Every state change is appended to `mint_event_log` for the diagnostic UI
 *     (capped at 1000 rows per mint_url).
 */

require_once __DIR__ . '/database.php';

class MintReliability {
    // Failure classification (what kind of fault the exception represents).
    const KIND_MINT_UNREACHABLE      = 'MINT_UNREACHABLE';
    const KIND_MINT_PROTOCOL_ERROR   = 'MINT_PROTOCOL_ERROR';
    const KIND_LIGHTNING_WALLET_ERROR = 'LIGHTNING_WALLET_ERROR';
    const KIND_INSUFFICIENT_BALANCE  = 'INSUFFICIENT_BALANCE';
    const KIND_USER_ERROR            = 'USER_ERROR';
    const KIND_UNKNOWN               = 'UNKNOWN';

    // Event log types.
    const EVENT_WITHDRAW_FAILURE         = 'WITHDRAW_FAILURE';
    const EVENT_QUOTE_FAILURE            = 'QUOTE_FAILURE';
    const EVENT_SUSPECT_OPENED           = 'SUSPECT_OPENED';
    const EVENT_SUSPECT_RESOLVED_FAULT   = 'SUSPECT_RESOLVED_FAULT';
    const EVENT_SUSPECT_RESOLVED_NO_FAULT = 'SUSPECT_RESOLVED_NO_FAULT';
    const EVENT_DISABLED_PENDING_SUCCESS = 'DISABLED_PENDING_SUCCESS';
    const EVENT_ENABLED_AFTER_SUCCESS    = 'ENABLED_AFTER_SUCCESS';
    const EVENT_PERMANENTLY_DISABLED     = 'PERMANENTLY_DISABLED';
    const EVENT_ADMIN_REENABLED          = 'ADMIN_REENABLED';
    const EVENT_ADMIN_CONFIRMED_BAD      = 'ADMIN_CONFIRMED_BAD';
    const EVENT_TRUSTED_LIST_DISABLED    = 'TRUSTED_LIST_DISABLED';
    const EVENT_TRUSTED_LIST_ENABLED     = 'TRUSTED_LIST_ENABLED';
    const EVENT_COUNTERS_RESET           = 'COUNTERS_RESET';

    // Permanent-disable threshold: STRICTLY greater than 5 lifetime failures.
    const PERMANENT_DISABLE_THRESHOLD = 5;

    // Per-mint cap on event log rows; oldest pruned on insert.
    const EVENT_LOG_CAP_PER_MINT = 1000;

    // How long after a MINT_UNREACHABLE failure to wait before re-including the
    // mint in the candidate list for new invoices. Closes the "zero-balance
    // mint gated by a quote failure has no way to be re-tested" hole — without
    // this, such mints stay disabled until an admin clicks Re-enable.
    //
    // Deliberately NOT applied to LIGHTNING_WALLET_ERROR: that gate exists
    // because withdraw failed and we don't want more funds added to the mint;
    // a successful quote doesn't prove the LNURL recovered, so the gate must
    // wait on the agreed resolution paths (cross-mint differential, protocol
    // introspection, successful melt, or admin action).
    const MINT_UNREACHABLE_RETRY_INTERVAL_SEC = 3600;

    /**
     * Classify a Throwable into one of the KIND_* constants.
     *
     * $stage hints what we were doing when the failure happened, so we can
     * disambiguate (e.g. an exception from LNURL lookup is wallet-side even if
     * the message matches a generic network pattern).
     *
     * Recognised stages: 'getInvoice', 'requestMintQuote', 'requestMeltQuote',
     * 'melt'. Anything else is treated as generic.
     */
    public static function classifyException(\Throwable $e, string $stage): string {
        $message = (string)$e->getMessage();
        $lower = strtolower($message);

        if (str_contains($lower, 'insufficient balance')) {
            return self::KIND_INSUFFICIENT_BALANCE;
        }

        // Exceptions raised by meltToAddress itself for wallet-side outcomes.
        if (str_contains($lower, 'lightning payment pending')
            || str_contains($lower, 'lightning payment failed')) {
            return self::KIND_LIGHTNING_WALLET_ERROR;
        }

        // LNURL / Lightning Address resolution failures: not the mint's fault.
        if ($stage === 'getInvoice') {
            return self::KIND_LIGHTNING_WALLET_ERROR;
        }

        // Network/transport errors → mint unreachable.
        if ($e instanceof \Exception && Invoice::isMintUnreachable($e)) {
            return self::KIND_MINT_UNREACHABLE;
        }

        // CashuException carries mint-protocol problems.
        if (class_exists('\\Cashu\\CashuException') && $e instanceof \Cashu\CashuException) {
            return self::KIND_MINT_PROTOCOL_ERROR;
        }

        // Anything thrown directly from a mint-bound call we couldn't otherwise
        // classify gets treated as a protocol error.
        if (in_array($stage, ['requestMintQuote', 'requestMeltQuote', 'melt'], true)) {
            return self::KIND_MINT_PROTOCOL_ERROR;
        }

        return self::KIND_UNKNOWN;
    }

    /**
     * Fetch the reliability row for a mint, creating it if missing.
     * Returned array always reflects the row after the ensure step.
     */
    public static function ensureRecord(string $mintUrl): array {
        $row = Database::fetchOne(
            "SELECT * FROM mint_reliability WHERE mint_url = ?",
            [$mintUrl]
        );
        if ($row !== null) {
            return $row;
        }
        $now = Database::timestamp();
        Database::insert('mint_reliability', [
            'mint_url' => $mintUrl,
            'total_failures' => 0,
            'consecutive_failures' => 0,
            'disabled_pending_success' => 0,
            'permanently_disabled' => 0,
            'trusted_list_disabled' => 0,
            'updated_at' => $now,
        ]);
        return Database::fetchOne(
            "SELECT * FROM mint_reliability WHERE mint_url = ?",
            [$mintUrl]
        );
    }

    /**
     * True iff the mint can be used to issue new invoices.
     *
     * A mint gated only by `disabled_pending_success` is re-admitted to the
     * candidate list after MINT_UNREACHABLE_RETRY_INTERVAL_SEC has elapsed —
     * but only when the gate was set by a MINT_UNREACHABLE failure. This is
     * the recovery path for the zero-balance-mint case: if invoice creation
     * gated the mint and there's no balance to drive an auto-melt success,
     * the mint would otherwise stay disabled forever. If the retry succeeds,
     * `recordQuoteSuccess` clears the gate; if it fails, `recordQuoteFailure`
     * re-stamps `last_failure_at` and the mint is gated for another interval
     * (and the lifetime counter ticks up toward the permanent-disable cap).
     *
     * Other gates are unaffected:
     *   - permanently_disabled / trusted_list_disabled always block;
     *   - a `disabled_pending_success` gate from LIGHTNING_WALLET_ERROR is
     *     NOT auto-cleared on interval — see the rationale on the
     *     MINT_UNREACHABLE_RETRY_INTERVAL_SEC constant.
     */
    public static function isAvailableForNewInvoices(string $mintUrl): bool {
        $row = Database::fetchOne(
            "SELECT disabled_pending_success, permanently_disabled,
                    trusted_list_disabled, last_failure_at, last_failure_kind
             FROM mint_reliability WHERE mint_url = ?",
            [$mintUrl]
        );
        if ($row === null) {
            return true; // unknown mint → assume healthy
        }
        if ((int)$row['permanently_disabled'] || (int)$row['trusted_list_disabled']) {
            return false;
        }
        if (!(int)$row['disabled_pending_success']) {
            return true;
        }
        if ($row['last_failure_kind'] !== self::KIND_MINT_UNREACHABLE) {
            return false;
        }
        $lastFailureAt = $row['last_failure_at'] !== null ? (int)$row['last_failure_at'] : 0;
        return (Database::timestamp() - $lastFailureAt) >= self::MINT_UNREACHABLE_RETRY_INTERVAL_SEC;
    }

    /**
     * Record a successful withdrawal. Drives the success side of the state
     * machine: clears disabled_pending_success, resolves the mint's own open
     * suspects (no fault), and — if the success was at a specific address —
     * resolves OTHER mints' suspects at the same address as confirmed fault.
     */
    public static function recordWithdrawSuccess(string $mintUrl, ?string $address, ?string $storeId): void {
        self::ensureRecord($mintUrl);
        $now = Database::timestamp();

        $prev = Database::fetchOne(
            "SELECT disabled_pending_success FROM mint_reliability WHERE mint_url = ?",
            [$mintUrl]
        );

        Database::query(
            "UPDATE mint_reliability
             SET last_success_at = ?, consecutive_failures = 0,
                 disabled_pending_success = 0, updated_at = ?
             WHERE mint_url = ?",
            [$now, $now, $mintUrl]
        );

        if ((int)($prev['disabled_pending_success'] ?? 0) === 1) {
            self::logEvent($mintUrl, self::EVENT_ENABLED_AFTER_SUCCESS, null, $storeId, $address, null);
        }

        // Resolve all suspects opened against THIS mint (no fault for this mint).
        $ownSuspects = Database::fetchAll(
            "SELECT address FROM mint_suspect WHERE mint_url = ?",
            [$mintUrl]
        );
        if (!empty($ownSuspects)) {
            Database::delete('mint_suspect', 'mint_url = ?', [$mintUrl]);
            foreach ($ownSuspects as $s) {
                self::logEvent(
                    $mintUrl,
                    self::EVENT_SUSPECT_RESOLVED_NO_FAULT,
                    self::KIND_LIGHTNING_WALLET_ERROR,
                    $storeId,
                    $s['address'],
                    json_encode(['reason' => 'mint succeeded subsequently'])
                );
            }
        }

        // Differential resolution: if this success was at a specific address,
        // any OTHER mint with an open suspect at that address is now confirmed
        // at fault → increment their lifetime counters.
        if ($address !== null && $address !== '') {
            $others = Database::fetchAll(
                "SELECT mint_url FROM mint_suspect WHERE address = ? AND mint_url != ?",
                [$address, $mintUrl]
            );
            foreach ($others as $row) {
                self::confirmMintAtFault(
                    $row['mint_url'],
                    $address,
                    $storeId,
                    'another mint succeeded at the same address'
                );
            }
        }
    }

    /**
     * Record a withdrawal failure. The state-machine branch depends on $kind.
     *
     * @param $wallet Optional wallet instance for the protocol-introspection
     *                fast path (#2) on LIGHTNING_WALLET_ERROR. Passed as mixed
     *                to avoid a hard dependency on the Cashu type here.
     * @param $quoteId Optional cashu melt-quote id, also for the #2 fast path.
     */
    public static function recordWithdrawFailure(
        string $mintUrl,
        ?string $address,
        ?string $storeId,
        string $kind,
        string $message,
        ?string $quoteId = null,
        $wallet = null
    ): void {
        // Pre-flight / user errors never penalize the mint or set flags.
        if ($kind === self::KIND_INSUFFICIENT_BALANCE || $kind === self::KIND_USER_ERROR) {
            return;
        }

        self::ensureRecord($mintUrl);
        $now = Database::timestamp();

        // Log the raw failure event first (the diagnostic view always shows it).
        self::logEvent($mintUrl, self::EVENT_WITHDRAW_FAILURE, $kind, $storeId, $address, $message);

        // Stop new inflows on ANY withdrawal failure (per spec).
        $prev = Database::fetchOne(
            "SELECT disabled_pending_success FROM mint_reliability WHERE mint_url = ?",
            [$mintUrl]
        );
        Database::query(
            "UPDATE mint_reliability
             SET disabled_pending_success = 1,
                 last_failure_at = ?, last_failure_kind = ?, last_failure_message = ?,
                 updated_at = ?
             WHERE mint_url = ?",
            [$now, $kind, $message, $now, $mintUrl]
        );
        if ((int)($prev['disabled_pending_success'] ?? 0) === 0) {
            self::logEvent($mintUrl, self::EVENT_DISABLED_PENDING_SUCCESS, $kind, $storeId, $address, $message);
        }

        if ($kind === self::KIND_MINT_UNREACHABLE || $kind === self::KIND_MINT_PROTOCOL_ERROR) {
            // Confirmed mint-side fault → count immediately.
            self::incrementFailureCounters($mintUrl, $storeId, $address, $kind);
            return;
        }

        // LIGHTNING_WALLET_ERROR (and UNKNOWN, conservatively): open/update
        // suspect unless we can resolve it now.

        // #2 fast path: ask the mint what state the quote is in.
        $probed = self::probeQuoteState($mintUrl, $quoteId, $wallet);
        if ($probed === 'UNPAID') {
            // Mint tried and the LSP/wallet rejected → not the mint's fault.
            Database::query(
                "UPDATE mint_reliability
                 SET disabled_pending_success = 0, updated_at = ?
                 WHERE mint_url = ?",
                [$now, $mintUrl]
            );
            self::logEvent(
                $mintUrl,
                self::EVENT_SUSPECT_RESOLVED_NO_FAULT,
                $kind,
                $storeId,
                $address,
                json_encode(['via' => 'protocol_state_UNPAID'])
            );
            return;
        }
        if ($probed === 'PAID') {
            // Odd: melt told us it failed but the mint reports paid. Treat as
            // success — funds left the mint. Caller still surfaces the error
            // upstream, but for reliability we don't punish the mint.
            self::recordWithdrawSuccess($mintUrl, $address, $storeId);
            return;
        }
        if ($probed === null || $probed === 'PENDING') {
            // Inconclusive. Open/update suspect for #1 differential resolution.
            if ($address === null || $address === '') {
                // Without an address we can't apply #1; leave the mint with
                // disabled_pending_success set but no suspect row to resolve
                // against. The next successful melt from this mint will clear it.
                return;
            }

            // #1 inverse: if any OTHER mint also has an open suspect on this
            // address, the address is at fault — clear suspects for all mints
            // on this address (including any prior one for this mint).
            $others = Database::fetchAll(
                "SELECT mint_url FROM mint_suspect WHERE address = ? AND mint_url != ?",
                [$address, $mintUrl]
            );
            if (!empty($others)) {
                Database::delete('mint_suspect', 'address = ?', [$address]);
                foreach ($others as $row) {
                    self::logEvent(
                        $row['mint_url'],
                        self::EVENT_SUSPECT_RESOLVED_NO_FAULT,
                        self::KIND_LIGHTNING_WALLET_ERROR,
                        $storeId,
                        $address,
                        json_encode(['via' => 'another_mint_also_failed_same_address'])
                    );
                }
                self::logEvent(
                    $mintUrl,
                    self::EVENT_SUSPECT_RESOLVED_NO_FAULT,
                    $kind,
                    $storeId,
                    $address,
                    json_encode(['via' => 'another_mint_also_failed_same_address'])
                );
                return;
            }

            // Open a new suspect or bump last_seen_at on an existing one.
            $existing = Database::fetchOne(
                "SELECT opened_at FROM mint_suspect WHERE mint_url = ? AND address = ?",
                [$mintUrl, $address]
            );
            if ($existing === null) {
                Database::insert('mint_suspect', [
                    'mint_url' => $mintUrl,
                    'address' => $address,
                    'store_id' => $storeId,
                    'opened_at' => $now,
                    'last_seen_at' => $now,
                ]);
                self::logEvent($mintUrl, self::EVENT_SUSPECT_OPENED, $kind, $storeId, $address, $message);
            } else {
                Database::query(
                    "UPDATE mint_suspect SET last_seen_at = ?
                     WHERE mint_url = ? AND address = ?",
                    [$now, $mintUrl, $address]
                );
            }
        }
    }

    /**
     * Record an invoice/quote creation failure. Per spec, only MINT_UNREACHABLE
     * counts toward the lifetime counter; protocol errors at this stage are too
     * ambiguous (the request itself may have been malformed) to penalize.
     *
     * MINT_UNREACHABLE also sets disabled_pending_success so the mint drops out
     * of the eligible list for subsequent invoices until it proves itself
     * reachable again (via recordQuoteSuccess or a successful withdrawal).
     */
    public static function recordQuoteFailure(string $mintUrl, ?string $storeId, string $kind, string $message): void {
        self::ensureRecord($mintUrl);
        self::logEvent($mintUrl, self::EVENT_QUOTE_FAILURE, $kind, $storeId, null, $message);

        if ($kind !== self::KIND_MINT_UNREACHABLE) {
            return;
        }
        $now = Database::timestamp();
        $prev = Database::fetchOne(
            "SELECT disabled_pending_success FROM mint_reliability WHERE mint_url = ?",
            [$mintUrl]
        );
        Database::query(
            "UPDATE mint_reliability
             SET disabled_pending_success = 1,
                 last_failure_at = ?, last_failure_kind = ?, last_failure_message = ?,
                 updated_at = ?
             WHERE mint_url = ?",
            [$now, $kind, $message, $now, $mintUrl]
        );
        if ((int)($prev['disabled_pending_success'] ?? 0) === 0) {
            self::logEvent($mintUrl, self::EVENT_DISABLED_PENDING_SUCCESS, $kind, $storeId, null, $message);
        }
        self::incrementFailureCounters($mintUrl, $storeId, null, $kind);
    }

    /**
     * Record a successful quote/invoice creation. Clears disabled_pending_success
     * (the mint is provably reachable now) and resets the consecutive counter.
     * The lifetime counter is intentionally preserved so > 5 still triggers
     * permanent disable across the mint's lifetime.
     */
    public static function recordQuoteSuccess(string $mintUrl, ?string $storeId): void {
        self::ensureRecord($mintUrl);
        $now = Database::timestamp();
        $prev = Database::fetchOne(
            "SELECT disabled_pending_success FROM mint_reliability WHERE mint_url = ?",
            [$mintUrl]
        );
        Database::query(
            "UPDATE mint_reliability
             SET last_success_at = ?, consecutive_failures = 0,
                 disabled_pending_success = 0, updated_at = ?
             WHERE mint_url = ?",
            [$now, $now, $mintUrl]
        );
        if ((int)($prev['disabled_pending_success'] ?? 0) === 1) {
            self::logEvent($mintUrl, self::EVENT_ENABLED_AFTER_SUCCESS, null, $storeId, null, null);
        }
    }

    /**
     * Apply the trusted-list "disabled" flag for this mint. Distinct from
     * permanently_disabled so the admin UI can show *why* the mint is off.
     */
    public static function setTrustedListDisabled(string $mintUrl, ?string $reason): void {
        $row = self::ensureRecord($mintUrl);
        $now = Database::timestamp();
        Database::query(
            "UPDATE mint_reliability
             SET trusted_list_disabled = 1, trusted_list_disabled_reason = ?, updated_at = ?
             WHERE mint_url = ?",
            [$reason, $now, $mintUrl]
        );
        if ((int)$row['trusted_list_disabled'] === 0) {
            self::logEvent(
                $mintUrl,
                self::EVENT_TRUSTED_LIST_DISABLED,
                null,
                null,
                null,
                json_encode(['reason' => $reason])
            );
        }
    }

    public static function clearTrustedListDisabled(string $mintUrl): void {
        $row = Database::fetchOne(
            "SELECT trusted_list_disabled FROM mint_reliability WHERE mint_url = ?",
            [$mintUrl]
        );
        if ($row === null || (int)$row['trusted_list_disabled'] === 0) {
            return;
        }
        $now = Database::timestamp();
        Database::query(
            "UPDATE mint_reliability
             SET trusted_list_disabled = 0, trusted_list_disabled_reason = NULL, updated_at = ?
             WHERE mint_url = ?",
            [$now, $mintUrl]
        );
        self::logEvent($mintUrl, self::EVENT_TRUSTED_LIST_ENABLED, null, null, null, null);
    }

    /**
     * Try the protocol-state introspection fast path. Returns 'UNPAID',
     * 'PENDING', 'PAID', or null if we can't determine state.
     */
    public static function probeQuoteState(string $mintUrl, ?string $quoteId, $wallet): ?string {
        if ($quoteId === null || $quoteId === '' || $wallet === null) {
            return null;
        }
        if (!method_exists($wallet, 'checkMeltQuote')) {
            return null;
        }
        try {
            $quote = $wallet->checkMeltQuote($quoteId);
            $state = is_object($quote) && property_exists($quote, 'state')
                ? strtoupper((string)$quote->state)
                : null;
            if (in_array($state, ['UNPAID', 'PENDING', 'PAID'], true)) {
                return $state;
            }
            return null;
        } catch (\Throwable $e) {
            // Probe failed: that's itself a signal of mint-side trouble, but we
            // don't act on it here — let the broader state machine handle it.
            return null;
        }
    }

    /** Admin: re-enable a disabled mint and reset all counters. */
    public static function adminReenable(string $mintUrl, ?string $actor = null): void {
        self::ensureRecord($mintUrl);
        $now = Database::timestamp();
        Database::query(
            "UPDATE mint_reliability
             SET total_failures = 0, consecutive_failures = 0,
                 disabled_pending_success = 0, permanently_disabled = 0,
                 updated_at = ?
             WHERE mint_url = ?",
            [$now, $mintUrl]
        );
        Database::delete('mint_suspect', 'mint_url = ?', [$mintUrl]);
        self::logEvent(
            $mintUrl,
            self::EVENT_ADMIN_REENABLED,
            null,
            null,
            null,
            $actor !== null ? json_encode(['actor' => $actor]) : null
        );
    }

    /** Admin: confirm the mint is bad — counts as one strike and (likely) tips it permanent. */
    public static function adminConfirmedBad(string $mintUrl, ?string $actor = null): void {
        self::ensureRecord($mintUrl);
        $now = Database::timestamp();
        Database::query(
            "UPDATE mint_reliability
             SET total_failures = total_failures + 1,
                 consecutive_failures = consecutive_failures + 1,
                 disabled_pending_success = 1,
                 updated_at = ?
             WHERE mint_url = ?",
            [$now, $mintUrl]
        );
        $row = Database::fetchOne(
            "SELECT total_failures FROM mint_reliability WHERE mint_url = ?",
            [$mintUrl]
        );
        if ((int)$row['total_failures'] > self::PERMANENT_DISABLE_THRESHOLD) {
            Database::query(
                "UPDATE mint_reliability SET permanently_disabled = 1, updated_at = ?
                 WHERE mint_url = ?",
                [$now, $mintUrl]
            );
            self::logEvent($mintUrl, self::EVENT_PERMANENTLY_DISABLED, null, null, null, null);
        }
        self::logEvent(
            $mintUrl,
            self::EVENT_ADMIN_CONFIRMED_BAD,
            null,
            null,
            null,
            $actor !== null ? json_encode(['actor' => $actor]) : null
        );
    }

    /** Admin: reset counters for a single mint (also clears all flags). */
    public static function resetCounters(string $mintUrl, ?string $actor = null): void {
        self::ensureRecord($mintUrl);
        $now = Database::timestamp();
        Database::query(
            "UPDATE mint_reliability
             SET total_failures = 0, consecutive_failures = 0,
                 disabled_pending_success = 0, permanently_disabled = 0,
                 updated_at = ?
             WHERE mint_url = ?",
            [$now, $mintUrl]
        );
        Database::delete('mint_suspect', 'mint_url = ?', [$mintUrl]);
        self::logEvent(
            $mintUrl,
            self::EVENT_COUNTERS_RESET,
            null,
            null,
            null,
            $actor !== null ? json_encode(['actor' => $actor]) : null
        );
    }

    /** Admin: reset counters for every mint. */
    public static function resetAllCounters(?string $actor = null): void {
        $rows = Database::fetchAll("SELECT mint_url FROM mint_reliability");
        foreach ($rows as $row) {
            self::resetCounters($row['mint_url'], $actor);
        }
    }

    /**
     * Diagnostic: event log for a mint, newest first.
     */
    public static function getEventLog(
        string $mintUrl,
        int $limit = 200,
        ?string $eventType = null,
        ?int $sinceTs = null,
        ?int $untilTs = null
    ): array {
        $sql = "SELECT * FROM mint_event_log WHERE mint_url = ?";
        $params = [$mintUrl];
        if ($eventType !== null && $eventType !== '') {
            $sql .= " AND event_type = ?";
            $params[] = $eventType;
        }
        if ($sinceTs !== null) {
            $sql .= " AND timestamp >= ?";
            $params[] = $sinceTs;
        }
        if ($untilTs !== null) {
            $sql .= " AND timestamp <= ?";
            $params[] = $untilTs;
        }
        $sql .= " ORDER BY timestamp DESC, id DESC LIMIT ?";
        $params[] = $limit;
        return Database::fetchAll($sql, $params);
    }

    /** Diagnostic: mints currently in any disabled state. */
    public static function listDisabledMints(): array {
        return Database::fetchAll(
            "SELECT * FROM mint_reliability
             WHERE disabled_pending_success = 1
                OR permanently_disabled = 1
                OR trusted_list_disabled = 1
             ORDER BY updated_at DESC"
        );
    }

    /** True iff any suspect older than $olderThanSec is open. Drives the dashboard banner. */
    public static function hasStaleSuspect(int $olderThanSec): bool {
        $cutoff = Database::timestamp() - $olderThanSec;
        $row = Database::fetchOne(
            "SELECT 1 FROM mint_suspect WHERE opened_at <= ? LIMIT 1",
            [$cutoff]
        );
        return $row !== null;
    }

    // ------------------------------------------------------------------
    // Internal
    // ------------------------------------------------------------------

    private static function incrementFailureCounters(string $mintUrl, ?string $storeId, ?string $address, string $kind): void {
        $now = Database::timestamp();
        Database::query(
            "UPDATE mint_reliability
             SET total_failures = total_failures + 1,
                 consecutive_failures = consecutive_failures + 1,
                 updated_at = ?
             WHERE mint_url = ?",
            [$now, $mintUrl]
        );
        $row = Database::fetchOne(
            "SELECT total_failures, permanently_disabled
             FROM mint_reliability WHERE mint_url = ?",
            [$mintUrl]
        );
        if ((int)$row['total_failures'] > self::PERMANENT_DISABLE_THRESHOLD
            && (int)$row['permanently_disabled'] === 0) {
            Database::query(
                "UPDATE mint_reliability SET permanently_disabled = 1, updated_at = ?
                 WHERE mint_url = ?",
                [$now, $mintUrl]
            );
            self::logEvent($mintUrl, self::EVENT_PERMANENTLY_DISABLED, $kind, $storeId, $address, null);
        }
    }

    private static function confirmMintAtFault(string $mintUrl, string $address, ?string $storeId, string $reason): void {
        Database::delete(
            'mint_suspect',
            'mint_url = ? AND address = ?',
            [$mintUrl, $address]
        );
        self::incrementFailureCounters(
            $mintUrl,
            $storeId,
            $address,
            self::KIND_LIGHTNING_WALLET_ERROR
        );
        self::logEvent(
            $mintUrl,
            self::EVENT_SUSPECT_RESOLVED_FAULT,
            self::KIND_LIGHTNING_WALLET_ERROR,
            $storeId,
            $address,
            json_encode(['via' => $reason])
        );
    }

    private static function logEvent(
        string $mintUrl,
        string $eventType,
        ?string $failureType,
        ?string $storeId,
        ?string $address,
        ?string $details
    ): void {
        Database::insert('mint_event_log', [
            'mint_url' => $mintUrl,
            'timestamp' => Database::timestamp(),
            'event_type' => $eventType,
            'failure_type' => $failureType,
            'store_id' => $storeId,
            'address' => $address,
            'details' => $details,
        ]);
        self::pruneLog($mintUrl);
    }

    /**
     * Drop oldest rows so this mint never exceeds EVENT_LOG_CAP_PER_MINT.
     * SQLite doesn't support DELETE … ORDER BY LIMIT without a compile flag,
     * so we delete by id selected via subquery.
     */
    private static function pruneLog(string $mintUrl): void {
        Database::query(
            "DELETE FROM mint_event_log
             WHERE id IN (
                 SELECT id FROM mint_event_log
                 WHERE mint_url = ?
                 ORDER BY timestamp DESC, id DESC
                 LIMIT -1 OFFSET ?
             )",
            [$mintUrl, self::EVENT_LOG_CAP_PER_MINT]
        );
    }
}
