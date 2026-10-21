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
require_once __DIR__ . '/locks.php';
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
     * which is then paid with fiat proofs. A caller holding an amount in the
     * store's mint unit (msat, cents) uses meltToAddressInMintUnit() instead —
     * passing an msat amount here asks for 1000x the intended payment.
     *
     * @param string $storeId Store ID for wallet access
     * @param string $address Lightning address
     * @param int $amountSats Amount in SATOSHIS (Lightning is always sats)
     * @param string|null $comment Optional comment
     * @param array|null $account ['mint_url' => …, 'unit' => …] of one of the store's
     *                            wallet accounts to pay from; null = the primary
     */
    public static function meltToAddress(
        string $storeId,
        string $address,
        int $amountSats,
        ?string $comment = null,
        string $transferType = Transfer::TYPE_LIGHTNING_ADDRESS,
        ?array $account = null
    ): array {
        if ($amountSats < 1) {
            throw new Exception('The amount to send is too small.');
        }
        // Refuse before asking the address for an invoice: nothing should be set in
        // motion while an earlier withdrawal may still be paying out.
        self::assertNoPendingWithdrawal($storeId);
        $account = self::resolveAccount($storeId, $account);

        // Get invoice from Lightning address. The library verifies that the returned
        // invoice is for the amount we asked for.
        $bolt11 = CashuLightningAddress::getInvoice($address, $amountSats, $comment);

        return self::executeMelt($storeId, $bolt11, $address, $transferType, $account);
    }

    /**
     * meltToAddress() for an amount in the paying account's own unit (sat, msat, cents).
     *
     * @param int $amountMintUnit Amount in the smallest unit of the account's mint unit
     */
    public static function meltToAddressInMintUnit(
        string $storeId,
        string $address,
        int $amountMintUnit,
        ?string $comment = null,
        string $transferType = Transfer::TYPE_LIGHTNING_ADDRESS,
        ?array $account = null
    ): array {
        $account = self::resolveAccount($storeId, $account);
        $amountSats = self::mintAmountToSats($storeId, $amountMintUnit, $account['unit']);
        return self::meltToAddress($storeId, $address, $amountSats, $comment, $transferType, $account);
    }

    /**
     * Convert an amount in a mint unit to whole satoshis for a Lightning request.
     *
     * Rounds down for msat: asking for more than the balance covers fails the melt.
     * Fiat units go through the store's configured price providers.
     */
    public static function mintAmountToSats(string $storeId, int $amount, ?string $unit = null): int {
        $unit = strtolower($unit ?? Config::getStoreMintUnit($storeId));
        if ($unit === 'sat' || $unit === 'sats') {
            return $amount;
        }
        if ($unit === 'msat') {
            return intdiv($amount, 1000);
        }
        $providers = Config::getStorePriceProviders($storeId);
        return ExchangeRates::convertMintUnitToSats(
            $amount,
            $unit,
            $providers['primary'] ?? null,
            $providers['secondary'] ?? null
        );
    }

    /**
     * The wallet account to pay from: one of the store's own, never an arbitrary mint.
     *
     * @param array|null $account ['mint_url' => …, 'unit' => …], or null for the primary
     * @return array{mint_url: string, unit: string, primary: bool}
     */
    public static function resolveAccount(string $storeId, ?array $account = null): array {
        $accounts = Config::getStoreWalletAccounts($storeId);
        if (empty($accounts)) {
            throw new Exception('This store has no wallet set up yet.');
        }
        if ($account === null || empty($account['mint_url'])) {
            foreach ($accounts as $candidate) {
                if (!empty($candidate['primary'])) {
                    return $candidate;
                }
            }
            return $accounts[0];
        }
        $mintUrl = rtrim((string)$account['mint_url'], '/');
        $unit = strtolower((string)($account['unit'] ?? Config::getStoreMintUnit($storeId)));
        foreach ($accounts as $candidate) {
            if ($candidate['mint_url'] === $mintUrl && $candidate['unit'] === $unit) {
                return $candidate;
            }
        }
        throw new Exception('That mint is not one of this store\'s wallets.');
    }

    /**
     * Refuse to start a withdrawal while an earlier one is unresolved.
     *
     * The lock in front of every withdrawal stops two at the same moment; this stops the
     * next one. A retry asks for a fresh invoice, so it is a new payment, and if the
     * first one completes as well the destination is paid twice.
     */
    public static function assertNoPendingWithdrawal(string $storeId): void {
        if (Transfer::hasPendingWithdrawal($storeId)) {
            throw new Exception(
                'A previous withdrawal is still being confirmed by the mint. Wait for it to '
                . 'finish before sending another — sending again could pay twice.'
            );
        }
    }

    /**
     * Request a melt quote, record the intent, and pay it.
     *
     * The ledger row is written *before* the melt, because a Lightning payment can
     * complete after its HTTP response is lost. Recording only on success meant a
     * settled withdrawal could vanish from accounting while the operator saw an error
     * and retried — paying twice.
     *
     * Takes no lock itself: callers hold 'funds_<storeId>' (admin's manual withdrawal,
     * checkAutoMelt), and a second flock on the same file from this process would fail.
     *
     * @param array{mint_url: string, unit: string} $account
     */
    private static function executeMelt(
        string $storeId,
        string $bolt11,
        ?string $destination,
        string $transferType,
        ?array $account = null
    ): array {
        self::assertNoPendingWithdrawal($storeId);
        $account = self::resolveAccount($storeId, $account);
        $mintUnit = $account['unit'];

        $wallet = Invoice::getWalletForStore($storeId, $account['mint_url'], $mintUnit);
        $meltQuote = $wallet->requestMeltQuote($bolt11);
        $totalNeeded = $meltQuote->amount + $meltQuote->feeReserve;

        $proofs = $wallet->getStorage()->getProofsAsObjects(ProofState::UNSPENT);
        $balance = Wallet::sumProofs($proofs);

        if ($balance < $totalNeeded) {
            throw new Exception("Insufficient balance. Have: {$balance} {$mintUnit}, Need: {$totalNeeded} {$mintUnit}");
        }

        // Cover the NUT-02 input fee the selection itself incurs, not just the quote.
        $selectedProofs = $wallet->selectProofsWithFees($proofs, $totalNeeded);
        $inputAmount = Wallet::sumProofs($selectedProofs);
        $inputSecrets = array_map(fn($p) => $p->secret, $selectedProofs);

        // Throws if the row cannot be written; nothing has been sent at that point.
        // The inputs are recorded with the quote: if the mint later forgets the quote,
        // their state is the only remaining evidence of whether the payment went out.
        $transferId = Transfer::open(
            $storeId,
            $transferType,
            $meltQuote->amount,
            $mintUnit,
            $destination,
            Transfer::meltReference($meltQuote->quote, $inputSecrets),
            'Melt quote ' . $meltQuote->quote,
            null,
            $account['mint_url']
        );

        try {
            $result = $wallet->melt($meltQuote->quote, $selectedProofs);
        } catch (Throwable $e) {
            // The library keeps a journal (melt:<quote>) from just before it sends the
            // payment until the outcome is known. No journal after a throw means the
            // request was never sent, or the mint refused it and the inputs were handed
            // back: nothing left. Otherwise the outcome is ambiguous and recovery decides,
            // so leave the row pending rather than calling it a failure.
            $journal = false;
            try {
                $journal = $wallet->getStorage()->getPendingOperationById('melt:' . $meltQuote->quote) !== null;
            } catch (Throwable $ignored) {
                $journal = true; // cannot tell: assume in flight
            }
            if ($journal) {
                Transfer::note($transferId, $e->getMessage());
            } else {
                Transfer::fail($transferId, $e->getMessage());
            }
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
            'mintUrl' => $account['mint_url'],
            'unit' => $mintUnit,
        ];
    }

    /**
     * Check and perform auto-melt for all stores with auto-melt enabled.
     *
     * Each store is withdrawn under its 'funds_<storeId>' lock — the same lock manual
     * withdrawal, export and reclaim take — so the cron and the operator never select
     * the same proofs. A store whose lock is held, or which has a withdrawal still
     * being confirmed, is skipped this time and tried again on a later run.
     *
     * Funds can sit at several of the store's mints (a backup, or a former primary
     * after a mint change). A melt can only spend proofs from one mint, so each
     * account with the primary's unit is withdrawn on its own: every account at or
     * above the threshold, or — when only the total reaches it — the largest one.
     *
     * @return array<int, array<string, mixed>>|null one entry per withdrawal attempt or skip
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
            $base = ['store_id' => $store['id'], 'store_name' => $store['name']];
            try {
                $accounts = self::autoMeltAccounts($store);
                if (empty($accounts)) {
                    continue;
                }

                $lock = cashupay_acquire_lock('funds_' . $store['id']);
                if (!$lock) {
                    $results[] = $base + [
                        'success' => false,
                        'skipped' => 'busy',
                        'message' => 'Another withdrawal or export is in progress; trying again later.',
                    ];
                    continue;
                }
                try {
                    foreach ($accounts as $account) {
                        if (Transfer::hasPendingWithdrawal($store['id'])) {
                            $results[] = $base + [
                                'success' => false,
                                'skipped' => 'pending',
                                'message' => 'A previous withdrawal is still being confirmed by the mint; '
                                    . 'waiting for it before sending another.',
                            ];
                            break;
                        }
                        $entry = self::autoMeltAccount($store, $account);
                        if ($entry !== null) {
                            $results[] = $base + $entry;
                        }
                    }
                } finally {
                    cashupay_release_lock($lock);
                }
            } catch (Exception $e) {
                error_log("Auto-melt check failed for store {$store['id']}: " . $e->getMessage());
                $results[] = $base + [
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return empty($results) ? null : $results;
    }

    /**
     * The store's accounts due for withdrawal, largest first, each with its local balance.
     *
     * Only accounts in the primary's unit count toward the threshold: the threshold is
     * set in that unit, and adding a backup's euro cents to a sat balance means nothing.
     *
     * @return array<int, array{mint_url: string, unit: string, balance: int}>
     */
    private static function autoMeltAccounts(array $store): array {
        $primaryUnit = strtolower($store['mint_unit'] ?? 'sat');
        $accountId = Config::getStore($store['id'])['wallet_account_id'] ?? null;
        if (empty($accountId)) {
            return [];
        }

        $accounts = [];
        $total = 0;
        foreach (Config::getStoreWalletAccounts($store['id']) as $account) {
            if ($account['unit'] !== $primaryUnit) {
                continue;
            }
            // Offline-first: local storage, no mint contact.
            $balance = (new \Cashu\WalletStorage(
                Database::getDbPath(),
                $account['mint_url'],
                $account['unit'],
                $accountId
            ))->getBalance();
            if ($balance <= 0) {
                continue;
            }
            $accounts[] = ['mint_url' => $account['mint_url'], 'unit' => $account['unit'], 'balance' => $balance];
            $total += $balance;
        }

        $threshold = (int)$store['auto_melt_threshold'];
        if ($total < $threshold || empty($accounts)) {
            return [];
        }
        usort($accounts, fn($a, $b) => $b['balance'] <=> $a['balance']);

        $due = array_values(array_filter($accounts, fn($a) => $a['balance'] >= $threshold));
        // Spread across mints, none alone over the threshold: withdraw the largest.
        return empty($due) ? [$accounts[0]] : $due;
    }

    /**
     * Withdraw one account's balance (minus donation and fee buffer) to the address.
     *
     * @return array<string, mixed>|null result entry, or null when there is too little to send
     */
    private static function autoMeltAccount(array $store, array $account): ?array {
        $mintUnit = $account['unit'];
        $balance = (int)$account['balance'];

        // Calculate donation (in mint units)
        $donationAmount = Donation::calculateAmount($balance);
        $meltAmountInMintUnit = $balance - $donationAmount;
        if ($meltAmountInMintUnit < 1) {
            return null;
        }

        // Lightning is denominated in sats. msat is not sats: treating an msat balance
        // as sats requested a Lightning payment 1000x the merchant's actual balance.
        if ($mintUnit === 'sat' || $mintUnit === 'sats') {
            $meltAmountSats = $meltAmountInMintUnit;
        } elseif ($mintUnit === 'msat') {
            // Round down: we must not ask for more than the balance covers.
            $meltAmountSats = intdiv($meltAmountInMintUnit, 1000);
        } else {
            $meltAmountSats = ExchangeRates::convertMintUnitToSats(
                $meltAmountInMintUnit,
                $mintUnit,
                $store['price_provider_primary'] ?? null,
                $store['price_provider_secondary'] ?? null,
                // The invoice path adds the operator's conversion fee; a payout deducts it,
                // so the same setting cannot silently disappear on the way out (MINIMAX 4.5).
                (float)($store['exchange_fee_percent'] ?? 0)
            );
        }

        $convertedSats = $meltAmountSats;
        $ratePerSat = $convertedSats > 0 ? $meltAmountInMintUnit / $convertedSats : 0.0;

        // Account for Lightning fee reserve - mints charge fees on top of invoice amount
        // Use 1% fee buffer (min 2 sats, max 100 sats) to ensure we have enough for fees
        $feeBuffer = max(2, min(100, (int)ceil($meltAmountSats * 0.01)));
        $meltAmountSats = $meltAmountSats - $feeBuffer;
        if ($meltAmountSats < 1) {
            return null;
        }

        try {
            self::meltToAddress(
                $store['id'],
                $store['auto_melt_address'],
                $meltAmountSats,
                'CashuPayServer auto-withdrawal',
                Transfer::TYPE_AUTO_WITHDRAW,
                $account
            );
        } catch (Exception $meltError) {
            // Mint unreachable, insufficient funds, … Log and carry on with other stores.
            error_log("Auto-melt operation failed for store {$store['id']} at {$account['mint_url']}: " . $meltError->getMessage());
            return [
                'mint_url' => $account['mint_url'],
                'success' => false,
                'error' => $meltError->getMessage(),
            ];
        }

        // The ledger row was opened before the melt and settled by it. Donate only after
        // the withdrawal went out, from the same account.
        $donation = null;
        if ($donationAmount > 0) {
            $donation = Donation::sendToDonationSink($store['id'], $donationAmount, $account);
        }

        error_log("Auto-melt: sent {$meltAmountSats} sats for {$meltAmountInMintUnit} {$mintUnit} "
            . '(rate ' . number_format($ratePerSat, 8, '.', '') . " {$mintUnit}/sat) from store {$store['name']} "
            . "({$account['mint_url']}) to {$store['auto_melt_address']}");

        return [
            'mint_url' => $account['mint_url'],
            'amount' => $meltAmountSats,
            'amountMintUnit' => $meltAmountInMintUnit,
            'mintUnit' => $mintUnit,
            // What the rate was and how many sats it produced, so the operator can audit
            // the payout instead of reconstructing it from a log line.
            'rate' => $ratePerSat,
            'convertedSats' => $convertedSats,
            'donation' => ($donation['success'] ?? false) ? $donationAmount : 0,
            'success' => true,
        ];
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
     * @param array|null $account ['mint_url' => …, 'unit' => …] of one of the store's
     *                            wallet accounts to pay from; null = the primary
     */
    public static function meltToBolt11(
        string $storeId,
        string $bolt11,
        ?int $expectedAmount = null,
        ?array $account = null
    ): array {
        // Amountless invoices need NUT-23 `options.amountless`, which the wallet does not
        // send; the mint answers 11011. Reject them here with a message the operator can
        // act on rather than surfacing a protocol error code.
        if (\Cashu\Bolt11::amountSats($bolt11) === 0) {
            throw new Exception(
                'This invoice has no amount. Amountless invoices are not supported yet — '
                . 'please request an invoice for a specific amount.'
            );
        }

        return self::executeMelt($storeId, $bolt11, $bolt11, Transfer::TYPE_LIGHTNING, $account);
    }
}

