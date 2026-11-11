<?php
/**
 * CashuPayServer — Development Fee + Hosting Fee
 *
 * Implements the fee structure introduced under the Modified MIT License
 * (see LICENSE.md). All math is in sats. Settlement is driven from the
 * cron tick (see cron.php → "Task 2.5"); each fee fires independently
 * once the owed amount crosses the per-fee threshold.
 *
 * Fee semantics:
 *   - Dev fee (CASHUPAY_DEV_FEE_PERCENT, hard-coded 1%):
 *       base = revenue − network_costs − upstream_paid. Paid to the LNURL
 *       configured by CASHUPAY_DEV_FEE_LNURL (default fees@getbarebits.com)
 *       with "Deployment: $DEPLOYMENT_ID" attached as a memo (LUD-12 comment
 *       if commentAllowed permits; otherwise LUD-18 payerdata.name; otherwise
 *       paid without a memo and a warning is logged). upstream_paid is the
 *       historical total of the retired 0.5% upstream dev fee (removed
 *       2026-08); it stays in the base so deployments that paid it don't see
 *       their dev fee retroactively increase, and is 0 on fresh installs.
 *   - Hosting fee (per-store hosting_fee_percent, default 0%):
 *       base = revenue (no network-cost decrement). Paid to the store's
 *       configured hosting_fee_destination LNURL.
 *
 * Idempotency: paid totals are derived from the melts table by tag/note;
 * each settle pass insert the melts row in the same transaction that the
 * proofs are spent, so a successful Lightning payment can never be double-
 * counted on a subsequent settle pass.
 *
 * Modifying CASHUPAY_DEV_FEE_PERCENT or pointing CASHUPAY_DEV_FEE_LNURL at
 * an address you control to capture this fee is a violation of the
 * Modified MIT License (see LICENSE.md, section 2).
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/invoice.php';
require_once __DIR__ . '/rates.php';
require_once __DIR__ . '/lightning_address.php';
require_once __DIR__ . '/free_trial.php';
require_once __DIR__ . '/stuck_funds.php';
require_once __DIR__ . '/safe_http.php';
require_once __DIR__ . '/../cashu-wallet-php/CashuWallet.php';

use Cashu\Wallet;

// MANDATORY: this constant is intentionally hard-coded and not env-overridable.
// Canonically defined in includes/config.php so lightweight callers (e.g. the
// setup wizard) get it without loading this settlement stack; the guard below
// is a defensive fallback for the rare path that reaches here first. Keep the
// two values in sync.
if (!defined('CASHUPAY_DEV_FEE_PERCENT')) {
    define('CASHUPAY_DEV_FEE_PERCENT', 1);
}

// Default dev fee destination. CASHUPAY_DEV_FEE_LNURL env override exists
// purely to support test deployments; production must use the default.
if (!defined('CASHUPAY_DEV_FEE_LNURL')) {
    $envLnurl = getenv('CASHUPAY_DEV_FEE_LNURL');
    define('CASHUPAY_DEV_FEE_LNURL', ($envLnurl !== false && $envLnurl !== '') ? $envLnurl : 'fees@getbarebits.com');
}

// Per-fee threshold in sats. Fees do not settle until at least this many
// sats are owed (avoids churning melts for tiny amounts).
if (!defined('CASHUPAY_FEE_SETTLE_THRESHOLD_SATS')) {
    define('CASHUPAY_FEE_SETTLE_THRESHOLD_SATS', 1000);
}

// ---------------------------------------------------------------------------
// Fee-redirect destinations.
//
// When an invoice is created and a fee is owed >= the invoice amount, the
// invoice's payment rails are pointed straight at the fee's destination
// instead of the merchant (see includes/fee_redirect.php). The destination
// used depends on how the customer pays:
//   - Lightning  -> the fee's LNURL / Lightning address (must support LUD-21
//                   so settlement is detectable without running an LN node).
//   - On-chain   -> a fresh address derived from the fee's xpub. The dev
//                   fee derives under a per-install branch
//                   (<zpub>/<branch>/0/<index>, see includes/fee_branch.php);
//                   until one has been allocated the dev fee does not take
//                   the on-chain rail at all.
//
// The dev fee ships with a BareBits mainnet zpub baked in (BIP84 / P2WPKH),
// so the dev fee's on-chain redirect rail is ON by default for mainnet
// stores. The env override exists purely to support test deployments, the
// same as CASHUPAY_DEV_FEE_LNURL; production must use the default (see the
// Modified MIT License, LICENSE.md section 2). The hosting fee's xpub is
// per-store and defaults empty, so that rail stays OFF until the deployer
// sets one. When a fee has no usable destination for one of the store's
// offered rails, that fee simply isn't redirect-eligible on that rail and
// the existing cashu-wallet settlement path (cron) keeps collecting it.
//
// Dev destinations are global (one payee for the whole deployment); the
// hosting fee's on-chain xpub is per-store (stores table).
// ---------------------------------------------------------------------------
$ff_env = static function (string $name, string $default): string {
    $v = getenv($name);
    return ($v !== false && $v !== '') ? $v : $default;
};

// Dev fee on-chain destination (LNURL already exists as CASHUPAY_DEV_FEE_LNURL).
// MANDATORY default: pointing this at an xpub you control to capture the dev
// fee is a violation of the Modified MIT License (LICENSE.md, section 2).
if (!defined('CASHUPAY_DEV_FEE_ONCHAIN_XPUB')) {
    define('CASHUPAY_DEV_FEE_ONCHAIN_XPUB', $ff_env(
        'CASHUPAY_DEV_FEE_ONCHAIN_XPUB',
        'zpub6mg9NbdenqrqfD2BbNqa4EdKS8z2yEBxH2PC4NXmXwnTDfukw3w7JLVMeb81xc9i1N8b7ReB9ia4wLoUyLVrVxaPQbJidU8Yn6Gjd95kQmA'
    ));
}
if (!defined('CASHUPAY_DEV_FEE_ONCHAIN_NETWORK')) {
    define('CASHUPAY_DEV_FEE_ONCHAIN_NETWORK', $ff_env('CASHUPAY_DEV_FEE_ONCHAIN_NETWORK', 'mainnet'));
}
if (!defined('CASHUPAY_DEV_FEE_ONCHAIN_ADDRESS_TYPE')) {
    define('CASHUPAY_DEV_FEE_ONCHAIN_ADDRESS_TYPE', $ff_env('CASHUPAY_DEV_FEE_ONCHAIN_ADDRESS_TYPE', 'P2WPKH'));
}
// Branch allocator for the dev fee's on-chain addresses (see
// includes/fee_branch.php): every install asks this endpoint once for a
// sequential BIP32 branch so the shared zpub never yields the same address on
// two installs. Same env-override-for-tests rule as the constants above; a
// value of 'disabled' switches the request off (the dev fee then never takes
// the on-chain rail). MANDATORY default in production (LICENSE.md,
// section 2).
if (!defined('CASHUPAY_DEV_FEE_BRANCH_ENDPOINT')) {
    define('CASHUPAY_DEV_FEE_BRANCH_ENDPOINT', $ff_env(
        'CASHUPAY_DEV_FEE_BRANCH_ENDPOINT',
        'https://getbarebits.com/xpub/xpub.php'
    ));
}

unset($ff_env);

// Melt note tags written to the `melts.note` column. FEE_NOTE_UPSTREAM is the
// tag of the retired 0.5% upstream dev fee — no new rows are written with it,
// but historical rows still carry it and are read by computeOwed() (dev-fee
// base) and the stats/diagnostics aggregations.
const FEE_NOTE_UPSTREAM = 'UPSTREAM_DEV_FEE';
const FEE_NOTE_DEV      = 'DEV_FEE';
const FEE_NOTE_HOSTING  = 'HOSTING_FEE';

/**
 * MeltLog — insert-only audit log of every successful outbound melt.
 *
 * Used by every melt call-site (user manual withdraw, auto-melt, the three
 * fee payments). The `note` column distinguishes fee payments from user
 * withdrawals and powers fee-paid aggregation. Failed melts are not logged
 * here in this PR; that will be revisited when the stats dashboard lands.
 */
