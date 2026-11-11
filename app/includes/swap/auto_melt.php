<?php
/**
 * Auto-melt via submarine swap.
 *
 * Optional alternative to the Lightning-address auto-melt path
 * ({@see LightningAddress::checkAutoMelt}). When enabled on a store, the
 * cron sweeps the mint balance through a reverse submarine swap that
 * settles directly to the store's on-chain xpub address — useful for
 * operators who don't have (or don't want to expose) an LNURL.
 *
 * Cost gates protect against sweeping at unfavourable rates:
 *   - Static minimum balance (CASHUPAY_AUTO_MELT_SWAP_MIN_SATS, default 5000):
 *     below this we don't even fetch a quote.
 *   - Per-store auto_melt_threshold: kept as an additional floor so an
 *     operator who raised the LN threshold gets matching behaviour here.
 *   - Percent cap (CASHUPAY_AUTO_MELT_SWAP_MAX_FEE_PCT, default 1.0): the
 *     best provider's total fees (percent + lockup + claim estimate) must
 *     be ≤ this percent of the amount being swept. In high-fee environments
 *     this gate will keep the sweep idle until balance is high enough for
 *     the fixed-fee components to amortize.
 *
 * Quote-history rate limiter ({@see swap_quote_history} table) prevents
 * hammering providers when the percent gate is consistently failing:
 *   - At most 1 quote-fetch per store per 24h.
 *   - If 5+ quotes in the rolling 30-day window all fail the percent gate
 *     when re-evaluated against the current balance, skip fetching.
 *
 * The actual swap creation reuses {@see SwapProviderFactory::rankedForStore}
 * (parallel quote fetch + auto-select-cheapest rules) and persists into a
 * dedicated `sweep_attempts` table so the existing {@see SwapPoller} +
 * {@see SwapClaimer} can drive lockup observation, claim broadcast, and
 * status transitions without touching the customer-invoice flow.
 */

require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../invoice.php';
require_once __DIR__ . '/../lightning_address.php';
require_once __DIR__ . '/../rates.php';
require_once __DIR__ . '/../mint_reliability.php';
require_once __DIR__ . '/../notification_sender.php';
require_once __DIR__ . '/../onchain/payments.php';
require_once __DIR__ . '/../crypto/secp256k1.php';
require_once __DIR__ . '/../crypto/taproot.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/quote_fetcher.php';
require_once __DIR__ . '/settlement_context.php';
require_once __DIR__ . '/../../cashu-wallet-php/CashuWallet.php';

use Cashu\Wallet;

/**
 * Sweep-flow settlement: terminal sweep_attempts rows fire a notification
 * (success or failure). No invoice to update.
 */
final class SweepSwapSettlement implements SwapSettlementContext {
    public function tableName(): string { return 'sweep_attempts'; }

    public function onSettled(array $row): void {
        $amount = (int)($row['target_onchain_amount_sats'] ?? 0);
        $destination = $row['merchant_address'] ?? '';
        NotificationSender::queueAutoCashoutSuccess($row['store_id'], $amount, $destination);
        error_log("SwapAutoMelt: sweep {$row['id']} settled — {$amount} sats to {$destination}");
    }

    public function onInvalid(array $row, string $providerStatus, string $message): void {
        $amount = (int)($row['target_onchain_amount_sats'] ?? 0);
        $destination = $row['merchant_address'] ?? '';
        NotificationSender::queueAutoCashoutFailure(
            $row['store_id'],
            $destination,
            "Swap-mode auto-cashout failed: {$providerStatus} — {$message}",
            $amount
        );
        error_log("SwapAutoMelt: sweep {$row['id']} invalid — {$providerStatus}: {$message}");
    }
}

final class SwapAutoMelt {
    // ----- Defaults for the deployment-tunable knobs -----
    public const MIN_SATS_DEFAULT = 5000;
    public const MAX_FEE_PCT_DEFAULT = 1.0;

    // ----- Internal rate-limiter constants (not operator-tunable) -----
    private const HISTORY_RETENTION_DAYS = 30;
    private const HISTORY_MIN_FAILED_TO_SKIP = 5;
    private const QUOTE_COOLDOWN_SECONDS = 86400; // at most 1 fresh quote per 24h per store

    // Headroom on the cashu melt-fee reserve when sizing the sweep target.
    // The mint quote charges fee_reserve sats on top of the invoice amount;
    // we can't know the exact value until we request the quote (which
    // requires the BOLT11, which requires the swap, which requires the
    // target). Nutshell-style mints typically return fee_reserve ≈ 1% of
    // the invoice amount as routing headroom, so reserve 2% up front +
    // 5 sat floor. Any leftover goes to mint melt-fee headroom and is
    // returned to the wallet as change after settlement.
    private const MELT_FEE_RESERVE_PCT = 2.0;
    private const MELT_FEE_RESERVE_MIN = 5;

    // ----- Mode values (legacy -1 "inherit" rows resolve to lightning) -----
    public const INHERIT = -1;
    public const FORCE_LIGHTNING = 0;
    public const FORCE_SWAP = 1;

