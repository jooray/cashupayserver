<?php
/**
 * Offline Cashu acceptance (NUT-12 DLEQ).
 *
 * When the mint is unreachable, a presented Cashu token cannot be swapped
 * immediately. If the store has opted in, we instead verify the token OFFLINE
 * — proving it is authentic mint-signed ecash via its DLEQ proofs (NUT-12) —
 * apply the store's risk policy (trusted-mint allowlist + per-tx and aggregate
 * caps + replay guard), and record it as a Provisional invoice. A later
 * reconciliation pass swaps it at the mint: success -> Settled, mint rejection
 * (e.g. double-spent) -> Invalid.
 *
 * TRUST BOUNDARY: DLEQ proves authenticity offline, NOT that the proof is
 * unspent. Double-spend can only be ruled out by the mint at reconnect. Offline
 * acceptance is therefore inherently provisional and carries a loss risk until
 * reconciled — surfaced to merchants via the opt-in warning and the Provisional
 * state.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/webhook_sender.php';
require_once __DIR__ . '/notification_sender.php';
require_once __DIR__ . '/invoice.php';
require_once __DIR__ . '/../cashu-wallet-php/CashuWallet.php';

use Cashu\Wallet;
use Cashu\Crypto;
use Cashu\CashuException;
use Cashu\CashuNetworkException;
use Cashu\CashuProtocolException;

class OfflineCashu {

    // ====================================================================
    // SETTINGS (read)
    // ====================================================================

    public static function isEnabled(string $storeId): bool {
        $store = Config::getStore($storeId);
        return !empty($store['offline_cashu_enabled']);
    }

    /** Policy floor: 'dleq' (active) or 'p2pk' (stubbed — not yet implemented). */
    public static function policy(string $storeId): string {
        $store = Config::getStore($storeId);
        $p = $store['offline_cashu_policy'] ?? 'dleq';
        return $p === 'p2pk' ? 'p2pk' : 'dleq';
    }

    /** Per-transaction cap in mint smallest unit. 0 = no per-tx cap. */
    public static function maxPerTx(string $storeId): int {
        $store = Config::getStore($storeId);
        return max(0, (int)($store['offline_cashu_max_per_tx'] ?? 0));
    }

    /** Aggregate outstanding (un-reconciled) exposure cap. 0 = no cap. */
    public static function maxOutstanding(string $storeId): int {
        $store = Config::getStore($storeId);
        return max(0, (int)($store['offline_cashu_max_outstanding'] ?? 0));
    }

    /** Accept offline tokens from any mint, bypassing the allowlist. */
    public static function acceptAllMints(string $storeId): bool {
        $store = Config::getStore($storeId);
        return !empty($store['offline_cashu_accept_all_mints']);
    }

    /** Whether per-invoice "allow any mint" overrides may be set at request time. */
    public static function perTxOverrideEnabled(string $storeId): bool {
        $store = Config::getStore($storeId);
        return !empty($store['offline_cashu_per_tx_override']);
    }

    /**
     * Persist the per-store offline settings. Routed through Database::update
     * directly (NOT Config::updateStore — its allowlist stays tight).
     */
    public static function saveSettings(string $storeId, array $s): void {
        $data = [];
        if (array_key_exists('enabled', $s)) {
            $data['offline_cashu_enabled'] = !empty($s['enabled']) ? 1 : 0;
        }
        if (array_key_exists('policy', $s)) {
            $data['offline_cashu_policy'] = ($s['policy'] === 'p2pk') ? 'p2pk' : 'dleq';
        }
        if (array_key_exists('max_per_tx', $s)) {
            $data['offline_cashu_max_per_tx'] = max(0, (int)$s['max_per_tx']);
        }
        if (array_key_exists('max_outstanding', $s)) {
            $data['offline_cashu_max_outstanding'] = max(0, (int)$s['max_outstanding']);
        }
        if (array_key_exists('accept_all_mints', $s)) {
            $data['offline_cashu_accept_all_mints'] = !empty($s['accept_all_mints']) ? 1 : 0;
        }
        if (array_key_exists('per_tx_override', $s)) {
            $data['offline_cashu_per_tx_override'] = !empty($s['per_tx_override']) ? 1 : 0;
        }
        if (!empty($data)) {
            Database::update('stores', $data, 'id = ?', [$storeId]);
        }
    }

    // ====================================================================
    // TRUSTED-MINT ALLOWLIST
    // ====================================================================

    /** All allowlist rows for a store. */
    public static function allowlist(string $storeId): array {
        return Database::fetchAll(
            "SELECT mint_url, enabled FROM store_offline_mints WHERE store_id = ? ORDER BY mint_url",
            [$storeId]
        );
    }

    /** Enabled mint URLs only (normalized, no trailing slash). */
    public static function allowedMintUrls(string $storeId): array {
        $rows = Database::fetchAll(
            "SELECT mint_url FROM store_offline_mints WHERE store_id = ? AND enabled = 1",
            [$storeId]
        );
        return array_map(fn($r) => rtrim($r['mint_url'], '/'), $rows);
    }

    public static function isMintAllowed(string $storeId, string $mintUrl): bool {
        return in_array(rtrim($mintUrl, '/'), self::allowedMintUrls($storeId), true);
    }

    public static function addAllowedMint(string $storeId, string $mintUrl): void {
        $mintUrl = rtrim(trim($mintUrl), '/');
        if ($mintUrl === '') return;
        // INSERT OR IGNORE to keep the UNIQUE(store_id, mint_url) idempotent.
        Database::query(
            "INSERT OR IGNORE INTO store_offline_mints (store_id, mint_url, enabled, created_at)
             VALUES (?, ?, 1, ?)",
            [$storeId, $mintUrl, Database::timestamp()]
        );
    }

    public static function setMintEnabled(string $storeId, string $mintUrl, bool $enabled): void {
        Database::update(
            'store_offline_mints',
            ['enabled' => $enabled ? 1 : 0],
            'store_id = ? AND mint_url = ?',
            [$storeId, rtrim($mintUrl, '/')]
        );
    }

    public static function removeAllowedMint(string $storeId, string $mintUrl): void {
        Database::query(
            "DELETE FROM store_offline_mints WHERE store_id = ? AND mint_url = ?",
            [$storeId, rtrim($mintUrl, '/')]
        );
    }

    /**
     * Seed the allowlist from the store's currently-configured mints (primary +
     * backups). Sane default so a merchant who flips the feature on can accept
     * tokens from the mints they already use without extra setup.
     */
    public static function seedAllowlistFromStoreMints(string $storeId): int {
        $added = 0;
        $primary = Config::getStoreMintUrl($storeId);
        $urls = [];
        if ($primary) $urls[] = $primary;
        foreach (Config::getStoreAllMintUrls($storeId) as $u) $urls[] = $u;
        foreach (array_unique($urls) as $u) {
            if (!self::isMintAllowed($storeId, $u)) {
                self::addAllowedMint($storeId, $u);
                $added++;
            }
        }
        return $added;
    }

    // ====================================================================
    // EXPOSURE
    // ====================================================================

    /** Total un-reconciled (Provisional) offline exposure for a store. */
    public static function outstandingExposure(string $storeId): int {
        $row = Database::fetchOne(
            "SELECT COALESCE(SUM(amount_sats), 0) AS s
             FROM invoices
             WHERE store_id = ? AND status = 'Provisional' AND payment_rail = 'cashu'",
            [$storeId]
        );
        return (int)($row['s'] ?? 0);
    }

    // ====================================================================
    // VERIFICATION
    // ====================================================================

    /**
     * Build a DLEQ-verification-capable wallet for a mint, falling back to the
     * cached keyset keys when the mint is unreachable. No seed needed (we only
     * verify, never swap here).
     */
    /**
     * Normalize a Cashu unit string for case-insensitive comparison. An empty
     * or missing unit is treated as 'sat' (the Cashu token default).
     */
    public static function normUnit(?string $u): string {
        $u = strtolower(trim((string)$u));
        return $u === '' ? 'sat' : $u;
    }

    private static function verifyWallet(string $mintUrl, string $unit): Wallet {
        $wallet = new Wallet(rtrim($mintUrl, '/'), $unit, Database::getDbPath());
        try {
            $wallet->loadMint();
        } catch (\Throwable $e) {
            // Mint unreachable (or otherwise un-loadable): use cached keys.
            $wallet->loadMintFromCache();
        }
        return $wallet;
    }

    /**
     * Verify a token OFFLINE under the store's policy, without recording it.
     *
     * @param bool $allowAnyMint When true, skip the allowlist check for this
     *                           token (store accept-all, or a per-invoice override).
     * @return array {
     *   ok: bool,
     *   amount: int,            // mint smallest unit
     *   mint_url: string,
     *   ys: string[],           // hash_to_curve(secret) per proof (replay keys)
     *   reason: ?string         // failure reason when ok=false
     * }
     */
    public static function verifyToken(string $storeId, string $tokenString, bool $allowAnyMint = false): array {
        $fail = fn(string $reason) => ['ok' => false, 'amount' => 0, 'mint_url' => '', 'ys' => [], 'reason' => $reason];

        if (self::policy($storeId) === 'p2pk') {
            // P2PK (NUT-11) offline locking is not implemented yet. Never fall
            // back to the weaker DLEQ-only acceptance when the merchant asked
            // for the stronger floor — reject instead.
            return $fail('Offline P2PK policy is not yet available');
        }

        $unit = Config::getStoreMintUnit($storeId);

        try {
            $token = (new Wallet('https://placeholder.invalid', $unit))->deserializeToken($tokenString);
        } catch (\Throwable $e) {
            return $fail('Could not parse token');
        }

        // Reject a unit mismatch BEFORE crediting anything. The token's amount is
        // taken raw below; a token denominated in a different unit (msat = 1000x,
        // usd = arbitrary) would otherwise be credited 1:1 in the store's unit,
        // corrupting the exposure cap, the cover check, and the recorded
        // settlement. Empty unit means 'sat' per the Cashu token default.
        $tokenUnit = self::normUnit($token->unit ?? null);
        $wantUnit = self::normUnit($unit);
        if ($tokenUnit !== $wantUnit) {
            return $fail("Token unit ({$tokenUnit}) does not match store unit ({$wantUnit})");
        }

        $mintUrl = rtrim($token->mint ?? '', '/');
        if ($mintUrl === '') {
            return $fail('Token has no mint URL');
        }
        $anyMint = $allowAnyMint || self::acceptAllMints($storeId);
        if (!$anyMint && !self::isMintAllowed($storeId, $mintUrl)) {
            return $fail('Token mint is not on the offline allowlist');
        }

        $proofs = $token->proofs ?? [];
        if (empty($proofs)) {
            return $fail('Token has no proofs');
        }

        $wallet = self::verifyWallet($mintUrl, $unit);

        $ys = [];
        foreach ($proofs as $proof) {
            if ($proof->dleq === null) {
                return $fail('Token has no DLEQ proofs (cannot verify offline)');
            }
            if (!$wallet->verifyProofDleq($proof)) {
                return $fail('DLEQ verification failed (mint keys unavailable or invalid signature)');
            }
            $ys[] = Crypto::computeY($proof->secret);
        }

        return [
            'ok' => true,
            'amount' => (int)$token->getAmount(),
            'mint_url' => $mintUrl,
            'ys' => $ys,
            'reason' => null,
        ];
    }

    // ====================================================================
    // ACCEPTANCE
    // ====================================================================

    /**
     * Accept a token offline: verify + enforce caps/replay + record a
     * Provisional invoice (or settle a pre-created 'New' cashu invoice into
     * Provisional). Returns a structured result for the receive.php handler.
     *
     * @param array $context {
     *   invoice?: array   // a pre-created 'New' cashu invoice row to attach to
     * }
     * @return array { ok: bool, status: 'provisional'|'rejected', invoice?: array, amount?: int, reason?: string }
     */
    public static function acceptOffline(string $storeId, string $tokenString, array $context = []): array {
        if (!self::isEnabled($storeId)) {
            return ['ok' => false, 'status' => 'rejected', 'reason' => 'Offline acceptance disabled for this store'];
        }

        // Per-invoice "allow any mint" override: honored only when the store
        // permits per-transaction overrides AND the attached invoice opted in.
        $existing = $context['invoice'] ?? null;
        $allowAnyMint = self::perTxOverrideEnabled($storeId)
            && $existing !== null
            && !empty($existing['cashu_offline_allow_any_mint']);

        $verify = self::verifyToken($storeId, $tokenString, $allowAnyMint);
        if (!$verify['ok']) {
            return ['ok' => false, 'status' => 'rejected', 'reason' => $verify['reason']];
        }

        $amount = $verify['amount'];

        // If attaching to a pre-created invoice, the token must cover its amount.
        if ($existing !== null && isset($existing['amount_sats'])
            && $amount < (int)$existing['amount_sats']) {
            return ['ok' => false, 'status' => 'rejected',
                    'reason' => "Token amount ({$amount}) is less than requested ({$existing['amount_sats']})"];
        }

        // Caps.
        $perTx = self::maxPerTx($storeId);
        if ($perTx > 0 && $amount > $perTx) {
            return ['ok' => false, 'status' => 'rejected',
                    'reason' => "Amount {$amount} exceeds the per-transaction offline limit ({$perTx})"];
        }
        $maxOut = self::maxOutstanding($storeId);
        if ($maxOut > 0 && (self::outstandingExposure($storeId) + $amount) > $maxOut) {
            return ['ok' => false, 'status' => 'rejected',
                    'reason' => "Accepting this would exceed the outstanding offline exposure limit ({$maxOut})"];
        }

        $unit = Config::getStoreMintUnit($storeId);
        $now = Database::timestamp();

        // beginImmediate (not beginTransaction): take the write lock up front so
        // the exposure re-check below is authoritative. The pre-transaction cap
        // check above is racy — two concurrent acceptances of distinct tokens
        // can both read the same exposure and both pass, overshooting the cap.
        Database::beginImmediate();
        try {
            // Authoritative cap re-check under the write lock. With acceptances
            // serialized by beginImmediate, this sees every already-committed
            // acceptance, so concurrent accepts can no longer jointly overshoot.
            if ($maxOut > 0 && (self::outstandingExposure($storeId) + $amount) > $maxOut) {
                Database::rollback();
                return ['ok' => false, 'status' => 'rejected',
                        'reason' => "Accepting this would exceed the outstanding offline exposure limit ({$maxOut})"];
            }
            if ($existing !== null) {
                $invoiceId = $existing['id'];
                Database::update('invoices', [
                    'status' => 'Provisional',
                    'payment_rail' => 'cashu',
                    'mint_url' => $verify['mint_url'],
                    'cashu_offline_token' => $tokenString,
                ], 'id = ?', [$invoiceId]);
            } else {
                $invoiceId = Database::generateId('inv');
                Database::insert('invoices', [
                    'id' => $invoiceId,
                    'store_id' => $storeId,
                    'status' => 'Provisional',
                    'additional_status' => 'None',
                    'amount' => (string)$amount,
                    'currency' => $unit,
                    'amount_sats' => $amount,
                    'payment_rail' => 'cashu',
                    'mint_url' => $verify['mint_url'],
                    'cashu_offline_token' => $tokenString,
                    'created_at' => $now,
                    // Provisional invoices don't time-expire; they wait for
                    // reconnection. Use a far-future bound so the 'New' expiry
                    // sweep never touches them.
                    'expiration_time' => $now + 315360000, // ~10 years
                ]);
            }

            // Replay guard + exposure ledger: a duplicate Y means this exact
            // proof was already accepted offline -> reject the whole token.
            foreach ($verify['ys'] as $y) {
                try {
                    Database::query(
                        "INSERT INTO cashu_offline_locks (y, invoice_id, store_id, amount, created_at)
                         VALUES (?, ?, ?, ?, ?)",
                        [$y, $invoiceId, $storeId, $amount, $now]
                    );
                } catch (\Throwable $dup) {
                    throw new RuntimeException('Token was already accepted offline (replay)');
                }
            }

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollback();
            return ['ok' => false, 'status' => 'rejected', 'reason' => $e->getMessage()];
        }

        $invoice = Invoice::getById($invoiceId);
        // Distinct event so merchant integrations do NOT treat an at-risk
        // offline acceptance as a final settlement.
        WebhookSender::fireEvent($storeId, 'InvoiceProvisional', $invoice);

        return ['ok' => true, 'status' => 'provisional', 'invoice' => $invoice, 'amount' => $amount];
    }

    /**
     * Create a 'New' cashu-rail invoice for a generated payment request, so the
     * POS screen has a row to poll and the eventual receipt (online or offline)
     * attaches to it. Returns the new invoice id.
     */
    public static function createPendingCashuInvoice(
        string $storeId,
        string $displayAmount,
        string $currency,
        int $amountSats,
        ?string $memo = null,
        int $ttlSeconds = 3600,
        bool $allowAnyMint = false
    ): string {
        $invoiceId = Database::generateId('inv');
        $now = Database::timestamp();
        Database::insert('invoices', [
            'id' => $invoiceId,
            'store_id' => $storeId,
            'status' => 'New',
            'additional_status' => 'None',
            'amount' => $displayAmount,
            'currency' => $currency,
            'amount_sats' => $amountSats,
            'payment_rail' => 'cashu',
            'mint_url' => Config::getStoreMintUrl($storeId),
            'cashu_offline_allow_any_mint' => $allowAnyMint ? 1 : 0,
            'metadata' => $memo ? json_encode(['itemDesc' => $memo]) : null,
            'created_at' => $now,
            'expiration_time' => $now + max(60, $ttlSeconds),
        ]);
        WebhookSender::fireEvent($storeId, 'InvoiceCreated', Invoice::getById($invoiceId));
        return $invoiceId;
    }

    /**
     * Record a successful ONLINE Cashu token receipt as a Settled invoice, so
     * the merchant ledger captures cashu-rail payments the same way offline
     * ones are. Reuses Invoice::updateStatus for the settlement webhooks +
     * paid notification.
     *
     * @param int $amount Net amount received (mint smallest unit, after swap fee)
     * @return array The settled invoice row
     */
    public static function recordOnlineReceipt(string $storeId, int $amount, string $mintUrl, ?array $existing = null): array {
        $unit = Config::getStoreMintUnit($storeId);
        $now = Database::timestamp();

        if ($existing !== null) {
            $invoiceId = $existing['id'];
            Database::update('invoices', [
                'payment_rail' => 'cashu',
                'mint_url' => rtrim($mintUrl, '/'),
            ], 'id = ?', [$invoiceId]);
        } else {
            $invoiceId = Database::generateId('inv');
            Database::insert('invoices', [
                'id' => $invoiceId,
                'store_id' => $storeId,
                'status' => 'New',
                'additional_status' => 'None',
                'amount' => (string)$amount,
                'currency' => $unit,
                'amount_sats' => $amount,
                'payment_rail' => 'cashu',
                'mint_url' => rtrim($mintUrl, '/'),
                'created_at' => $now,
                'expiration_time' => $now + 3600,
            ]);
            WebhookSender::fireEvent($storeId, 'InvoiceCreated', Invoice::getById($invoiceId));
        }

        Invoice::updateStatus($invoiceId, 'Settled', null, 'cashu');
        return Invoice::getById($invoiceId);
    }

    // ====================================================================
    // RECONCILIATION (online)
    // ====================================================================

    /**
     * Swap Provisional offline invoices at the mint now that it may be
     * reachable. Success -> Settled (existing auto-melt then pays out). A mint
     * rejection (double-spend / invalid) -> Invalid + recorded reason. Still
     * offline -> left Provisional for the next pass.
     *
     * @return array { processed:int, settled:int, failed:int, skipped:int }
     */
    public static function reconcile(int $limit = 20): array {
        $summary = ['processed' => 0, 'settled' => 0, 'failed' => 0, 'skipped' => 0];

        $rows = Database::fetchAll(
            "SELECT * FROM invoices
             WHERE status = 'Provisional' AND payment_rail = 'cashu'
               AND cashu_offline_token IS NOT NULL
             ORDER BY created_at ASC
             LIMIT ?",
            [$limit]
        );

        foreach ($rows as $invoice) {
            $summary['processed']++;
            $invoiceId = $invoice['id'];
            try {
                $wallet = Invoice::getWalletForStore($invoice['store_id'], $invoice['mint_url'] ?? null);
                // Online swap consumes the bearer token and stores fresh proofs
                // in the wallet balance (existing auto-melt handles payout).
                $wallet->receive($invoice['cashu_offline_token']);

                Invoice::updateStatus($invoiceId, 'Settled', null, 'cashu');
                self::clearLocks($invoiceId);
                $summary['settled']++;
            } catch (CashuNetworkException $e) {
                // Mint still unreachable — try again next pass.
                $summary['skipped']++;
            } catch (\Throwable $e) {
                // Mint responded and rejected (double-spent / already redeemed /
                // invalid), or another terminal error: this is a real loss event.
                Database::update('invoices', [
                    'cashu_offline_fail_reason' => substr($e->getMessage(), 0, 500),
                ], 'id = ?', [$invoiceId]);
                Invoice::updateStatus($invoiceId, 'Invalid', 'OfflineReconcileFailed');
                self::clearLocks($invoiceId);
                $summary['failed']++;
            }
        }

        return $summary;
    }

    private static function clearLocks(string $invoiceId): void {
        Database::query("DELETE FROM cashu_offline_locks WHERE invoice_id = ?", [$invoiceId]);
    }
}