class MeltLog {
    /**
     * @param string      $storeId
     * @param int         $amountSats       Sats delivered to the destination
     * @param int         $networkFeeSats   Sats consumed by network/mint fees
     * @param string      $destination      BOLT-11 / LNURL / sink URL
     * @param string|null $preimage         Lightning preimage when available
     * @param string|null $note             One of FEE_NOTE_*, or NULL for user/auto-melt
     * @param string|null $strikeInvoiceId  Strike invoice id when the melt paid a
     *                                      Strike-quoted bolt11 (dashboard reconciliation)
     */
    public static function record(
        string $storeId,
        int $amountSats,
        int $networkFeeSats,
        string $destination,
        ?string $preimage,
        ?string $note,
        ?string $strikeInvoiceId = null
    ): int {
        return (int) Database::insert('melts', [
            'store_id' => $storeId,
            'amount_sats' => max(0, $amountSats),
            'network_fee_sats' => max(0, $networkFeeSats),
            'destination' => $destination,
            'preimage' => $preimage,
            'note' => $note,
            'status' => 'paid',
            'strike_invoice_id' => $strikeInvoiceId,
            'created_at' => time(),
        ]);
    }

    /**
     * Write-ahead a 'pending' fee-melt intent BEFORE the proofs are spent.
     *
     * The row counts toward DevFee::sumPaid() immediately (it's a real melts
     * row), so a crash between the melt and the would-be MeltLog::record() can
     * no longer leave the fee looking unpaid and double-pay on the next tick.
     * $meltQuoteId lets DevFee::reconcilePendingFeeMelts() later resolve the
     * intent against the mint's melt-quote state. Returns the row id to
     * finalize/delete. network_fee is 0 and preimage null until finalized.
     */
    public static function recordPendingIntent(
        string $storeId,
        int $amountSats,
        string $destination,
        ?string $note,
        ?string $meltQuoteId
    ): int {
        return (int) Database::insert('melts', [
            'store_id' => $storeId,
            'amount_sats' => max(0, $amountSats),
            'network_fee_sats' => 0,
            'destination' => $destination,
            'preimage' => null,
            'note' => $note,
            'status' => 'pending',
            'melt_quote_id' => $meltQuoteId,
            'created_at' => time(),
        ]);
    }