    /**
     * @return int Static-floor sats. Reads CASHUPAY_AUTO_MELT_SWAP_MIN_SATS if
     *             defined; otherwise {@see MIN_SATS_DEFAULT}.
     */
    public static function minSats(): int {
        if (defined('CASHUPAY_AUTO_MELT_SWAP_MIN_SATS')) {
            $v = (int)CASHUPAY_AUTO_MELT_SWAP_MIN_SATS;
            if ($v > 0) return $v;
        }
        return self::MIN_SATS_DEFAULT;
    }

    /**
     * @return float Percent cap on the swap's total fees relative to the
     *               amount being swept. Reads CASHUPAY_AUTO_MELT_SWAP_MAX_FEE_PCT
     *               if defined; otherwise {@see MAX_FEE_PCT_DEFAULT}.
     */
    public static function maxFeePct(): float {
        if (defined('CASHUPAY_AUTO_MELT_SWAP_MAX_FEE_PCT')) {
            $v = (float)CASHUPAY_AUTO_MELT_SWAP_MAX_FEE_PCT;
            if ($v > 0) return $v;
        }
        return self::MAX_FEE_PCT_DEFAULT;
    }

    /**
     * Resolve the effective auto-melt mode for a store. Only meaningful when
     * the store has auto_melt_enabled = 1; otherwise auto-melt is off entirely.
     *
     * @return 'lightning'|'swap' Effective rail for this store's auto-melt.
     *   Stores that pick swap but lack an xpub (or have swaps force-disabled)
     *   fall back to 'lightning' so the operator is never silently stuck.
     *   Legacy -1 "inherit" rows (from when a site-wide default existed)
     *   resolve to lightning.
     */
    public static function modeForStore(array $store): string {
        $tri = isset($store['auto_melt_use_swap']) ? (int)$store['auto_melt_use_swap'] : self::FORCE_LIGHTNING;
        $picksSwap = ($tri === self::FORCE_SWAP);
        if (!$picksSwap) return 'lightning';
        // Swap mode needs swaps enabled for the store (which requires an xpub
        // in xpub mode — static-address mode is incompatible with swap claims).
        if (!SwapsConfig::isEnabledForStore($store['id'])) return 'lightning';
        $mode = $store['onchain_address_mode'] ?? 'xpub';
        if ($mode !== 'xpub') return 'lightning';
        return 'swap';
    }

    public static function setStoreOverride(string $storeId, int $tri): void {
        if (!in_array($tri, [self::FORCE_LIGHTNING, self::FORCE_SWAP], true)) {
            throw new InvalidArgumentException("Invalid auto_melt_use_swap value: {$tri}");
        }
        Database::query(
            "UPDATE stores SET auto_melt_use_swap = ? WHERE id = ?",
            [$tri, $storeId]
        );
    }

