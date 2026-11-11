<?php
/**
 * Builds, signs, and broadcasts the Taproot script-path claim transaction
 * that completes a reverse swap. Triggered by SwapPoller once the provider's
 * lockup transaction is in the mempool / confirmed.
 *
 * Boltz reverse-swap claim leaf (used by Zeus + Boltz):
 *   OP_SIZE <0x20> OP_EQUALVERIFY OP_HASH160 <hash160(preimage_hash)>
 *                OP_EQUALVERIFY <claim_pubkey_xonly> OP_CHECKSIG
 *
 * Witness:
 *   [schnorr_sig, preimage (32B), claim_script, control_block]
 */

require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../safe_http.php';
require_once __DIR__ . '/../onchain/wallet.php';
require_once __DIR__ . '/../onchain/provider.php';
require_once __DIR__ . '/../crypto/secp256k1.php';
require_once __DIR__ . '/../crypto/schnorr.php';
require_once __DIR__ . '/../crypto/taproot.php';
require_once __DIR__ . '/../crypto/tx_builder.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/settlement_context.php';

final class SwapClaimer {
    // Approximate vsize of a reverse-swap claim tx (1 Taproot script-path input
    // with [sig, preimage, script, control-block] witness, 1 output) — used to
    // turn a sat/vB feerate into an absolute claim fee. Rounded up slightly so
    // we err toward overpaying rather than stranding the tx in the mempool.
    private const CLAIM_TX_VSIZE = 150;