    /** Promote a pending intent to a confirmed payment (idempotent on status). */
    public static function finalizeIntent(int $rowId, int $networkFeeSats, ?string $preimage): void {
        Database::update(
            'melts',
            ['status' => 'paid', 'network_fee_sats' => max(0, $networkFeeSats), 'preimage' => $preimage],
            'id = ? AND status = ?',
            [$rowId, 'pending']
        );
    }

    /** Drop a pending intent (the melt definitively did not happen). */
    public static function deleteIntent(int $rowId): void {
        Database::delete('melts', 'id = ? AND status = ?', [$rowId, 'pending']);
    }
}

/**
 * DevFee — owed-amount math + per-store settlement loop.
 */
class DevFee {
    /**
     * Settle dev / hosting fees for every store with accrued revenue.
     * Called from cron.php as a separate task before auto-melt.
     */
    public static function settleAllStores(): array {
        $results = [];
        $stores = Database::fetchAll(
            "SELECT id FROM stores WHERE mint_url IS NOT NULL AND seed_phrase IS NOT NULL"
        );
        foreach ($stores as $store) {
            $result = self::settleStore($store['id']);
            if ($result !== null) {
                $results[] = ['store_id' => $store['id']] + $result;
            }
        }
        return $results;
    }

    /**
     * Settle dev / hosting fees for a single store. Returns null if nothing
     * was owed; otherwise an array describing what was attempted.
     */
    public static function settleStore(string $storeId): ?array {
        $owed = self::computeOwed($storeId);
        if ($owed['revenue'] <= 0) {
            return null;
        }

        $threshold = CASHUPAY_FEE_SETTLE_THRESHOLD_SATS;
        $outcomes = [];

        if ($owed['dev_owed'] < $threshold && $owed['hosting_owed'] < $threshold) {
            return ['owed' => $owed, 'outcomes' => $outcomes];
        }

        // The fee melts spend the same proofs the dashboard withdraw / export
        // and the auto-cashout rails do, so they take the same per-store
        // funds lock, and they do not fight the operator for proofs while a
        // withdrawal is still being settled by the mint.
        require_once __DIR__ . '/locks.php';
        $fundsLock = cashupay_acquire_lock('funds_' . $storeId);
        if (!$fundsLock) {
            return ['owed' => $owed, 'outcomes' => ['skipped' => 'busy']];
        }
        try {
            try {
                LightningAddress::assertNoPendingWithdrawal($storeId);
            } catch (Exception $pendingErr) {
                return ['owed' => $owed, 'outcomes' => ['skipped' => 'pending', 'error' => $pendingErr->getMessage()]];
            }

            if ($owed['dev_owed'] >= $threshold) {
                $outcomes['dev'] = self::payViaLnurl(
                    $storeId,
                    CASHUPAY_DEV_FEE_LNURL,
                    $owed['dev_owed'],
                    FEE_NOTE_DEV
                );
            }

            // Hosting fee.
            if ($owed['hosting_owed'] >= $threshold) {
                $store = Config::getStore($storeId);
                $hostingDest = $store['hosting_fee_destination'] ?? null;
                if ($hostingDest === null || $hostingDest === '') {
                    $outcomes['hosting'] = ['success' => false, 'error' => 'hosting_fee_destination not configured'];
                } else {
                    $outcomes['hosting'] = self::payViaLnurl(
                        $storeId,
                        $hostingDest,
                        $owed['hosting_owed'],
                        FEE_NOTE_HOSTING
                    );
                }
            }
        } finally {
            cashupay_release_lock($fundsLock);
        }

        return ['owed' => $owed, 'outcomes' => $outcomes];
    }