/**
 * Donation Class - Send tokens to the donation sink
 */
class Donation {
    /**
     * Send tokens to the donation sink
     *
     * Selects from whatever the account holds now, so it is meant to be called *after*
     * a withdrawal has gone out: the melt's change is already back in the wallet, and a
     * withdrawal that failed costs no donation. The caller holds 'funds_<storeId>'.
     *
     * @param string $storeId Store ID for wallet access
     * @param int $amount Amount to donate, in the account's unit
     * @param array|null $account ['mint_url' => …, 'unit' => …] to donate from; null = primary
     */
    public static function sendToDonationSink(string $storeId, int $amount, ?array $account = null): array {
        if ($amount < 1) {
            return ['success' => false, 'token' => null, 'error' => 'Amount too small'];
        }

        try {
            $account = LightningAddress::resolveAccount($storeId, $account);
            $wallet = Invoice::getWalletForStore($storeId, $account['mint_url'], $account['unit']);
            $proofs = $wallet->getStorage()->getProofsAsObjects(ProofState::UNSPENT);

            // Check proof states at mint - filter out PENDING/SPENT proofs
            if (!empty($proofs)) {
                try {
                    $states = $wallet->checkProofState($proofs);
                    $validProofs = [];
                    $spentSecrets = [];

                    foreach ($states as $i => $state) {
                        $mintState = strtoupper((string)($state['state'] ?? '')); // missing is not UNSPENT
                        if ($mintState === ProofState::UNSPENT) {
                            $validProofs[] = $proofs[$i];
                        } elseif ($mintState === ProofState::SPENT) {
                            $spentSecrets[] = $proofs[$i]->secret;
                        }
                        // Skip PENDING proofs - they can't be used for split
                    }

                    if (!empty($spentSecrets)) {
                        $wallet->getStorage()->updateProofsState($spentSecrets, ProofState::SPENT);
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

            // The fee above is what *we* pay to split the donation off. There is a second
            // fee, and it is the one that actually matters: NUT-02 mints charge per input
            // proof, so whoever receives the token pays to swap it. On a mint charging
            // 100 ppk a 1 sat donation costs 1 sat to redeem and nets its recipient
            // nothing — the sink cannot accept it, we cannot take it back on its own
            // either, and the retry loop would run forever. Don't create it.
            $redeemFee = self::redeemFeeFor((int)$amount, $wallet->getInputFeePpk());
            if ($redeemFee >= $amount) {
                return [
                    'success' => false,
                    'token' => null,
                    'error' => 'Donation of ' . (int)$amount . ' would cost more than that to '
                        . 'cash in at this mint, so it would never arrive. Skipped; the money '
                        . 'stays in your balance.',
                ];
            }

            $result = $wallet->split($proofs, $amount);
            $donationProofs = $result['send'];
            $donationSecrets = array_map(fn($p) => $p->secret, $donationProofs);
            $token = $wallet->serializeToken($donationProofs);

            // Record the intent and its token, then move the proofs to EXPORTED — not
            // SPENT. Marking them spent before the POST destroyed the only copy whenever
            // the sink was unreachable; EXPORTED keeps them out of the spendable pool
            // while leaving something to retry. If the row cannot be written this throws
            // and the token is never sent: the split-off proofs stay in the balance.
            $transferId = Transfer::open(
                $storeId,
                Transfer::TYPE_DONATION,
                (int)$amount,
                $account['unit'],
                self::sinkDescription(),
                implode(',', $donationSecrets),
                null,
                $token,
                $account['mint_url']
            );
            try {
                Invoice::markProofsExported($storeId, $donationSecrets);
            } catch (Throwable $e) {
                // Something else took these proofs first; this token is not ours to give.
                Transfer::fail($transferId, $e->getMessage());
                throw $e;
            }

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
            $payload = ['token' => $token];
            if (defined('CASHUPAY_DONATION_SINK_PROJECT')
                && trim((string)CASHUPAY_DONATION_SINK_PROJECT) !== '') {
                $payload['project'] = (string)CASHUPAY_DONATION_SINK_PROJECT;
            }

            $ch = curl_init(CASHUPAY_DONATION_SINK_URL);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload),
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
        // A configured 0 % means no donation, not the 1-unit floor below.
        if (!defined('CASHUPAY_DONATION_PERCENT') || (float)CASHUPAY_DONATION_PERCENT <= 0 || $amount <= 0) {
            return 0;
        }
        $donationAmount = max(1, (int)floor($amount * CASHUPAY_DONATION_PERCENT / 100));
        return min($donationAmount, (int)floor($amount * 0.1));
    }

    /**
     * What the recipient of a donation of $amount will pay to swap it.
     *
     * A donation is handed over as ecash, and ecash is redeemed by swapping it, which
     * costs one fee per input proof (NUT-02). The proof count is fixed by the amount:
     * it decomposes into powers of two, so 1 sat is one proof and 18 sat is two.
     *
     * Every place that decides whether an amount is worth handing out as ecash (the
     * withdrawal donation, the export's inline donation) asks this, so they agree.
     *
     * @param int $ppk The mint's input fee in parts per thousand per proof (NUT-02)
     */
    public static function redeemFeeFor(int $amount, int $ppk): int {
        if ($amount <= 0 || $ppk <= 0) {
            return 0;
        }
        return (int)ceil(count(Wallet::splitAmount($amount)) * $ppk / 1000);
    }

    /** Would $amount cost at least as much to redeem at this ppk as it is worth? */
    public static function isDust(int $amount, int $ppk): bool {
        return $amount <= 0 || self::redeemFeeFor($amount, $ppk) >= $amount;
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
