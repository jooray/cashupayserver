<?php
/**
 * CashuPayServer - Notification Sender Module
 *
 * Queues email notifications for invoice settlements and auto-cashout
 * results, applying:
 *   - the site-wide master switch + per-type toggle gate,
 *   - per-store opt-in (with site-wide fallback "to" address),
 *   - 48h dedupe on identical auto-cashout failures (store + destination).
 *
 * Emails sit in `notification_queue` until cron drains them — the request
 * path never blocks on SMTP. See includes/email_sender.php for transport.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/email_sender.php';
// Circular with invoice.php (which requires this file) — harmless under
// require_once; Invoice is only referenced at call time, never at load time.
require_once __DIR__ . '/invoice.php';

class NotificationSender {
    public const EVENT_INVOICE_PAID = 'InvoicePaid';
    public const EVENT_AUTO_CASHOUT_SUCCESS = 'AutoCashoutSuccess';
    public const EVENT_AUTO_CASHOUT_FAILURE = 'AutoCashoutFailure';
    public const EVENT_PAYER_RECEIPT = 'PayerReceipt';
    public const EVENT_UPDATE_AVAILABLE = 'UpdateAvailable';

    // 48-hour window for suppressing identical auto-cashout failure emails.
    private const FAILURE_DEDUPE_WINDOW_SEC = 48 * 60 * 60;

    // Cap drain work per cron tick. Real-world traffic is low, but a backlog
    // of failed deliveries shouldn't be able to wedge the whole cron run.
    private const DRAIN_BATCH = 25;

    // Delivery lease, mirroring WebhookSender's claim lease. When a drainer
    // claims a row it pushes next_attempt_at this far into the future, so any
    // concurrent drainer's SELECT skips the row while the (possibly multi-second
    // SMTP) send is in flight. Comfortably exceeds a normal send; if the sender
    // dies mid-send, the row simply becomes due again after the lease expires
    // and is retried — at-least-once, never lost.
    private const CLAIM_LEASE_SEC = 120;

    // Give up after this many delivery attempts (initial + retries), mirroring
    // WebhookSender::MAX_ATTEMPTS. Past this the row is stamped failed_at and
    // never retried — otherwise a permanently-bad recipient or SMTP misconfig
    // would be re-sent every cron tick forever (a retry storm against SMTP and
    // endless error_log spam).
    private const MAX_ATTEMPTS = 6;

    // Per-invoice cap on payer receipts. The endpoint that calls
    // queuePayerReceipt is public (anyone with the invoice URL can hit it),
    // so without a limit it becomes a small open relay for paste-the-invoice
    // spam. Three lets a customer fix one typo'd address.
    public const PAYER_RECEIPT_MAX_PER_INVOICE = 3;

    /**
     * Queue an "invoice paid" email for a settled invoice. Idempotent at
     * the gate level — if the gate is closed (global off, per-type off,
     * store disabled, no recipient) nothing is enqueued.
     */
    public static function queueInvoicePaid(array $invoice): void {
        if (!self::isEventEnabled('notifications_invoice_paid_enabled')) {
            return;
        }
        $storeId = $invoice['store_id'] ?? null;
        if (!$storeId) return;

        $recipient = self::resolveRecipient($storeId);
        if ($recipient === null) return;

        $invoiceId = $invoice['id'] ?? '(unknown)';
        $amountSats = $invoice['amount_sats'] ?? null;
        $amount = $invoice['amount'] ?? null;
        $currency = $invoice['currency'] ?? '';

        $satsLine = $amountSats !== null
            ? number_format((int)$amountSats) . ' sats'
            : '(sats amount unavailable)';

        // amount/currency reflect what the customer was billed (e.g. "12.50 USD"
        // for a fiat-priced invoice, or "1500 sat" for a sats-priced one).
        $fiatLine = ($amount !== null && $currency !== '')
            ? trim($amount . ' ' . $currency)
            : '(fiat amount unavailable)';

        $storeName = self::storeName($storeId);
        $subject = "Invoice paid: {$invoiceId} ({$satsLine})";
        $body = "An invoice was paid.\n\n"
              . "Store:       {$storeName}\n"
              . "Invoice ID:  {$invoiceId}\n"
              . "Amount:      {$satsLine}\n"
              . "Amount (fiat): {$fiatLine}\n";

        self::enqueue($storeId, self::EVENT_INVOICE_PAID, $recipient, $subject, $body, null, $invoiceId);
    }

    /**
     * Whether the public payment page should offer the "email me a receipt"
     * form for a paid invoice. Composed gate: site-wide master switch ON,
     * per-type toggle ON, and SMTP configured. If any of these is off, the
     * payment page falls back to a "screenshot this page" hint instead.
     */
    public static function isPayerReceiptOffered(): bool {
        if (Config::get('notifications_enabled', false) !== true) return false;
        if (Config::get('notifications_payer_receipt_enabled', false) !== true) return false;
        return EmailSender::isSmtpConfigured();
    }

    /**
     * Count payer-receipt rows we've accepted for this invoice (queued, sent,
     * or stuck failing — they all count toward the cap). The cap is the only
     * thing protecting this public endpoint from being abused as an open
     * relay for paste-the-invoice spam.
     */
    public static function payerReceiptCountForInvoice(string $invoiceId): int {
        $row = Database::fetchOne(
            "SELECT COUNT(*) AS c FROM notification_queue
             WHERE invoice_id = ? AND event_type = ?",
            [$invoiceId, self::EVENT_PAYER_RECEIPT]
        );
        return (int)($row['c'] ?? 0);
    }

    /**
     * Queue a payment-confirmation email to the payer. Caller is responsible
     * for verifying the invoice is Settled and that isPayerReceiptOffered()
     * is true; this method only re-checks the rate limit (since the public
     * endpoint can be hit concurrently). Returns true if queued, false if
     * the per-invoice cap is already reached.
     *
     * The receipt is per-payer, not per-store: the payer entered the address
     * to opt in, so we bypass the store's notifications_enabled flag.
     */
    public static function queuePayerReceipt(array $invoice, string $payerEmail): bool {
        $invoiceId = $invoice['id'] ?? null;
        if (!$invoiceId) return false;

        $storeId = $invoice['store_id'] ?? '';
        $storeName = $storeId !== '' ? self::storeName($storeId) : '';

        $subject = "Payment receipt: invoice {$invoiceId}";
        $body = self::buildPayerReceiptBody($invoice, $storeName);

        // Serialize the count + insert under the write lock. This is a public
        // endpoint that "can be hit concurrently" — two simultaneous requests
        // for the same invoice could otherwise both read count < cap and both
        // insert, overshooting PAYER_RECEIPT_MAX_PER_INVOICE (open-relay abuse).
        Database::beginImmediate();
        try {
            if (self::payerReceiptCountForInvoice($invoiceId) >= self::PAYER_RECEIPT_MAX_PER_INVOICE) {
                Database::rollback();
                return false;
            }
            self::enqueue($storeId, self::EVENT_PAYER_RECEIPT, $payerEmail, $subject, $body, null, $invoiceId);
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollback();
            throw $e;
        }
        return true;
    }

    /**
     * Queue payer receipts that were requested before settlement (the payer
     * left their email on the on-chain "payment detected" screen while the tx
     * was still confirming). Settlement happens on several rails with no
     * shared choke-point, so this is a sweep: the payment page's status poll
     * calls it for its own invoice (instant receipt while the tab is open)
     * and cron calls it for everything (payer walked away). The CAS on
     * payer_receipt_requested makes each request queue at most one receipt
     * even when both callers race.
     *
     * Returns the number of receipts queued.
     */
    public static function flushRequestedPayerReceipts(?string $invoiceId = null): int {
        $sql = "SELECT * FROM invoices
                WHERE status = 'Settled' AND payer_receipt_requested = 1
                  AND customer_email IS NOT NULL AND customer_email != ''";
        $params = [];
        if ($invoiceId !== null) {
            $sql .= " AND id = ?";
            $params[] = $invoiceId;
        }

        $queued = 0;
        foreach (Database::fetchAll($sql, $params) as $invoice) {
            $claimed = Database::update(
                'invoices',
                ['payer_receipt_requested' => 0],
                'id = ? AND payer_receipt_requested = 1',
                [$invoice['id']]
            );
            if ($claimed !== 1) {
                continue; // another caller already claimed this request
            }
            // If receipts were switched off between the request and settlement,
            // the claim still clears the flag — the email stays saved on the
            // invoice, mirroring the settled-page behaviour when receipts are
            // not offered.
            if (self::isPayerReceiptOffered()) {
                if (self::queuePayerReceipt($invoice, (string)$invoice['customer_email'])) {
                    $queued++;
                }
            }
        }
        return $queued;
    }

    /**
     * Build the plain-text body of a payer-facing payment confirmation.
     * Format mirrors the operator-facing InvoicePaid template — same
     * label-aligned style, plus payment-method-specific lines (on-chain
     * txids, swap claim txid, receiving address).
     */
    private static function buildPayerReceiptBody(array $invoice, string $storeName): string {
        $invoiceId = (string)($invoice['id'] ?? '(unknown)');
        $paidAt = isset($invoice['paid_at']) && $invoice['paid_at']
            ? gmdate('Y-m-d H:i:s', (int)$invoice['paid_at']) . ' UTC'
            : '(unknown)';

        $amountSats = $invoice['amount_sats'] ?? null;
        $satsLine = $amountSats !== null
            ? number_format((int)$amountSats) . ' sats'
            : '(sats amount unavailable)';

        $currency = strtoupper((string)($invoice['currency'] ?? ''));
        $amount = $invoice['amount'] ?? null;
        // For sat-denominated invoices the "fiat" line is the same number — drop
        // it. For fiat-denominated invoices, show what the customer was billed.
        $fiatLine = ($amount !== null && $currency !== '' && $currency !== 'SAT' && $currency !== 'SATS')
            ? trim($amount . ' ' . $currency)
            : null;

        // The rail that actually moved funds, with Invoice's fallback to the
        // rail chosen at create time (older rows, legacy cashu-token rows
        // that settled as 'mint') so every row gets a sensible label.
        $rail = (string)(Invoice::effectiveRail($invoice) ?? '');
        $methodLine = self::paymentMethodLabel($rail, (string)($invoice['mint_url'] ?? ''));

        $lines = ["Thank you for shopping at {$storeName}.", ""];
        $lines[] = "Your payment has been received.";
        $lines[] = "";
        $lines[] = "Invoice ID:     {$invoiceId}";
        $lines[] = "Paid at:        {$paidAt}";
        $lines[] = "Amount:         {$satsLine}";
        if ($fiatLine !== null) {
            $lines[] = "Amount (fiat):  {$fiatLine}";
        }
        $lines[] = "Payment method: {$methodLine}";

        // Rail-specific receipt detail: on-chain txid(s) + receiving address,
        // or swap claim txid + merchant address. Pure Lightning rails have no
        // on-chain identifier to print.
        foreach (self::railDetailLines($invoice, $rail) as $detail) {
            $lines[] = $detail;
        }

        $lines[] = "";
        $lines[] = "Keep this email for your records.";
        return implode("\n", $lines) . "\n";
    }

    private static function paymentMethodLabel(string $rail, string $mintUrl = ''): string {
        // Mint-rail payments arrive over Lightning but land as Cashu ecash at
        // the store's mint; direct token receipts never touch Lightning. Both
        // name the mint by hostname, matching the admin invoices tab.
        $mintSuffix = '';
        if ($mintUrl !== '') {
            $host = parse_url($mintUrl, PHP_URL_HOST);
            $mintSuffix = '(' . (is_string($host) && $host !== '' ? $host : $mintUrl) . ')';
        }
        switch ($rail) {
            case 'onchain':   return 'On-chain Bitcoin';
            case 'swap':      return 'Lightning → submarine swap to on-chain';
            case 'lnaddress': return 'Lightning (LNURL)';
            case 'strike':    return 'Lightning (Strike API)';
            case 'mint':      return 'Lightning (cashu)' . $mintSuffix;
            case 'cashu':     return 'Cashu' . $mintSuffix;
            default:          return $rail !== '' ? $rail : '(unknown)';
        }
    }

    /**
     * Look up rail-specific identifiers we want to surface on the receipt.
     * Done as a separate query rather than at enqueue time because the rail
     * tables (onchain_payments, swap_attempts) only get their txid filled in
     * once settlement actually broadcasts — and the payer can request the
     * receipt at any moment after the invoice is marked Settled.
     */
    private static function railDetailLines(array $invoice, string $rail): array {
        $invoiceId = (string)($invoice['id'] ?? '');
        if ($invoiceId === '') return [];

        $lines = [];
        if ($rail === 'onchain') {
            $addr = (string)($invoice['onchain_address'] ?? '');
            if ($addr !== '') {
                $lines[] = "Receiving addr: {$addr}";
            }
            $rows = Database::fetchAll(
                "SELECT txid, vout, amount_sat FROM onchain_payments
                 WHERE invoice_id = ? ORDER BY first_seen_at ASC",
                [$invoiceId]
            );
            foreach ($rows as $row) {
                $lines[] = "Txid:           {$row['txid']}:{$row['vout']}";
            }
        } elseif ($rail === 'swap') {
            $row = Database::fetchOne(
                "SELECT merchant_address, claim_txid FROM swap_attempts
                 WHERE invoice_id = ? ORDER BY id DESC LIMIT 1",
                [$invoiceId]
            );
            if ($row) {
                if (!empty($row['merchant_address'])) {
                    $lines[] = "Receiving addr: {$row['merchant_address']}";
                }
                if (!empty($row['claim_txid'])) {
                    $lines[] = "Claim txid:     {$row['claim_txid']}";
                }
            }
        }
        // mint / lnaddress: nothing to surface — pure Lightning, no on-chain
        // identifier the payer can look up.
        return $lines;
    }
    public static function queueAutoCashoutSuccess(
        string $storeId,
        int $amountSats,
        string $destination
    ): void {
        if (!self::isEventEnabled('notifications_auto_cashout_enabled')) {
            return;
        }
        $recipient = self::resolveRecipient($storeId);
        if ($recipient === null) return;

        $storeName = self::storeName($storeId);
        $sats = number_format($amountSats) . ' sats';
        $subject = "Auto-cashout succeeded: {$sats} to {$destination}";
        $body = "An auto-cashout completed successfully.\n\n"
              . "Store:        {$storeName}\n"
              . "Amount:       {$sats}\n"
              . "Destination:  {$destination}\n";

        // Successful cashouts are not deduped — operators want to see each one.
        self::enqueue($storeId, self::EVENT_AUTO_CASHOUT_SUCCESS, $recipient, $subject, $body, null);
    }

    /**
     * Queue an auto-cashout failure email, with 48h dedupe on
     * (store_id, destination). Identical repeats are silently dropped.
     */
    public static function queueAutoCashoutFailure(
        string $storeId,
        string $destination,
        string $errorMessage,
        ?int $attemptedAmountSats = null
    ): void {
        if (!self::isEventEnabled('notifications_auto_cashout_enabled')) {
            return;
        }
        $recipient = self::resolveRecipient($storeId);
        if ($recipient === null) return;

        $dedupeKey = self::failureDedupeKey($destination);
        if (self::isWithinDedupeWindow($storeId, self::EVENT_AUTO_CASHOUT_FAILURE, $dedupeKey)) {
            return;
        }

        $storeName = self::storeName($storeId);
        $amountLine = $attemptedAmountSats !== null
            ? number_format($attemptedAmountSats) . ' sats'
            : '(amount unavailable)';
        $subject = "Auto-cashout failed: {$destination}";
        $body = "An auto-cashout failed.\n\n"
              . "Store:        {$storeName}\n"
              . "Destination:  {$destination}\n"
              . "Amount:       {$amountLine}\n"
              . "Reason:       {$errorMessage}\n\n"
              . "Subsequent identical failures will be suppressed for 48 hours.\n";

        self::enqueue($storeId, self::EVENT_AUTO_CASHOUT_FAILURE, $recipient, $subject, $body, $dedupeKey);
    }

    /**
     * Queue an "update available" email to the site-wide notification address.
     * Instance-level (no store): the recipient is notifications_to_email only,
     * per-store overrides don't apply. Gated on the master switch plus the
     * per-type toggle — which, unlike the other per-type toggles, DEFAULTS ON:
     * an operator who enabled email notifications almost certainly wants to
     * hear about security updates, and the toggle predates none of their
     * installs (the key is only written once they uncheck it).
     *
     * The caller (Updater::maybeNotifyUpdateAvailable) owns once-per-release
     * dedupe; this method only applies the notification gates. Returns true
     * if a row was enqueued.
     */
    public static function queueUpdateAvailable(array $state): bool {
        if (Config::get('notifications_enabled', false) !== true) {
            return false;
        }
        if (Config::get('notifications_update_available_enabled', true) !== true) {
            return false;
        }
        $recipient = trim((string)Config::get('notifications_to_email', ''));
        if ($recipient === '') {
            return false;
        }

        $latest = trim((string)($state['latest_version'] ?? '')) ?: '(unknown version)';
        $current = trim((string)($state['current_version'] ?? '')) ?: '(unknown)';
        $channel = trim((string)($state['channel'] ?? '')) ?: 'main';

        $subject = "Software update available: {$latest}";
        $body = "A new version of your payment server is available.\n\n"
              . "Latest version:   {$latest}\n"
              . "Current version:  {$current}\n"
              . "Update channel:   {$channel}\n\n"
              . "Automatic updates are disabled on this install, so this update\n"
              . "will NOT apply on its own. Updates deliver new features and\n"
              . "critical security fixes — please apply it soon: open the admin\n"
              . "dashboard and click \"Update now\", or enable automatic updates\n"
              . "under Settings → Auto-update.\n";

        self::enqueue(null, self::EVENT_UPDATE_AVAILABLE, $recipient, $subject, $body, null);
        return true;
    }

    /**
     * Drain up to DRAIN_BATCH pending notifications from the queue.
     * Called from cron.php. Returns a small summary suitable for the cron
     * status JSON: ['sent' => N, 'failed' => N].
     */
    public static function drainQueue(): array {
        $now = time();
        $rows = Database::fetchAll(
            "SELECT * FROM notification_queue
             WHERE sent_at IS NULL
               AND failed_at IS NULL
               AND (next_attempt_at IS NULL OR next_attempt_at <= ?)
             ORDER BY id ASC
             LIMIT ?",
            [$now, self::DRAIN_BATCH]
        );

        $sent = 0;
        $failed = 0;
        foreach ($rows as $row) {
            // Atomic lease claim BEFORE sending. The SELECT above is not a lock,
            // so two drainers (cron racing a future request-path drain, or two
            // cron passes) can read the same due row and both send the email —
            // a double delivery. The claim pushes next_attempt_at CLAIM_LEASE_SEC
            // into the future, gated on the row still being due; SQLite
            // serializes the two UPDATEs so only one drainer wins (rowCount===1)
            // and the loser's WHERE no longer matches. While the winner sends,
            // the row is leased out of every other drainer's SELECT. Mirrors
            // WebhookSender::drainPending.
            $claimed = Database::update(
                'notification_queue',
                ['next_attempt_at' => $now + self::CLAIM_LEASE_SEC, 'attempts' => (int)$row['attempts'] + 1],
                'id = ? AND sent_at IS NULL AND failed_at IS NULL AND (next_attempt_at IS NULL OR next_attempt_at <= ?)',
                [$row['id'], $now]
            );
            if ($claimed !== 1) {
                continue; // another drainer leased this row
            }
            try {
                EmailSender::send(
                    $row['to_email'],
                    $row['subject'],
                    $row['body'],
                    !empty($row['store_id']) ? (string)$row['store_id'] : null
                );
                $sentAt = time();
                Database::update(
                    'notification_queue',
                    ['sent_at' => $sentAt, 'last_error' => null],
                    'id = ? AND sent_at IS NULL',
                    [$row['id']]
                );
                // Record into the dedupe log so failure-suppression can see it.
                if (!empty($row['dedupe_key']) && !empty($row['store_id'])) {
                    self::recordDedupe($row['store_id'], $row['event_type'], $row['dedupe_key'], $sentAt);
                }
                $sent++;
            } catch (Throwable $e) {
                // attempts was already bumped to this value at claim time.
                $attempts = (int)$row['attempts'] + 1;
                $lastError = substr($e->getMessage(), 0, 500);
                if ($attempts >= self::MAX_ATTEMPTS) {
                    // Give up permanently: stamp failed_at so the row leaves the
                    // due-set for good instead of retrying every tick forever.
                    Database::update(
                        'notification_queue',
                        ['last_error' => $lastError, 'failed_at' => time(), 'next_attempt_at' => null],
                        'id = ?',
                        [$row['id']]
                    );
                } else {
                    // Back off the retry instead of releasing the lease to NULL
                    // (immediately due). Mirrors WebhookSender's backoff so a
                    // transient SMTP outage doesn't get hammered every cron pass.
                    Database::update(
                        'notification_queue',
                        ['last_error' => $lastError, 'next_attempt_at' => time() + self::backoffSeconds($attempts)],
                        'id = ?',
                        [$row['id']]
                    );
                }
                error_log(
                    "NotificationSender: send failed for queue row {$row['id']} (attempt {$attempts}): " . $e->getMessage()
                );
                $failed++;
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    /**
     * Exponential-ish backoff (seconds) keyed by the number of attempts made.
     * Mirrors WebhookSender::backoffSeconds so the two outboxes pace retries
     * identically.
     */
    private static function backoffSeconds(int $attempts): int {
        $schedule = [15, 60, 300, 1800, 3600];
        $idx = max(0, min($attempts - 1, count($schedule) - 1));
        return $schedule[$idx];
    }

    /**
     * Delete terminal queue rows (delivered or permanently failed) older than
     * $retentionSec. Still-pending rows (both timestamps NULL) are always kept.
     * drainQueue marks rows but never deletes them, so without this the queue
     * grows unbounded. Mirrors WebhookSender::pruneDeliveries.
     *
     * @return int number of rows deleted
     */
    public static function cleanup(int $retentionSec = 2592000): int {
        return Database::query(
            "DELETE FROM notification_queue
              WHERE (sent_at IS NOT NULL OR failed_at IS NOT NULL)
                AND created_at < ?",
            [time() - $retentionSec]
        )->rowCount();
    }

    /**
     * Lightweight check used by the admin UI: are there any rows still pending
     * delivery? Excludes rows that have been given up on (failed_at set).
     */
    public static function pendingCount(): int {
        $row = Database::fetchOne(
            "SELECT COUNT(*) AS c FROM notification_queue WHERE sent_at IS NULL AND failed_at IS NULL"
        );
        return (int)($row['c'] ?? 0);
    }

    // ------------------------------------------------------------------------
    // Gating / recipient resolution
    // ------------------------------------------------------------------------

    private static function isEventEnabled(string $perTypeKey): bool {
        if (Config::get('notifications_enabled', false) !== true) {
            return false;
        }
        return Config::get($perTypeKey, false) === true;
    }

    /**
     * Resolve the "to" address for a store. Per-store override wins; site-wide
     * notifications_to_email is the fallback. Returns null when the store has
     * notifications disabled or no recipient is configured anywhere.
     */
    private static function resolveRecipient(string $storeId): ?string {
        $store = Config::getStore($storeId);
        if ($store === null) return null;
        if ((int)($store['notifications_enabled'] ?? 0) !== 1) return null;

        $perStore = trim((string)($store['notification_email'] ?? ''));
        if ($perStore !== '') {
            return $perStore;
        }
        $siteWide = trim((string)Config::get('notifications_to_email', ''));
        return $siteWide !== '' ? $siteWide : null;
    }

    private static function storeName(string $storeId): string {
        $store = Config::getStore($storeId);
        return (string)($store['name'] ?? $storeId);
    }

    // ------------------------------------------------------------------------
    // Dedupe
    // ------------------------------------------------------------------------

    /**
     * Per the spec: "identical" failure = same store + same destination.
     * Error text is intentionally NOT part of the key.
     */
    private static function failureDedupeKey(string $destination): string {
        return hash('sha256', strtolower($destination));
    }

    private static function isWithinDedupeWindow(string $storeId, string $eventType, string $dedupeKey): bool {
        $row = Database::fetchOne(
            "SELECT sent_at FROM notification_log
             WHERE store_id = ? AND event_type = ? AND dedupe_key = ?",
            [$storeId, $eventType, $dedupeKey]
        );
        if ($row === null) return false;
        $sentAt = (int)$row['sent_at'];
        return ($sentAt + self::FAILURE_DEDUPE_WINDOW_SEC) > time();
    }

    private static function recordDedupe(string $storeId, string $eventType, string $dedupeKey, int $sentAt): void {
        // Upsert: SQLite supports ON CONFLICT REPLACE via a delete-then-insert.
        Database::delete(
            'notification_log',
            'store_id = ? AND event_type = ? AND dedupe_key = ?',
            [$storeId, $eventType, $dedupeKey]
        );
        Database::insert('notification_log', [
            'store_id' => $storeId,
            'event_type' => $eventType,
            'dedupe_key' => $dedupeKey,
            'sent_at' => $sentAt,
        ]);
    }

    // ------------------------------------------------------------------------
    // Queue insert
    // ------------------------------------------------------------------------

    private static function enqueue(
        ?string $storeId,
        string $eventType,
        string $toEmail,
        string $subject,
        string $body,
        ?string $dedupeKey,
        ?string $invoiceId = null
    ): void {
        Database::insert('notification_queue', [
            'store_id' => $storeId,
            'event_type' => $eventType,
            'to_email' => $toEmail,
            'subject' => $subject,
            'body' => $body,
            'dedupe_key' => $dedupeKey,
            'invoice_id' => $invoiceId,
            'created_at' => time(),
        ]);
    }
}