    /**
     * Resolve fee-melt intents left 'pending' by a crash between the melt and
     * its finalize (see MeltLog::recordPendingIntent). For each stale pending
     * row that carries a mint melt-quote id, ask the mint's quote state:
     *   - PAID    -> finalize to 'paid' (the fee really went out; it stays
     *                counted so it is never re-paid).
     *   - PENDING -> leave it (still in flight; check again next tick).
     *   - else    -> the proofs were not spent, so the fee is genuinely still
     *                owed; drop the intent so the next settle pass retries it.
     * $graceSeconds avoids racing a settle pass that is finalizing right now.
     *
     * Only the LNURL fee path (dev + hosting) writes quote-bearing intents.
     */
    public static function reconcilePendingFeeMelts(int $graceSeconds = 120): array {
        $cutoff = time() - max(0, $graceSeconds);
        $rows = Database::fetchAll(
            "SELECT id, store_id, melt_quote_id FROM melts
             WHERE status = 'pending' AND melt_quote_id IS NOT NULL AND created_at < ?",
            [$cutoff]
        );
        $out = ['checked' => count($rows), 'finalized' => 0, 'reverted' => 0, 'left' => 0];
        foreach ($rows as $r) {
            try {
                $wallet = Invoice::getWalletInstance((string)$r['store_id']);
                $quote = $wallet->checkMeltQuote((string)$r['melt_quote_id']);
            } catch (\Throwable $e) {
                // Mint unreachable / wallet error — leave it, retry next tick.
                $out['left']++;
                continue;
            }
            if ($quote->isPaid()) {
                MeltLog::finalizeIntent((int)$r['id'], 0, null);
                $out['finalized']++;
            } elseif ($quote->isPending()) {
                $out['left']++;
            } else {
                MeltLog::deleteIntent((int)$r['id']);
                $out['reverted']++;
            }
        }
        return $out;
    }

