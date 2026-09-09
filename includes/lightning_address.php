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
require_once __DIR__ . '/transfer.php';
require_once __DIR__ . '/../cashu-wallet-php/CashuWallet.php';

use Cashu\Wallet;
use Cashu\Proof;
use Cashu\ProofState;
use Cashu\LightningAddress as CashuLightningAddress;

class LightningAddress {
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
    public static function meltToAddress(
        string $storeId,
        string $address,
        int $amountSats,
        ?string $comment = null,
        string $transferType = Transfer::TYPE_LIGHTNING_ADDRESS
    ): array {
        // Get invoice from Lightning address. The library verifies that the returned
        // invoice is for the amount we asked for.
        $bolt11 = CashuLightningAddress::getInvoice($address, $amountSats, $comment);

        return self::executeMelt($storeId, $bolt11, $address, $transferType);
    }

    /**
     * Request a melt quote, record the intent, and pay it.
     *
     * The ledger row is written *before* the melt, because a Lightning payment can
     * complete after its HTTP response is lost. Recording only on success meant a
     * settled withdrawal could vanish from accounting while the operator saw an error
     * and retried — paying twice.
     */
    private static function executeMelt(
        string $storeId,
        string $bolt11,
        ?string $destination,
        string $transferType
    ): array {
        $wallet = Invoice::getWalletInstance($storeId);
        $meltQuote = $wallet->requestMeltQuote($bolt11);
        $totalNeeded = $meltQuote->amount + $meltQuote->feeReserve;

        $proofs = Invoice::getUnspentProofs($storeId);
        $balance = Wallet::sumProofs($proofs);
        $mintUnit = Config::getStoreMintUnit($storeId);

        if ($balance < $totalNeeded) {
            throw new Exception("Insufficient balance. Have: {$balance} {$mintUnit}, Need: {$totalNeeded} {$mintUnit}");
        }

        // Cover the NUT-02 input fee the selection itself incurs, not just the quote.
        $selectedProofs = $wallet->selectProofsWithFees($proofs, $totalNeeded);
        $inputAmount = Wallet::sumProofs($selectedProofs);

        $transferId = Transfer::open(
            $storeId,
            $transferType,
            $meltQuote->amount,
            $mintUnit,
            $destination,
            $meltQuote->quote,
            'Melt quote ' . $meltQuote->quote
        );

        try {
            $result = $wallet->melt($meltQuote->quote, $selectedProofs);
        } catch (Throwable $e) {
            // The quote stays in the library journal; recovery decides its fate. Leave
            // the ledger row pending rather than calling an ambiguous melt a failure.
            Transfer::note($transferId, $e->getMessage());
            throw $e;
        }

        if (!$result['paid']) {
            // Pending or unpaid: the row stays pending and names the quote, so recovery
            // (and the operator) can resume this payment instead of starting a new one.
            throw new Exception(
                "Lightning payment did not complete (melt quote {$meltQuote->quote}). "
                . "It is recorded as pending; do not retry until it resolves."
            );
        }

        $changeAmount = Wallet::sumProofs($result['change'] ?? []);
        // Real cost = what left minus what came back. feeReserve - change went negative
        // whenever proof denominations over-covered the target.
        $fee = $inputAmount - $changeAmount - $meltQuote->amount;

        Transfer::complete(
            $transferId,
            $meltQuote->amount,
            max(0, $fee),
            $result['preimage'] ?? null
        );

        return [
            'success' => true,
            'preimage' => $result['preimage'],
            'amountPaid' => $meltQuote->amount,
            'fee' => $fee,
            'changeAmount' => $changeAmount,
            'quote' => $meltQuote->quote,
            'transferId' => $transferId,
        ];
    }

