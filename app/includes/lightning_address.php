<?php
/**
 * CashuPayServer - Lightning Address Module
 *
 * LNURL-pay resolution and auto-melt functionality.
 * Uses cashu-wallet-php library for Lightning address resolution.
 * Supports per-store wallet configuration.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/invoice.php';
require_once __DIR__ . '/rates.php';
require_once __DIR__ . '/mint_reliability.php';
require_once __DIR__ . '/notification_sender.php';
require_once __DIR__ . '/safe_http.php';
require_once __DIR__ . '/swap/auto_melt.php';
require_once __DIR__ . '/store_ln_addresses.php';
require_once __DIR__ . '/clink/client.php';
require_once __DIR__ . '/nwc/client.php';
require_once __DIR__ . '/locks.php';
require_once __DIR__ . '/store_safety.php';
require_once __DIR__ . '/../cashu-wallet-php/CashuWallet.php';

use Cashu\Wallet;
use Cashu\Proof;
use Cashu\ProofState;
use Cashu\LightningAddress as CashuLightningAddress;

class LightningAddress {
    /**
     * Absolute floor (in the mint's smallest unit) under which a fee reserve
     * is always considered plausible, so tiny payments aren't rejected over a
     * legitimate base routing fee. Above this floor, a reserve may not exceed
     * the payment amount itself.
     */
    private const FEE_RESERVE_SANITY_FLOOR = 1000;

    /**
     * Reject a mint-supplied melt quote whose fee reserve is implausibly large.
     * A real Lightning routing reserve is a small fraction of the payment; a
     * reserve larger than the payment itself (beyond a small-payment floor)
     * indicates a buggy or hostile mint trying to drain extra balance into
     * "fees". This is intentionally generous — it never trips on normal
     * routing, only on egregious values.
     *
     * @throws Exception if the reserve fails the sanity bound
     */
    private static function assertSaneFeeReserve(int $amount, int $feeReserve): void {
        $maxReserve = max($amount, self::FEE_RESERVE_SANITY_FLOOR);
        if ($feeReserve > $maxReserve) {
            throw new Exception(
                "Melt fee reserve {$feeReserve} exceeds sanity bound {$maxReserve} "
                . "(payment amount {$amount}); refusing to melt"
            );
        }
    }

    /**
     * The fee a melt actually cost: what went in, minus the change that came
     * back, minus the invoice amount. Never negative — proof selection
     * over-covers whenever denominations do not match exactly and the mint
     * returns that over-coverage as change, so "fee reserve minus change"
     * went negative and recorded real routing fees as zero.
     */
    public static function meltFee(int $inputAmount, int $changeAmount, int $invoiceAmount): int {
        return max(0, $inputAmount - $changeAmount - $invoiceAmount);
    }

    /**
     * Refuse to start a withdrawal while a previous one is still being settled
     * by the mint.
     *
     * A melt the mint answered PENDING leaves its inputs PENDING with a journal
     * the cron reconciles later. Each retry fetches a fresh invoice (and a
     * fresh quote), so nothing else stops a second payment from being made out
     * of the remaining proofs — and when the first one settles, the
     * destination has been paid twice. A journal whose inputs are UNSPENT
     * (an UNPAID failure the library kept for bookkeeping) does not block.
     *
     * @throws Exception
     */
    public static function assertNoPendingWithdrawal(string $storeId): void {
        foreach (Config::getStoreWalletAccounts($storeId) as $account) {
            try {
                $storage = StoreSafety::storage($account['mint_url'], $account['unit']);
                foreach ($storage->getPendingOperations('melt') as $op) {
                    $inputs = array_values(array_map('strval', (array)($op['data']['input_secrets'] ?? [])));
                    if (empty($inputs)) {
                        continue;
                    }
                    foreach ($storage->getProofsStatesBySecrets($inputs) as $state) {
                        if ($state === ProofState::PENDING) {
                            throw new Exception(
                                'A previous withdrawal from this store is still being settled by the mint. '
                                . 'It is checked automatically; wait for it to finish before starting another one.'
                            );
                        }
                    }
                }
            } catch (\PDOException $e) {
                // A backup namespace that cannot be opened is not a reason to
                // block the primary; the primary's own storage is always readable.
                error_log("assertNoPendingWithdrawal: could not read {$account['mint_url']}: " . $e->getMessage());
            }
        }
    }

    /**
     * Validate a Lightning address format
     */
    public static function isValid(string $address): bool {
        return CashuLightningAddress::isValid($address);
    }

    /**
     * Resolve Lightning address to LNURL-pay metadata
     */
    public static function resolve(string $address): ?array {
        return CashuLightningAddress::resolve($address);
    }

    /**
     * Get a BOLT11 invoice from a Lightning address
     */
    public static function getInvoice(string $address, int $amountSats, ?string $comment = null): string {
        return CashuLightningAddress::getInvoice($address, $amountSats, $comment);
    }

    /**
     * Melt tokens to a Lightning address
     *
     * IMPORTANT: Amount must ALWAYS be in SATOSHIS, not mint unit.
     * Lightning Network always operates in satoshis. For fiat mints,
     * the melt quote will return the cost in mint's unit (EUR/USD),
     * which is then paid with fiat proofs.
     *
     * @param string $storeId Store ID for wallet access
     * @param string $address Lightning address
     * @param int $amountSats Amount in SATOSHIS (Lightning is always sats)
     * @param string|null $comment Optional comment
     */
    public static function meltToAddress(string $storeId, string $address, int $amountSats, ?string $comment = null): array {
        // The reliability tracker needs to know which mint we're talking to;
        // grab it up front so failures in any stage can be attributed correctly.
        $mintUrl = Config::getStoreMintUrl($storeId);
        $wallet = null;
        $meltQuoteId = null;

        try {
            // LNURL resolution / Lightning Address invoice request: failures
            // here are wallet-side (LNURL host down, bad address, etc.).
            try {
                $bolt11 = CashuLightningAddress::getInvoice($address, $amountSats, $comment);
            } catch (Exception $e) {
                self::recordMeltFailure($mintUrl, $address, $storeId, $e, 'getInvoice', null, null);
                throw $e;
            }

            try {
                $wallet = Invoice::getWalletInstance($storeId);
            } catch (Exception $e) {
                self::recordMeltFailure($mintUrl, $address, $storeId, $e, 'requestMeltQuote', null, null);
                throw $e;
            }

            try {
                $meltQuote = $wallet->requestMeltQuote($bolt11);
                $meltQuoteId = $meltQuote->quote ?? null;
            } catch (Exception $e) {
                self::recordMeltFailure($mintUrl, $address, $storeId, $e, 'requestMeltQuote', null, $wallet);
                throw $e;
            }

            try {
                self::assertSaneFeeReserve($meltQuote->amount, $meltQuote->feeReserve);
            } catch (Exception $e) {
                self::recordMeltFailure($mintUrl, $address, $storeId, $e, 'requestMeltQuote', $meltQuoteId, $wallet);
                throw $e;
            }

            $totalNeeded = $meltQuote->amount + $meltQuote->feeReserve;
            $proofs = Invoice::getUnspentProofs($storeId);
            $balance = Wallet::sumProofs($proofs);
            $mintUnit = Config::getStoreMintUnit($storeId);

            if ($balance < $totalNeeded) {
                // Pre-flight; not a mint or wallet fault — see MintReliability.
                throw new Exception("Insufficient balance. Have: {$balance} {$mintUnit}, Need: {$totalNeeded} {$mintUnit}");
            }

            // Fee-aware selection: on a mint that charges per input the
            // inputs must also cover their own NUT-02 fee, or melt() refuses.
            $selectedProofs = $wallet->selectProofsWithFees($proofs, $totalNeeded);
            $inputAmount = Wallet::sumProofs($selectedProofs);

            try {
                $result = $wallet->melt($meltQuote->quote, $selectedProofs);
            } catch (Exception $e) {
                self::recordMeltFailure($mintUrl, $address, $storeId, $e, 'melt', $meltQuoteId, $wallet);
                throw $e;
            }

            if (!$result['paid']) {
                $msg = ($result['pending'] ?? false)
                    ? 'Lightning payment pending - proofs marked as pending for recovery'
                    : 'Lightning payment failed';
                $e = new Exception($msg);
                self::recordMeltFailure($mintUrl, $address, $storeId, $e, 'melt', $meltQuoteId, $wallet);
                throw $e;
            }

            if ($mintUrl !== null && $mintUrl !== '') {
                MintReliability::recordWithdrawSuccess($mintUrl, $address, $storeId);
            }

            self::noteChangeRecoveryPending($storeId, $address, $meltQuoteId, $result);
            $changeAmount = Wallet::sumProofs($result['change'] ?? []);
            return [
                'success' => true,
                'preimage' => $result['preimage'],
                'amountPaid' => $meltQuote->amount,
                'fee' => self::meltFee($inputAmount, $changeAmount, $meltQuote->amount),
                'changeAmount' => $changeAmount,
                'changeRecoveryPending' => (bool)($result['changeRecoveryPending'] ?? false),
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * A melt the mint PAID whose change could not be collected yet (the NUT-09
     * restore or the change unblinding failed after the payment went through)
     * is still a SUCCESS: the destination was paid. The library keeps the
     * melt journal and the cron's recoverPendingMelts() collects the change;
     * until then the fee reported here is overstated by that change. Never
     * retry the withdrawal for this — it would pay twice.
     */
    public static function noteChangeRecoveryPending(string $storeId, ?string $destination, ?string $quoteId, array $result): void {
        if (empty($result['changeRecoveryPending'])) {
            return;
        }
        $reason = (string)($result['changeRecoveryError'] ?? 'unknown');
        error_log(sprintf(
            'CashuPayServer: melt %s for store %s PAID; change collection deferred to cron (%s)',
            $quoteId ?? '?', $storeId, $reason
        ));
        try {
            AdminLog::log('melt', 'change_pending', $storeId, null, $destination,
                'Payment went through; the mint\'s change will be collected by the next cron run: ' . $reason);
        } catch (\Throwable $e) {
            // best-effort
        }
    }

    /**
     * Classify and record a meltToAddress failure against the reliability
     * tracker. INSUFFICIENT_BALANCE is filtered out inside MintReliability.
     */
    private static function recordMeltFailure(
        ?string $mintUrl,
        ?string $address,
        string $storeId,
        Exception $e,
        string $stage,
        ?string $meltQuoteId,
        $wallet
    ): void {
        if ($mintUrl === null || $mintUrl === '') {
            return;
        }
        $kind = MintReliability::classifyException($e, $stage);
        MintReliability::recordWithdrawFailure(
            $mintUrl,
            $address,
            $storeId,
            $kind,
            $e->getMessage(),
            $meltQuoteId,
            $wallet
        );
    }

    /**
     * Check and perform auto-melt for all stores with auto-melt enabled
     * Called on each admin page load to check if any stores need auto-cashout
     */
    public static function checkAutoMelt(): ?array {
        // Get all stores with auto-melt enabled. Includes auto_melt_use_swap +
        // the on-chain fields {@see SwapAutoMelt::modeForStore} needs, so we
        // can hand stores that opted into swap-mode auto-melt over to
        // SwapAutoMelt::checkAndExecute without re-querying.
        // The set of LN addresses now lives in store_ln_addresses (ordered
        // fallback chain), so we no longer filter on a single column here —
        // stores with an empty chain are skipped per-iteration below.
        $stores = Database::fetchAll(
            "SELECT id, name, mint_url, mint_unit, auto_melt_threshold,
                    auto_melt_use_swap, onchain_address_mode, onchain_xpub,
                    swaps_enabled,
                    price_provider_primary, price_provider_secondary
             FROM stores
             WHERE auto_melt_enabled = 1
               AND mint_url IS NOT NULL
               AND seed_phrase IS NOT NULL"
        );

        if (empty($stores)) {
            return null;
        }

        $results = [];

        foreach ($stores as $store) {
            // LNURL/noffer is the primary mint-drain rail: it runs for EVERY
            // auto-melt-enabled store that has a destination configured — even
            // stores that ALSO have the on-chain (swap) rail set up. Draining
            // here first means funds leave the mint promptly instead of waiting
            // for an on-chain swap to become economically rational. The swap
            // rail (SwapAutoMelt::checkAndExecute, run right after in cron) then
            // reads a drained wallet and no-ops, so it acts as an automatic
            // fallback that only fires when the LN host is down and funds remain.
            //
            // Ordered fallback chain for this store; skip stores with none
            // (replaces the old WHERE auto_melt_address IS NOT NULL filter). A
            // swap-only store with no Lightning address / noffer falls through
            // here untouched and is handled solely by the swap rail. Mixed
            // types: Lightning addresses and CLINK noffers, tried in order.
            $destinations = StoreLnAddresses::destinationsForStore($store['id']);
            if (empty($destinations)) {
                continue;
            }
            // Display-safe form (masks NWC connection URIs) — this string ends
            // up in notifications and logs, never used to actually melt.
            $primaryAddress = StoreLnAddresses::displayValue(
                $destinations[0]['type'], $destinations[0]['value']
            );
            // Per-store funds lock shared with the dashboard's withdraw /
            // export actions and the fee melt: never select the same proofs
            // as a concurrent operator action. Busy → skip this store this
            // tick (it is re-tried next tick), never fail it.
            $fundsLock = cashupay_acquire_lock('funds_' . $store['id']);
            if (!$fundsLock) {
                $results[] = [
                    'store_id' => $store['id'],
                    'store_name' => $store['name'],
                    'success' => false,
                    'skipped' => 'busy',
                ];
                continue;
            }
            try {
                // A withdrawal the mint is still settling must finish before a
                // new one is paid out of the remaining proofs (double payment).
                try {
                    self::assertNoPendingWithdrawal($store['id']);
                } catch (Exception $pendingErr) {
                    $results[] = [
                        'store_id' => $store['id'],
                        'store_name' => $store['name'],
                        'success' => false,
                        'skipped' => 'pending',
                        'error' => $pendingErr->getMessage(),
                    ];
                    continue;
                }

                // Check store balance from local storage (offline-first, no mint contact)
                // This prevents crashes when mint is unreachable
                $balance = Invoice::getBalance($store['id']);
                $mintUnit = strtolower($store['mint_unit'] ?? 'sat');
                $isFiatMint = !in_array($mintUnit, ['sat', 'sats', 'msat']);

                // No balance threshold: LNURL/noffer is the always-on primary
                // drain, so we attempt a cashout whenever there is any spendable
                // balance. The only floor is physical — an amount too small to
                // cover the Lightning fee is dropped by the post-fee dust guard
                // below (before any LNURL host is contacted), so sub-fee dust
                // never triggers network calls or failure emails.
                // auto_melt_threshold intentionally NO LONGER gates this rail; it
                // still governs the on-chain swap floor via SwapAutoMelt.
                if ($balance >= 1) {
                    // Fees (upstream dev / dev / hosting) are settled separately
                    // on the cron fee-settlement tick (which runs BEFORE auto-melt),
                    // so auto-melt just empties the post-fee balance to the
                    // operator's Lightning address.
                    $meltAmountInMintUnit = $balance;

                    if ($meltAmountInMintUnit < 1) {
                        continue;
                    }

                    // For Lightning payments, we need the amount in SATS
                    // Convert from mint unit to sats if using a fiat mint
                    if ($isFiatMint) {
                        $meltAmountSats = ExchangeRates::convertMintUnitToSats(
                            $meltAmountInMintUnit,
                            $mintUnit,
                            $store['price_provider_primary'] ?? null,
                            $store['price_provider_secondary'] ?? null
                        );
                    } else {
                        $meltAmountSats = $meltAmountInMintUnit;
                    }

                    // Account for Lightning fee reserve - mints charge fees on top of invoice amount
                    // Use 1% fee buffer (min 2 sats, max 100 sats) to ensure we have enough for fees
                    $feeBuffer = max(2, min(100, (int)ceil($meltAmountSats * 0.01)));
                    $meltAmountSats = $meltAmountSats - $feeBuffer;

                    if ($meltAmountSats < 1) {
                        continue;
                    }

                    // Perform melt to a Lightning address (amount in SATS),
                    // walking the priority chain: the first address that accepts
                    // the payment wins; the rest are tried only on failure.
                    // Wrapped per-attempt to handle host/mint failures gracefully.
                    $meltResult = null;
                    $usedAddress = null;
                    $lastMeltError = null;
                    foreach ($destinations as $priority => $dest) {
                        // Log/notification-safe form (masks NWC secrets).
                        $destShown = StoreLnAddresses::displayValue($dest['type'], $dest['value']);
                        try {
                            $meltResult = self::meltToDestination(
                                $store['id'],
                                $dest,
                                $meltAmountSats,
                                'BareBits auto-cashout'
                            );
                            $usedAddress = $destShown;
                            if ($priority > 0) {
                                error_log("Auto-melt: used fallback destination (priority {$priority}, {$dest['type']}) {$destShown} for store {$store['id']}");
                            }
                            break;
                        } catch (Exception $meltError) {
                            $lastMeltError = $meltError;
                            error_log("Auto-melt attempt to {$destShown} ({$dest['type']}, priority {$priority}) failed for store {$store['id']}: " . $meltError->getMessage());
                        }
                    }

                    if ($meltResult !== null) {
                        // Record successful melt for fee-base accounting + future stats.
                        // Network fee converted to sats for fiat mints so the dev-fee
                        // base shrinks honestly regardless of mint unit.
                        $networkFeeSats = (int)($meltResult['fee'] ?? 0);
                        if ($isFiatMint && $networkFeeSats > 0) {
                            $networkFeeSats = ExchangeRates::convertMintUnitToSats(
                                $networkFeeSats,
                                $mintUnit,
                                $store['price_provider_primary'] ?? null,
                                $store['price_provider_secondary'] ?? null
                            );
                        }
                        require_once __DIR__ . '/dev_fee.php';
                        MeltLog::record(
                            $store['id'],
                            $meltAmountSats,
                            $networkFeeSats,
                            $usedAddress,
                            $meltResult['preimage'] ?? null,
                            null,
                            $meltResult['strike_invoice_id'] ?? null
                        );

                        $results[] = [
                            'store_id' => $store['id'],
                            'store_name' => $store['name'],
                            'amount' => $meltAmountSats,
                            'amountMintUnit' => $meltAmountInMintUnit,
                            'mintUnit' => $mintUnit,
                            'success' => true,
                        ];

                        NotificationSender::queueAutoCashoutSuccess(
                            $store['id'],
                            $meltAmountSats,
                            $usedAddress
                        );

                        error_log("Auto-melt: Sent {$meltAmountSats} sats (~{$meltAmountInMintUnit} {$mintUnit}) from store {$store['name']} to {$usedAddress}");
                    } else {
                        // Every address failed (mint unreachable, hosts down, insufficient
                        // funds, etc.). Notify against the primary and continue — don't
                        // crash the admin page load.
                        $errMsg = $lastMeltError ? $lastMeltError->getMessage() : 'unknown error';
                        error_log("Auto-melt operation failed for store {$store['id']} (all " . count($destinations) . " destination(s)): " . $errMsg);
                        NotificationSender::queueAutoCashoutFailure(
                            $store['id'],
                            $primaryAddress,
                            $errMsg,
                            $meltAmountSats
                        );
                        $results[] = [
                            'store_id' => $store['id'],
                            'store_name' => $store['name'],
                            'success' => false,
                            'error' => $errMsg,
                        ];
                    }
                }
            } catch (Exception $e) {
                error_log("Auto-melt check failed for store {$store['id']}: " . $e->getMessage());
                // Pre-flight failure (balance lookup, etc.) — still notify so
                // operators see that auto-cashout is wedged.
                if (!empty($primaryAddress)) {
                    NotificationSender::queueAutoCashoutFailure(
                        $store['id'],
                        $primaryAddress,
                        $e->getMessage(),
                        null
                    );
                }
                $results[] = [
                    'store_id' => $store['id'],
                    'store_name' => $store['name'],
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            } finally {
                cashupay_release_lock($fundsLock);
            }
        }

        return empty($results) ? null : $results;
    }

    /**
     * Check if input is a BOLT-11 Lightning invoice
     */
    public static function isBolt11Invoice(string $input): bool {
        $input = strtolower(trim($input));
        return preg_match('/^ln(bc|tb|tbs|bcrt)[0-9]/', $input) === 1;
    }

    /**
     * Get the amount from a BOLT-11 invoice (if it has one)
     *
     * @param string $bolt11 The BOLT-11 invoice string
     * @param string|null $storeId Optional store ID for wallet access (fixes issue with random store selection)
     * @return array|null Array with 'amountSats', 'amountMintUnit', 'feeReserve' or null on error
     */
    public static function getBolt11Amount(string $bolt11, ?string $storeId = null): ?array {
        // Parse the bolt11 amount locally first (no network needed)
        $amountSats = self::parseBolt11Amount($bolt11);

        try {
            // Get wallet for the specified store, or fall back to first configured store
            if ($storeId) {
                $wallet = Invoice::getWalletInstance($storeId);
            } else {
                $stores = Database::fetchAll("SELECT id FROM stores WHERE mint_url IS NOT NULL AND seed_phrase IS NOT NULL LIMIT 1");
                if (empty($stores)) {
                    return ['amountSats' => $amountSats, 'amountMintUnit' => null, 'feeReserve' => null];
                }
                $wallet = Invoice::getWalletInstance($stores[0]['id']);
            }

            // Get melt quote (returns amount in mint's unit and fee estimate)
            $meltQuote = $wallet->requestMeltQuote($bolt11);

            return [
                'amountSats' => $amountSats,
                'amountMintUnit' => $meltQuote->amount,
                'feeReserve' => $meltQuote->feeReserve,
            ];
        } catch (Exception $e) {
            error_log("getBolt11Amount error: " . $e->getMessage());
            // Still return the locally-parsed amount even if melt quote fails
            return ['amountSats' => $amountSats, 'amountMintUnit' => null, 'feeReserve' => null, 'meltError' => $e->getMessage()];
        }
    }

    /**
     * Parse the amount from a BOLT-11 invoice string
     *
     * @param string $bolt11 The BOLT-11 invoice string
     * @return int Amount in satoshis (0 if no amount encoded or parse error)
     */
    public static function parseBolt11Amount(string $bolt11): int {
        $bolt11 = strtolower(trim($bolt11));

        // BOLT-11 format: ln<prefix><amount><multiplier><data>
        // Prefix: bc (mainnet), tb (testnet), tbs (signet), bcrt (regtest)
        // Amount: optional digits followed by optional multiplier
        // Multipliers: m (milli = 0.001), u (micro = 0.000001), n (nano = 0.000000001), p (pico = 0.000000000001)

        $patterns = [
            '/^lnbc(\d+)([munp]?)1/' => 'mainnet',
            '/^lntb(\d+)([munp]?)1/' => 'testnet',
            '/^lntbs(\d+)([munp]?)1/' => 'signet',
            '/^lnbcrt(\d+)([munp]?)1/' => 'regtest',
        ];

        foreach ($patterns as $pattern => $network) {
            if (preg_match($pattern, $bolt11, $matches)) {
                $amount = (int)$matches[1];
                $multiplier = $matches[2] ?? '';

                // Convert to satoshis based on multiplier
                // 1 BTC = 100,000,000 sats
                switch ($multiplier) {
                    case '':
                        // Amount is in BTC
                        return $amount * 100000000;
                    case 'm':
                        // Amount is in milli-BTC (0.001 BTC)
                        return $amount * 100000;
                    case 'u':
                        // Amount is in micro-BTC (0.000001 BTC)
                        return $amount * 100;
                    case 'n':
                        // Amount is in nano-BTC (0.000000001 BTC)
                        // 1 nano-BTC = 0.1 sat, round up
                        return (int)ceil($amount / 10);
                    case 'p':
                        // Amount is in pico-BTC (0.000000000001 BTC)
                        // 1 pico-BTC = 0.0001 sat, round up
                        return (int)ceil($amount / 10000);
                    default:
                        return 0;
                }
            }
        }

        // No amount prefix found (zero-amount invoice)
        // Check for invoices without amount (just prefix + 1 + data)
        if (preg_match('/^ln(bc|tb|tbs|bcrt)1/', $bolt11)) {
            return 0;
        }

        return 0;
    }

    /**
     * Melt tokens to a BOLT-11 invoice.
     *
     * Failures here flow through the mint reliability tracker on the same
     * terms as {@see meltToAddress}: a manual BOLT-11 melt (or a dev-fee
     * settlement, which also goes through this method) that fails counts
     * toward the mint's withdrawal-failure state and therefore toward stuck-
     * fund detection. Insufficient balance is filtered out inside
     * MintReliability.
     *
     * @param string $storeId Store ID for wallet access
     * @param string $bolt11 The BOLT-11 invoice
     * @param int|null $expectedAmount Optional expected amount (for amountless invoices)
     * @param callable|null $onQuoteReady Optional hook invoked as
     *   `fn(string $meltQuoteId): void` immediately BEFORE proofs are spent,
     *   once the mint melt quote exists. The fee-settlement path uses it to
     *   write a write-ahead "pending" intent keyed by the quote id, so a crash
     *   in the spend→record window can't double-pay (see DevFee). Other callers
     *   pass null and behaviour is unchanged.
     */
    public static function meltToBolt11(string $storeId, string $bolt11, ?int $expectedAmount = null, ?callable $onQuoteReady = null): array {
        $mintUrl = Config::getStoreMintUrl($storeId);
        $wallet = null;
        $meltQuoteId = null;

        // BOLT-11 is a one-shot invoice, not a reusable address — pass null
        // so the reliability tracker doesn't use it for cross-mint
        // differential resolution (which is keyed on stable Lightning
        // addresses).
        $addressForRecord = null;

        // An amountless invoice cannot be melted (the mint would quote 0 and
        // the operator would see a raw mint error after a network round trip).
        // Say so plainly, before any network call.
        if (self::parseBolt11Amount($bolt11) === 0) {
            throw new Exception(
                'This invoice has no amount. Only invoices that specify an amount can be paid — '
                . 'ask the recipient for an invoice with the amount filled in.'
            );
        }

        try {
            $wallet = Invoice::getWalletInstance($storeId);
        } catch (Exception $e) {
            self::recordMeltFailure($mintUrl, $addressForRecord, $storeId, $e, 'requestMeltQuote', null, null);
            throw $e;
        }

        try {
            $meltQuote = $wallet->requestMeltQuote($bolt11);
            $meltQuoteId = $meltQuote->quote ?? null;
        } catch (Exception $e) {
            self::recordMeltFailure($mintUrl, $addressForRecord, $storeId, $e, 'requestMeltQuote', null, $wallet);
            throw $e;
        }

        // Bound the amount the mint says this BOLT11 demands against what the
        // caller expected to pay (e.g. the fee owed). Catches a fetched
        // invoice that encodes a larger amount than requested before any funds
        // leave. Tolerance absorbs rounding between fiat conversion and sats.
        if ($expectedAmount !== null && $expectedAmount > 0) {
            $tolerance = max(2, (int)ceil($expectedAmount * 0.01));
            if ($meltQuote->amount > $expectedAmount + $tolerance) {
                $e = new Exception(
                    "BOLT11 amount {$meltQuote->amount} exceeds expected {$expectedAmount} (+{$tolerance} tol)"
                );
                self::recordMeltFailure($mintUrl, $addressForRecord, $storeId, $e, 'requestMeltQuote', $meltQuoteId, $wallet);
                throw $e;
            }
        }

        try {
            self::assertSaneFeeReserve($meltQuote->amount, $meltQuote->feeReserve);
        } catch (Exception $e) {
            self::recordMeltFailure($mintUrl, $addressForRecord, $storeId, $e, 'requestMeltQuote', $meltQuoteId, $wallet);
            throw $e;
        }

        $totalNeeded = $meltQuote->amount + $meltQuote->feeReserve;

        // Get unspent proofs for this store
        $proofs = Invoice::getUnspentProofs($storeId);
        $balance = Wallet::sumProofs($proofs);

        $mintUnit = Config::getStoreMintUnit($storeId);

        if ($balance < $totalNeeded) {
            // Pre-flight; not a mint or wallet fault — see MintReliability.
            throw new Exception("Insufficient balance. Have: {$balance} {$mintUnit}, Need: {$totalNeeded} {$mintUnit}");
        }

        // Select proofs (covering their own NUT-02 input fee on fee mints)
        $selectedProofs = $wallet->selectProofsWithFees($proofs, $totalNeeded);
        $inputAmount = Wallet::sumProofs($selectedProofs);

        // Write-ahead hook: fired with the mint melt-quote id right before the
        // proofs are spent, so the caller can persist a pending intent that is
        // resolvable against this quote if we crash mid-melt. Keep it from
        // aborting the melt — a bookkeeping failure must not strand funds.
        if ($onQuoteReady !== null) {
            try {
                $onQuoteReady((string)$meltQuote->quote);
            } catch (\Throwable $e) {
                error_log('meltToBolt11 onQuoteReady hook failed: ' . $e->getMessage());
            }
        }

        // Execute melt
        try {
            $result = $wallet->melt($meltQuote->quote, $selectedProofs);
        } catch (Exception $e) {
            self::recordMeltFailure($mintUrl, $addressForRecord, $storeId, $e, 'melt', $meltQuoteId, $wallet);
            throw $e;
        }

        if (!$result['paid']) {
            $msg = ($result['pending'] ?? false)
                ? 'Lightning payment pending - proofs marked as pending for recovery'
                : 'Lightning payment failed';
            $e = new Exception($msg);
            self::recordMeltFailure($mintUrl, $addressForRecord, $storeId, $e, 'melt', $meltQuoteId, $wallet);
            throw $e;
        }

        if ($mintUrl !== null && $mintUrl !== '') {
            MintReliability::recordWithdrawSuccess($mintUrl, null, $storeId);
        }

        self::noteChangeRecoveryPending($storeId, $addressForRecord, $meltQuoteId, $result);
        $changeAmount = Wallet::sumProofs($result['change'] ?? []);
        return [
            'success' => true,
            'preimage' => $result['preimage'],
            'amountPaid' => $meltQuote->amount,
            'fee' => self::meltFee($inputAmount, $changeAmount, $meltQuote->amount),
            'changeAmount' => $changeAmount,
            'changeRecoveryPending' => (bool)($result['changeRecoveryPending'] ?? false),
        ];
    }

    /**
     * Withdraw to a typed destination from the ordered chain, dispatching on
     * type so callers can walk a mixed list of Lightning addresses, NWC
     * connections, and CLINK noffers uniformly.
     *
     *   lnaddress → meltToAddress (LNURL-pay resolution).
     *   nwc       → ask the merchant's own wallet for a BOLT11 over Nostr
     *               Wallet Connect (make_invoice), then melt to that one-shot
     *               invoice. Only invoice creation is requested of the wallet;
     *               the mint pays it, and the cashu melt preimage is the proof
     *               of payment — no lookup_invoice needed on this side.
     *   noffer    → ask the noffer's Nostr service for a BOLT11 (acting as the
     *               payer), then melt to that one-shot invoice. The cashu melt
     *               preimage is the proof of payment, so noffer withdrawal needs
     *               no kind-21001 receipt — that's only for the receive side.
     *   strike    → create a BTC invoice in the merchant's Strike account via
     *               the API key and melt to its quoted BOLT11 — the receive-
     *               only scopes suffice (funds flow INTO the account). The
     *               cashu melt preimage is the proof of payment, so no Strike
     *               read-back is needed on this side.
     *
     * Returns the same shape as meltToAddress/meltToBolt11. Throws on failure
     * (the chain-walking callers catch per-attempt and fall through).
     *
     * @param array{type:string,value:string} $dest
     */
    public static function meltToDestination(string $storeId, array $dest, int $amountSats, ?string $comment = null): array {
        $type = $dest['type'] ?? StoreLnAddresses::TYPE_LNADDRESS;
        $value = (string)($dest['value'] ?? '');
        if ($type === StoreLnAddresses::TYPE_NOFFER) {
            // Resolve a fresh invoice for exactly this amount, then pay it.
            $resolved = ClinkClient::requestInvoice($value, $amountSats, $comment);
            return self::meltToBolt11($storeId, $resolved['bolt11'], $amountSats);
        }
        if ($type === StoreLnAddresses::TYPE_NWC) {
            // makeInvoice verifies the returned bolt11 encodes exactly
            // $amountSats; meltToBolt11's expectedAmount bound re-checks
            // against the mint's melt quote before proofs are spent.
            $made = NwcClient::makeInvoice($value, $amountSats, $comment);
            return self::meltToBolt11($storeId, $made['bolt11'], $amountSats);
        }
        if ($type === StoreLnAddresses::TYPE_STRIKE) {
            // createInvoiceWithQuote verifies the quote is sat-exact for
            // $amountSats; meltToBolt11's expectedAmount bound re-checks
            // against the mint's melt quote before proofs are spent.
            require_once __DIR__ . '/strike/client.php';
            $made = StrikeClient::createInvoiceWithQuote($value, $amountSats, $comment);
            $result = self::meltToBolt11($storeId, $made['bolt11'], $amountSats);
            // Ride Strike's invoice id along so cashout call sites can log
            // it (melts.strike_invoice_id) for reconciliation in the
            // merchant's Strike dashboard.
            $result['strike_invoice_id'] = $made['invoice_id'];
            return $result;
        }
        return self::meltToAddress($storeId, $value, $amountSats, $comment);
    }
}