    /**
     * Pure aggregation: compute revenue / network cost / paid totals and
     * how much is owed for each fee type. All values in sats.
     *
     * Honors the deployment-wide free trial: while [[free_trial_active]] is
     * true, every owed value is forced to zero. Settlement gates on owed >=
     * threshold, so zero-owed means cron won't pay anything either.
     */
    public static function computeOwed(string $storeId): array {
        // Lazy expiry: an inert deployment that has no cron at all still
        // gets its trial flipped the moment anyone opens the dashboard or
        // hits an admin endpoint that asks for fee math. Cheap no-op once
        // the trial has either expired or was never configured.
        FreeTrial::expireIfNeeded();

        $start = (int) Config::get('fee_tracking_start_at', 0);

        // Revenue: sum of paid invoices' sat value created after the
        // migration timestamp. amount_sats may be null on legacy rows; those
        // are excluded by the filter.
        $revenue = (int) Database::fetchOne(
            "SELECT COALESCE(SUM(amount_sats), 0) AS s
             FROM invoices
             WHERE store_id = ? AND status = 'Settled'
               AND amount_sats IS NOT NULL
               AND created_at >= ?",
            [$storeId, $start]
        )['s'];

        $networkCost = (int) Database::fetchOne(
            "SELECT COALESCE(SUM(network_fee_sats), 0) AS s FROM melts WHERE store_id = ?",
            [$storeId]
        )['s'];

        // Historical total of the retired 0.5% upstream dev fee. No new
        // upstream fee accrues or settles, but amounts already paid stay
        // subtracted from the dev-fee base so existing deployments don't see
        // their dev fee retroactively increase. 0 on fresh installs.
        $upstreamPaid = self::sumPaid($storeId, FEE_NOTE_UPSTREAM);
        $devPaid      = self::sumPaid($storeId, FEE_NOTE_DEV);
        $hostingPaid  = self::sumPaid($storeId, FEE_NOTE_HOSTING);

        $store = Config::getStore($storeId);
        $hostingPct = (float)($store['hosting_fee_percent'] ?? 0);

        $devBase = max(0, $revenue - $networkCost - $upstreamPaid);
        $devOwed = max(0, (int) floor($devBase * CASHUPAY_DEV_FEE_PERCENT / 100) - $devPaid);

        $hostingOwed = max(0, (int) floor($revenue * $hostingPct / 100) - $hostingPaid);

        $trialActive = FreeTrial::isActive();
        if ($trialActive) {
            $devOwed = 0;
            $hostingOwed = 0;
        }

        // Stuck-funds absorption: sats currently stranded in a mint with a
        // pending withdrawal-failure flag come out of the dev share first
        // (then hosting) so the loss doesn't shift onto the merchant's
        // settlement. Live read — once the mint recovers and the proofs
        // drain or the flag clears, the next call returns 0 here.
        $stuckPerMint = StuckFunds::computeStuckSatsPerMint($storeId);
        $stuckTotal = array_sum($stuckPerMint);
        $deduction = StuckFunds::applyDeduction($devOwed, $hostingOwed, $stuckTotal);

        return [
            'revenue' => $revenue,
            'network_cost' => $networkCost,
            'upstream_paid' => $upstreamPaid,
            'dev_paid' => $devPaid,
            'hosting_paid' => $hostingPaid,
            'dev_owed' => $deduction['dev_owed'],
            'hosting_owed' => $deduction['hosting_owed'],
            'trial_active' => $trialActive,
            'stuck_total_sats' => $deduction['stuck_total_sats'],
            'stuck_absorbed_total' => $deduction['stuck_absorbed_total'],
            'stuck_absorbed_dev' => $deduction['stuck_absorbed_dev'],
            'stuck_absorbed_hosting' => $deduction['stuck_absorbed_hosting'],
            'stuck_uncovered' => $deduction['stuck_uncovered'],
            'stuck_per_mint' => $stuckPerMint,
        ];
    }

    private static function sumPaid(string $storeId, string $note): int {
        $row = Database::fetchOne(
            "SELECT COALESCE(SUM(amount_sats), 0) AS s FROM melts WHERE store_id = ? AND note = ?",
            [$storeId, $note]
        );
        return (int)($row['s'] ?? 0);
    }