    /**
     * Walk every store with auto-melt enabled in swap mode, gate-check, and
     * (if all gates pass) trigger a sweep. Mirrors the per-store iteration
     * shape of {@see LightningAddress::checkAutoMelt} so the cron can call
     * either / both depending on per-store mode.
     *
     * Returns one row per store touched, suitable for the cron summary JSON.
     */
    public static function checkAndExecute(): ?array {
        // Sweep swaps sign Taproot claim transactions — EC math that needs
        // GMP. Gate the whole rail up front on GMP-less hosts: this runs from
        // cron every minute, and without the gate each eligible store would
        // fail mid-pipeline with a cryptic "undefined function gmp_init".
        require_once __DIR__ . '/../onchain/wallet.php';
        if (($envError = OnchainWallet::environmentError()) !== null) {
            error_log('SwapAutoMelt: skipping swap rail: ' . $envError);
            return null;
        }
        $stores = Database::fetchAll(
            "SELECT s.* FROM stores s
              WHERE auto_melt_enabled = 1
                AND mint_url IS NOT NULL
                AND seed_phrase IS NOT NULL"
        );
        if (empty($stores)) return null;

        $results = [];
        foreach ($stores as $store) {
            if (self::modeForStore($store) !== 'swap') {
                continue; // store wants LN-address rail; LightningAddress::checkAutoMelt handles it
            }
            try {
                $r = self::processStore($store);
                if ($r !== null) $results[] = $r;
            } catch (Throwable $e) {
                error_log("SwapAutoMelt processStore {$store['id']}: " . $e->getMessage());
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
     * Delete swap_quote_history rows older than {@see HISTORY_RETENTION_DAYS}
     * days. Called from cron's non-essential cleanup pass.
     */
    public static function cleanupQuoteHistory(): int {
        $cutoff = time() - (self::HISTORY_RETENTION_DAYS * 24 * 3600);
        return (int) Database::query(
            "DELETE FROM swap_quote_history WHERE fetched_at < ?",
            [$cutoff]
        )->rowCount();
    }

    // ===== Internal pipeline =====

    private static function processStore(array $store): ?array {
        $storeId = $store['id'];

        // Per-store funds lock shared with the dashboard withdraw/export, the
        // LN auto-cashout rail and the fee melt: two of them must never select
        // the same proofs. Busy → skip this tick; a withdrawal the mint is
        // still settling → skip until it resolves (a retry would pay twice).
        require_once __DIR__ . '/../locks.php';
        require_once __DIR__ . '/../lightning_address.php';
        $fundsLock = cashupay_acquire_lock('funds_' . $storeId);
        if (!$fundsLock) {
            return [
                'store_id' => $storeId,
                'store_name' => $store['name'],
                'success' => false,
                'skipped' => 'busy',
            ];
        }
        try {
            try {
                LightningAddress::assertNoPendingWithdrawal($storeId);
            } catch (Exception $pendingErr) {
                return [
                    'store_id' => $storeId,
                    'store_name' => $store['name'],
                    'success' => false,
                    'skipped' => 'pending',
                    'error' => $pendingErr->getMessage(),
                ];
            }
            return self::processStoreLocked($store);
        } finally {
            cashupay_release_lock($fundsLock);
        }
    }

    /** The sweep pipeline proper; runs with the store's funds lock held. */
    private static function processStoreLocked(array $store): ?array {
        $storeId = $store['id'];
        $mintUnit = strtolower($store['mint_unit'] ?? 'sat');
        $isFiatMint = !in_array($mintUnit, ['sat', 'sats', 'msat'], true);

        // 1. Balance pre-flight (offline read, no mint contact).
        $balanceMintUnit = Invoice::getBalance($storeId);
        $balanceSats = $isFiatMint
            ? ExchangeRates::convertMintUnitToSats(
                $balanceMintUnit,
                $mintUnit,
                $store['price_provider_primary'] ?? null,
                $store['price_provider_secondary'] ?? null
              )
            : $balanceMintUnit;

        $floor = max(
            self::minSats(),
            (int)($store['auto_melt_threshold'] ?? 0)
        );
        if ($balanceSats < $floor) {
            return null; // silent: not enough to be worth checking yet
        }

        // 2. Quote-history rate limits.
        if (self::recentQuoteWithinCooldown($storeId)) {
            return null;
        }
        if (self::historicalQuotesAllFail($storeId, $balanceSats)) {
            return [
                'store_id'   => $storeId,
                'store_name' => $store['name'],
                'success'    => false,
                'skipped'    => 'fee_history_exceeds_cap',
                'note'       => "balance {$balanceSats} sats — recent quotes all exceeded "
                              . self::maxFeePct() . '% cap; no fresh quote requested',
            ];
        }

        // 3. Fetch quotes via the existing parallel fetcher / ranking helper.
        // rankedForStore() also applies the auto-select-cheapest rule, so the
        // first entry already reflects the operator's preferred provider with
        // the configured cheapness-undercut threshold honoured.
        $network = $store['onchain_network'] ?? 'mainnet';
        $ranked = SwapProviderFactory::rankedForStore($storeId, $network, $balanceSats);
        if (empty($ranked)) {
            return [
                'store_id'   => $storeId,
                'store_name' => $store['name'],
                'success'    => false,
                'error'      => 'no swap providers configured',
            ];
        }

        // Headroom on the cashu mint's melt fee_reserve: we have to size the
        // swap target so that swap_invoice + mint_fee_reserve ≤ balance, but
        // the fee_reserve is only known after we request a melt quote (which
        // requires the BOLT11, which requires the swap, which requires the
        // target). Reserve a conservative buffer up front; if the actual
        // fee_reserve comes in higher the melt step will catch it.
        $meltFeeBuffer = max(
            self::MELT_FEE_RESERVE_MIN,
            (int)ceil($balanceSats * self::MELT_FEE_RESERVE_PCT / 100.0)
        );

        // Record every quote we observed for the rate-limiter; the threshold
        // check uses the eventual target (= balance − all fees) rather than
        // the raw balance so the percent cap holds against the on-chain
        // amount actually being moved.
        $maxFeePct = self::maxFeePct();
        $now = time();
        $audit = SwapQuoteFetcher::lastAuditTrail();
        $auditByName = [];
        foreach (($audit['providers'] ?? []) as $entry) {
            if (isset($entry['provider'])) $auditByName[$entry['provider']] = $entry;
        }
        foreach ($ranked as $candidate) {
            $name = $candidate['provider']->getName();
            $entry = $auditByName[$name] ?? null;
            if (!$entry || !($entry['reachable'] ?? false)) continue;
            $target = self::solveTargetFromBalance(
                $balanceSats,
                (float)($entry['fee_percent'] ?? 0),
                (int)($entry['lockup_fee_sats'] ?? 0),
                $meltFeeBuffer
            );
            if ($target <= 0) continue;
            $total = (int)ceil($target * (float)($entry['fee_percent'] ?? 0) / 100.0)
                   + (int)($entry['lockup_fee_sats'] ?? 0)
                   + (int)($entry['claim_fee_estimate_sats'] ?? 0);
            $met = ($total > 0) && (($total * 100) <= ($target * $maxFeePct));
            self::recordQuoteHistory($storeId, $name, $network, $entry, $balanceSats, $total, $met, $now);
        }

        // 4. Pick the first ranked candidate that has a usable quote AND
        // satisfies the percent cap once we back-solve the on-chain target.
        // Out-of-range and percent-cap failures are recorded for the auditor.
        $pickedRow = null;
        $skipReasons = [];
        foreach ($ranked as $candidate) {
            $provider = $candidate['provider'];
            $quote = $candidate['quote'];
            $name = $provider->getName();
            if ($quote === null) {
                // Unreachable on this round; the audit row above already
                // captured the failure. Try the next in priority.
                $skipReasons[] = "{$name}: no quote";
                continue;
            }
            // Back-solve target sats given balance + this quote so the
            // resulting swap invoice + mint melt-fee buffer fits in the
            // wallet.
            $targetSats = self::solveTargetFromBalance(
                $balanceSats, $quote->feePercent, $quote->lockupFeeSats, $meltFeeBuffer
            );
            if ($targetSats <= 0) {
                $skipReasons[] = "{$name}: balance {$balanceSats} too small to cover fees";
                continue;
            }
            if ($targetSats < $quote->minSats || $targetSats > $quote->maxSats) {
                $skipReasons[] = "{$name}: target {$targetSats} sat outside [{$quote->minSats}, {$quote->maxSats}]";
                continue;
            }
            $totalCost = SwapQuoteFetcher::totalCostSats($quote, $targetSats);
            // Percent cap: totalCost / target ≤ maxFeePct / 100 (the amount
            // being swapped is the on-chain receive, == targetSats).
            if (($totalCost * 100) > ($targetSats * $maxFeePct)) {
                $skipReasons[] = sprintf(
                    '%s: total fee %d sats > %.2f%% of %d',
                    $name, $totalCost, $maxFeePct, $targetSats
                );
                continue;
            }
            $pickedRow = [
                'provider'    => $provider,
                'quote'       => $quote,
                'total_cost'  => $totalCost,
                'target_sats' => $targetSats,
            ];
            break;
        }

        if ($pickedRow === null) {
            return [
                'store_id'   => $storeId,
                'store_name' => $store['name'],
                'success'    => false,
                'skipped'    => 'fee_above_cap',
                'note'       => 'no provider quote satisfied ' . $maxFeePct . '% cap: '
                              . implode('; ', $skipReasons),
            ];
        }

        // 5. Create the swap with the picked provider, using the back-solved
        // target so swap_invoice + mint_fee_reserve fits in the cashu balance.
        $targetSats = (int)$pickedRow['target_sats'];
        $created = self::createSweepSwap($store, $targetSats, $pickedRow, $network);
        if ($created === null) {
            return [
                'store_id'   => $storeId,
                'store_name' => $store['name'],
                'success'    => false,
                'error'      => 'swap creation failed (see error log)',
            ];
        }

        // 6. Persist the sweep_attempts row before initiating the cashu melt.
        // If the melt step fails we still want a recoverable row pointing at
        // the provider's swap so the operator (or cleanup) can cancel it.
        $sweepId = self::persistSweepAttempt($created, $store, $balanceSats, $pickedRow);

        // 7. Pay the provider's invoice via cashu melt. The provider issues a
        // HOLD invoice — the mint returns paid=true when fully settled, or
        // pending=true while the swap is held.
        //
        // A timeout (or any post-flight failure) does not necessarily mean
        // the LN payment didn't go through: the cashu mint records a pending
        // operation BEFORE the network call, so proofs are marked PENDING
        // and the LND payment may already be in flight. We rely on
        // SwapPoller as the source of truth: it watches Boltz's swap status,
        // and once Boltz reports transaction.mempool we know our LN payment
        // landed. If Boltz times the swap out instead, the row transitions
        // to swap.expired and proofs reconcile back to UNSPENT.
        //
        // Pre-flight failures (the wallet refused before any network call —
        // e.g. {@see meltAgainstInvoice}'s insufficient-balance throw) ARE
        // fatal: cancel the swap and mark the sweep as error so the held
        // HTLC is released and the operator is notified.
        $meltResult = null;
        try {
            $meltResult = self::meltAgainstInvoice($storeId, $created['swap']->invoice, $created['swap']->invoiceAmountSats);
        } catch (Throwable $e) {
            if (self::isPreflightFailure($e)) {
                self::cancelSwapBestEffort($created['provider'], $network, $created['swap']->swapId);
                Database::query(
                    "UPDATE sweep_attempts SET status = 'error', error_message = ?, updated_at = ? WHERE id = ?",
                    [substr('melt failed: ' . $e->getMessage(), 0, 500), time(), $sweepId]
                );
                NotificationSender::queueAutoCashoutFailure(
                    $storeId,
                    $created['merchant_address'],
                    'Swap-mode auto-cashout failed (cashu melt): ' . $e->getMessage(),
                    $balanceSats
                );
                throw $e;
            }
            // Treat any other failure as "melt-in-flight". Record the error
            // for forensics but keep the row in swap.created so SwapPoller
            // can pick it up on the next tick.
            error_log("SwapAutoMelt sweep {$sweepId} melt non-fatal: " . $e->getMessage());
            Database::query(
                "UPDATE sweep_attempts SET error_message = ?, updated_at = ? WHERE id = ?",
                [substr('melt in-flight: ' . $e->getMessage(), 0, 500), time(), $sweepId]
            );
        }

        if (!empty($meltResult['preimage'])) {
            Database::query(
                "UPDATE sweep_attempts SET melt_preimage = ?, updated_at = ? WHERE id = ?",
                [$meltResult['preimage'], time(), $sweepId]
            );
        }

        return [
            'store_id'   => $storeId,
            'store_name' => $store['name'],
            'success'    => true,
            'sweep_id'   => $sweepId,
            'amount'     => $targetSats,
            'provider'   => $created['provider']->getName(),
            'destination'=> $created['merchant_address'],
            'paid'       => $meltResult !== null && (bool)($meltResult['paid'] ?? false),
            'pending'    => $meltResult === null
                            || (bool)($meltResult['pending'] ?? false),
        ];
    }

    /**
     * @return array|null  same shape as Invoice::trySwapCreate's success row,
     *                     plus 'pair_info' for fee recording
     */
    private static function createSweepSwap(array $store, int $targetSats, array $pickedRow, string $network): ?array {
        $provider = $pickedRow['provider'];
        $quote = $pickedRow['quote'];
        $name = $provider->getName();

        $alloc = OnchainPayments::allocateClaimAddress($store['id']);
        if ($alloc === null) {
            error_log("SwapAutoMelt: store {$store['id']} has no claim address (xpub mode required)");
            return null;
        }

        $claimPriv = random_bytes(32);
        $claimPubPoint = Secp256k1::generatorMult(Secp256k1::bytesToNum($claimPriv));
        if ($claimPubPoint === null) {
            error_log("SwapAutoMelt: claim key derivation failed for store {$store['id']}");
            return null;
        }
        $claimPub = Secp256k1::pointToCompressed($claimPubPoint);
        $preimage = random_bytes(32);
        $preimageHash = hash('sha256', $preimage, true);

        try {
            $swap = $provider->createReverseSwap(
                $network,
                $targetSats,
                bin2hex($claimPub),
                bin2hex($preimageHash)
            );
        } catch (Throwable $e) {
            error_log("SwapAutoMelt: createReverseSwap via {$name} failed: " . $e->getMessage());
            return null;
        }

        if (!self::verifySwapLockup($swap, $claimPub, $network)) {
            error_log("SwapAutoMelt: lockup address mismatch from {$name} — aborting sweep");
            self::cancelSwapBestEffort($provider, $network, $swap->swapId);
            return null;
        }

        $lockupFee = $quote->lockupFeeSats;

        // Bound the invoice we're about to pay against the quoted economics.
        // invoiceAmountSats is provider-controlled (response field or BOLT11
        // decode); the percent-cap gate earlier validated the QUOTE, not the
        // issued invoice. Without this, a provider that returns an invoice
        // charging far more than quoted would have us melt up to the whole
        // wallet balance to pay it. Expected max = target + lockup fee +
        // ceil(target * feePercent) + tolerance.
        $expectedMaxInvoice = $targetSats
            + $lockupFee
            + (int)ceil($targetSats * $quote->feePercent / 100.0);
        $tolerance = max(2, (int)ceil($expectedMaxInvoice * 0.005));
        if ($swap->invoiceAmountSats > $expectedMaxInvoice + $tolerance) {
            error_log(sprintf(
                'SwapAutoMelt: %s issued invoice %d sat exceeds quoted max %d (+%d tol) for target %d — aborting sweep',
                $name, $swap->invoiceAmountSats, $expectedMaxInvoice, $tolerance, $targetSats
            ));
            self::cancelSwapBestEffort($provider, $network, $swap->swapId);
            return null;
        }

        $percentFee = max(0, $swap->invoiceAmountSats - $targetSats - $lockupFee);

        return [
            'provider'         => $provider,
            'swap'             => $swap,
            'network'          => $network,
            'claim_privkey'    => $claimPriv,
            'claim_pubkey'     => $claimPub,
            'preimage'         => $preimage,
            'preimage_hash'    => $preimageHash,
            'merchant_address' => $alloc['address'],
            'merchant_address_index' => $alloc['index'],
            'lockup_fee_sats'  => $lockupFee,
            'percent_fee_sats' => $percentFee,
            'quotes_audit'     => SwapQuoteFetcher::lastAuditTrail(),
        ];
    }

    /**
     * Mirror of Invoice::verifySwapLockup — recompute the Taproot output key
     * and compare it against the provider-returned lockup_address.
     */
    private static function verifySwapLockup(SwapCreateResult $swap, string $claimPub33, string $network): bool {
        try {
            $claimLeafHash  = Taproot::tapLeafHash(Taproot::TAPSCRIPT_LEAF_VERSION, $swap->claimLeafScript);
            $refundLeafHash = Taproot::tapLeafHash(Taproot::TAPSCRIPT_LEAF_VERSION, $swap->refundLeafScript);
            $merkleRoot = Taproot::tapBranchHash($claimLeafHash, $refundLeafHash);
            $refundPub33 = hex2bin($swap->refundPublicKeyHex);
            $internalKey = Taproot::keyAggInternalKey([$refundPub33, $claimPub33]);
            [$outKey, $_parity] = Taproot::tweakOutputKey($internalKey, $merkleRoot);
            $expected = Taproot::encodeP2trAddress($outKey, $network);
            return strcasecmp($expected, $swap->lockupAddress) === 0;
        } catch (Throwable $e) {
            error_log('SwapAutoMelt verifySwapLockup threw: ' . $e->getMessage());
            return false;
        }
    }

    private static function persistSweepAttempt(array $created, array $store, int $balanceSats, array $pickedRow): int {
        $swap = $created['swap'];
        $now = time();
        $quotesAuditJson = !empty($created['quotes_audit'])
            ? json_encode($created['quotes_audit'])
            : null;
        Database::getInstance()->prepare(
            "INSERT INTO sweep_attempts (
                store_id, provider, network, direction,
                swap_id_external, status,
                preimage_hex, preimage_hash_hex,
                claim_pubkey_hex, claim_privkey_hex, refund_pubkey_hex,
                lockup_address, timeout_block_height,
                claim_leaf_script_hex, refund_leaf_script_hex,
                lightning_invoice,
                target_onchain_amount_sats, invoice_amount_sats,
                swap_lockup_fee_sats, swap_percent_fee_sats,
                merchant_address, merchant_address_index,
                balance_sats_at_create, quote_total_cost_sats,
                provider_response_json, quotes_compared_json,
                created_at, updated_at
            ) VALUES (
                :store_id, :provider, :network, :direction,
                :swap_id_external, :status,
                :preimage_hex, :preimage_hash_hex,
                :claim_pubkey_hex, :claim_privkey_hex, :refund_pubkey_hex,
                :lockup_address, :timeout_block_height,
                :claim_leaf_script_hex, :refund_leaf_script_hex,
                :lightning_invoice,
                :target_onchain_amount_sats, :invoice_amount_sats,
                :swap_lockup_fee_sats, :swap_percent_fee_sats,
                :merchant_address, :merchant_address_index,
                :balance_sats_at_create, :quote_total_cost_sats,
                :provider_response_json, :quotes_compared_json,
                :created_at, :updated_at
            )"
        )->execute([
            ':store_id' => $store['id'],
            ':provider' => $created['provider']->getName(),
            ':network'  => $created['network'],
            ':direction'=> 'reverse',
            ':swap_id_external' => $swap->swapId,
            ':status'   => 'swap.created',
            ':preimage_hex' => bin2hex($created['preimage']),
            ':preimage_hash_hex' => bin2hex($created['preimage_hash']),
            ':claim_pubkey_hex' => bin2hex($created['claim_pubkey']),
            ':claim_privkey_hex' => bin2hex($created['claim_privkey']),
            ':refund_pubkey_hex' => $swap->refundPublicKeyHex,
            ':lockup_address' => $swap->lockupAddress,
            ':timeout_block_height' => $swap->timeoutBlockHeight,
            ':claim_leaf_script_hex' => bin2hex($swap->claimLeafScript),
            ':refund_leaf_script_hex' => bin2hex($swap->refundLeafScript),
            ':lightning_invoice' => $swap->invoice,
            ':target_onchain_amount_sats' => $swap->onchainAmountSats,
            ':invoice_amount_sats' => $swap->invoiceAmountSats,
            ':swap_lockup_fee_sats' => $created['lockup_fee_sats'],
            ':swap_percent_fee_sats' => $created['percent_fee_sats'],
            ':merchant_address' => $created['merchant_address'],
            ':merchant_address_index' => $created['merchant_address_index'],
            ':balance_sats_at_create' => $balanceSats,
            ':quote_total_cost_sats'  => $pickedRow['total_cost'],
            ':provider_response_json' => $swap->rawResponse ? json_encode($swap->rawResponse) : null,
            ':quotes_compared_json'   => $quotesAuditJson,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        return (int)Database::getInstance()->lastInsertId();
    }

    /**
     * Pay the swap provider's BOLT11 hold invoice using the store's cashu
     * wallet. Unlike {@see LightningAddress::meltToBolt11}, a "pending"
     * outcome here is acceptable — the swap provider holds the LN HTLC until
     * the on-chain claim reveals the preimage, at which point the mint's
     * upstream settles and the proofs flip to SPENT.
     */
    private static function meltAgainstInvoice(string $storeId, string $bolt11, int $expectedInvoiceSats): array {
        $mintUrl = Config::getStoreMintUrl($storeId);
        $wallet = null;
        $meltQuoteId = null;
        try {
            $wallet = Invoice::getWalletInstance($storeId);
            try {
                $meltQuote = $wallet->requestMeltQuote($bolt11);
                $meltQuoteId = $meltQuote->quote ?? null;
            } catch (Exception $e) {
                self::recordMeltFailure($mintUrl, $bolt11, $storeId, $e, 'requestMeltQuote', null, $wallet);
                throw $e;
            }

            // Last line of defense before funds leave: the mint independently
            // decoded the actual BOLT11 into $meltQuote->amount. Refuse if it
            // exceeds the amount we expected for this swap (the provider's
            // claimed invoiceAmount, already bounded against the quote at
            // create time). Catches a provider that returns a benign
            // invoiceAmount field but a BOLT11 charging more. Pre-flight
            // failure => cancel the swap, release the held HTLC.
            if ($expectedInvoiceSats > 0) {
                $tolerance = max(2, (int)ceil($expectedInvoiceSats * 0.005));
                if ($meltQuote->amount > $expectedInvoiceSats + $tolerance) {
                    $e = new Exception(sprintf(
                        'Melt quote amount %d sat exceeds expected swap invoice amount %d (+%d tol)',
                        $meltQuote->amount, $expectedInvoiceSats, $tolerance
                    ));
                    self::recordMeltFailure($mintUrl, $bolt11, $storeId, $e, 'requestMeltQuote', $meltQuoteId, $wallet);
                    throw $e;
                }
            }

            $totalNeeded = $meltQuote->amount + $meltQuote->feeReserve;
            $proofs = Invoice::getUnspentProofs($storeId);
            $balance = Wallet::sumProofs($proofs);
            $mintUnit = Config::getStoreMintUnit($storeId);
            if ($balance < $totalNeeded) {
                throw new Exception("Insufficient balance for sweep melt. Have: {$balance} {$mintUnit}, Need: {$totalNeeded} {$mintUnit}");
            }
            // Fee-aware selection: inputs must also cover their own NUT-02 fee.
            $selectedProofs = $wallet->selectProofsWithFees($proofs, $totalNeeded);

            try {
                $result = $wallet->melt($meltQuote->quote, $selectedProofs);
            } catch (Exception $e) {
                self::recordMeltFailure($mintUrl, $bolt11, $storeId, $e, 'melt', $meltQuoteId, $wallet);
                throw $e;
            }

            $isPaid = (bool)($result['paid'] ?? false);
            $isPending = (bool)($result['pending'] ?? false);
            if (!$isPaid && !$isPending) {
                $e = new Exception('Lightning melt did not enter paid or pending state');
                self::recordMeltFailure($mintUrl, $bolt11, $storeId, $e, 'melt', $meltQuoteId, $wallet);
                throw $e;
            }
            // PAID with the change still to be collected (NUT-09 by the cron's
            // recoverPendingMelts) is a success: the provider's invoice was
            // paid, the swap proceeds. Logged, never retried.
            if ($isPaid) {
                LightningAddress::noteChangeRecoveryPending($storeId, 'sweep:' . substr($bolt11, 0, 16), $meltQuoteId, $result);
            }

            if ($mintUrl !== null && $mintUrl !== '') {
                // Use sweep destination (the on-chain address) for stats so
                // the reliability tracker doesn't mistake the swap's BOLT11
                // for a Lightning-address destination.
                MintReliability::recordWithdrawSuccess($mintUrl, 'sweep:' . substr($bolt11, 0, 16), $storeId);
            }

            return [
                'paid' => $isPaid,
                'pending' => $isPending,
                'preimage' => $result['preimage'] ?? null,
                'invoice_amount_mint_unit' => $meltQuote->amount,
                'fee_reserve_mint_unit'   => $meltQuote->feeReserve,
                'changeRecoveryPending' => (bool)($result['changeRecoveryPending'] ?? false),
            ];
        } catch (Throwable $e) {
            throw $e;
        }
    }

    private static function recordMeltFailure(
        ?string $mintUrl,
        string $destination,
        string $storeId,
        Throwable $e,
        string $stage,
        ?string $meltQuoteId,
        $wallet
    ): void {
        if ($mintUrl === null || $mintUrl === '') return;
        $kind = MintReliability::classifyException($e instanceof Exception ? $e : new Exception($e->getMessage()), $stage);
        MintReliability::recordWithdrawFailure(
            $mintUrl,
            'sweep:' . substr($destination, 0, 16),
            $storeId,
            $kind,
            $e->getMessage(),
            $meltQuoteId,
            $wallet
        );
    }

    /**
     * Classify a melt-time exception as a pre-flight (= local, no network
     * call was made or response was definitive) vs an in-flight failure
     * (= the LN payment may have been initiated and is now in an
     * indeterminate state).
     *
     * Pre-flight failures we cancel + error on:
     *   - insufficient balance to cover invoice + fees
     *   - cashu wallet/storage cannot reach mint for the quote request
     *   - definitive 'Lightning melt did not enter paid or pending state'
     *
     * Anything else (timeouts, HTTP 5xx, EOF after partial response) we
     * leave to SwapPoller to drive via Boltz's status — Boltz is the
     * source of truth for whether the LN payment was actually received.
     */
    private static function isPreflightFailure(Throwable $e): bool {
        $msg = $e->getMessage();
        $needles = [
            'Insufficient balance',
            'did not enter paid or pending state',
            'Cannot spend proof',
            'Invalid Lightning address',
            'getInvoice failed',
            'no active keyset',
            // Amount-bound rejection thrown by meltAgainstInvoice BEFORE any
            // melt — no LN payment was sent, so cancel the swap and release
            // the held HTLC rather than leaving the row for the poller.
            'exceeds expected swap invoice amount',
        ];
        foreach ($needles as $needle) {
            if (stripos($msg, $needle) !== false) return true;
        }
        return false;
    }

    private static function cancelSwapBestEffort(SwapProvider $provider, string $network, string $swapId): void {
        try {
            $provider->cancelInvoice($network, $swapId);
        } catch (Throwable $e) {
            error_log("SwapAutoMelt cancelInvoice {$swapId}: " . $e->getMessage());
        }
    }

    private static function recordQuoteHistory(
        string $storeId, string $providerName, string $network,
        array $auditEntry, int $balanceSats, int $totalCost, bool $met, int $now
    ): void {
        Database::insert('swap_quote_history', [
            'store_id' => $storeId,
            'provider' => $providerName,
            'network'  => $network,
            'fetched_at' => $now,
            'fee_percent' => (float)($auditEntry['fee_percent'] ?? 0),
            'lockup_fee_sats' => (int)($auditEntry['lockup_fee_sats'] ?? 0),
            'claim_fee_estimate_sats' => (int)($auditEntry['claim_fee_estimate_sats'] ?? 0),
            'min_sats' => (int)($auditEntry['min_sats'] ?? 0),
            'max_sats' => (int)($auditEntry['max_sats'] ?? 0),
            'balance_sats_at_fetch' => $balanceSats,
            'total_cost_sats_at_fetch' => $totalCost,
            'met_threshold' => $met ? 1 : 0,
        ]);
    }

    /**
     * Back-solve the largest on-chain target a sweep can ask for given a
     * fixed cashu balance, the provider's percent + lockup fees, and our
     * up-front mint melt-fee buffer.
     *
     * The constraint is:  invoiceAmount + meltFeeBuffer ≤ balance
     * where               invoiceAmount = target + ceil(target * pct / 100) + lockup
     *
     * Solving for an integer target without overshooting the inequality:
     *   maxInvoice = balance − meltFeeBuffer − lockup
     *   target    = floor(maxInvoice / (1 + pct/100))      // upper bound
     *
     * Then we step down by 1 sat as long as the actual ceil-based fee
     * would push invoiceAmount past maxInvoice (covers the off-by-one
     * cases at the ceil() boundary).
     */
    public static function solveTargetFromBalance(
        int $balance,
        float $feePct,
        int $lockupFee,
        int $meltFeeBuffer
    ): int {
        $maxInvoice = $balance - $meltFeeBuffer - $lockupFee;
        if ($maxInvoice <= 0) return 0;
        // Continuous upper bound; we'll trim down for the ceil() boundary.
        $target = (int)floor($maxInvoice / (1.0 + $feePct / 100.0));
        if ($target <= 0) return 0;
        // Step down until ceil-based invoice amount actually fits.
        while ($target > 0) {
            $percentFee = (int)ceil($target * $feePct / 100.0);
            if (($target + $percentFee + $lockupFee) <= $maxInvoice + $lockupFee) {
                return $target;
            }
            $target--;
        }
        return 0;
    }

    private static function recentQuoteWithinCooldown(string $storeId): bool {
        $since = time() - self::QUOTE_COOLDOWN_SECONDS;
        $row = Database::fetchOne(
            "SELECT 1 FROM swap_quote_history WHERE store_id = ? AND fetched_at >= ? LIMIT 1",
            [$storeId, $since]
        );
        return $row !== null;
    }

    /**
     * Replay historical quotes against the current balance. If we have at
     * least {@see HISTORY_MIN_FAILED_TO_SKIP} quotes in the rolling 30-day
     * window AND none of them would satisfy the percent cap for $balanceSats,
     * signal "don't bother fetching fresh".
     *
     * Provider-agnostic — quote source doesn't matter.
     */
    private static function historicalQuotesAllFail(string $storeId, int $balanceSats): bool {
        $since = time() - (self::HISTORY_RETENTION_DAYS * 24 * 3600);
        $rows = Database::fetchAll(
            "SELECT fee_percent, lockup_fee_sats, claim_fee_estimate_sats
               FROM swap_quote_history
              WHERE store_id = ? AND fetched_at >= ?",
            [$storeId, $since]
        );
        if (count($rows) < self::HISTORY_MIN_FAILED_TO_SKIP) {
            return false;
        }
        $maxFeePct = self::maxFeePct();
        foreach ($rows as $r) {
            $percentSats = (int)ceil($balanceSats * (float)$r['fee_percent'] / 100.0);
            $total = $percentSats + (int)$r['lockup_fee_sats'] + (int)$r['claim_fee_estimate_sats'];
            if ($total > 0 && ($total * 100) <= ($balanceSats * $maxFeePct)) {
                return false; // at least one historical quote would have worked
            }
        }
        return true;
    }
}