    // Sane band for the derived feerate (sat/vB). A bad feerate can neither
    // strand the claim (too low to confirm) nor burn the output to miners (too
    // high). DEFAULT is the absolute last resort when neither Esplora nor the
    // cache yields a value.
    private const FEERATE_MIN_SAT_VB = 1.0;
    private const FEERATE_MAX_SAT_VB = 100.0;
    private const FEERATE_DEFAULT_SAT_VB = 2.0;
    /**
     * Build, sign, and broadcast the claim transaction. Returns the txid on
     * success and stores it in the row's table (swap_attempts or
     * sweep_attempts, per the supplied context). Throws on any error so the
     * caller can mark the row with an error_message.
     *
     * @param array $row the swap row (assoc) — same column shape on both tables
     * @param string $lockupTxHex hex of the lockup transaction (from provider status)
     * @param SwapSettlementContext|null $ctx settlement context (defaults to customer)
     * @return string txid (hex, big-endian display order)
     */
    public static function buildAndBroadcast(array $row, string $lockupTxHex, ?SwapSettlementContext $ctx = null): string {
        // Schnorr-signing the claim needs GMP. Swaps can't be created without
        // it, but a host downgrade (or migration to a GMP-less box) with swaps
        // still pending would otherwise die here with a bare "undefined
        // function" instead of a message the operator can act on.
        require_once __DIR__ . '/../onchain/wallet.php';
        if (($envError = OnchainWallet::environmentError()) !== null) {
            throw new RuntimeException($envError);
        }
        $ctx = $ctx ?? new CustomerSwapSettlement();
        $table = $ctx->tableName();
        // 1. Parse the lockup transaction; find the output paying our lockup_address.
        $lockupRaw = self::hexToBin($lockupTxHex);
        // The lockup tx may be a segwit transaction; strip marker+flag so the
        // existing parser (which doesn't handle witness) sees the right shape.
        $lockupRawNoWit = self::stripWitnessIfPresent($lockupRaw);
        $parsed = Taproot::parseUnsignedTx($lockupRawNoWit);

        // The Taproot output for our lockup_address has scriptPubKey = OP_1 OP_PUSH32 <outkey>
        $outputKey = Taproot::decodeP2trAddress($row['lockup_address'], $row['network'] ?? 'mainnet');
        if ($outputKey === null) {
            throw new RuntimeException('Cannot decode lockup_address as P2TR');
        }
        $expectedSpk = TxBuilder::p2trScript($outputKey);

        $lockupVout = -1;
        $lockupAmount = 0;
        foreach ($parsed['outputs'] as $idx => $out) {
            if ($out['script'] === $expectedSpk) {
                $lockupVout = $idx;
                $lockupAmount = $out['value'];
                break;
            }
        }
        if ($lockupVout < 0) {
            throw new RuntimeException('Lockup output not found in provider lockup tx');
        }

        // Cross-check the on-chain amount the provider actually locked up
        // against the swap's agreed economics, before signing over it:
        //   * lower bound: it must cover target_onchain_amount_sats (the
        //     onchainAmount we requested). A lockup below that means the
        //     provider under-funded and we'd claim less than we paid the LN
        //     invoice for — a direct loss.
        //   * upper bound: it must not exceed invoice_amount_sats (what we
        //     paid over Lightning). The provider always takes a fee, so the
        //     on-chain side can never legitimately exceed the LN side; a larger
        //     value indicates a parser misread/overflow (the BIP341 sighash
        //     commits to $lockupAmount, so a wrong value invalidates the claim
        //     anyway — fail loudly rather than broadcast a dead tx).
        // A small tolerance absorbs rounding.
        $targetLockup  = (int)($row['target_onchain_amount_sats'] ?? 0);
        $invoiceAmount = (int)($row['invoice_amount_sats'] ?? 0);
        if ($targetLockup > 0) {
            $lowTol = max(1, (int)ceil($targetLockup * 0.001));
            if ($lockupAmount < $targetLockup - $lowTol) {
                throw new RuntimeException(
                    "Lockup amount {$lockupAmount} sat is below agreed on-chain amount "
                    . "{$targetLockup} sat (provider under-funded)"
                );
            }
        }
        if ($invoiceAmount > 0) {
            $highTol = max(1, (int)ceil($invoiceAmount * 0.001));
            if ($lockupAmount > $invoiceAmount + $highTol) {
                throw new RuntimeException(
                    "Lockup amount {$lockupAmount} sat exceeds paid invoice amount "
                    . "{$invoiceAmount} sat (possible parse error)"
                );
            }
        }

        // 2. Compute lockup txid (double-sha256 of NO-WITNESS serialization, displayed big-endian).
        $lockupTxidLE = hash('sha256', hash('sha256', $lockupRawNoWit, true), true);
        $lockupTxidBE = strrev($lockupTxidLE);

        // Persist what we observed before attempting the spend. Includes the
        // raw lockup tx hex so an operator could manually craft a claim even
        // without access to the originating Bitcoin node.
        Database::getInstance()->prepare(
            "UPDATE {$table}
                SET lockup_txid = ?, lockup_vout = ?, lockup_amount_sats = ?,
                    lockup_tx_hex = COALESCE(lockup_tx_hex, ?),
                    updated_at = ?
              WHERE id = ?"
        )->execute([
            bin2hex($lockupTxidBE),
            $lockupVout,
            $lockupAmount,
            $lockupTxHex,
            time(),
            $row['id'],
        ]);

        // 3. Resolve the merchant payout script for `merchant_address`. We derive
        // address strings from the store's xpub at allocation time, so we can
        // safely re-derive the scriptPubKey from the address string.
        $store = Database::fetchOne(
            "SELECT onchain_xpub, onchain_address_type, onchain_network FROM stores WHERE id = ?",
            [$row['store_id']]
        );
        if (!$store) {
            throw new RuntimeException('store row missing for swap_attempts.store_id');
        }
        $outScript = self::scriptForMerchantAddress(
            $row['merchant_address'],
            $store['onchain_address_type'] ?: 'P2WPKH',
            $row['network'] ?? ($store['onchain_network'] ?: 'mainnet')
        );

        // 4. Compute the claim amount: lockup_amount minus a current claim-fee
        // estimate (re-fetched, since on-chain feerate may have moved since
        // create-time). swap_attempts has no stored fee column, so if the
        // re-fetch fails the placeholder below is left intact and the sanity
        // check at line 118 forces the hardcoded ~1 sat/vB fallback.
        $provider = SwapProviderFactory::byName($row['provider']);
        $claimFeeEstimate = (int)($row['lockup_amount_sats'] ?? 0); // placeholder; sanity-floored below
        try {
            if ($provider) {
                $pair = $provider->getReversePairInfo($row['network'] ?? 'mainnet');
                $claimFeeEstimate = $pair->claimFeeEstimateSats;
            }
        } catch (Throwable $e) {
            error_log("swap claimer: pair re-fetch failed, using stored estimate: " . $e->getMessage());
        }
        if ($claimFeeEstimate <= 0 || $claimFeeEstimate >= $lockupAmount) {
            // Provider estimate unusable: derive the fee from the live mempool
            // feerate (Esplora /fee-estimates) applied to the claim tx vsize.
            // Falls back to the last cached feerate when Esplora is unreachable,
            // then a conservative default — all clamped to a sane band. Replaces
            // the old flat 200-sat guess that could strand the claim in any
            // non-trivial fee environment.
            $claimFeeEstimate = self::estimateClaimFeeSats($row['network'] ?? 'mainnet', self::CLAIM_TX_VSIZE);
        }
        if ($claimFeeEstimate >= $lockupAmount) {
            throw new RuntimeException('Claim fee estimate exceeds lockup amount');
        }
        $claimOutAmount = $lockupAmount - $claimFeeEstimate;
        if ($claimOutAmount < 546) {
            throw new RuntimeException('Lockup output too small after claim fee');
        }

        // 5. Build the unsigned tx (1 input, 1 output).
        $unsigned = TxBuilder::buildUnsigned(
            $lockupTxidBE, $lockupVout,
            0xFFFFFFFD, // sequence: enables RBF, no relative locktime
            $outScript,
            $claimOutAmount,
            0 // locktime
        );

        // 6. Compute the BIP341 script-path sighash for the claim leaf.
        $claimScript = self::hexToBin($row['claim_leaf_script_hex']);
        $refundScript = self::hexToBin($row['refund_leaf_script_hex']);
        $claimLeafHash  = Taproot::tapLeafHash(Taproot::TAPSCRIPT_LEAF_VERSION, $claimScript);
        $refundLeafHash = Taproot::tapLeafHash(Taproot::TAPSCRIPT_LEAF_VERSION, $refundScript);
        $lockupSpk = TxBuilder::p2trScript($outputKey);
        $sighash = Taproot::sighashSchnorrScriptPath(
            $unsigned,
            0,
            [$lockupSpk],
            [$lockupAmount],
            $claimLeafHash
        );

        // 7. Sign with claim_privkey.
        $claimPriv = self::hexToBin($row['claim_privkey_hex']);
        $sig = Schnorr::sign($claimPriv, $sighash);

        // 8. Build the control block. The output key parity is what BIP341
        // requires for script-path; recompute from internal_key + merkle_root.
        $refundPub33 = self::hexToBin($row['refund_pubkey_hex']);
        $claimPub33  = self::hexToBin($row['claim_pubkey_hex']);
        $internalKey = Taproot::keyAggInternalKey([$refundPub33, $claimPub33]);
        $merkleRoot = Taproot::tapBranchHash($claimLeafHash, $refundLeafHash);
        [$expectedOutKey, $parity] = Taproot::tweakOutputKey($internalKey, $merkleRoot);
        if ($expectedOutKey !== $outputKey) {
            // Sanity: the lockup_address Boltz returned should produce the same
            // output key we compute from the tree. Caught earlier at create
            // time too; this catches DB-row tampering.
            throw new RuntimeException('Lockup output key does not match recomputed taproot output');
        }

        $preimage = self::hexToBin($row['preimage_hex']);
        $controlBlock = Taproot::controlBlock2Leaf(
            Taproot::TAPSCRIPT_LEAF_VERSION,
            $parity,
            $internalKey,
            $refundLeafHash
        );

        // 9. Attach witness and broadcast.
        $finalTx = TxBuilder::attachWitness($unsigned, [$sig, $preimage, $claimScript, $controlBlock]);
        $finalHex = bin2hex($finalTx);

        // The claim txid is the double-SHA256 of the NON-witness serialization
        // ($unsigned has no witness), so we can compute it without the network.
        $claimTxid = bin2hex(strrev(hash('sha256', hash('sha256', $unsigned, true), true)));

        // Atomic claim gate. cron (30s) and the checkout poll (8s) can both
        // reach this for the same row, because the last_polled_at gate's window
        // elapses during the multi-second build above. We record claim_txid +
        // the signed hex and flip to claim.broadcast in ONE conditional UPDATE,
        // BEFORE broadcasting:
        //   - claim_txid (not status) is the gate, because processRow() mirrors
        //     the provider status over our status column every tick and would
        //     clobber any status sentinel.
        //   - persisting before broadcast means a relayed-but-timed-out
        //     broadcast still leaves the txid on record, so we never blindly
        //     rebuild+rebroadcast.
        // A poller that loses the race here must NOT broadcast.
        $claim = Database::getInstance()->prepare(
            "UPDATE {$table}
                SET claim_txid = ?, claim_tx_hex = ?, status = 'claim.broadcast', updated_at = ?
              WHERE id = ? AND claim_txid IS NULL"
        );
        $claim->execute([$claimTxid, $finalHex, time(), $row['id']]);
        if ($claim->rowCount() !== 1) {
            // Another poller already owns/finished the claim for this row.
            $existing = Database::fetchOne("SELECT claim_txid FROM {$table} WHERE id = ?", [$row['id']]);
            return (string)($existing['claim_txid'] ?? $claimTxid);
        }

        try {
            self::broadcastWithFallback($row, $finalHex, $claimTxid);
        } catch (Throwable $e) {
            // A genuine broadcast failure (not an "already known" race): release
            // the claim gate so the next poll retries. The tx is deterministic,
            // so a retry rebroadcasts identical bytes (same txid). We restore the
            // provider-mirrored status the row had on entry.
            Database::getInstance()->prepare(
                "UPDATE {$table} SET claim_txid = NULL, status = ?, updated_at = ? WHERE id = ?"
            )->execute([(string)($row['status'] ?? 'transaction.mempool'), time(), $row['id']]);
            throw $e;
        }

        return $claimTxid;
    }

