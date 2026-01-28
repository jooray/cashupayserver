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

        // Store invoice with mint URL used
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
            'metadata' => $metadata ? json_encode($metadata) : null,
            'checkout_config' => $checkout ? json_encode($checkout) : null,
            'created_at' => $now,
            'expiration_time' => $expiration,
        ]);

        $invoice = self::getById($invoiceId);

        // Fire InvoiceCreated webhook
        WebhookSender::fireEvent($storeId, 'InvoiceCreated', $invoice);

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
    public static function updateStatus(string $invoiceId, string $status, ?string $additionalStatus = null): void {
        $updates = ['status' => $status];

        if ($additionalStatus !== null) {
            $updates['additional_status'] = $additionalStatus;
        }

        Database::update('invoices', $updates, 'id = ?', [$invoiceId]);

        // Get updated invoice for webhook
        $invoice = self::getById($invoiceId);

        // Fire appropriate webhook
        $eventType = match ($status) {
            'Processing' => 'InvoiceProcessing',
            'Settled' => 'InvoiceSettled',
            'Expired' => 'InvoiceExpired',
            'Invalid' => 'InvoiceInvalid',
            default => null,
        };

        if ($eventType && $invoice) {
            WebhookSender::fireEvent($invoice['store_id'], $eventType, $invoice);
        }
    }

    /**
     * Mark expired invoices without contacting the mint
     *
     * @return int Number of invoices marked as expired
     */
    public static function markExpiredInvoices(): int {
        $stmt = Database::query(
            "UPDATE invoices SET status = 'Expired'
             WHERE status = 'New' AND expiration_time < ?",
            [time()]
        );
        return $stmt->rowCount();
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
        $pendingInvoices = Database::fetchAll(
            "SELECT * FROM invoices
             WHERE status = 'New'
             AND quote_id IS NOT NULL
             AND expiration_time > ?
             AND (last_polled_at IS NULL OR (? - last_polled_at) >= ?)
             ORDER BY
                 CASE WHEN last_polled_at IS NULL THEN 0 ELSE 1 END,
                 last_polled_at ASC
             LIMIT ?",
            [$now, $now, $minInterval, $batchLimit]
        );

        if (empty($pendingInvoices)) {
            return;
        }

        foreach ($pendingInvoices as $invoice) {
            try {
                // Update last_polled_at before polling (so we don't re-poll on failure)
                Database::update('invoices', ['last_polled_at' => $now], 'id = ?', [$invoice['id']]);

                // Get wallet for this invoice's store
                $wallet = self::getWalletForStore($invoice['store_id']);

                // Check quote status
                $quoteStatus = $wallet->checkMintQuote($invoice['quote_id']);

                if ($quoteStatus->isPaid() || $quoteStatus->isIssued()) {
                    if ($quoteStatus->isIssued()) {
                        self::completeIssuedInvoice($invoice, $wallet);
                    } else {
                        self::mintAndStoreTokens($invoice, $wallet);
                    }
                }
            } catch (Exception $e) {
                error_log("CashuPayServer: Error polling invoice {$invoice['id']}: " . $e->getMessage());
            }
        }
    }

    /**
     * Mint tokens and store proofs
     */
    private static function mintAndStoreTokens(array $invoice, Wallet $wallet): void {
        self::clearWebhookQueue();

        // Mark as Processing BEFORE minting
        Database::update('invoices', ['status' => 'Processing'], 'id = ?', [$invoice['id']]);

        // Mint tokens - library stores proofs in cashu_proofs with quote_id
        $proofs = $wallet->mint($invoice['quote_id'], $invoice['amount_sats']);

        // Update invoice status in a transaction
        Database::beginTransaction();

        try {
            self::queueWebhook($invoice['store_id'], 'InvoiceReceivedPayment', $invoice);

            Database::update('invoices', ['status' => 'Settled'], 'id = ?', [$invoice['id']]);
            $updatedInvoice = self::getById($invoice['id']);
            self::queueWebhook($invoice['store_id'], 'InvoiceSettled', $updatedInvoice);

            Database::commit();

            self::flushWebhookQueue();
        } catch (Exception $e) {
            Database::rollback();
            self::clearWebhookQueue();
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
     * Webhook queue for deferred delivery after transaction commit
     */
    private static array $webhookQueue = [];

    /**
     * Get or create wallet instance for a store
     *
     * @param string $storeId Store ID
     * @param string|null $mintUrl Optional specific mint URL (for backup mints)
     * @return Wallet
     */
    public static function getWalletForStore(string $storeId, ?string $mintUrl = null): Wallet {
        $store = Config::getStore($storeId);
        if (!$store) {
            throw new Exception('Store not found');
        }

        $mintUrl = $mintUrl ?? $store['mint_url'];
        $mintUnit = $store['mint_unit'] ?? 'sat';
        $seedPhrase = $store['seed_phrase'];

        if (empty($mintUrl) || empty($seedPhrase)) {
            throw new Exception('Store wallet not configured');
        }

        $cacheKey = $storeId . '|' . $mintUrl . '|' . $mintUnit;

        if (!isset(self::$walletCache[$cacheKey])) {
            $wallet = new Wallet($mintUrl, $mintUnit, Database::getDbPath());
            $wallet->loadMint();
            $wallet->initFromMnemonic($seedPhrase);

            self::$walletCache[$cacheKey] = $wallet;
        }

        return self::$walletCache[$cacheKey];
    }

    /**
     * Get wallet instance for a store (public accessor)
     */
    public static function getWalletInstance(string $storeId): Wallet {
        return self::getWalletForStore($storeId);
    }

    // =========================================================================
    // WEBHOOK QUEUE
    // =========================================================================

    private static function queueWebhook(string $storeId, string $event, array $data): void {
        self::$webhookQueue[] = compact('storeId', 'event', 'data');
    }

    private static function flushWebhookQueue(): void {
        foreach (self::$webhookQueue as $item) {
            WebhookSender::fireEvent($item['storeId'], $item['event'], $item['data']);
        }
        self::$webhookQueue = [];
    }

    private static function clearWebhookQueue(): void {
        self::$webhookQueue = [];
    }

    // =========================================================================
    // SINGLE INVOICE POLLING
    // =========================================================================

    /**
     * Poll a single invoice's quote status
     */
    public static function pollSingleQuote(string $invoiceId): void {
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

        try {
            $wallet = self::getWalletForStore($invoice['store_id']);
            $quoteStatus = $wallet->checkMintQuote($invoice['quote_id']);

            error_log("CashuPayServer: Quote {$invoice['quote_id']} state: {$quoteStatus->state}");

            if ($quoteStatus->isPaid() || $quoteStatus->isIssued()) {
                error_log("CashuPayServer: Quote is paid/issued, processing...");
                if ($quoteStatus->isIssued()) {
                    self::completeIssuedInvoice($invoice, $wallet);
                } elseif ($invoice['status'] === 'New') {
                    self::mintAndStoreTokens($invoice, $wallet);
                } elseif ($invoice['status'] === 'Processing') {
                    self::mintAndStoreTokens($invoice, $wallet);
                }
            }
        } catch (Exception $e) {
            error_log("CashuPayServer: Error polling single quote {$invoice['id']}: " . $e->getMessage());
        }
    }

    // =========================================================================
    // ISSUED QUOTE HANDLING
    // =========================================================================

    private static function completeIssuedInvoice(array $invoice, Wallet $wallet): void {
        if ($wallet->hasStorage()) {
            $proofs = $wallet->getStorage()->getProofsByQuoteId($invoice['quote_id']);
            if (!empty($proofs)) {
                Database::beginTransaction();
                try {
                    Database::update('invoices', ['status' => 'Settled'], 'id = ?', [$invoice['id']]);
                    Database::commit();

                    $updatedInvoice = self::getById($invoice['id']);
                    WebhookSender::fireEvent($invoice['store_id'], 'InvoiceSettled', $updatedInvoice);
                    return;
                } catch (Exception $e) {
                    Database::rollback();
                    throw $e;
                }
            }
        }

        error_log("CashuPayServer: ISSUED quote {$invoice['quote_id']} has no proofs in storage - invoice {$invoice['id']}");
    }

    // =========================================================================
    // ORPHANED INVOICE RECOVERY
    // =========================================================================

    /**
     * Recover orphaned invoices stuck in Processing state
     */
    public static function recoverOrphanedInvoices(): array {
        $recovered = [];

        $stuck = Database::fetchAll(
            "SELECT * FROM invoices WHERE status = 'Processing' AND created_at < ?",
            [time() - 60]
        );

        foreach ($stuck as $invoice) {
            try {
                $wallet = self::getWalletForStore($invoice['store_id']);
                if ($wallet->hasStorage() && $invoice['quote_id']) {
                    $proofs = $wallet->getStorage()->getProofsByQuoteId($invoice['quote_id']);
                    if (!empty($proofs)) {
                        Database::update('invoices', ['status' => 'Settled'], 'id = ?', [$invoice['id']]);
                        $recovered[] = $invoice['id'];

                        $updatedInvoice = self::getById($invoice['id']);
                        WebhookSender::fireEvent($invoice['store_id'], 'InvoiceSettled', $updatedInvoice);

                        error_log("CashuPayServer: Recovered orphaned invoice {$invoice['id']}");
                    }
                }
            } catch (Exception $e) {
                error_log("CashuPayServer: Error recovering invoice {$invoice['id']}: " . $e->getMessage());
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

        $storage = new WalletStorage(
            Database::getDbPath(),
            $store['mint_url'],
            $store['mint_unit'] ?? 'sat'
        );
        return $storage->getBalance();
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
            $store['mint_unit'] ?? 'sat'
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
            $store['mint_unit'] ?? 'sat'
        );
        $storage->updateProofsState($secrets, ProofState::SPENT);
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
            $store['mint_unit'] ?? 'sat'
        );
        $storage->updateProofsState($secrets, ProofState::PENDING);
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

            // Separate into spent and unspent
            $spentSecrets = [];
            $unspentSecrets = [];
            foreach ($response['states'] ?? [] as $i => $state) {
                $mintState = $state['state'] ?? ProofState::UNSPENT;
                $YHex = $Ys[$i];
                if (!isset($proofMap[$YHex])) continue;

                if ($mintState === ProofState::SPENT) {
                    $spentSecrets[] = $proofMap[$YHex];
                } else {
                    $unspentSecrets[] = $proofMap[$YHex];
                }
            }

            // Update database
            if (!empty($spentSecrets)) {
                $wallet->getStorage()->updateProofsState($spentSecrets, ProofState::SPENT);
            }

            if (!empty($unspentSecrets)) {
                $wallet->getStorage()->updateProofsState($unspentSecrets, ProofState::UNSPENT);
            }

            return [
                'checked' => count($rows),
                'spent' => count($spentSecrets),
                'recovered' => count($unspentSecrets)
            ];
        } catch (\Exception $e) {
            error_log("CashuPayServer: Error checking pending proofs: " . $e->getMessage());
            return ['checked' => 0, 'spent' => 0, 'recovered' => 0, 'error' => $e->getMessage()];
        }
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