    /**
     * Pay a fee via Lightning Address / LNURL-pay with the deployment-id
     * memo attached. Records a melts row on success.
     */
    private static function payViaLnurl(
        string $storeId,
        string $destination,
        int $owedSats,
        string $noteTag
    ): array {
        $deploymentId = (string) Config::get('deployment_id', 'ANONYMOUS');
        $memo = "Deployment: {$deploymentId}";

        // Resolve LNURL parameters once so we can decide which memo channel
        // (LUD-12 comment vs LUD-18 payerdata) the server supports.
        $params = LnurlPay::resolve($destination);
        if ($params === null) {
            return ['success' => false, 'error' => "could not resolve LNURL {$destination}"];
        }

        $amountMsats = $owedSats * 1000;
        if ($amountMsats < ($params['minSendable'] ?? 0)) {
            return ['success' => false, 'error' => 'amount below minSendable'];
        }
        if ($amountMsats > ($params['maxSendable'] ?? PHP_INT_MAX)) {
            return ['success' => false, 'error' => 'amount above maxSendable'];
        }

        // Try LUD-12 (comment), then LUD-18 (payerData.name) as fallback.
        // Either way, the BOLT-11 invoice is what we melt.
        try {
            $bolt11 = LnurlPay::fetchInvoice($params, $owedSats, $memo);
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'fetchInvoice: ' . $e->getMessage()];
        }

        // Write-ahead a pending intent keyed by the mint melt-quote id, just
        // before the proofs are spent (via the meltToBolt11 hook). If we crash
        // after the melt but before finalizing, the pending row keeps the fee
        // from being recomputed as owed and double-paid; reconcilePendingFeeMelts
        // later resolves it against the mint. On a thrown melt we leave the
        // pending row in place (the payment may be in-flight) — reconcile, not
        // this path, decides whether it really happened.
        $intentRowId = null;
        try {
            $result = LightningAddress::meltToBolt11(
                $storeId,
                $bolt11,
                $owedSats,
                function (string $meltQuoteId) use ($storeId, $owedSats, $destination, $noteTag, &$intentRowId) {
                    $intentRowId = MeltLog::recordPendingIntent($storeId, $owedSats, $destination, $noteTag, $meltQuoteId);
                }
            );
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'meltToBolt11: ' . $e->getMessage()];
        }

        $networkFeeSats = self::networkFeeSats($storeId, $result['fee'] ?? 0);

        if ($intentRowId !== null) {
            MeltLog::finalizeIntent($intentRowId, $networkFeeSats, $result['preimage'] ?? null);
        } else {
            // Hook never fired (shouldn't happen on a successful melt) — fall
            // back to a direct, already-confirmed record so the fee is counted.
            MeltLog::record($storeId, $owedSats, $networkFeeSats, $destination, $result['preimage'] ?? null, $noteTag);
        }

        return ['success' => true, 'amount_sats' => $owedSats, 'network_fee_sats' => $networkFeeSats];
    }

    /**
     * Convert a wallet-reported fee to sats. For sat mints it's a no-op; for
     * fiat mints we convert via ExchangeRates so the dev-fee base shrinks
     * honestly regardless of mint unit.
     */
    private static function networkFeeSats(string $storeId, int $feeInMintUnit): int {
        if ($feeInMintUnit <= 0) {
            return 0;
        }
        $store = Config::getStore($storeId);
        $mintUnit = strtolower($store['mint_unit'] ?? 'sat');
        if (in_array($mintUnit, ['sat', 'sats', 'msat'])) {
            return $mintUnit === 'msat' ? (int)ceil($feeInMintUnit / 1000) : $feeInMintUnit;
        }
        $providers = Config::getStorePriceProviders($storeId);
        return (int) ExchangeRates::convertMintUnitToSats(
            $feeInMintUnit, $mintUnit, $providers['primary'], $providers['secondary']
        );
    }
}

/**
 * LnurlPay — minimal LNURL-pay client with LUD-12 / LUD-18 memo support.
 *
 * The cashu-wallet-php library's LightningAddress::getInvoice only does
 * LUD-12 comment. We need the LUD-18 payerData.name fallback for the
 * deployment-id memo so fees can be attributed regardless of which LUDs
 * the endpoint advertises.
 */
