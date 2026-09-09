<?php
/**
 * CashuPayServer - Invoice Module
 *
 * Invoice creation, management, and payment detection.
 * Supports per-store wallet configuration.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/rates.php';
require_once __DIR__ . '/webhook_sender.php';
require_once __DIR__ . '/urls.php';
require_once __DIR__ . '/../cashu-wallet-php/CashuWallet.php';

use Cashu\Wallet;
use Cashu\WalletStorage;
use Cashu\Proof;
use Cashu\ProofState;

class Invoice {
    /**
     * Seconds after an invoice's expiry during which we keep re-checking the mint for a
     * late payment (a quote paid in the last seconds, or while cron was down). A quote
     * paid within this window is still minted and settled instead of being lost.
     */
    const EXPIRY_RECOVERY_GRACE = 259200; // 72h of frequent re-checks

    /**
     * After the frequent window, unresolved quotes are still re-checked, just rarely.
     *
     * A quote paid before expiry while cron was down is mintable long afterwards; giving
     * up at 72 hours made those sats unrecoverable for no reason. Checks continue daily
     * until the mint gives a terminal answer.
     */
    const EXPIRY_RECOVERY_SLOW_INTERVAL = 86400; // 24h

    /** Cooldown before a stuck 'Processing' invoice may be re-claimed for minting. */
    const MINT_RETRY_COOLDOWN = 120; // 2 min

    /**
     * Atomically claim an invoice for minting so that concurrent pollers (payment page,
     * API GET, cron) cannot both call mint() for the same quote. Returns true only for the
     * single caller that wins the claim. See FABLE-CASHUPAYSERVER-AUDIT (C3).
     */
    private static function claimForMinting(string $invoiceId): bool {
        $now = time();
        // Claim a fresh (New) or recovered (Expired-but-paid) invoice.
        $claimed = Database::update(
            'invoices',
            ['status' => 'Processing', 'processing_since' => $now],
            "id = ? AND status IN ('New', 'Expired')",
            [$invoiceId]
        );
        if ($claimed === 1) {
            return true;
        }
        // Re-claim a Processing invoice whose previous attempt appears to have died.
        $claimed = Database::update(
            'invoices',
            ['processing_since' => $now],
            "id = ? AND status = 'Processing' AND (processing_since IS NULL OR processing_since < ?)",
            [$invoiceId, $now - self::MINT_RETRY_COOLDOWN]
        );
        return $claimed === 1;
    }

    /**
     * Atomically transition an invoice to Settled exactly once. Returns true only if this
     * call performed the transition (so the caller fires the webhook once, avoiding
     * duplicate InvoiceSettled events under races).
     */
    private static function markSettledOnce(string $invoiceId): bool {
        $changed = Database::update(
            'invoices',
            ['status' => 'Settled'],
            "id = ? AND status != 'Settled'",
            [$invoiceId]
        );
        return $changed === 1;
    }

    private static function settleAndEnqueue(string $invoiceId): bool {
        Database::beginTransaction();
        try {
            $settled = self::markSettledOnce($invoiceId);
            if ($settled) {
                $invoice = self::getById($invoiceId);
                // BTCPay emits the payment-received and processing events before settling,
                // and clients may subscribe to only those. Recovery paths reached
                // settlement without them, so a shop listening for InvoiceProcessing
                // never heard about a recovered payment. The outbox deduplicates on a
                // logical key, so emitting them here is a no-op when they already went out.
                WebhookSender::fireEvent($invoice['store_id'], 'InvoiceReceivedPayment', $invoice);
                WebhookSender::fireEvent($invoice['store_id'], 'InvoiceProcessing', $invoice);
                WebhookSender::fireEvent($invoice['store_id'], 'InvoiceSettled', $invoice);
            }
            Database::commit();
            return $settled;
        } catch (Throwable $e) {
            Database::rollback();
            throw $e;
        }
    }

    /**
     * Create a new invoice
     *
     * Uses per-store mint configuration and supports multi-mint fallback.
     */
    public static function create(string $storeId, array $options): array {
        $amount = $options['amount'];
        $currency = $options['currency'] ?? 'sat';
        $metadata = $options['metadata'] ?? null;
        $checkout = $options['checkout'] ?? null;

        // Get store configuration
        $store = Config::getStore($storeId);
        if (!$store) {
            throw new Exception('Store not found');
        }

        if (!Config::isStoreConfigured($storeId)) {
            throw new Exception('Store not configured - mint and seed phrase required');
        }

        $mintUrl = $store['mint_url'];
        $mintUnit = $store['mint_unit'];
        $exchangeFee = (float)($store['exchange_fee_percent'] ?? 0);
        $primaryProvider = $store['price_provider_primary'] ?? 'coingecko';
        $secondaryProvider = $store['price_provider_secondary'] ?? 'binance';

        // Convert amount to mint unit using bidirectional conversion
        $amountInMintUnit = ExchangeRates::convertToMintUnit(
            $amount,
            $currency,
            $mintUnit,
            $exchangeFee,
            $primaryProvider,
            $secondaryProvider
        );

        // Get exchange rate for fiat currencies
        $exchangeRate = null;
        if (!in_array(strtoupper($currency), ['SAT', 'SATS', 'BTC'])) {
            $exchangeRate = ExchangeRates::getBtcPrice($currency, $primaryProvider, $secondaryProvider);
        }

        // Try primary mint first, then backup mints
        $allMints = Config::getStoreAllMintUrls($storeId);
        $lastError = null;
        $quote = null;
        $usedMintUrl = null;

        foreach ($allMints as $tryMintUrl) {
            try {
                $wallet = self::getWalletForStore($storeId, $tryMintUrl);
                $quote = $wallet->requestMintQuote($amountInMintUnit);
                $usedMintUrl = $tryMintUrl;
                break; // Success!
            } catch (Exception $e) {
                $lastError = $e;
                error_log("Mint quote failed for $tryMintUrl: " . $e->getMessage());
                continue; // Try next mint
            }
        }

        if ($quote === null) {
            throw new Exception(
                'Failed to get mint quote from all configured mints. ' .
                'Last error: ' . ($lastError ? $lastError->getMessage() : 'Unknown')
            );
        }

        // Calculate expiration
        $expiration = $quote->expiry ?? (time() + Config::getInvoiceExpiration());

        // Generate invoice ID
        $invoiceId = Database::generateId('inv');
        $now = Database::timestamp();

        Database::beginTransaction();
        try {
            Database::insert('invoices', [
                'id' => $invoiceId,
                'store_id' => $storeId,
                'status' => 'New',
                'additional_status' => 'None',
                'amount' => $amount,
                'currency' => $currency,
                'amount_sats' => $amountInMintUnit, // Actually amount in mint's smallest unit
                'exchange_rate' => $exchangeRate,
                'quote_id' => $quote->quote,
                'bolt11' => $quote->request,
                'mint_url' => $usedMintUrl,
                'metadata' => $metadata ? json_encode($metadata) : null,
                'checkout_config' => $checkout ? json_encode($checkout) : null,
                'created_at' => $now,
                'expiration_time' => $expiration,
            ]);
            $invoice = self::getById($invoiceId);
            WebhookSender::fireEvent($storeId, 'InvoiceCreated', $invoice);
            Database::commit();
        } catch (Throwable $e) {
            Database::rollback();
            throw $e;
        }

        return $invoice;
    }

    /**
     * Get invoice by ID
     */
    public static function getById(string $id): ?array {
        return Database::fetchOne(
            "SELECT * FROM invoices WHERE id = ?",
            [$id]
        );
    }

    /**
     * Get invoices by store
     */
    public static function getByStore(string $storeId, ?string $status = null, int $limit = 50, int $offset = 0): array {
        $sql = "SELECT * FROM invoices WHERE store_id = ?";
        $params = [$storeId];

        if ($status !== null) {
            $sql .= " AND status = ?";
            $params[] = $status;
        }

        $sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        return Database::fetchAll($sql, $params);
    }

    /**
     * Update invoice status
     */
    /**
     * Statuses that may still change. Anything else is a claim we have already resolved.
     */
    private const MUTABLE_STATUSES = ['New', 'Processing'];

    /**
     * Move an invoice to a new status and emit its event in the same transaction.
     *
     * The transition is conditional on the invoice still being in a mutable state.
     * Without that, an expiry poller that read `New` before another worker minted and
     * settled would afterwards write `Expired` over the settlement — funds held, order
     * shown as expired, and a contradictory event already delivered.
     *
     * @param string[]|null $expectedStatuses Apply only from one of these (default: any mutable one)
     * @return bool True when this call performed the transition
     */
    public static function updateStatus(
        string $invoiceId,
        string $status,
        ?string $additionalStatus = null,
        ?array $expectedStatuses = null
    ): bool {
        $updates = ['status' => $status];

        if ($additionalStatus !== null) {
            $updates['additional_status'] = $additionalStatus;
        }

        $eventType = match ($status) {
            'Processing' => 'InvoiceProcessing',
            'Settled' => 'InvoiceSettled',
            'Expired' => 'InvoiceExpired',
            'Invalid' => 'InvoiceInvalid',
            default => null,
        };

        $expected = $expectedStatuses ?? self::MUTABLE_STATUSES;
        $placeholders = implode(',', array_fill(0, count($expected), '?'));

        Database::beginTransaction();
        try {
            $set = implode(' = ?, ', array_keys($updates)) . ' = ?';
            $stmt = Database::query(
                "UPDATE invoices SET {$set} WHERE id = ? AND status IN ({$placeholders})",
                array_merge(array_values($updates), [$invoiceId], $expected)
            );
            if ($stmt->rowCount() === 0) {
                // Someone else already moved it somewhere this call may not overwrite.
                Database::commit();
                return false;
            }

            $invoice = self::getById($invoiceId);
            if ($eventType && $invoice) {
                WebhookSender::fireEvent($invoice['store_id'], $eventType, $invoice);
            }
            Database::commit();
            return true;
        } catch (Throwable $e) {
            Database::rollback();
            throw $e;
        }
    }

    /**
     * Mark expired invoices without contacting the mint
     *
     * @return int Number of invoices marked as expired
     */
    public static function markExpiredInvoices(): int {
        $now = time();
        // Capture which invoices are transitioning so we can fire InvoiceExpired for each
        // (the bulk UPDATE alone emitted no webhook, so shops never learned of expiry).
        // See FABLE-CASHUPAYSERVER-AUDIT (C-WH-1).
        Database::beginTransaction();
        try {
            // Select and update inside the transaction, and update exactly the ids
            // selected: reading first and then updating a broader predicate expired
            // invoices that were never in the list (and skipped events for them).
            $expiring = Database::fetchAll(
                "SELECT id, store_id FROM invoices WHERE status = 'New' AND expiration_time < ?",
                [$now]
            );

            $count = 0;
            foreach ($expiring as $row) {
                $stmt = Database::query(
                    "UPDATE invoices SET status = 'Expired' WHERE id = ? AND status = 'New'",
                    [$row['id']]
                );
                if ($stmt->rowCount() === 0) {
                    continue; // settled by another worker in the meantime
                }
                $count++;
                $invoice = self::getById($row['id']);
                if ($invoice) {
                    WebhookSender::fireEvent($row['store_id'], 'InvoiceExpired', $invoice);
                }
            }
            Database::commit();
        } catch (Throwable $e) {
            Database::rollback();
            throw $e;
        }

        return $count;
    }

    /**
     * Poll pending quotes and process payments with rate limiting and backoff
     *
     * @param int $minInterval Minimum seconds between polls for the same invoice (default 30)
     * @param int $batchLimit Maximum invoices to poll per call (default 10)
     */
    public static function pollPendingQuotes(int $minInterval = 30, int $batchLimit = 10): void {
        // First, mark all expired invoices without contacting the mint
        self::markExpiredInvoices();

        $now = time();

        // Fetch invoices that need polling with backoff strategy:
        // - Not expired
        // - Not recently polled (respects minInterval)
        // - Ordered by last_polled_at (NULL first = never polled)
        // - Limited batch size to avoid hammering mint
        // Poll New invoices that aren't expired, PLUS any invoice stuck in Processing
        // (its mint() may have failed transiently and needs to be re-driven). Without the
        // Processing arm, a paid-but-unminted invoice could sit forever waiting for a page
        // load. See FABLE-CASHUPAYSERVER-AUDIT (C5).
        $pendingInvoices = Database::fetchAll(
            "SELECT * FROM invoices
             WHERE status IN ('New', 'Processing')
             AND quote_id IS NOT NULL
             AND (status = 'Processing' OR expiration_time > ?)
             AND (last_polled_at IS NULL OR last_polled_at <= ?)
             ORDER BY
                 CASE WHEN last_polled_at IS NULL THEN 0 ELSE 1 END,
                 last_polled_at ASC
             LIMIT ?",
            [$now, $now - $minInterval, $batchLimit]
        );

        if (empty($pendingInvoices)) {
            return;
        }

        foreach ($pendingInvoices as $invoice) {
            try {
                // Update last_polled_at before polling (so we don't re-poll on failure)
                Database::update('invoices', ['last_polled_at' => $now], 'id = ?', [$invoice['id']]);

                // Get wallet for the exact mint that issued this invoice's quote
                $wallet = self::getWalletForStore($invoice['store_id'], $invoice['mint_url'] ?? null);

                // Check quote status
                $quoteStatus = $wallet->checkMintQuote($invoice['quote_id']);
                self::recordPollOutcome($invoice['id'], $quoteStatus->state, null);

                if ($quoteStatus->isPaid() || $quoteStatus->isIssued()) {
                    if ($quoteStatus->isIssued()) {
                        self::completeIssuedInvoice($invoice, $wallet);
                    } else {
                        self::mintAndStoreTokens($invoice, $wallet);
                    }
                }
            } catch (Throwable $e) {
                // Visible to the operator, not just to a log they cannot read.
                self::recordPollOutcome($invoice['id'], null, $e->getMessage());
                error_log("CashuPayServer: Error polling invoice {$invoice['id']}: " . $e->getMessage());
            }
        }
    }

    /**
     * Mint tokens and store proofs
     */
    private static function mintAndStoreTokens(array $invoice, Wallet $wallet): void {
        // Atomic claim: only one worker mints a given quote. Prevents double-mint and the
        // pending-op corruption it can cause. See FABLE-CASHUPAYSERVER-AUDIT (C3).
        if (!self::claimForMinting($invoice['id'])) {
            return; // another worker owns this mint (or it's not claimable)
        }

        // Mint tokens - library stores proofs in cashu_proofs with quote_id.
        // Proofs are persisted by the library BEFORE this returns; a crash here is
        // recovered by recoverOrphanedInvoices() on the next cron tick.
        $proofs = $wallet->mint($invoice['quote_id'], $invoice['amount_sats']);

        // Update invoice status in a transaction
        Database::beginTransaction();

        try {
            $settled = self::markSettledOnce($invoice['id']);
            if ($settled) {
                $updatedInvoice = self::getById($invoice['id']);
                WebhookSender::fireEvent($invoice['store_id'], 'InvoiceReceivedPayment', $updatedInvoice);
                WebhookSender::fireEvent($invoice['store_id'], 'InvoiceSettled', $updatedInvoice);
            }
            Database::commit();
        } catch (Throwable $e) {
            Database::rollback();
            throw $e;
        }
    }

    /**
     * Format invoice for API response
     */
    public static function formatForApi(array $invoice): array {
        // Get store's mint unit for proper display
        $mintUnit = Config::getStoreMintUnit($invoice['store_id']);

        $result = [
            'id' => $invoice['id'],
            'storeId' => $invoice['store_id'],
            'amount' => $invoice['amount'],
            'currency' => $invoice['currency'],
            'status' => $invoice['status'],
            'additionalStatus' => $invoice['additional_status'],
            'createdTime' => $invoice['created_at'],
            'expirationTime' => $invoice['expiration_time'],
            'checkoutLink' => Urls::payment($invoice['id']),
        ];

        // Add Lightning payment info if available
        if ($invoice['bolt11']) {
            $result['checkout'] = [
                'paymentMethods' => [
                    'BTC-LightningNetwork' => [
                        'paymentLink' => 'lightning:' . $invoice['bolt11'],
                        'destination' => $invoice['bolt11'],
                    ],
                ],
            ];
        }

        // Include converted amount in mint unit
        if ($invoice['amount_sats']) {
            $result['amountInMintUnit'] = $invoice['amount_sats'];
            $result['mintUnit'] = $mintUnit;
        }

        if ($invoice['exchange_rate']) {
            $result['exchangeRate'] = [
                'rate' => $invoice['exchange_rate'],
                'currency' => $invoice['currency'],
            ];
        }

        // Include metadata
        if ($invoice['metadata']) {
            $result['metadata'] = json_decode($invoice['metadata'], true);
        }

        // Include checkout config
        if ($invoice['checkout_config']) {
            $checkoutConfig = json_decode($invoice['checkout_config'], true);
            if (isset($checkoutConfig['redirectURL'])) {
                $result['checkout']['redirectURL'] = $checkoutConfig['redirectURL'];
            }
            if (isset($checkoutConfig['redirectAutomatically'])) {
                $result['checkout']['redirectAutomatically'] = $checkoutConfig['redirectAutomatically'];
            }
        }

        return $result;
    }

    /**
     * Cache for wallet instances per store+mint
     */
    private static array $walletCache = [];

    /**
     * Get or create wallet instance for a store
     *
     * @param string $storeId Store ID
     * @param string|null $mintUrl Optional specific mint URL (for backup mints)
     * @return Wallet
     */
    public static function getWalletForStore(
        string $storeId,
        ?string $mintUrl = null,
        ?string $mintUnit = null
    ): Wallet {
        $store = Config::getStore($storeId);
        if (!$store) {
            throw new Exception('Store not found');
        }

        $mintUrl = $mintUrl ?? $store['mint_url'];
        $mintUnit = $mintUnit ?? ($store['mint_unit'] ?? 'sat');
        $seedPhrase = $store['seed_phrase'];
        $accountId = $store['wallet_account_id'] ?? null;

        if (empty($mintUrl) || empty($seedPhrase) || empty($accountId)) {
            throw new Exception('Store wallet not configured');
        }

        $cacheKey = $storeId . '|' . $mintUrl . '|' . $mintUnit;

        if (!isset(self::$walletCache[$cacheKey])) {
            $wallet = new Wallet($mintUrl, $mintUnit, Database::getDbPath(), $accountId);
            $wallet->loadMint();
            $wallet->initFromMnemonic($seedPhrase);

            self::$walletCache[$cacheKey] = $wallet;
        }

        return self::$walletCache[$cacheKey];
    }

    /** Initialize a newly configured store explicitly as new or recovery-only. */
    public static function initializeWalletForStore(
        string $storeId,
        bool $existingSeed,
        ?string $mintUrl = null,
        ?string $mintUnit = null
    ): Wallet {
        $store = Config::getStore($storeId);
        if (!$store || empty($store['mint_url']) || empty($store['seed_phrase']) || empty($store['wallet_account_id'])) {
            throw new Exception('Store wallet not configured');
        }
        $mintUrl = $mintUrl ?? $store['mint_url'];
        $mintUnit = $mintUnit ?? ($store['mint_unit'] ?? 'sat');
        $wallet = new Wallet(
            $mintUrl,
            $mintUnit,
            Database::getDbPath(),
            $store['wallet_account_id']
        );
        $wallet->loadMint();
        $fingerprint = $wallet->getStorage()?->getSeedFingerprint();
        if ($fingerprint !== null) {
            $wallet->initFromMnemonic($store['seed_phrase']);
        } elseif ($existingSeed) {
            $wallet->initializeForRestore($store['seed_phrase']);
        } else {
            $wallet->initializeNewFromMnemonic($store['seed_phrase']);
        }
        self::$walletCache[$storeId . '|' . $mintUrl . '|' . $mintUnit] = $wallet;
        return $wallet;
    }

    /**
     * Get wallet instance for a store (public accessor)
     */
    public static function getWalletInstance(string $storeId): Wallet {
        return self::getWalletForStore($storeId);
    }

    // =========================================================================
    // SINGLE INVOICE POLLING
    // =========================================================================

    /**
     * Poll a single invoice's quote status
     */
    /**
     * Shortest interval between two mint round-trips for the same invoice.
     *
     * The checkout page polls every couple of seconds from every open tab, and each poll
     * used to hit the mint. A known checkout link was therefore enough to occupy a small
     * PHP-FPM pool with slow network calls.
     */
    const MIN_POLL_INTERVAL = 5;

    public static function pollSingleQuote(string $invoiceId, bool $force = false): void {
        $invoice = self::getById($invoiceId);
        if (!$invoice || !$invoice['quote_id']) {
            return;
        }

        // Only process New or Processing invoices
        if (!in_array($invoice['status'], ['New', 'Processing'])) {
            return;
        }

        // Check expiration (only for New invoices)
        if ($invoice['status'] === 'New' && $invoice['expiration_time'] < time()) {
            self::updateStatus($invoice['id'], 'Expired');
            return;
        }

        // Coalesce concurrent pollers for this invoice.
        $now = time();
        if (!$force) {
            $claimed = Database::query(
                "UPDATE invoices SET last_polled_at = ?
                 WHERE id = ? AND (last_polled_at IS NULL OR last_polled_at <= ?)",
                [$now, $invoiceId, $now - self::MIN_POLL_INTERVAL]
            )->rowCount();
            if ($claimed === 0) {
                return; // another request checked this quote moments ago
            }
        }

        try {
            $wallet = self::getWalletForStore($invoice['store_id'], $invoice['mint_url'] ?? null);
            $quoteStatus = $wallet->checkMintQuote($invoice['quote_id']);

            self::recordPollOutcome($invoice['id'], $quoteStatus->state, null);

            if ($quoteStatus->isPaid() || $quoteStatus->isIssued()) {
                if ($quoteStatus->isIssued()) {
                    self::completeIssuedInvoice($invoice, $wallet);
                } elseif ($invoice['status'] === 'New') {
                    self::mintAndStoreTokens($invoice, $wallet);
                } elseif ($invoice['status'] === 'Processing') {
                    self::mintAndStoreTokens($invoice, $wallet);
                }
            }
        } catch (Throwable $e) {
            // Record it where the operator can see it. This used to go only to the PHP
            // error log, which on most shared hosts nobody can read — so "why wasn't my
            // order marked paid?" had no answer at all.
            self::recordPollOutcome($invoice['id'], null, $e->getMessage());
            error_log("CashuPayServer: Error polling single quote {$invoice['id']}: " . $e->getMessage());
        }
    }

    /**
     * Remember what the mint last said about an invoice's quote, and any failure.
     *
     * Never throws: diagnostics must not be the thing that breaks a payment.
     */
    private static function recordPollOutcome(string $invoiceId, ?string $state, ?string $error): void {
        try {
            Database::update(
                'invoices',
                [
                    'last_poll_state' => $state,
                    'last_poll_error' => $error !== null ? mb_substr($error, 0, 300) : null,
                ],
                'id = ?',
                [$invoiceId]
            );
        } catch (Throwable $e) {
            // Ignore: this is only bookkeeping.
        }
    }

    // =========================================================================
    // ISSUED QUOTE HANDLING
    // =========================================================================

    private static function completeIssuedInvoice(array $invoice, Wallet $wallet): void {
        if ($wallet->hasStorage()) {
            $proofs = $wallet->getStorage()->getProofsByQuoteId($invoice['quote_id']);
            if (!empty($proofs)) {
                // Proofs already exist for this quote — just settle (once).
                self::settleAndEnqueue($invoice['id']);
                return;
            }
        }

        // ISSUED at the mint but no proofs locally: the mint() call was interrupted after
        // the mint signed but before proofs were stored. Retry mint() — the library's
        // pending-op journal re-sends identical outputs and recovers the signatures (NUT-09
        // restore inside mint retry). See FABLE-CASHUPAYSERVER-AUDIT (C-REC-1).
        error_log("CashuPayServer: ISSUED quote {$invoice['quote_id']} has no proofs - retrying mint for invoice {$invoice['id']}");
        try {
            self::mintAndStoreTokens($invoice, $wallet);
        } catch (Exception $e) {
            error_log("CashuPayServer: mint retry for ISSUED quote {$invoice['quote_id']} failed: " . $e->getMessage());
        }
    }

    // =========================================================================
    // ORPHANED INVOICE RECOVERY
    // =========================================================================

    /**
     * Recover orphaned invoices stuck in Processing state.
     *
     * If proofs already exist for the quote, settle. Otherwise ask the mint: if the quote
     * is PAID/ISSUED, re-drive mint() (recovers a mint interrupted after the mint signed).
     * See FABLE-CASHUPAYSERVER-AUDIT (C5, C-REC-1).
     */
    public static function recoverOrphanedInvoices(): array {
        $recovered = [];

        $stuck = Database::fetchAll(
            "SELECT * FROM invoices WHERE status = 'Processing' AND created_at < ?",
            [time() - 60]
        );

        foreach ($stuck as $invoice) {
            try {
                if (!$invoice['quote_id']) {
                    continue;
                }
                $wallet = self::getWalletForStore($invoice['store_id'], $invoice['mint_url'] ?? null);
                if (!$wallet->hasStorage()) {
                    continue;
                }

                $proofs = $wallet->getStorage()->getProofsByQuoteId($invoice['quote_id']);
                if (!empty($proofs)) {
                    if (self::settleAndEnqueue($invoice['id'])) {
                        $recovered[] = $invoice['id'];
                        error_log("CashuPayServer: Recovered orphaned invoice {$invoice['id']}");
                    }
                    continue;
                }

                // No proofs yet: re-check the mint and re-drive minting if paid/issued.
                $quoteStatus = $wallet->checkMintQuote($invoice['quote_id']);
                if ($quoteStatus->isPaid() || $quoteStatus->isIssued()) {
                    self::mintAndStoreTokens($invoice, $wallet);
                    if (self::getById($invoice['id'])['status'] === 'Settled') {
                        $recovered[] = $invoice['id'];
                        error_log("CashuPayServer: Re-minted orphaned invoice {$invoice['id']}");
                    }
                }
            } catch (Exception $e) {
                error_log("CashuPayServer: Error recovering invoice {$invoice['id']}: " . $e->getMessage());
            }
        }

        return $recovered;
    }

    /**
     * Recover invoices that were marked Expired by the local clock but whose Lightning
     * quote was actually paid (late payment, or cron down at expiry). Within a grace window
     * we re-check the mint and, if paid/issued, mint the tokens and settle — instead of
     * silently losing the customer's payment. See FABLE-CASHUPAYSERVER-AUDIT (C1).
     *
     * @return array Recovered invoice IDs
     */
    public static function recoverExpiredPaidInvoices(int $minInterval = 60, int $batchLimit = 10): array {
        $recovered = [];
        $now = time();

        // Two windows, not a cutoff: frequent checks right after expiry, then a daily
        // re-check that continues indefinitely. A quote paid before expiry while cron was
        // down stays mintable, so abandoning it after 72 hours simply lost the money.
        // `Invalid` is included: a merchant cancelling an order does not revoke the
        // Lightning invoice a customer may still pay.
        $rows = Database::fetchAll(
            "SELECT * FROM invoices
             WHERE status IN ('Expired', 'Invalid')
             AND quote_id IS NOT NULL
             AND (last_polled_at IS NULL OR last_polled_at <= ?)
             ORDER BY (expiration_time > ?) DESC, last_polled_at ASC
             LIMIT ?",
            [$now - $minInterval, $now - self::EXPIRY_RECOVERY_GRACE, $batchLimit]
        );

        // Anything past the frequent window is only re-checked once a day.
        $rows = array_values(array_filter($rows, function (array $invoice) use ($now): bool {
            if ((int)$invoice['expiration_time'] > $now - self::EXPIRY_RECOVERY_GRACE) {
                return true;
            }
            $last = (int)($invoice['last_polled_at'] ?? 0);
            return $now - $last >= self::EXPIRY_RECOVERY_SLOW_INTERVAL;
        }));

        foreach ($rows as $invoice) {
            try {
                Database::update('invoices', ['last_polled_at' => $now], 'id = ?', [$invoice['id']]);

                $wallet = self::getWalletForStore($invoice['store_id'], $invoice['mint_url'] ?? null);
                $quoteStatus = $wallet->checkMintQuote($invoice['quote_id']);

                if ($quoteStatus->isPaid() || $quoteStatus->isIssued()) {
                    error_log("CashuPayServer: Late payment on expired invoice {$invoice['id']} - recovering");
                    if ($quoteStatus->isIssued()) {
                        // Un-expire so completeIssuedInvoice/mint can settle it.
                        self::claimForMinting($invoice['id']);
                        self::completeIssuedInvoice(self::getById($invoice['id']), $wallet);
                    } else {
                        self::mintAndStoreTokens($invoice, $wallet);
                    }
                    if (self::getById($invoice['id'])['status'] === 'Settled') {
                        $recovered[] = $invoice['id'];
                    }
                } elseif ($quoteStatus->expiry !== null && $quoteStatus->expiry < $now
                          && !$quoteStatus->isPaid()) {
                    // The mint's own quote has expired unpaid: this claim is finally
                    // resolved and the row may be cleaned up by age from now on.
                    Database::update('invoices', ['quote_id' => null], 'id = ?', [$invoice['id']]);
                }
            } catch (Throwable $e) {
                error_log("CashuPayServer: Error recovering expired invoice {$invoice['id']}: " . $e->getMessage());
            }
        }

        return $recovered;
    }

    // =========================================================================
    // PER-STORE BALANCE OPERATIONS (OFFLINE-FIRST)
    // =========================================================================
    // These methods read directly from local storage without contacting the mint.
    // Ecash is offline-first - local storage is the source of truth for proofs.

    /**
     * Get total balance for a store (reads from local storage)
     *
     * This is the default offline-first method. Reads directly from SQLite
     * without contacting the mint. Use for balance display, threshold checks,
     * and any operation that doesn't require mint verification.
     */
    public static function getBalance(string $storeId): int {
        $store = Config::getStore($storeId);
        if (!$store || empty($store['mint_url'])) {
            return 0;
        }

        $total = 0;
        foreach (Config::getStoreWalletAccounts($storeId) as $account) {
            $storage = new WalletStorage(
                Database::getDbPath(),
                $account['mint_url'],
                $account['unit'],
                $store['wallet_account_id']
            );
            $total += $storage->getBalance();
        }
        return $total;
    }

    /**
     * Get unspent proofs for a store (reads from local storage)
     *
     * Returns Proof objects directly from SQLite storage.
     * No mint contact required - ecash proofs are stored locally.
     */
    public static function getUnspentProofs(string $storeId): array {
        $store = Config::getStore($storeId);
        if (!$store || empty($store['mint_url'])) {
            return [];
        }

        $storage = new WalletStorage(
            Database::getDbPath(),
            $store['mint_url'],
            $store['mint_unit'] ?? 'sat',
            $store['wallet_account_id']
        );
        return $storage->getProofsAsObjects(ProofState::UNSPENT);
    }

    /**
     * Mark proofs as spent for a store (updates local storage)
     */
    public static function markProofsSpent(string $storeId, array $secrets): void {
        if (empty($secrets)) {
            return;
        }

        $store = Config::getStore($storeId);
        if (!$store || empty($store['mint_url'])) {
            return;
        }

        $storage = new WalletStorage(
            Database::getDbPath(),
            $store['mint_url'],
            $store['mint_unit'] ?? 'sat',
            $store['wallet_account_id']
        );
        $storage->updateProofsState($secrets, ProofState::SPENT);
    }

    /**
     * Value sitting in tokens this store handed out that nobody has redeemed yet.
     *
     * Shown next to the balance so the money is visibly accounted for. Without it, an
     * operator upgrading from a version that counted exported tokens as spendable simply
     * sees their balance drop and assumes funds were lost.
     */
    public static function getExportedBalance(string $storeId): int {
        $store = Config::getStore($storeId);
        if (!$store || empty($store['mint_url'])) {
            return 0;
        }

        $total = 0;
        foreach (Config::getStoreWalletAccounts($storeId) as $account) {
            $storage = new WalletStorage(
                Database::getDbPath(),
                $account['mint_url'],
                $account['unit'],
                $store['wallet_account_id']
            );
            $total += \Cashu\Wallet::sumProofs($storage->getProofsAsObjects(ProofState::EXPORTED));
        }
        return $total;
    }

    /**
     * Mark proofs as handed to a third party (exported token, donation).
     *
     * Distinct from PENDING on purpose: the mint reporting an exported proof UNSPENT
     * only means the recipient has not redeemed it yet, so recovery must never take it
     * back. Reclaiming an export is an explicit operator action that swaps the proofs.
     */
    public static function markProofsExported(string $storeId, array $secrets): void {
        if (empty($secrets)) {
            return;
        }

        $store = Config::getStore($storeId);
        if (!$store || empty($store['mint_url'])) {
            return;
        }

        $storage = new WalletStorage(
            Database::getDbPath(),
            $store['mint_url'],
            $store['mint_unit'] ?? 'sat',
            $store['wallet_account_id']
        );
        $storage->updateProofsState($secrets, ProofState::EXPORTED);
    }

    /**
     * Mark proofs as pending for a store (updates local storage)
     *
     * Marks proofs as PENDING in local storage. Used when proofs are sent
     * but not yet confirmed spent (e.g., token export, melt in progress).
     */
    public static function markProofsPending(string $storeId, array $secrets): void {
        if (empty($secrets)) {
            return;
        }

        $store = Config::getStore($storeId);
        if (!$store || empty($store['mint_url'])) {
            return;
        }

        $storage = new WalletStorage(
            Database::getDbPath(),
            $store['mint_url'],
            $store['mint_unit'] ?? 'sat',
            $store['wallet_account_id']
        );
        $storage->updateProofsState($secrets, ProofState::PENDING);
    }

    // =========================================================================
    // STRANDED-FUND RECOVERY (previous mints / units)
    // =========================================================================
    //
    // A store's wallet namespace is hash(mint_url, unit, wallet_account_id).
    // Changing any of those points the store at a fresh namespace, leaving the
    // old mint's proofs stranded — present in the DB but invisible to the UI.
    // These helpers find and export those funds without contacting any mint.

    /**
     * Export all UNSPENT proofs of a specific wallet namespace as a Cashu token.
     *
     * Read-only: it does NOT change local proof state, so a lost token can be
     * re-exported safely. No mint contact — pure local serialization. Call
     * markNamespaceRecovered() after the token is confirmed claimed.
     *
     * @return array{token: ?string, amount: int, count: int, wallet_id: string}
     */
    public static function exportNamespaceAsToken(string $mintUrl, string $unit, ?string $account): array {
        $wallet = new Wallet(rtrim($mintUrl, '/'), strtolower($unit), Database::getDbPath(), $account);
        $storage = $wallet->getStorage();
        $unspent = $storage->getProofsAsObjects(ProofState::UNSPENT);
        $wid = $storage->getWalletId();
        if (empty($unspent)) {
            return ['token' => null, 'amount' => 0, 'count' => 0, 'wallet_id' => $wid];
        }
        return [
            'token' => $wallet->serializeToken($unspent),
            'amount' => Wallet::sumProofs($unspent),
            'count' => count($unspent),
            'wallet_id' => $wid,
        ];
    }

    /**
     * Mark a namespace's UNSPENT proofs SPENT after its token has been claimed.
     * @return int number of proofs marked
     */
    public static function markNamespaceRecovered(string $mintUrl, string $unit, ?string $account): int {
        $storage = new WalletStorage(Database::getDbPath(), rtrim($mintUrl, '/'), strtolower($unit), $account);
        $unspent = $storage->getProofsAsObjects(ProofState::UNSPENT);
        if (empty($unspent)) {
            return 0;
        }
        $storage->updateProofsState(array_map(fn($p) => $p->secret, $unspent), ProofState::SPENT);
        return count($unspent);
    }

    /**
     * Find wallet namespaces that still hold UNSPENT proofs but are not
     * reachable by any store's current wallet — funds stranded by a past
     * mint/unit change. Best-effort identifies each namespace's mint by
     * matching known + historical mint URLs against the wallet_id derivation.
     *
     * Pure local/DB — no mint contact. Single-operator: reports all stranded
     * namespaces regardless of store (see [[no-multitenancy]]).
     *
     * @return array<int, array{wallet_id:string, mint_url:?string, unit:?string,
     *   account:?string, account_mode:string, amount:int, count:int, keysets:array}>
     */
    public static function scanStrandedNamespaces(): array {
        $rows = Database::fetchAll(
            "SELECT wallet_id, COUNT(*) c, COALESCE(SUM(amount),0) s
             FROM cashu_proofs WHERE state = ? GROUP BY wallet_id",
            [ProofState::UNSPENT]
        );
        if (empty($rows)) {
            return [];
        }

        // Namespaces reachable by a live store wallet (primary + backups), and
        // the pool of candidate mint URLs + accounts for identifying the rest.
        $live = [];
        $candidateMints = [];
        $accounts = [];
        foreach (Database::fetchAll("SELECT id, wallet_account_id FROM stores") as $st) {
            $acct = $st['wallet_account_id'] ?: null;
            if ($acct !== null) {
                $accounts[$acct] = true;
            }
            foreach (Config::getStoreWalletAccounts($st['id']) as $acc) {
                $candidateMints[rtrim($acc['mint_url'], '/')] = true;
                $live[WalletStorage::deriveWalletId($acc['mint_url'], $acc['unit'], $acct)] = true;
            }
        }
        // Historical mint URLs (invoices retain mint_url until cleanup).
        foreach (Database::fetchAll("SELECT DISTINCT mint_url FROM invoices WHERE mint_url IS NOT NULL AND mint_url <> ''") as $r) {
            $candidateMints[rtrim($r['mint_url'], '/')] = true;
        }

        $units = ['sat', 'usd', 'eur', 'usdt', 'msat'];
        $accountsToTry = array_merge([null], array_keys($accounts));
        $out = [];
        foreach ($rows as $r) {
            $wid = $r['wallet_id'];
            if (isset($live[$wid])) {
                continue; // reachable by a current store wallet — not stranded
            }
            $found = null;
            foreach (array_keys($candidateMints) as $mu) {
                foreach ($units as $unit) {
                    foreach ($accountsToTry as $a) {
                        if (WalletStorage::deriveWalletId($mu, $unit, $a) === $wid) {
                            $found = ['mint_url' => $mu, 'unit' => $unit, 'account' => $a];
                            break 3;
                        }
                    }
                }
            }
            $keysets = array_column(
                Database::fetchAll("SELECT DISTINCT keyset_id FROM cashu_proofs WHERE wallet_id = ?", [$wid]),
                'keyset_id'
            );
            $out[] = [
                'wallet_id' => $wid,
                'mint_url' => $found['mint_url'] ?? null,
                'unit' => $found['unit'] ?? null,
                'account' => $found['account'] ?? null,
                'account_mode' => $found === null ? 'unknown' : ($found['account'] === null ? 'legacy' : 'account'),
                'amount' => (int)$r['s'],
                'count' => (int)$r['c'],
                'keysets' => $keysets,
            ];
        }
        return $out;
    }

    /**
     * Resolve the (account) that, together with $mintUrl/$unit, yields a
     * namespace holding unspent proofs — used when the operator supplies a mint
     * URL manually for an unidentified stranded namespace.
     *
     * @return array{found: bool, account: ?string, wallet_id: ?string, amount: int}
     */
    public static function resolveNamespaceForMint(string $mintUrl, string $unit): array {
        $mintUrl = rtrim($mintUrl, '/');
        $unit = strtolower($unit);
        $accounts = [null];
        foreach (Database::fetchAll("SELECT DISTINCT wallet_account_id FROM stores WHERE wallet_account_id IS NOT NULL") as $r) {
            $accounts[] = $r['wallet_account_id'];
        }
        foreach ($accounts as $a) {
            $wid = WalletStorage::deriveWalletId($mintUrl, $unit, $a);
            $row = Database::fetchOne(
                "SELECT COUNT(*) c, COALESCE(SUM(amount),0) s FROM cashu_proofs WHERE wallet_id = ? AND state = ?",
                [$wid, ProofState::UNSPENT]
            );
            if ($row && (int)$row['c'] > 0) {
                return ['found' => true, 'account' => $a, 'wallet_id' => $wid, 'amount' => (int)$row['s']];
            }
        }
        return ['found' => false, 'account' => null, 'wallet_id' => null, 'amount' => 0];
    }

    /**
     * Store proofs as unspent for a store
     */
    public static function storeProofs(string $storeId, array $proofs): void {
        if (empty($proofs)) {
            return;
        }

        $wallet = self::getWalletForStore($storeId);
        $wallet->getStorage()->storeProofs($proofs);
    }

    /**
     * Check pending proofs at the mint and update their state
     */
    public static function checkPendingProofs(string $storeId): array {
        try {
            $wallet = self::getWalletForStore($storeId);

            $rows = [];
            if ($wallet->hasStorage()) {
                $rows = $wallet->getStorage()->getProofs(ProofState::PENDING);
            }

            if (empty($rows)) {
                return ['checked' => 0, 'spent' => 0, 'recovered' => 0];
            }

            // Melt/swap recovery exclusively owns these inputs. A mint may briefly report
            // them UNSPENT after a timeout even though the original request is queued.
            $reserved = array_flip($wallet->getStorage()->getReservedInputSecrets());
            $rows = array_values(array_filter(
                $rows,
                fn($row) => !isset($reserved[$row['secret']])
            ));
            if (empty($rows)) {
                return ['checked' => 0, 'spent' => 0, 'recovered' => 0];
            }

            // Build Y values for batch check
            $Ys = [];
            $proofMap = [];
            foreach ($rows as $row) {
                $secret = $row['secret'];
                $Y = \Cashu\Crypto::hashToCurve($secret);
                $YHex = bin2hex(\Cashu\Secp256k1::compressPoint($Y));
                $Ys[] = $YHex;
                $proofMap[$YHex] = $secret;
            }

            // Check with mint
            $store = Config::getStore($storeId);
            $client = new \Cashu\MintClient($store['mint_url']);
            $response = $client->post('checkstate', ['Ys' => $Ys]);

            $states = $response['states'] ?? null;
            if (!is_array($states) || count($states) !== count($Ys)) {
                throw new \Exception('Mint returned an incomplete proof state response');
            }

            // Only the SPENT transition is applied. Returning a proof to the spendable
            // pool because the mint says UNSPENT is exactly how the same bearer proofs
            // used to be handed out twice: "not redeemed yet" is not "still ours".
            $spentSecrets = [];
            $stillPending = 0;
            foreach ($states as $i => $state) {
                $YHex = $Ys[$i];
                if (isset($state['Y']) && !hash_equals($YHex, strtolower((string)$state['Y']))) {
                    throw new \Exception('Mint returned proof states in an unexpected order');
                }
                if (!isset($proofMap[$YHex])) continue;

                if (strtoupper($state['state'] ?? '') === ProofState::SPENT) {
                    $spentSecrets[] = $proofMap[$YHex];
                } else {
                    $stillPending++;
                }
            }

            if (!empty($spentSecrets)) {
                $wallet->getStorage()->updateProofsState($spentSecrets, ProofState::SPENT);
            }

            return [
                'checked' => count($rows),
                'spent' => count($spentSecrets),
                'pending' => $stillPending,
                'recovered' => 0,
            ];
        } catch (\Throwable $e) {
            error_log("CashuPayServer: Error checking pending proofs: " . $e->getMessage());
            return ['checked' => 0, 'spent' => 0, 'pending' => 0, 'recovered' => 0, 'error' => $e->getMessage()];
        }
    }

    /** Recover ambiguous outgoing operations for primary and disabled backup mints. */
    public static function recoverPendingWalletOperations(): array {
        $result = ['accounts' => 0, 'melts' => 0, 'swaps' => 0, 'mints' => 0, 'recovered_amount' => 0, 'errors' => []];
        $stores = Database::fetchAll(
            "SELECT id FROM stores WHERE mint_url IS NOT NULL AND seed_phrase IS NOT NULL AND wallet_account_id IS NOT NULL"
        );
        foreach ($stores as $store) {
            foreach (Config::getStoreWalletAccounts($store['id']) as $account) {
                $label = $store['id'] . '|' . $account['mint_url'] . '|' . $account['unit'];
                try {
                    $wallet = self::getWalletForStore($store['id'], $account['mint_url'], $account['unit']);
                    $melts = $wallet->recoverPendingMelts();
                    $swaps = $wallet->recoverPendingSwaps();
                    // A mint whose response was lost leaves proofs the mint already
                    // signed and we never received; nothing used to look for them.
                    $mints = $wallet->recoverPendingMints();
                    $result['accounts']++;
                    $result['melts'] += (int)($melts['paid'] ?? 0) + (int)($melts['restored'] ?? 0);
                    $result['swaps'] += (int)($swaps['recovered'] ?? 0) + (int)($swaps['released'] ?? 0);
                    $result['mints'] += (int)($mints['recovered'] ?? 0) + (int)($mints['retired'] ?? 0);
                    $result['recovered_amount'] += (int)($mints['amount'] ?? 0);
                    if ((int)($mints['recovered'] ?? 0) > 0) {
                        error_log(
                            "CashuPayServer: recovered {$mints['recovered']} unfinished mint(s) "
                            . "worth {$mints['amount']} for store {$store['id']}"
                        );
                    }
                    if (!empty($melts['errors']) || !empty($swaps['errors']) || !empty($mints['errors'])) {
                        $result['errors'][$label] = array_merge(
                            $melts['errors'] ?? [],
                            $swaps['errors'] ?? [],
                            $mints['errors'] ?? []
                        );
                    }
                } catch (Throwable $e) {
                    $result['errors'][$label] = $e->getMessage();
                }
            }
        }
        return $result;
    }

    /**
     * Check if an exception indicates the mint is unreachable
     *
     * This includes connection errors, timeouts, and other network issues.
     * Used to determine when to fall back to offline token export.
     */
    public static function isMintUnreachable(\Exception $e): bool {
        $message = strtolower($e->getMessage());

        // cURL connection/network errors
        $networkErrors = [
            'http request failed',
            'could not resolve',
            'connection refused',
            'connection timed out',
            'operation timed out',
            'failed to connect',
            'network is unreachable',
            'no route to host',
            'ssl connect error',
            'couldn\'t connect to server',
            'recv failure',
            'send failure',
            'tls handshake',
        ];

        foreach ($networkErrors as $pattern) {
            if (strpos($message, $pattern) !== false) {
                return true;
            }
        }

        // Check for specific HTTP errors that indicate server issues
        // 5xx errors, 0 (no response), certain 4xx that indicate server problems
        if ($e instanceof \Cashu\CashuException) {
            // CashuException with "HTTP request failed" means network error
            if (strpos($message, 'http request failed') !== false) {
                return true;
            }
        }

        return false;
    }
}