    /**
     * Try the provider's broadcast endpoint first; fall back to Esplora if
     * the provider call fails. Last resort raises.
     */
    private static function broadcastWithFallback(array $row, string $rawTxHex, string $expectedTxid): string {
        $provider = SwapProviderFactory::byName($row['provider']);
        $network = $row['network'] ?? 'mainnet';
        if ($provider) {
            try {
                return $provider->broadcastTx($network, $rawTxHex);
            } catch (Throwable $e) {
                // The claim tx is deterministic, so on a retry after a relayed-
                // but-timed-out broadcast the node reports it as already known —
                // treat that as success rather than failing the row forever.
                if (self::isAlreadyKnownError($e->getMessage())) {
                    return $expectedTxid;
                }
                error_log("swap broadcast via {$row['provider']} failed: " . $e->getMessage());
            }
        }
        // Esplora fallback (does not exist on regtest). Broadcasting is rare
        // and critical, so every default host is tried — no cooldown skipping.
        require_once __DIR__ . '/../onchain/provider.php';
        foreach (EsploraProvider::defaultUrlsForNetwork($network) ?? [] as $esploraUrl) {
            $url = rtrim($esploraUrl, '/') . '/tx';
            $result = \SafeHttp::request($url, [
                'method' => 'POST',
                'body' => $rawTxHex,
                'timeout' => 20,
                'headers' => ['Content-Type: text/plain'],
                'allowPrivate' => \SafeHttp::privateEndpointsAllowed(),
            ]);
            if ($result['error'] === '' && $result['status'] < 400) {
                return trim($result['body']);
            }
            if (self::isAlreadyKnownError($result['body'])) {
                return $expectedTxid;
            }
            error_log("swap broadcast via esplora ({$esploraUrl}) failed: HTTP {$result['status']}: " . substr($result['body'], 0, 200));
        }
        throw new RuntimeException('All broadcast paths failed for swap claim');
    }