    /**
     * Check and perform auto-melt for all stores with auto-melt enabled
     * Called on each admin page load to check if any stores need auto-withdrawal
     */
    public static function checkAutoMelt(): ?array {
        // Get all stores with auto-melt enabled
        $stores = Database::fetchAll(
            "SELECT id, name, mint_url, mint_unit, auto_melt_address, auto_melt_threshold,
                    price_provider_primary, price_provider_secondary
             FROM stores
             WHERE auto_melt_enabled = 1
               AND auto_melt_address IS NOT NULL
               AND auto_melt_address != ''
               AND mint_url IS NOT NULL
               AND seed_phrase IS NOT NULL"
        );

        if (empty($stores)) {
            return null;
        }

        $results = [];

        foreach ($stores as $store) {
            try {
                // Check store balance from local storage (offline-first, no mint contact)
                // This prevents crashes when mint is unreachable
                $balance = Invoice::getBalance($store['id']);
                $mintUnit = strtolower($store['mint_unit'] ?? 'sat');
                $isFiatMint = !in_array($mintUnit, ['sat', 'sats', 'msat']);

                if ($balance >= $store['auto_melt_threshold']) {
                    // Calculate donation (in mint units)
                    $donationAmount = Donation::calculateAmount($balance);
                    $meltAmountInMintUnit = $balance - $donationAmount;

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

                    // Perform melt to Lightning address (amount in SATS)
                    // This is wrapped in try-catch to handle mint failures gracefully
                    try {
                        $result = self::meltToAddress(
                            $store['id'],
                            $store['auto_melt_address'],
                            $meltAmountSats,
                            'CashuPayServer auto-withdrawal',
                            Transfer::TYPE_AUTO_WITHDRAW
                        );

                        // The ledger row was opened before the melt and settled by it.

                        // Send donation if configured (in mint units)
                        if ($donationAmount > 0) {
                            Donation::sendToDonationSink($store['id'], $donationAmount);
                        }

                        $results[] = [
                            'store_id' => $store['id'],
                            'store_name' => $store['name'],
                            'amount' => $meltAmountSats,
                            'amountMintUnit' => $meltAmountInMintUnit,
                            'mintUnit' => $mintUnit,
                            'donation' => $donationAmount,
                            'success' => true,
                        ];

                        error_log("Auto-melt: Sent {$meltAmountSats} sats (~{$meltAmountInMintUnit} {$mintUnit}) from store {$store['name']} to {$store['auto_melt_address']}");
                    } catch (Exception $meltError) {
                        // Melt operation failed (mint unreachable, insufficient funds, etc.)
                        // Log and continue - don't crash the entire admin page load
                        error_log("Auto-melt operation failed for store {$store['id']}: " . $meltError->getMessage());
                        $results[] = [
                            'store_id' => $store['id'],
                            'store_name' => $store['name'],
                            'success' => false,
                            'error' => $meltError->getMessage(),
                        ];
                    }
                }
            } catch (Exception $e) {
                error_log("Auto-melt check failed for store {$store['id']}: " . $e->getMessage());
                $results[] = [
                    'store_id' => $store['id'],
                    'store_name' => $store['name'],
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
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
     * Melt tokens to a BOLT-11 invoice
     *
     * @param string $storeId Store ID for wallet access
     * @param string $bolt11 The BOLT-11 invoice
     * @param int|null $expectedAmount Optional expected amount (for amountless invoices)
     */
    public static function meltToBolt11(string $storeId, string $bolt11, ?int $expectedAmount = null): array {
        // Amountless invoices need NUT-23 `options.amountless`, which the wallet does not
        // send; the mint answers 11011. Reject them here with a message the operator can
        // act on rather than surfacing a protocol error code.
        if (\Cashu\Bolt11::amountSats($bolt11) === 0) {
            throw new Exception(
                'This invoice has no amount. Amountless invoices are not supported yet — '
                . 'please request an invoice for a specific amount.'
            );
        }

        return self::executeMelt($storeId, $bolt11, $bolt11, Transfer::TYPE_LIGHTNING);
    }
}

/**
 * Donation Class - Send tokens to the donation sink
 */
class Donation {
    /**
     * Send tokens to the donation sink
     *
     * @param string $storeId Store ID for wallet access
     * @param int $amount Amount to donate
     */
    public static function sendToDonationSink(string $storeId, int $amount): array {
        if ($amount < 1) {
            return ['success' => false, 'token' => null, 'error' => 'Amount too small'];
        }

        try {
            $wallet = Invoice::getWalletInstance($storeId);
            $proofs = Invoice::getUnspentProofs($storeId);

            // Check proof states at mint - filter out PENDING/SPENT proofs
            if (!empty($proofs)) {
                try {
                    $states = $wallet->checkProofState($proofs);
                    $validProofs = [];
                    $spentSecrets = [];

                    foreach ($states as $i => $state) {
                        $mintState = $state['state'] ?? ProofState::UNSPENT;
                        if ($mintState === ProofState::UNSPENT) {
                            $validProofs[] = $proofs[$i];
                        } elseif ($mintState === ProofState::SPENT) {
                            $spentSecrets[] = $proofs[$i]->secret;
                        }
                        // Skip PENDING proofs - they can't be used for split
                    }

                    if (!empty($spentSecrets)) {
                        Invoice::markProofsSpent($storeId, $spentSecrets);
                    }

                    $proofs = $validProofs;
                } catch (\Cashu\CashuException $e) {
                    error_log("Donation checkProofState failed: " . $e->getMessage());
                }
            }

            $balance = Wallet::sumProofs($proofs);

            $fee = $wallet->calculateFee($proofs);
            $totalNeeded = $amount + $fee;

            if ($balance < $totalNeeded) {
                return ['success' => false, 'token' => null, 'error' => 'Insufficient balance for donation'];
            }

            if ($fee > $amount) {
                return ['success' => false, 'token' => null, 'error' => 'Fee exceeds donation amount'];
            }

            $result = $wallet->split($proofs, $amount);
            $donationProofs = $result['send'];
            $donationSecrets = array_map(fn($p) => $p->secret, $donationProofs);
            $token = $wallet->serializeToken($donationProofs);

            // Record the intent and its token, then move the proofs to EXPORTED — not
            // SPENT. Marking them spent before the POST destroyed the only copy whenever
            // the sink was unreachable; EXPORTED keeps them out of the spendable pool
            // while leaving something to retry.
            $transferId = Transfer::open(
                $storeId,
                Transfer::TYPE_DONATION,
                (int)$amount,
                Config::getStoreMintUnit($storeId),
                self::sinkDescription(),
                implode(',', $donationSecrets),
                null,
                $token
            );
            Invoice::markProofsExported($storeId, $donationSecrets);

            if (!self::postTokenToSink($token)) {
                return [
                    'success' => false,
                    'token' => $token,
                    'transferId' => $transferId,
                    'error' => 'Donation sink did not acknowledge; the token is stored and will be retried',
                ];
            }

            Transfer::complete($transferId, (int)$amount);
            $wallet->getStorage()->updateProofsState($donationSecrets, ProofState::SPENT);

            return ['success' => true, 'token' => $token, 'transferId' => $transferId, 'error' => null];

        } catch (Exception $e) {
            error_log("Donation error: " . $e->getMessage());
            return ['success' => false, 'token' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Select proofs summing to exactly $amount, or null when no exact subset exists.
     *
     * Offline donations cannot make change, so "close enough" means overpaying with
     * whatever proof happened to be next: a 1 % donation on a 64-sat export used to
     * send a whole 32-sat proof.
     *
     * @param \Cashu\Proof[] $proofs
     * @return \Cashu\Proof[]|null
     */
    public static function selectExact(array $proofs, int $amount): ?array {
        if ($amount < 1) {
            return null;
        }

        // Denominations are powers of two and donations are small, so a greedy
        // largest-first pass over proofs no larger than the target is exact whenever an
        // exact subset exists.
        $candidates = array_values(array_filter($proofs, fn($p) => $p->amount <= $amount));
        usort($candidates, fn($a, $b) => $b->amount <=> $a->amount);

        $selected = [];
        $remaining = $amount;
        foreach ($candidates as $proof) {
            if ($proof->amount <= $remaining) {
                $selected[] = $proof;
                $remaining -= $proof->amount;
                if ($remaining === 0) {
                    return $selected;
                }
            }
        }

        return null;
    }

    /** Human-readable destination recorded in the transfer ledger. */
    public static function sinkDescription(): string {
        return defined('CASHUPAY_DONATION_SINK_URL')
            ? (string)CASHUPAY_DONATION_SINK_URL
            : 'CashuPayServer donation';
    }

    /**
     * POST a token to the donation sink.
     *
     * @return bool True only when the sink acknowledged with 2xx. A false result means
     *              the token may or may not have arrived, so the caller must keep it.
     */
    public static function postTokenToSink(string $token): bool {
        if (!defined('CASHUPAY_DONATION_SINK_URL')) {
            error_log("Donation sink URL not configured");
            return false;
        }

        try {
            $ch = curl_init(CASHUPAY_DONATION_SINK_URL);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode(['token' => $token]),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if (curl_errno($ch)) {
                error_log("Donation sink POST failed: " . curl_error($ch));
                return false;
            }
            if ($httpCode < 200 || $httpCode >= 300) {
                error_log("Donation sink returned HTTP {$httpCode}: {$response}");
                return false;
            }
            return true;

        } catch (Throwable $e) {
            error_log("Donation sink error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Calculate donation amount from a given withdrawal amount
     */
    public static function calculateAmount(int $amount): int {
        if (!defined('CASHUPAY_DONATION_PERCENT')) {
            return 0;
        }
        $donationAmount = max(1, (int)floor($amount * CASHUPAY_DONATION_PERCENT / 100));
        return min($donationAmount, (int)floor($amount * 0.1));
    }

    /**
     * Calculate max withdrawal amount when donation is enabled
     */
    public static function calculateMaxWithdrawal(int $balance): int {
        if (!defined('CASHUPAY_DONATION_PERCENT') || CASHUPAY_DONATION_PERCENT <= 0) {
            return $balance;
        }
        return (int)floor($balance / (1 + CASHUPAY_DONATION_PERCENT / 100));
    }
}
