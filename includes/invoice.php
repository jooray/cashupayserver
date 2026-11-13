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
        // Claim a fresh (New) or recovered (Expired-but-paid) invoice. An Invalid
        // (merchant-cancelled) one is claimable too: cancelling the order does not
        // revoke the Lightning invoice, and a customer who pays it anyway has paid the
        // mint. Only the mint saying PAID/ISSUED leads here, so this collects money that
        // already arrived; it never re-opens an unpaid invoice.
        $claimed = Database::update(
            'invoices',
            ['status' => 'Processing', 'processing_since' => $now],
            "id = ? AND status IN ('New', 'Expired', 'Invalid')",
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

        // '_priced' (trusted callers only, never built from request input: the API
        // passes an explicit option list): the amount in mint units the caller already
        // priced and showed, so a rate tick between its check and this call cannot
        // change what the invoice asks for. Ignored if the store's unit moved since.
        $priced = $options['_priced'] ?? null;
        if (is_array($priced) && is_int($priced['amount_in_mint_unit'] ?? null) && $priced['amount_in_mint_unit'] > 0
            && strtolower((string)($priced['mint_unit'] ?? '')) === strtolower((string)$mintUnit)) {
            $amountInMintUnit = $priced['amount_in_mint_unit'];
        } else {
            // Convert amount to mint unit using bidirectional conversion
            $amountInMintUnit = ExchangeRates::convertToMintUnit(
                $amount,
                $currency,
                $mintUnit,
                $exchangeFee,
                $primaryProvider,
                $secondaryProvider
            );
        }

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

        $recovering = 0;
        foreach ($allMints as $tryMintUrl) {
            try {
                $wallet = self::getWalletForStore($storeId, $tryMintUrl);
                if ($wallet->requiresRecovery()) {
                    // Still scanning this seed's history at that mint (after a mint
                    // change or an imported seed). A quote there could be paid but not
                    // collected until the scan finishes, so use another mint if one is
                    // ready.
                    $recovering++;
                    continue;
                }
                $quote = $wallet->requestMintQuote($amountInMintUnit);
                $usedMintUrl = $tryMintUrl;
                break; // Success!
            } catch (Throwable $e) {
                // Throwable, not Exception: a malformed mint reply must fail over to the
                // next mint, not take the checkout down with a TypeError.
                $lastError = $e;
                error_log("Mint quote failed for $tryMintUrl: " . $e->getMessage());
                continue; // Try next mint
            }
        }

        if ($quote === null && $recovering > 0 && $lastError === null) {
            throw new Exception(
                'Payments are being set up with this store\'s mint and will work in a few '
                . 'minutes. Please try again shortly.'
            );
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
                // The quote is denominated in this unit; collecting it with any other
                // (after the store's unit changes) can never succeed.
                'mint_unit' => $mintUnit,
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
     * How long past its expiry a `New` invoice may stay open while the mint has not
     * confirmed that it is unpaid. Expiring on the local clock alone gave a payment made
     * just before expiry an InvoiceExpired followed by InvoiceSettled; past this, an
     * unreachable mint no longer keeps the order open (late payments are still
     * collected by recoverExpiredPaidInvoices()).
     */
    private const EXPIRY_CONFIRM_WINDOW = 3600;

    /**
     * How long after a mint quote expired before a definitely-unpaid answer lets us drop
     * the quote id. A mint's watcher can lag; dropping the only link to a quote that was
     * actually paid makes the money uncollectable (MINIMAX 4.25).
     */
    private const UNPAID_WRITE_OFF_DELAY = 7 * 86400;

    /**
     * Expire `New` invoices whose expiry the mint has confirmed, or that are long past it.
     *
     * An invoice is expired here only when a poll that started after its expiry saw the
     * quote unpaid (normally the poller expires it itself), or when it is more than
     * EXPIRY_CONFIRM_WINDOW past expiry. Fresh expiries are left to the pollers, which
     * ask the mint first.
     *
     * @return int Number of invoices marked as expired
     */
    public static function markExpiredInvoices(): int {
        $now = time();
        return self::expireNewInvoices(
            "expiration_time < ? AND (expiration_time < ?
                OR (last_polled_at >= expiration_time AND last_poll_error IS NULL
                    AND UPPER(COALESCE(last_poll_state, '')) = 'UNPAID'))",
            [$now, $now - self::EXPIRY_CONFIRM_WINDOW]
        );
    }

    /**
     * Expire `New` invoices created before the given time, whatever their expiry says.
     * Same path as markExpiredInvoices(), so each one emits InvoiceExpired.
     */
    public static function expireInvoicesCreatedBefore(int $createdBefore): int {
        return self::expireNewInvoices('created_at < ?', [$createdBefore]);
    }

    /**
     * Move the `New` invoices matching $where to Expired, firing InvoiceExpired for each.
     *
     * @param string $where SQL condition on invoices (ANDed with status = 'New')
     */
    /** How many expiries one write transaction handles. */
    private const EXPIRY_BATCH_SIZE = 100;

    private static function expireNewInvoices(string $where, array $params): int {
        // Capture which invoices are transitioning so we can fire InvoiceExpired for each
        // (the bulk UPDATE alone emitted no webhook, so shops never learned of expiry).
        // See FABLE-CASHUPAYSERVER-AUDIT (C-WH-1). It reads before it writes, so it
        // must hold the write lock from the start (see beginWriteTransaction).
        //
        // Bounded batches: an unbounded SELECT of a large backlog exhausted memory, and
        // the fatal took every later background task with it (MINIMAX 4.8). Each batch
        // is its own transaction, so the SQLite writer lock is held for a short time.
        $count = 0;
        while (true) {
            Database::beginWriteTransaction();
            try {
                // Select and update inside the transaction, and update exactly the ids
                // selected: reading first and then updating a broader predicate expired
                // invoices that were never in the list (and skipped events for them).
                $expiring = Database::fetchAll(
                    "SELECT id, store_id FROM invoices WHERE status = 'New' AND ({$where}) ORDER BY id ASC LIMIT ?",
                    array_merge($params, [self::EXPIRY_BATCH_SIZE])
                );

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
            if (count($expiring) < self::EXPIRY_BATCH_SIZE) {
                break;
            }
        }

        return $count;
    }

    /**
     * Poll pending quotes and process payments with rate limiting and backoff
     *
     * @param int $minInterval Minimum seconds between polls for the same invoice (default 30)
     * @param int $batchLimit Maximum invoices to poll per call (default 10)
     * @return array{polled: int, errors: int} errors counts only invoices still open
     *         afterwards, so the background health sees a mint outage (it used to report
     *         "success" while every poll failed)
     */
    public static function pollPendingQuotes(int $minInterval = 30, int $batchLimit = 10): array {
        // First, expire the invoices the mint has already confirmed unpaid after their
        // expiry, and those long past it (see markExpiredInvoices()).
        self::markExpiredInvoices();

        $now = time();

        // Fetch invoices that need polling with backoff strategy:
        // - Not expired
        // - Not recently polled (respects minInterval)
        // - Processing first (paid, waiting to be credited), then the longest-waiting:
        //   a never-polled invoice counts as waiting since it was created, so a burst of
        //   new checkouts cannot keep pushing older ones out of the batch
        // - Limited batch size to avoid hammering mint
        // Poll New invoices that aren't expired, PLUS any invoice stuck in Processing
        // (its mint() may have failed transiently and needs to be re-driven). Without the
        // Processing arm, a paid-but-unminted invoice could sit forever waiting for a page
        // load. See FABLE-CASHUPAYSERVER-AUDIT (C5). A New invoice that has just
        // expired is polled once more, and expired only if the mint says it is unpaid.
        $pendingInvoices = Database::fetchAll(
            "SELECT * FROM invoices
             WHERE status IN ('New', 'Processing')
             AND quote_id IS NOT NULL
             AND (status = 'Processing' OR expiration_time > ?)
             AND (last_polled_at IS NULL OR last_polled_at <= ?)
             ORDER BY
                 CASE WHEN status = 'Processing' THEN 0 ELSE 1 END,
                 COALESCE(last_polled_at, created_at) ASC
             LIMIT ?",
            [$now - self::EXPIRY_CONFIRM_WINDOW, $now - $minInterval, $batchLimit]
        );

        $outcome = ['polled' => 0, 'errors' => 0];
        if (empty($pendingInvoices)) {
            return $outcome;
        }

        foreach ($pendingInvoices as $invoice) {
            try {
                // Update last_polled_at before polling (so we don't re-poll on failure)
                Database::update('invoices', ['last_polled_at' => $now], 'id = ?', [$invoice['id']]);

                // Get wallet for the exact mint that issued this invoice's quote
                $wallet = self::getWalletForStore($invoice['store_id'], $invoice['mint_url'] ?? null, $invoice['mint_unit'] ?? null);

                // Check quote status
                $quoteStatus = $wallet->checkMintQuote($invoice['quote_id']);
                self::recordPollOutcome($invoice['id'], $quoteStatus->state, null);

                if ($quoteStatus->isPaid() || $quoteStatus->isIssued()) {
                    if ($quoteStatus->isIssued()) {
                        self::completeIssuedInvoice($invoice, $wallet);
                    } else {
                        self::mintAndStoreTokens($invoice, $wallet);
                    }
                } else {
                    self::expireIfConfirmedUnpaid($invoice, $quoteStatus);
                }
            } catch (Throwable $e) {
                // Visible to the operator, not just to a log they cannot read.
                self::recordPollOutcome($invoice['id'], null, $e->getMessage());
                error_log("CashuPayServer: Error polling invoice {$invoice['id']}: " . $e->getMessage());
                // A race lost to another worker that settled it is not a failure.
                $current = Database::fetchOne('SELECT status FROM invoices WHERE id = ?', [$invoice['id']]);
                if ($current !== null && in_array($current['status'], ['New', 'Processing'], true)) {
                    $outcome['errors']++;
                }
            }
            $outcome['polled']++;
        }
        return $outcome;
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
        // The unit the invoice was quoted in, not the store's current one.
        $mintUnit = $invoice['mint_unit'] ?? null ?: Config::getStoreMintUnit($invoice['store_id']);

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
     * Advance recovery of a store's account at a mint, for at most $seconds.
     *
     * An account bound in restore mode (an imported seed, a mint change, a backup mint)
     * cannot mint until the library has scanned the seed's history at that mint:
     * minting from counter zero on a seed that was used there before would reuse
     * secrets the mint already signed. The library records that provenance and only
     * readies the account when its scan completes; nothing here may shortcut it.
     *
     * The scan is resumable, so this does bounded steps and returns. Without GMP a full
     * scan takes minutes, far longer than one request may run; the background runner
     * keeps calling this until it completes.
     *
     * @return array{ready: bool, status: string, recovered: array, error: ?string}
     */
    public static function advanceStoreRecovery(
        string $storeId,
        float $seconds = 8.0,
        ?string $mintUrl = null,
        ?string $mintUnit = null
    ): array {
        $total = ['outputs' => 0, 'proofs' => 0, 'unspent' => 0, 'spent' => 0, 'amount' => 0];
        $wallet = self::initializeWalletForStore($storeId, true, $mintUrl, $mintUnit);
        if (!$wallet->requiresRecovery()) {
            return ['ready' => true, 'status' => 'complete', 'recovered' => $total, 'error' => null];
        }

        // Step sizes from the library's measured per-output cost (REFERENCE.md): a few
        // milliseconds with GMP, around a hundred without.
        $step = extension_loaded('gmp') ? 500 : 20;
        $deadline = microtime(true) + $seconds;
        $result = ['status' => 'pending', 'error' => null];
        do {
            $result = $wallet->restoreStep($step, $deadline);
            foreach ($total as $key => $_) {
                $total[$key] += (int)($result['recovered'][$key] ?? 0);
            }
        } while ($result['status'] === 'pending' && microtime(true) < $deadline);

        $store = Config::getStore($storeId);
        $cacheKey = $storeId . '|' . ($mintUrl ?? $store['mint_url']) . '|' . ($mintUnit ?? ($store['mint_unit'] ?? 'sat'));
        unset(self::$walletCache[$cacheKey]);
        $ready = $result['status'] === 'complete' && !empty($result['ready']);

        return [
            'ready' => $ready,
            'status' => (string)$result['status'],
            'recovered' => $total,
            'error' => $result['status'] === 'error' ? (string)($result['error'] ?? 'recovery failed') : null,
        ];
    }

    /** advanceStoreRecovery() for the store's primary mint, as a yes/no. */
    public static function readyStoreWallet(string $storeId, float $seconds = 8.0): bool {
        return self::advanceStoreRecovery($storeId, $seconds)['ready'];
    }

    /** The account at this mint can mint and spend right now. */
    public static function isAccountReady(string $storeId, string $mintUrl, string $mintUnit): bool {
        try {
            return !self::getWalletForStore($storeId, $mintUrl, $mintUnit)->requiresRecovery();
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Keep recovering every store account that is not ready yet — primary and enabled
     * backup mints — a bounded step at a time. Runs before quote polling, so payments
     * that arrived while an account was recovering are credited as soon as it is ready.
     * An account whose steps keep failing backs off exponentially (a minute, doubling
     * to an hour), so an unreachable or rate-limiting mint is not hammered.
     */
    public static function ensurePrimaryWalletsReady(float $budgetSeconds = 15.0): string {
        $accounts = [];
        foreach (Database::fetchAll(
            "SELECT id, mint_url, mint_unit, wallet_account_id FROM stores
             WHERE mint_url IS NOT NULL AND seed_phrase IS NOT NULL AND wallet_account_id IS NOT NULL"
        ) as $store) {
            $accounts[] = [$store['id'], $store['mint_url'], $store['mint_unit'] ?? 'sat', $store['wallet_account_id'], true];
            foreach (Database::fetchAll(
                'SELECT mint_url, unit FROM store_mints WHERE store_id = ? AND enabled = 1',
                [$store['id']]
            ) as $backup) {
                $accounts[] = [$store['id'], $backup['mint_url'], $backup['unit'] ?? 'sat', $store['wallet_account_id'], false];
            }
        }

        $deadline = microtime(true) + $budgetSeconds;
        $readied = 0;
        $pending = 0;
        // Primary accounts whose recovery is failing, including those waiting out their
        // backoff: reported as errors so the payment health sees a store that cannot
        // collect. A failing backup does not stop payments to the primary.
        $failing = 0;
        foreach ($accounts as [$storeId, $mintUrl, $unit, $accountId, $isPrimary]) {
            $walletId = WalletStorage::deriveWalletId($mintUrl, $unit, $accountId);
            try {
                $row = Database::fetchOne('SELECT ready FROM cashu_wallet_metadata WHERE wallet_id = ?', [$walletId]);
            } catch (Throwable $e) {
                return 'none'; // no wallet table yet: nothing has ever been opened
            }
            if ($row && (int)$row['ready'] === 1) {
                continue;
            }
            $errorKey = 'wallet_recovery_error_' . $walletId;
            $failure = Config::get($errorKey);
            $failure = is_array($failure) ? $failure : ['at' => (int)$failure, 'n' => $failure ? 1 : 0];
            $backoff = $failure['n'] > 0 ? min(3600, 60 * 2 ** min(6, $failure['n'] - 1)) : 0;
            if (time() - (int)$failure['at'] < $backoff || microtime(true) >= $deadline) {
                $pending++;
                if ($failure['n'] > 0 && $isPrimary) {
                    $failing++;
                }
                continue;
            }
            try {
                $step = self::advanceStoreRecovery($storeId, max(1.0, $deadline - microtime(true)), $mintUrl, $unit);
                if ($step['ready']) {
                    $readied++;
                    Config::delete($errorKey);
                    error_log("CashuPayServer: store {$storeId} account at {$mintUrl} ({$unit}) is ready");
                    continue;
                }
                $pending++;
                if ($step['error'] !== null) {
                    $failing += $isPrimary ? 1 : 0;
                    Config::set($errorKey, ['at' => time(), 'n' => $failure['n'] + 1]);
                    error_log("CashuPayServer: recovery for store {$storeId} at {$mintUrl} failed: {$step['error']}");
                }
            } catch (Throwable $e) {
                $pending++;
                $failing += $isPrimary ? 1 : 0;
                Config::set($errorKey, ['at' => time(), 'n' => $failure['n'] + 1]);
                error_log("CashuPayServer: recovery for store {$storeId} at {$mintUrl} failed: " . $e->getMessage());
            }
        }
        if ($readied === 0 && $pending === 0) {
            return 'none';
        }
        // "N errors" is what BackgroundRunner::failureOf() reads as a failure.
        return "readied {$readied}, still recovering {$pending}" . ($failing > 0 ? ", {$failing} errors" : '');
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
     * Shortest interval between two mint round-trips for the same invoice, while payment
     * is expected imminently.
     *
     * The checkout page polls every couple of seconds from every open tab, and each poll
     * used to hit the mint directly. A known checkout link was therefore enough to
     * occupy a small PHP-FPM pool with slow network calls. Coalescing per *invoice* caps
     * that no matter how many tabs (or strangers) are watching, while keeping the page
     * itself responsible for settlement — it does not wait for cron.
     */
    const MIN_POLL_INTERVAL = 2;

    /** Interval once payment is no longer imminent — a tab someone left open. */
    const IDLE_POLL_INTERVAL = 15;

    /** How long an invoice counts as "payment expected imminently". */
    const EAGER_POLL_WINDOW = 900;

    /**
     * How often this invoice's quote may be re-checked with the mint.
     *
     * Customers pay in the first minutes, which is exactly when someone is watching the
     * checkout page and feels every second of delay. Later, an abandoned tab should not
     * keep asking at the same rate.
     */
    private static function pollIntervalFor(array $invoice): int {
        $age = time() - (int)($invoice['created_at'] ?? 0);
        return $age <= self::EAGER_POLL_WINDOW ? self::MIN_POLL_INTERVAL : self::IDLE_POLL_INTERVAL;
    }

    public static function pollSingleQuote(string $invoiceId, bool $force = false): void {
        $invoice = self::getById($invoiceId);
        if (!$invoice || !$invoice['quote_id']) {
            return;
        }

        // Only process New or Processing invoices
        if (!in_array($invoice['status'], ['New', 'Processing'])) {
            return;
        }

        // An expired New invoice is not expired on the local clock alone: the mint is
        // asked once below, and the invoice expires only when it says "unpaid". A
        // payment made just before expiry used to get InvoiceExpired and then
        // InvoiceSettled. Past the confirm window markExpiredInvoices() takes over.
        if ($invoice['status'] === 'New'
            && (int)$invoice['expiration_time'] < time() - self::EXPIRY_CONFIRM_WINDOW) {
            self::updateStatus($invoice['id'], 'Expired', null, ['New']);
            return;
        }

        // Coalesce concurrent pollers for this invoice. The claim is atomic, so of the
        // several workers that may be looking at one invoice — every open checkout tab
        // plus the cron batch — exactly one contacts the mint and the rest return
        // immediately with what is already known. Cron reads the same column, so
        // whichever gets there first does the work and the other simply finds nothing
        // due; they cannot both drive the same quote.
        $now = time();
        if (!$force) {
            $claimed = Database::query(
                "UPDATE invoices SET last_polled_at = ?
                 WHERE id = ? AND (last_polled_at IS NULL OR last_polled_at <= ?)",
                [$now, $invoiceId, $now - self::pollIntervalFor($invoice)]
            )->rowCount();
            if ($claimed === 0) {
                return; // another request checked this quote moments ago
            }
        }

        try {
            $wallet = self::getWalletForStore($invoice['store_id'], $invoice['mint_url'] ?? null, $invoice['mint_unit'] ?? null);
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
            } else {
                self::expireIfConfirmedUnpaid($invoice, $quoteStatus);
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
            if ($error !== null) {
                // Losing a race is not a failure. While one worker was talking to the
                // mint another may have minted and settled the same invoice, so this
                // one's "quote already issued" is the system working, not breaking.
                // Recording it would put a scare on the operator's dashboard.
                $current = Database::fetchOne('SELECT status FROM invoices WHERE id = ?', [$invoiceId]);
                if ($current !== null && !in_array($current['status'], ['New', 'Processing'], true)) {
                    return;
                }
            }

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

    /** Expire a New invoice past its expiry once the mint has said its quote is unpaid. */
    private static function expireIfConfirmedUnpaid(array $invoice, \Cashu\MintQuote $quote): void {
        if ($invoice['status'] === 'New' && (int)$invoice['expiration_time'] < time()
            && self::quoteIsDefinitelyUnpaid($quote)) {
            self::updateStatus($invoice['id'], 'Expired', null, ['New']);
        }
    }

    // =========================================================================
    // ISSUED QUOTE HANDLING
    // =========================================================================

    /**
     * Whether the mint has said, in so many words, that nothing was paid on this quote.
     * The NUT-04 accounting fields win when present; otherwise only an explicit UNPAID.
     */
    private static function quoteIsDefinitelyUnpaid(\Cashu\MintQuote $quote): bool {
        if ($quote->amountPaid !== null && $quote->amountIssued !== null) {
            return $quote->amountPaid === 0 && $quote->amountIssued === 0;
        }
        return strtoupper($quote->state) === 'UNPAID';
    }

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
        } catch (Throwable $e) {
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
    /** How often one Processing invoice is re-asked about, and how many per pass. */
    private const ORPHAN_POLL_INTERVAL = 300;
    private const ORPHAN_BATCH_LIMIT = 50;

    public static function recoverOrphanedInvoices(int $batchLimit = self::ORPHAN_BATCH_LIMIT): array {
        $recovered = [];
        $now = time();

        // Bounded and rate-limited: it used to load and re-poll every Processing row on
        // every pass, which grows without limit and can exhaust memory while holding the
        // cron lease (MINIMAX 4.10).
        $stuck = Database::fetchAll(
            "SELECT * FROM invoices
             WHERE status = 'Processing' AND quote_id IS NOT NULL AND created_at < ?
               AND (last_polled_at IS NULL OR last_polled_at <= ?)
             ORDER BY created_at ASC
             LIMIT ?",
            [$now - 60, $now - self::ORPHAN_POLL_INTERVAL, $batchLimit]
        );

        foreach ($stuck as $invoice) {
            try {
                Database::update(
                    'invoices',
                    ['last_polled_at' => $now],
                    "id = ? AND status = 'Processing'",
                    [$invoice['id']]
                );
                $wallet = self::getWalletForStore($invoice['store_id'], $invoice['mint_url'] ?? null, $invoice['mint_unit'] ?? null);
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
            } catch (Throwable $e) {
                // A malformed row must not abort the whole batch (TypeError/Error used to
                // escape this guard). The cooldown prevents a hot loop on the same row.
                error_log("CashuPayServer: Error recovering invoice {$invoice['id']}: " . get_class($e) . ': ' . $e->getMessage());
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
        // Each window gets its own batch, with its own due-time in SQL. With one shared
        // LIMIT the frequent window (every abandoned checkout of the last 72 hours, due
        // again after a minute) took every slot on a busy shop, and the daily re-check of
        // older invoices, which exists for payments made during a long outage, never ran
        // (S8, 2026-10-28).
        $base = "SELECT * FROM invoices
                 WHERE status IN ('Expired', 'Invalid')
                 AND quote_id IS NOT NULL";
        $recent = Database::fetchAll(
            "$base AND expiration_time > ?
             AND (last_polled_at IS NULL OR last_polled_at <= ?)
             ORDER BY COALESCE(last_polled_at, expiration_time) ASC
             LIMIT ?",
            [$now - self::EXPIRY_RECOVERY_GRACE, $now - $minInterval, $batchLimit]
        );
        $older = Database::fetchAll(
            "$base AND expiration_time <= ?
             AND (last_polled_at IS NULL OR last_polled_at <= ?)
             ORDER BY COALESCE(last_polled_at, expiration_time) ASC
             LIMIT ?",
            [$now - self::EXPIRY_RECOVERY_GRACE, $now - self::EXPIRY_RECOVERY_SLOW_INTERVAL, $batchLimit]
        );
        $rows = array_merge($recent, $older);

        foreach ($rows as $invoice) {
            try {
                Database::update('invoices', ['last_polled_at' => $now], 'id = ?', [$invoice['id']]);
                if (self::collectClosedInvoice($invoice, $now)) {
                    $recovered[] = $invoice['id'];
                }
            } catch (Throwable $e) {
                error_log("CashuPayServer: Error recovering expired invoice {$invoice['id']}: " . $e->getMessage());
            }
        }

        return $recovered;
    }

    /**
     * Ask the mint whether an Expired or Invalid invoice was paid after all, and collect
     * it if so; write off a quote the mint has long since expired unpaid.
     *
     * @return bool True when the invoice is now Settled
     */
    private static function collectClosedInvoice(array $invoice, int $now): bool {
        $wallet = self::getWalletForStore($invoice['store_id'], $invoice['mint_url'] ?? null, $invoice['mint_unit'] ?? null);
        $quoteStatus = $wallet->checkMintQuote($invoice['quote_id']);

        if ($quoteStatus->isPaid() || $quoteStatus->isIssued()) {
            error_log("CashuPayServer: Late payment on expired invoice {$invoice['id']} - recovering");
            if ($quoteStatus->isIssued()) {
                // No claim here: completeIssuedInvoice either settles proofs we
                // already hold, or calls mintAndStoreTokens, which claims for
                // itself. Claiming first made that second claim fail (cooldown),
                // so the retry announced in the log never happened.
                self::completeIssuedInvoice($invoice, $wallet);
            } else {
                self::mintAndStoreTokens($invoice, $wallet);
            }
            return self::getById($invoice['id'])['status'] === 'Settled';
        } elseif ($quoteStatus->expiry !== null
                  && $quoteStatus->expiry < $now - self::UNPAID_WRITE_OFF_DELAY
                  && self::quoteIsDefinitelyUnpaid($quoteStatus)) {
            // The mint's own quote has long expired and the mint says plainly that
            // nothing was paid: this claim is resolved and the row may be cleaned
            // up by age from now on. The delay gives a lagging mint watcher time to
            // report a payment made just before expiry. Anything less than that
            // plain answer (no state, PENDING, an unknown word) keeps the quote:
            // dropping the only link to a payment is a one-way door.
            Database::update(
                'invoices',
                [
                    'quote_id' => null,
                    'last_poll_state' => 'written_off_unpaid',
                ],
                "id = ? AND status IN ('Expired', 'Invalid') AND quote_id IS NOT NULL",
                [$invoice['id']]
            );
        }
        return false;
    }

    /** What a shop may show its customer when no invoice can be created. */
    public const CHECKOUT_UNAVAILABLE_MESSAGE = 'Bitcoin payments are temporarily unavailable. Please try again '
        . 'in a few minutes, or choose another payment method.';

    /**
     * Remember that a customer could not start a payment, for the operator's dashboard,
     * and return the text to give the shop. Invoice-creation errors used to reach only
     * the shop and the error log, so a mint that was down stopped every checkout while
     * the dashboard stayed green (A11, 2026-10-28).
     */
    public static function recordCheckoutFailure(string $storeId, Throwable $e): string {
        $message = $e->getMessage();
        if ($message === ExchangeRates::UNAVAILABLE_MESSAGE) {
            $reason = 'prices';
            $public = $message;
        } elseif (str_contains($message, 'being set up')) {
            $reason = 'setting_up';
            $public = $message;
        } elseif (str_contains($message, 'mint quote')) {
            $reason = 'mint';
            $public = self::CHECKOUT_UNAVAILABLE_MESSAGE;
        } elseif ($e instanceof InvalidArgumentException) {
            // A bad request from the shop's own integration: its wording is useful as is.
            return $message;
        } else {
            $reason = 'other';
            $public = self::CHECKOUT_UNAVAILABLE_MESSAGE;
        }
        try {
            $key = 'checkout_failures_' . $storeId;
            $previous = Config::get($key);
            $now = time();
            Config::set($key, [
                'first' => is_array($previous) ? (int)($previous['first'] ?? $now) : $now,
                'last' => $now,
                'count' => (is_array($previous) ? (int)($previous['count'] ?? 0) : 0) + 1,
                'reason' => $reason,
                'detail' => mb_substr($message, 0, 300),
            ]);
        } catch (Throwable $ignored) {
            // Recording is best effort; the shop still gets its answer.
        }
        error_log("CashuPayServer: checkout failed for store {$storeId} ({$reason}): {$message}");
        return $public;
    }

    /** A customer could start a payment again: the problem is over. */
    public static function recordCheckoutSuccess(string $storeId): void {
        try {
            if (Config::get('checkout_failures_' . $storeId) !== null) {
                Config::delete('checkout_failures_' . $storeId);
            }
        } catch (Throwable $ignored) {
        }
    }

    /**
     * The checkout page's own check of a recently closed invoice, so a customer who paid
     * at the last second (or paid an order the shop then cancelled) sees "Payment
     * Complete" instead of being told to pay again (C1, C2, 2026-10-28). Throttled per
     * invoice, and only inside the frequent recovery window.
     */
    public static function recheckClosedInvoice(string $invoiceId, int $minInterval = 15): void {
        $now = time();
        $claimed = Database::query(
            "UPDATE invoices SET last_polled_at = ?
             WHERE id = ? AND status IN ('Expired', 'Invalid') AND quote_id IS NOT NULL
             AND expiration_time > ? AND (last_polled_at IS NULL OR last_polled_at <= ?)",
            [$now, $invoiceId, $now - self::EXPIRY_RECOVERY_GRACE, $now - $minInterval]
        )->rowCount();
        if ($claimed !== 1) {
            return;
        }
        $invoice = self::getById($invoiceId);
        if ($invoice === null) {
            return;
        }
        try {
            self::collectClosedInvoice($invoice, $now);
        } catch (Throwable $e) {
            error_log("CashuPayServer: Error re-checking closed invoice {$invoiceId}: " . $e->getMessage());
        }
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
        $total = 0;
        foreach (self::getAccountBalances($storeId) as $account) {
            if ($account['same_unit']) {
                $total += $account['balance'];
            }
        }
        return $total;
    }

    /**
     * Spendable balance per wallet account (primary and every backup mint).
     *
     * A failover payment is minted at whichever mint issued its quote, so money can sit
     * at a backup. Each account is listed with its own unit; only accounts in the
     * primary's unit are added up into the headline balance — a EUR backup is not sats.
     *
     * @return list<array{mint_url: string, unit: string, balance: int, primary: bool, same_unit: bool}>
     */
    public static function getAccountBalances(string $storeId): array {
        $store = Config::getStore($storeId);
        if (!$store || empty($store['mint_url'])) {
            return [];
        }
        $primaryUrl = rtrim((string)$store['mint_url'], '/');
        $primaryUnit = strtolower((string)($store['mint_unit'] ?? 'sat'));

        $result = [];
        foreach (Config::getStoreWalletAccounts($storeId) as $account) {
            $storage = new WalletStorage(
                Database::getDbPath(),
                $account['mint_url'],
                $account['unit'],
                $store['wallet_account_id']
            );
            $unit = strtolower((string)$account['unit']);
            $result[] = [
                'mint_url' => $account['mint_url'],
                'unit' => $unit,
                'balance' => $storage->getBalance(),
                'primary' => rtrim((string)$account['mint_url'], '/') === $primaryUrl && $unit === $primaryUnit,
                'same_unit' => $unit === $primaryUnit,
            ];
        }
        return $result;
    }

    /**
     * The account a withdrawal or export of $amount should be paid from.
     *
     * One payment can only spend ecash from one mint. The primary is used whenever it
     * can cover the amount (or when nothing can); otherwise the same-unit account with
     * the most money that can. Returns null for a store without a wallet.
     *
     * @return array{mint_url: string, unit: string, balance: int, primary: bool, same_unit: bool}|null
     */
    public static function getFundsAccount(string $storeId, int $amount): ?array {
        $accounts = array_values(array_filter(self::getAccountBalances($storeId), fn($a) => $a['same_unit']));
        if (empty($accounts)) {
            return null;
        }
        $primary = null;
        foreach ($accounts as $account) {
            if ($account['primary']) {
                $primary = $account;
            }
        }
        if ($primary !== null && $primary['balance'] >= $amount) {
            return $primary;
        }
        usort($accounts, fn($x, $y) => $y['balance'] <=> $x['balance']);
        if ($accounts[0]['balance'] >= $amount) {
            return $accounts[0];
        }
        return $primary ?? $accounts[0];
    }

    /** The most one withdrawal or export can move: the largest single same-unit account. */
    public static function getLargestSpendableBalance(string $storeId): int {
        $max = 0;
        foreach (self::getAccountBalances($storeId) as $account) {
            if ($account['same_unit']) {
                $max = max($max, $account['balance']);
            }
        }
        return $max;
    }

    /**
     * Get unspent proofs for a store (reads from local storage)
     *
     * Returns Proof objects directly from SQLite storage, for the primary account unless
     * a specific account (mint URL and unit) is named.
     * No mint contact required - ecash proofs are stored locally.
     */
    public static function getUnspentProofs(string $storeId, ?string $mintUrl = null, ?string $unit = null): array {
        $storage = self::accountStorage($storeId, $mintUrl, $unit);
        return $storage ? $storage->getProofsAsObjects(ProofState::UNSPENT) : [];
    }

    /** Local storage of one of a store's accounts (primary by default), or null. */
    private static function accountStorage(string $storeId, ?string $mintUrl = null, ?string $unit = null): ?WalletStorage {
        $store = Config::getStore($storeId);
        if (!$store || empty($store['mint_url'])) {
            return null;
        }
        return new WalletStorage(
            Database::getDbPath(),
            $mintUrl ?? $store['mint_url'],
            $unit ?? ($store['mint_unit'] ?? 'sat'),
            $store['wallet_account_id']
        );
    }

    /** Local storage of the account that holds these proofs (see accountHoldingSecrets). */
    private static function storageHoldingSecrets(string $storeId, array $secrets, ?string $mintUrl = null, ?string $unit = null): ?WalletStorage {
        $account = self::accountHoldingSecrets($storeId, $secrets, $mintUrl, $unit);
        if ($account['mint_url'] === '') {
            return null;
        }
        return self::accountStorage($storeId, $account['mint_url'], $account['unit']);
    }

    /**
     * Mark proofs as spent for a store (updates local storage)
     *
     * Conditional, not a blind overwrite: proofs a melt or swap journal has reserved
     * are left to that journal, which settles them itself — overwriting them made the
     * journal's own finalization fail ("no longer owns a reserved input").
     *
     * $mintUrl/$unit name the exact wallet account (e.g. a transfer row's mint_url and
     * unit), which may be a former or disabled mint; when null, the account holding the
     * secrets is looked up among the store's current ones (CPS-11).
     */
    public static function markProofsSpent(string $storeId, array $secrets, ?string $mintUrl = null, ?string $unit = null): void {
        if (empty($secrets)) {
            return;
        }
        $storage = self::storageHoldingSecrets($storeId, $secrets, $mintUrl, $unit);
        if (!$storage) {
            return;
        }
        $storage->transitionProofStates(
            $secrets,
            ProofState::SPENT,
            [ProofState::UNSPENT, ProofState::PENDING, ProofState::EXPORTED],
            array_flip($storage->getReservedInputSecrets())
        );
    }

    /**
     * Which of a store's mints actually holds these proofs.
     *
     * A token handed out before a mint switch belongs to the mint it was minted on, which
     * may now be a backup rather than the primary. Assuming the primary asked the wrong
     * mint about proofs it has never seen — and a mint answers UNSPENT for secrets it does
     * not know, so the wrong answer looked like a confident one.
     *
     * When $mintUrl is given, that exact account is used: a transfer row records where
     * its proofs came from, and that mint may since have been switched away from or
     * disabled, so it is no longer among the store's accounts and the lookup below would
     * fall back to the primary (CPS-11). $unit defaults to the matching account's, else
     * the store's.
     *
     * @param string[] $secrets
     * @return array{mint_url: string, unit: string}
     */
    private static function accountHoldingSecrets(string $storeId, array $secrets, ?string $mintUrl = null, ?string $unit = null): array
    {
        $store = Config::getStore($storeId);
        if ($mintUrl !== null && trim($mintUrl) !== '') {
            $mintUrl = rtrim(trim($mintUrl), '/');
            if ($unit === null || trim($unit) === '') {
                $unit = strtolower((string)($store['mint_unit'] ?? 'sat'));
                foreach ($store ? Config::getStoreWalletAccounts($storeId) : [] as $account) {
                    if ($account['mint_url'] === $mintUrl) {
                        $unit = $account['unit'];
                        break;
                    }
                }
            }
            return ['mint_url' => $mintUrl, 'unit' => strtolower(trim($unit))];
        }
        $fallback = [
            'mint_url' => rtrim((string)($store['mint_url'] ?? ''), '/'),
            'unit' => strtolower((string)($store['mint_unit'] ?? 'sat')),
        ];
        if (!$store || empty($store['wallet_account_id']) || empty($secrets)) {
            return $fallback;
        }

        foreach (Config::getStoreWalletAccounts($storeId) as $account) {
            try {
                $storage = new WalletStorage(
                    Database::getDbPath(),
                    $account['mint_url'],
                    $account['unit'],
                    $store['wallet_account_id']
                );
                if (count($storage->getProofsBySecretsAsObjects($secrets)) === count($secrets)) {
                    return ['mint_url' => $account['mint_url'], 'unit' => $account['unit']];
                }
            } catch (Throwable $e) {
                // A backup we cannot open is not a reason to fail the lookup.
            }
        }

        return $fallback;
    }

    /**
     * NUT-07 states of a store's proofs, asked of the mint that holds them.
     * $mintUrl/$unit: see accountHoldingSecrets() (null = look it up, as before).
     *
     * @param string[] $secrets
     * @return array<string, string> secret => state, or [] when there is nothing to ask
     */
    public static function checkProofStatesBySecrets(string $storeId, array $secrets, ?string $mintUrl = null, ?string $unit = null): array {
        $secrets = array_values(array_filter($secrets, 'strlen'));
        if (empty($secrets)) {
            return [];
        }

        $store = Config::getStore($storeId);
        if (!$store || empty($store['mint_url'])) {
            return [];
        }

        $Ys = [];
        foreach ($secrets as $secret) {
            $Ys[] = bin2hex(\Cashu\Secp256k1::compressPoint(\Cashu\Crypto::hashToCurve($secret)));
        }

        $account = self::accountHoldingSecrets($storeId, $secrets, $mintUrl, $unit);
        $client = new \Cashu\MintClient($account['mint_url']);
        $response = $client->post('checkstate', ['Ys' => $Ys]);
        $states = $response['states'] ?? null;
        if (!is_array($states) || count($states) !== count($secrets)) {
            throw new Exception('Mint returned an incomplete proof state response');
        }

        $result = [];
        foreach ($states as $i => $state) {
            if (isset($state['Y']) && !hash_equals($Ys[$i], strtolower((string)$state['Y']))) {
                throw new Exception('Mint returned proof states in an unexpected order');
            }
            $result[$secrets[$i]] = strtoupper($state['state'] ?? '');
        }

        return $result;
    }

    /**
     * Take back an exported token's value by swapping its proofs for fresh ones.
     *
     * This is the only safe way to undo an export: simply marking the proofs spendable
     * again would leave the token in the recipient's hands still valid, and whoever got
     * there second would lose. Swapping invalidates the token at the mint.
     *
     * Refuses unless the mint confirms every proof is still unspent — if the recipient
     * already redeemed it, there is nothing to take back.
     *
     * $mintUrl/$unit: the exact account the token came from (see accountHoldingSecrets();
     * null = look it up among the store's current accounts, as before).
     *
     * @param string[] $secrets
     * @return int Amount returned to the spendable balance
     */
    public static function reclaimExportedProofs(string $storeId, array $secrets, ?string $mintUrl = null, ?string $unit = null): int {
        $secrets = array_values(array_filter($secrets, 'strlen'));
        if (empty($secrets)) {
            throw new Exception('Nothing to reclaim');
        }

        $states = self::checkProofStatesBySecrets($storeId, $secrets, $mintUrl, $unit);
        foreach ($secrets as $secret) {
            if (($states[$secret] ?? null) !== ProofState::UNSPENT) {
                throw new Exception(
                    'This token has already been cashed in (or is being cashed in right now), '
                    . 'so it cannot be taken back.'
                );
            }
        }

        $account = self::accountHoldingSecrets($storeId, $secrets, $mintUrl, $unit);
        $wallet = self::getWalletForStore($storeId, $account['mint_url'], $account['unit']);
        $storage = $wallet->getStorage();
        $proofs = $storage->getProofsBySecretsAsObjects($secrets);
        if (count($proofs) !== count($secrets)) {
            throw new Exception('Some of this token\'s ecash is no longer in the wallet');
        }

        $amount = \Cashu\Wallet::sumProofs($proofs);
        $fee = $wallet->calculateFee($proofs);

        // Dust. A mint that charges per input can make a small token cost more to swap
        // than it is worth, which used to be the end of the story: the recipient could
        // not cash it in and the operator could not take it back either, so the money
        // was simply stuck. Swapping it *together* with ecash we already hold fixes it —
        // NUT-02 sums the per-input fee and rounds once for the whole swap, so absorbing
        // one more input usually costs nothing extra at all.
        $helpers = [];
        if ($fee >= $amount) {
            $exclude = array_flip($secrets);
            // Helpers must come from the same mint — ecash from another mint cannot be an
            // input to this mint's swap.
            foreach ($storage->getProofsAsObjects(ProofState::UNSPENT) as $candidate) {
                if (isset($exclude[$candidate->secret])) {
                    continue;
                }
                $helpers[] = $candidate;
                if ($wallet->calculateFee(array_merge($proofs, $helpers))
                    < $amount + \Cashu\Wallet::sumProofs($helpers)) {
                    break;
                }
            }

            $fee = $wallet->calculateFee(array_merge($proofs, $helpers));
            if ($fee >= $amount + \Cashu\Wallet::sumProofs($helpers)) {
                throw new Exception(
                    'This is worth less than the mint charges to move it, and there is no '
                    . 'other ecash in this store to combine it with. It will become '
                    . 'recoverable once you take another payment.'
                );
            }
        }

        $inputs = array_merge($proofs, $helpers);
        $total = \Cashu\Wallet::sumProofs($inputs);

        // The library reserves the exported proofs straight from EXPORTED inside its
        // journaled transaction. They are never made spendable on the way, and a
        // rejected swap returns them to EXPORTED itself. (The old code flipped them to
        // UNSPENT first and back in a catch block: a crash in between put a token
        // someone else holds back into the balance, and overwriting the journal's
        // PENDING reservation made a timed-out swap impossible to finish.)
        $wallet->swap(
            $inputs,
            \Cashu\Wallet::splitAmount($total - $fee),
            [ProofState::UNSPENT, ProofState::EXPORTED]
        );

        // What the operator gets back is the token's own value less the fee this swap
        // cost; the helpers were already theirs and merely came along for the ride.
        return max(0, $amount - $fee);
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
        $storage = self::storageHoldingSecrets($storeId, $secrets);
        if (!$storage) {
            return;
        }
        // Only spendable proofs may be handed out. If something else (the cron's
        // automatic withdrawal, a concurrent request) already reserved or spent one, the
        // token being built is not ours to give: fail instead of handing it over.
        $changed = $storage->transitionProofStates($secrets, ProofState::EXPORTED, [ProofState::UNSPENT]);
        if ($changed !== count(array_unique($secrets))) {
            throw new Exception('Some of this money was being used by another payment at the same moment. Nothing was sent; please try again.');
        }
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
        $storage = self::storageHoldingSecrets($storeId, $secrets);
        if (!$storage) {
            return;
        }
        // Only spendable proofs may be handed out. If something else (the cron's
        // automatic withdrawal, a concurrent request) already reserved or spent one, the
        // token being built is not ours to give: fail instead of handing it over.
        $changed = $storage->transitionProofStates($secrets, ProofState::PENDING, [ProofState::UNSPENT]);
        if ($changed !== count(array_unique($secrets))) {
            throw new Exception('Some of this money was being used by another payment at the same moment. Nothing was sent; please try again.');
        }
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
     * Export all proofs of a specific wallet namespace as a Cashu token.
     *
     * The proofs are marked EXPORTED first, so this token is the only live copy: the
     * balance stops counting them and the ordinary withdrawal path cannot spend them
     * while the token circulates (MINIMAX 4.9). A lost token can be shown again — the
     * same proofs serialize to the same token — and markNamespaceRecovered() writes
     * them off once the recipient has claimed it.
     *
     * No mint contact — pure local serialization.
     *
     * @return array{token: ?string, amount: int, count: int, wallet_id: string, secrets: string[]}
     */
    public static function exportNamespaceAsToken(string $mintUrl, string $unit, ?string $account): array {
        self::assertNotLiveNamespace($mintUrl, $unit, $account);
        $wallet = new Wallet(rtrim($mintUrl, '/'), strtolower($unit), Database::getDbPath(), $account);
        $storage = $wallet->getStorage();
        $wid = $storage->getWalletId();

        // Inputs an unfinished operation still lists are not ours to hand out: its
        // recovery may yet spend them at the mint (UX-F4). Normally they are PENDING
        // already; this covers one released back to UNSPENT with its journal kept.
        $reserved = [];
        foreach ($storage->getPendingOperations() as $op) {
            foreach ((array)($op['data']['input_secrets'] ?? []) as $secret) {
                $reserved[(string)$secret] = true;
            }
        }
        $free = fn(array $proofs): array => array_values(array_filter($proofs, fn($p) => !isset($reserved[$p->secret])));

        $unspent = $free($storage->getProofsAsObjects(ProofState::UNSPENT));
        if (!empty($unspent)) {
            $secrets = array_map(fn($p) => $p->secret, $unspent);
            $changed = $storage->transitionProofStates($secrets, ProofState::EXPORTED, [ProofState::UNSPENT]);
            if ($changed !== count(array_unique($secrets))) {
                // A concurrent spender took part of the set. The transition above is
                // already durable, so the honest answer is the token over everything now
                // EXPORTED, not an error that pretends nothing happened.
                error_log('CashuPayServer: stranded export reserved ' . $changed . ' of ' . count($secrets)
                    . ' proofs; returning the reserved set');
            }
        }

        $exported = $free($storage->getProofsAsObjects(ProofState::EXPORTED));
        if (empty($exported)) {
            return ['token' => null, 'amount' => 0, 'count' => 0, 'wallet_id' => $wid, 'secrets' => []];
        }
        return [
            'token' => $wallet->serializeToken($exported),
            'amount' => Wallet::sumProofs($exported),
            'count' => count($exported),
            'wallet_id' => $wid,
            // Server-side only: which proofs this token carries, so "mark recovered"
            // touches exactly these and not a payment that arrived afterwards.
            'secrets' => array_map(fn($p) => $p->secret, $exported),
        ];
    }

    /**
     * Refuse the stranded-funds tools on a namespace a store still uses.
     *
     * Exporting a live wallet as a "stranded" token and then marking it recovered
     * wrote off whatever was in it — including payments that arrived in between.
     * Live wallets have the ordinary withdraw and export buttons.
     */
    private static function assertNotLiveNamespace(string $mintUrl, string $unit, ?string $account): void {
        $wid = WalletStorage::deriveWalletId(rtrim($mintUrl, '/'), strtolower($unit), $account);
        foreach (Database::fetchAll("SELECT id, wallet_account_id FROM stores") as $st) {
            foreach (Config::getStoreWalletAccounts($st['id']) as $acc) {
                if (WalletStorage::deriveWalletId($acc['mint_url'], $acc['unit'], $st['wallet_account_id'] ?: null) === $wid) {
                    throw new Exception('This money belongs to a mint a store still uses. Use Withdraw or Export on that store instead.');
                }
            }
        }
    }

    /** markNamespaceRecovered(): the mint still reports part of the token as unclaimed. */
    public const STRANDED_NOT_CLAIMED = 409;
    /** markNamespaceRecovered(): the mint could not be asked; retry with $allowUnverified. */
    public const STRANDED_UNVERIFIED = 503;

    /**
     * Mark the proofs of a claimed stranded-funds token SPENT.
     *
     * Asks the namespace's mint first (UX-F2): writing off a token nobody has claimed
     * yet hides money that is still there. Refuses (code STRANDED_NOT_CLAIMED) while any
     * proof is unspent or mid-spend at the mint. When the mint cannot be asked it
     * refuses with STRANDED_UNVERIFIED unless $allowUnverified (the operator's second,
     * explicit confirmation), and logs the unverified write-off.
     *
     * @param string[] $secrets the 'secrets' exportNamespaceAsToken() returned
     * @return int number of proofs marked
     */
    public static function markNamespaceRecovered(string $mintUrl, string $unit, ?string $account, array $secrets,
                                                  bool $allowUnverified = false): int {
        self::assertNotLiveNamespace($mintUrl, $unit, $account);
        if (empty($secrets)) {
            return 0;
        }
        $wallet = new Wallet(rtrim($mintUrl, '/'), strtolower($unit), Database::getDbPath(), $account);
        $storage = $wallet->getStorage();
        $proofs = $storage->getProofsBySecretsAsObjects($secrets);
        $states = [];
        if (!empty($proofs)) {
            try {
                $states = array_map(fn($st) => strtoupper((string)($st['state'] ?? '')), $wallet->checkProofState($proofs));
            } catch (Throwable $e) {
                $states = null;
                error_log('CashuPayServer: could not check stranded token at ' . $mintUrl . ': ' . $e->getMessage());
            }
        }
        if ($states !== null && (in_array(ProofState::UNSPENT, $states, true) || in_array(ProofState::PENDING, $states, true))) {
            throw new Exception(
                'This token has not been claimed yet: the mint still holds the money. Paste it into a '
                . 'Cashu wallet and receive it first, then mark it recovered.',
                self::STRANDED_NOT_CLAIMED
            );
        }
        // Every proof SPENT, or nothing left locally to write off.
        $verified = $states !== null && array_diff($states, [ProofState::SPENT]) === [];
        if (!$verified) {
            if (!$allowUnverified) {
                throw new Exception(
                    'The mint could not be reached to confirm the token was claimed.',
                    self::STRANDED_UNVERIFIED
                );
            }
            error_log('CashuPayServer: stranded token at ' . $mintUrl . " ({$unit}) written off WITHOUT mint confirmation, "
                . count($proofs) . ' proof(s), ' . Wallet::sumProofs($proofs) . " {$unit}, at the operator's request");
        }
        // Only the proofs that went into the claimed token. They are EXPORTED while the
        // token circulates; a namespace exported before the reservation existed still has
        // them UNSPENT, so accept both.
        return $storage->transitionProofStates($secrets, ProofState::SPENT, [ProofState::UNSPENT, ProofState::EXPORTED]);
    }

    /**
     * Find wallet namespaces that still hold value but are not reachable by any
     * store's current wallet — funds stranded by a past mint/unit change.
     * Best-effort identifies each namespace's mint by matching known + historical
     * mint URLs against the wallet_id derivation.
     *
     * 'amount'/'count' are what an export can put into a token (UNSPENT + EXPORTED).
     * 'unsettled_amount' is PENDING or UNKNOWN ecash and 'journals' unfinished
     * operations (UX-F3, UX-F4): neither can be exported — handing out inputs an
     * operation may still spend would pay twice — but re-adding the mint as a backup
     * puts the namespace back on recovery's list, which finishes them. 'note' says so.
     *
     * Pure local/DB — no mint contact. Single-operator: reports all stranded
     * namespaces regardless of store (see [[no-multitenancy]]).
     *
     * @return array<int, array{wallet_id:string, mint_url:?string, unit:?string,
     *   account:?string, account_mode:string, amount:int, count:int, keysets:array,
     *   unsettled_amount:int, journals:int, exportable:bool, note:?string}>
     */
    public static function scanStrandedNamespaces(): array {
        $rows = [];
        foreach (Database::fetchAll(
            "SELECT wallet_id,
                    SUM(CASE WHEN state IN (?, ?) THEN 1 ELSE 0 END) c,
                    COALESCE(SUM(CASE WHEN state IN (?, ?) THEN amount ELSE 0 END), 0) s,
                    COALESCE(SUM(CASE WHEN state IN (?, ?) THEN amount ELSE 0 END), 0) u
             FROM cashu_proofs WHERE state IN (?, ?, ?, ?) GROUP BY wallet_id",
            [ProofState::UNSPENT, ProofState::EXPORTED, ProofState::UNSPENT, ProofState::EXPORTED,
             ProofState::PENDING, ProofState::UNKNOWN,
             ProofState::UNSPENT, ProofState::EXPORTED, ProofState::PENDING, ProofState::UNKNOWN]
        ) as $r) {
            $rows[$r['wallet_id']] = ['c' => (int)$r['c'], 's' => (int)$r['s'], 'u' => (int)$r['u'], 'j' => 0];
        }
        // A namespace with only a journal (e.g. a paid Lightning invoice not yet
        // collected) holds value too, with no proofs to show for it yet.
        foreach (Database::fetchAll("SELECT wallet_id, COUNT(*) j FROM cashu_pending_operations GROUP BY wallet_id") as $r) {
            $rows[$r['wallet_id']] = ($rows[$r['wallet_id']] ?? ['c' => 0, 's' => 0, 'u' => 0, 'j' => 0]);
            $rows[$r['wallet_id']]['j'] = (int)$r['j'];
        }
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
        // Removed backup mints, and mints that outgoing transfers or payment requests used.
        foreach (['store_mints', 'transfers', 'payment_requests'] as $table) {
            try {
                foreach (Database::fetchAll("SELECT DISTINCT mint_url FROM {$table} WHERE mint_url IS NOT NULL AND mint_url <> ''") as $r) {
                    $candidateMints[rtrim($r['mint_url'], '/')] = true;
                }
            } catch (Throwable $e) {
                // Table or column not in this database yet.
            }
        }

        // Every unit a namespace could have been made with, not a fixed five (CPS-08).
        require_once __DIR__ . '/store_safety.php';
        $units = StoreSafety::candidateUnits();
        $accountsToTry = array_merge([null], array_keys($accounts));
        $out = [];
        foreach ($rows as $wid => $r) {
            $wid = (string)$wid;
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
                'amount' => $r['s'],
                'count' => $r['c'],
                'keysets' => $keysets,
                'unsettled_amount' => $r['u'],
                'journals' => $r['j'],
                'exportable' => $r['c'] > 0,
                'note' => ($r['u'] > 0 || $r['j'] > 0)
                    ? self::unfinishedStrandedNote($found['mint_url'] ?? null, $found['unit'] ?? null, $r['u'])
                    : null,
            ];
        }
        return $out;
    }

    /** What an unfinished, non-exportable part of a stranded namespace means, in plain words. */
    private static function unfinishedStrandedNote(?string $mintUrl, ?string $unit, int $amount): string {
        $host = $mintUrl !== null ? (parse_url($mintUrl, PHP_URL_HOST) ?: $mintUrl) : null;
        $what = $amount > 0 && $unit !== null ? "{$amount} {$unit} in an unfinished payment" : 'an unfinished payment';
        if ($host === null) {
            return ucfirst($what) . ' at a mint this server cannot name. It cannot be exported as a token.';
        }
        return ucfirst($what) . " at {$host}: add this mint as a backup mint"
            . ($unit !== null && $unit !== 'sat' ? " (unit {$unit})" : '')
            . ' to finish it. It cannot be exported as a token until then.';
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
                "SELECT COUNT(*) c, COALESCE(SUM(amount),0) s FROM cashu_proofs WHERE wallet_id = ? AND state IN (?, ?)",
                [$wid, ProofState::UNSPENT, ProofState::EXPORTED]
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
        // Every account of the store, backups included: proofs left PENDING on a backup
        // mint were never looked at (REL-03). Local storage first; a mint is contacted
        // only when that account has PENDING proofs to ask about.
        $result = ['checked' => 0, 'spent' => 0, 'pending' => 0, 'recovered' => 0];
        $errors = [];
        foreach (Config::getStoreWalletAccounts($storeId) as $account) {
            try {
                $storage = self::accountStorage($storeId, $account['mint_url'], $account['unit']);
                if ($storage === null) {
                    continue;
                }
                $counts = self::checkPendingProofsAt($storage, $account['mint_url']);
                foreach (['checked', 'spent', 'pending'] as $key) {
                    $result[$key] += $counts[$key];
                }
            } catch (\Throwable $e) {
                error_log("CashuPayServer: Error checking pending proofs at {$account['mint_url']}: " . $e->getMessage());
                $errors[] = $e->getMessage();
            }
        }
        if ($errors !== []) {
            $result['error'] = $errors[0];
        }
        return $result;
    }

    /** checkPendingProofs() for one account. @return array{checked: int, spent: int, pending: int} */
    private static function checkPendingProofsAt(WalletStorage $storage, string $mintUrl): array {
        $none = ['checked' => 0, 'spent' => 0, 'pending' => 0];
        $rows = $storage->getProofs(ProofState::PENDING);
        if (empty($rows)) {
            return $none;
        }

        // Melt/swap recovery exclusively owns these inputs. A mint may briefly report
        // them UNSPENT after a timeout even though the original request is queued.
        $reserved = array_flip($storage->getReservedInputSecrets());
        $rows = array_values(array_filter(
            $rows,
            fn($row) => !isset($reserved[$row['secret']])
        ));
        if (empty($rows)) {
            return $none;
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

        $client = new \Cashu\MintClient($mintUrl);
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
            $storage->transitionProofStates(
                $spentSecrets, ProofState::SPENT, [ProofState::PENDING], $reserved
            );
        }

        return ['checked' => count($rows), 'spent' => count($spentSecrets), 'pending' => $stillPending];
    }

    /**
     * recoverPendingSwaps() for one store wallet, with the plans of swaps paying NUT-18
     * requests recorded first (PaymentRequest::rememberSwapPlans(), CPS-01). Under the
     * store's receive lock, so receive.php cannot journal a new request swap between the
     * two steps; when a receive is in progress the swaps wait for the next run.
     */
    public static function recoverSwapsForStore(string $storeId, Wallet $wallet): array {
        if ($wallet->getStorage()->getPendingOperations('swap') === []) {
            return [];
        }
        require_once __DIR__ . '/payment_request.php';
        require_once __DIR__ . '/locks.php';
        $lock = cashupay_acquire_lock('receive_' . $storeId);
        if (!$lock) {
            return ['skipped' => 'receive in progress'];
        }
        try {
            PaymentRequest::rememberSwapPlans($wallet);
            return $wallet->recoverPendingSwaps();
        } finally {
            cashupay_release_lock($lock);
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
                    // Nothing journaled here: don't contact the mint. Asking every mint of
                    // every store on each run got backup mints to rate-limit us.
                    $local = new Wallet($account['mint_url'], $account['unit'], Database::getDbPath(),
                        Config::getStore($store['id'])['wallet_account_id']);
                    if ($local->getStorage()->getPendingOperations() === []) {
                        continue;
                    }
                    $wallet = self::getWalletForStore($store['id'], $account['mint_url'], $account['unit']);
                    $melts = $wallet->recoverPendingMelts();
                    $swaps = self::recoverSwapsForStore($store['id'], $wallet);
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