    /**
     * Recognise the various "this transaction is already in the mempool / chain"
     * responses from Bitcoin nodes and block explorers. On a retry of a
     * deterministic claim tx these are the expected (benign) outcome, not a
     * failure — otherwise a relayed-but-timed-out first broadcast would wedge
     * the row into a permanent error loop.
     */
    private static function isAlreadyKnownError(string $msg): bool {
        $m = strtolower($msg);
        foreach ([
            'already in mempool',
            'already known',
            'txn-already-known',
            'txn-already-in-mempool',
            'transaction already in block chain',
            'already in block chain',
            'duplicate transaction',
        ] as $needle) {
            if (strpos($m, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Estimate the absolute claim fee (sats) for a $vsize-vByte claim tx on
     * $network. Order of preference:
     *   1. live mempool feerate from Esplora /fee-estimates (and cache it),
     *   2. the most recently cached feerate (Esplora unreachable / no public
     *      endpoint, e.g. regtest),
     *   3. a conservative default.
     * The feerate is clamped to a sane band before being applied so a bad value
     * can neither strand the tx (too low) nor burn the output (too high).
     */
    public static function estimateClaimFeeSats(string $network, int $vsize): int {
        $rate = null;
        foreach (EsploraProvider::defaultUrlsForNetwork($network) ?? [] as $esploraUrl) {
            $rate = self::fetchFeerateSatPerVb($esploraUrl);
            if ($rate !== null) {
                self::cacheFeerate($network, $rate); // remember last good
                break;
            }
        }
        if ($rate === null) {
            $rate = self::cachedFeerate($network); // Esplora unavailable -> cache
        }
        if ($rate === null) {
            $rate = self::FEERATE_DEFAULT_SAT_VB; // absolute last resort
        }
        $rate = max(self::FEERATE_MIN_SAT_VB, min($rate, self::FEERATE_MAX_SAT_VB));
        return max(1, (int)ceil($rate * $vsize));
    }

    /**
     * Fetch a sat/vB feerate from an Esplora-style /fee-estimates endpoint.
     * Returns the rate for a moderate confirmation target (≈3 blocks, falling
     * back to faster targets, then any positive value), or null on any failure.
     */
    public static function fetchFeerateSatPerVb(string $esploraBaseUrl): ?float {
        $url = rtrim($esploraBaseUrl, '/') . '/fee-estimates';
        $result = \SafeHttp::request($url, [
            'timeout' => 10,
            'allowPrivate' => \SafeHttp::privateEndpointsAllowed(),
        ]);
        if ($result['error'] !== '' || $result['status'] < 200 || $result['status'] >= 300) {
            return null;
        }
        $data = json_decode($result['body'], true);
        if (!is_array($data)) {
            return null;
        }
        foreach (['3', '2', '1'] as $target) {
            if (isset($data[$target]) && is_numeric($data[$target]) && (float)$data[$target] > 0) {
                return (float)$data[$target];
            }
        }
        // No standard target present — take any positive estimate.
        foreach ($data as $v) {
            if (is_numeric($v) && (float)$v > 0) {
                return (float)$v;
            }
        }
        return null;
    }

    private static function cacheFeerate(string $network, float $rate): void {
        Config::set('swap_claim_feerate_' . $network, ['rate' => $rate, 'timestamp' => time()]);
    }

    private static function cachedFeerate(string $network): ?float {
        $d = Config::get('swap_claim_feerate_' . $network);
        if (!is_array($d) || !isset($d['rate'])) {
            return null;
        }
        $r = (float)$d['rate'];
        return $r > 0 ? $r : null;
    }

    private static function scriptForMerchantAddress(string $addr, string $type, string $network): string {
        // We re-derive the address from the xpub at allocation, so we can rely
        // on the address being a valid P2WPKH or P2SH-P2WPKH in our supported
        // address-type set. Decode it manually here.
        require_once __DIR__ . '/../onchain/wallet.php';
        if ($type === 'P2WPKH') {
            // Bech32 segwit v0; decode HRP + program.
            $hrp = $network === 'mainnet' ? 'bc' : ($network === 'regtest' ? 'bcrt' : 'tb');
            [, $bech] = OnchainWallet::SUPPORTED_NETWORKS ? [null, null] : [null, null]; // suppress lint
            // Use bitwasp/bech32 (already a dep) to decode.
            return self::p2wpkhScriptFromAddress($addr, $hrp);
        }
        if ($type === 'P2SH-P2WPKH') {
            return self::p2shScriptFromBase58($addr);
        }
        throw new RuntimeException("Unsupported merchant address type: {$type}");
    }

    private static function p2wpkhScriptFromAddress(string $address, string $expectedHrp): string {
        require_once __DIR__ . '/../../vendor/autoload.php';
        try {
            [$hrp, $version, $program] = self::decodeBech32Segwit($address);
        } catch (Throwable $e) {
            throw new RuntimeException("Cannot decode merchant address: " . $e->getMessage());
        }
        if ($hrp !== $expectedHrp || $version !== 0 || count($program) !== 20) {
            throw new RuntimeException('merchant address: expected v0 20-byte segwit');
        }
        return TxBuilder::p2wpkhScript(implode('', array_map('chr', $program)));
    }

    private static function p2shScriptFromBase58(string $address): string {
        // Decode base58check; first byte is version, next 20 are scriptHash.
        $decoded = self::base58CheckDecode($address);
        if ($decoded === null || strlen($decoded) !== 21) {
            throw new RuntimeException("merchant address: invalid base58 P2SH");
        }
        $scriptHash = substr($decoded, 1);
        return TxBuilder::p2shScript($scriptHash);
    }

    // -------- helpers --------

    private static function hexToBin(string $hex): string {
        $b = @hex2bin($hex);
        if ($b === false) {
            throw new RuntimeException('invalid hex');
        }
        return $b;
    }

    private static function stripWitnessIfPresent(string $raw): string {
        // A segwit tx has marker=0x00 flag=0x01 at offset 4 (after version).
        // Need at least version(4)+marker+flag(2)+locktime(4) to be parseable.
        if (strlen($raw) >= 10 && $raw[4] === "\x00" && $raw[5] === "\x01") {
            $rawLen = strlen($raw);
            // Reparse to find where the witness section starts/ends, and rebuild
            // without it. Easier: serialize the inputs/outputs ourselves.
            // We do a minimal parse: skip marker+flag, parse inputs+outputs into
            // a no-witness layout, then serialize back.
            $pos = 6;
            $version = substr($raw, 0, 4);
            $numIn = self::readCompactSize($raw, $pos);
            if ($numIn > $rawLen) {
                throw new RuntimeException('stripWitness: implausible input count');
            }
            $inputs = [];
            for ($i = 0; $i < $numIn; $i++) {
                $startIn = $pos;
                $pos += 32 + 4; // prevout
                $sLen = self::readCompactSize($raw, $pos);
                $pos += $sLen;
                $pos += 4; // sequence
                if ($pos > $rawLen) {
                    throw new RuntimeException('stripWitness: input overruns buffer');
                }
                $inputs[] = substr($raw, $startIn, $pos - $startIn);
            }
            $numOut = self::readCompactSize($raw, $pos);
            if ($numOut > $rawLen) {
                throw new RuntimeException('stripWitness: implausible output count');
            }
            $outputsStart = $pos;
            for ($i = 0; $i < $numOut; $i++) {
                $pos += 8;
                $sLen = self::readCompactSize($raw, $pos);
                $pos += $sLen;
                if ($pos > $rawLen) {
                    throw new RuntimeException('stripWitness: output overruns buffer');
                }
            }
            $outputsRaw = substr($raw, $outputsStart, $pos - $outputsStart);
            // Skip the witness section (variable length); locktime is the
            // final 4 bytes of $raw.
            $locktime = substr($raw, -4);
            $out = $version . Taproot::compactSize($numIn) . implode('', $inputs)
                 . Taproot::compactSize($numOut) . $outputsRaw . $locktime;
            return $out;
        }
        return $raw;
    }

    private static function readCompactSize(string $raw, int &$pos): int {
        $len = strlen($raw);
        if ($pos < 0 || $pos + 1 > $len) {
            throw new RuntimeException('stripWitness: truncated compactSize');
        }
        $b = ord($raw[$pos]); $pos++;
        $need = function (int $n) use ($pos, $len) {
            if ($pos + $n > $len) {
                throw new RuntimeException('stripWitness: truncated compactSize value');
            }
        };
        if ($b < 0xFD) return $b;
        if ($b === 0xFD) { $need(2); $v = unpack('v', substr($raw, $pos, 2))[1]; $pos += 2; return $v; }
        if ($b === 0xFE) { $need(4); $v = unpack('V', substr($raw, $pos, 4))[1]; $pos += 4; return $v; }
        $need(8); $v = unpack('P', substr($raw, $pos, 8))[1]; $pos += 8;
        return $v;
    }

    private static function decodeBech32Segwit(string $addr): array {
        // Use existing bitwasp/bech32 to decode v0/v1 segwit addresses.
        $addr = strtolower($addr);
        $pos = strrpos($addr, '1');
        if ($pos === false) throw new RuntimeException('no bech32 separator');
        $hrp = substr($addr, 0, $pos);
        // Bech32 charset position lookup.
        $charset = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
        $data = [];
        for ($i = $pos + 1; $i < strlen($addr); $i++) {
            $d = strpos($charset, $addr[$i]);
            if ($d === false) throw new RuntimeException('bech32 char invalid');
            $data[] = $d;
        }
        if (count($data) < 7) throw new RuntimeException('bech32 too short');
        $version = $data[0];
        $programBits = array_slice($data, 1, -6);
        // 5→8 bit regroup
        $acc = 0; $bits = 0; $program = [];
        foreach ($programBits as $v) {
            $acc = ($acc << 5) | $v;
            $bits += 5;
            while ($bits >= 8) {
                $bits -= 8;
                $program[] = ($acc >> $bits) & 0xFF;
            }
        }
        return [$hrp, $version, $program];
    }

    private static function base58CheckDecode(string $s): ?string {
        // Reuse the helper logic from OnchainWallet via a copy here so we
        // don't depend on private API.
        $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
        $num = gmp_init(0);
        for ($i = 0; $i < strlen($s); $i++) {
            $pos = strpos($alphabet, $s[$i]);
            if ($pos === false) return null;
            $num = gmp_add(gmp_mul($num, 58), $pos);
        }
        $hex = gmp_strval($num, 16);
        if (strlen($hex) % 2 !== 0) $hex = '0' . $hex;
        $bytes = $hex === '0' ? '' : hex2bin($hex);
        $leading = 0;
        for ($i = 0; $i < strlen($s) && $s[$i] === '1'; $i++) $leading++;
        $decoded = str_repeat("\x00", $leading) . $bytes;
        if (strlen($decoded) < 4) return null;
        $payload = substr($decoded, 0, -4);
        $checksum = substr($decoded, -4);
        $expected = substr(hash('sha256', hash('sha256', $payload, true), true), 0, 4);
        if (!hash_equals($expected, $checksum)) return null;
        return $payload;
    }
}