class LnurlPay {
    /**
     * Resolve a Lightning Address (or raw LNURL) into payRequest params.
     * Returns the parsed JSON or null on failure. Includes payerData so we
     * can decide on LUD-18 support.
     */
    public static function resolve(string $address): ?array {
        // Lightning Address path: user@domain → well-known/lnurlp
        if (preg_match('/^[a-zA-Z0-9_.+-]+@[a-zA-Z0-9-]+\.[a-zA-Z0-9-.]+$/', $address)) {
            [$user, $domain] = explode('@', $address, 2);
            $url = "https://{$domain}/.well-known/lnurlp/{$user}";
        } else {
            // Bare https URL fallback (LNURL-pay endpoint directly)
            if (!preg_match('#^https?://#', $address)) {
                return null;
            }
            $url = $address;
        }

        $result = \SafeHttp::request($url, [
            'maxBytes' => 64 * 1024,
            'timeout' => 10,
            'followRedirects' => false,
            'headers' => ['Accept: application/json'],
            'allowPrivate' => \SafeHttp::privateEndpointsAllowed(),
        ]);

        if ($result['status'] !== 200 || $result['body'] === '') {
            return null;
        }
        $data = json_decode($result['body'], true);
        if (!is_array($data) || !isset($data['callback'], $data['minSendable'], $data['maxSendable'])) {
            return null;
        }
        return [
            'callback' => $data['callback'],
            'minSendable' => (int)$data['minSendable'],
            'maxSendable' => (int)$data['maxSendable'],
            'commentAllowed' => (int)($data['commentAllowed'] ?? 0),
            'payerData' => $data['payerData'] ?? null,
        ];
    }

    /**
     * Request a BOLT-11 invoice from a resolved LNURL-pay endpoint,
     * attaching the memo via LUD-12 (comment) if commentAllowed permits;
     * otherwise via LUD-18 (payerdata.name) if the server advertises a name
     * field; otherwise logging a warning and paying without a memo.
     */
    public static function fetchInvoice(array $params, int $amountSats, string $memo): string {
        $amountMsats = $amountSats * 1000;
        $callback = $params['callback'];
        $separator = (strpos($callback, '?') !== false) ? '&' : '?';
        $url = $callback . $separator . 'amount=' . $amountMsats;

        $memoApplied = false;
        $commentAllowed = (int)($params['commentAllowed'] ?? 0);
        if ($commentAllowed >= strlen($memo)) {
            $url .= '&comment=' . urlencode($memo);
            $memoApplied = true;
        } elseif (self::payerDataSupportsName($params['payerData'] ?? null)) {
            $payerdata = json_encode(['name' => $memo]);
            $url .= '&payerdata=' . urlencode($payerdata);
            $memoApplied = true;
        }

        if (!$memoApplied) {
            error_log("LnurlPay: endpoint does not support comment ≥ "
                . strlen($memo) . " chars and has no payerData.name; paying without memo");
        }

        // The callback URL was chosen by the LNURL host — treat it as
        // attacker-influenced. Honor the operator opt-in (same flag the
        // mint and onchain provider use); refuse redirects unconditionally.
        $result = \SafeHttp::request($url, [
            'maxBytes' => 64 * 1024,
            'timeout' => 15,
            'followRedirects' => false,
            'headers' => ['Accept: application/json'],
            'allowPrivate' => \SafeHttp::privateEndpointsAllowed(),
        ]);

        if ($result['status'] !== 200 || $result['body'] === '') {
            throw new Exception("LNURL callback failed (HTTP {$result['status']})");
        }
        $data = json_decode($result['body'], true);
        if (!is_array($data) || !isset($data['pr'])) {
            $err = $data['reason'] ?? $data['message'] ?? 'no pr field';
            throw new Exception("LNURL callback error: {$err}");
        }
        return (string) $data['pr'];
    }

    /**
     * LUD-18 advertises supported payer-data fields under payerData; we
     * specifically look for a writable `name` slot.
     */
    private static function payerDataSupportsName($payerData): bool {
        if (!is_array($payerData)) {
            return false;
        }
        return isset($payerData['name']) && is_array($payerData['name']);
    }
}
