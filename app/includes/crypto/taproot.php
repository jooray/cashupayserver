<?php
/**
 * BIP341 Taproot helpers + BIP327 KeyAgg (point-math only).
 *
 * Used to construct the script-path Taproot claim of a submarine-swap lockup:
 * - Recompute the lockup output key from claim+refund pubkeys + the parsed
 *   swap tree, and assert it matches the address returned by the provider.
 * - Compute the BIP341 sighash for SIGHASH_DEFAULT over a single input.
 * - Compute the control block to include in the witness.
 *
 * Reference: https://github.com/bitcoin/bips/blob/master/bip-0341.mediawiki
 * Reference: https://github.com/bitcoin/bips/blob/master/bip-0327.mediawiki (KeyAgg only)
 *
 * MuSig2 signing (BIP327 NonceGen/PartialSign/Aggregate) is intentionally NOT
 * implemented here — see plan: shipping script-path-only claim in v1 avoids
 * the nonce-reuse footgun under PHP's no-shared-memory cron model.
 */

require_once __DIR__ . '/secp256k1.php';

final class Taproot {
    public const TAPSCRIPT_LEAF_VERSION = 0xC0;

    /**
     * Encode a Bitcoin compact-size unsigned integer.
     */
    public static function compactSize(int $n): string {
        if ($n < 0) {
            throw new InvalidArgumentException('compactSize negative');
        }
        if ($n < 0xFD) return chr($n);
        if ($n <= 0xFFFF) return "\xFD" . pack('v', $n);
        if ($n <= 0xFFFFFFFF) return "\xFE" . pack('V', $n);
        return "\xFF" . pack('P', $n); // little-endian uint64
    }

    /**
     * tap_leaf_hash(leaf) per BIP341.
     * leaf_version is one byte; script is the raw script bytes.
     */
    public static function tapLeafHash(int $leafVersion, string $script): string {
        return Secp256k1::taggedHash(
            'TapLeaf',
            chr($leafVersion & 0xFF) . self::compactSize(strlen($script)) . $script
        );
    }

    /**
     * tap_branch_hash(a, b) per BIP341 — children sorted lexicographically.
     */
    public static function tapBranchHash(string $a, string $b): string {
        return strcmp($a, $b) <= 0
            ? Secp256k1::taggedHash('TapBranch', $a . $b)
            : Secp256k1::taggedHash('TapBranch', $b . $a);
    }

    /**
     * Compute the merkle root from an ordered list of leaf hashes.
     * For a 2-leaf swap tree we use a single tap_branch over the two leaves.
     *
     * @param string[] $leafHashes
     */
    public static function merkleRoot(array $leafHashes): string {
        $n = count($leafHashes);
        if ($n === 0) {
            throw new InvalidArgumentException('No leaves to hash');
        }
        if ($n === 1) {
            return $leafHashes[0];
        }
        if ($n === 2) {
            return self::tapBranchHash($leafHashes[0], $leafHashes[1]);
        }
        // Generic ladder: leaves combined left-to-right.
        $cur = $leafHashes;
        while (count($cur) > 1) {
            $next = [];
            for ($i = 0; $i < count($cur); $i += 2) {
                if ($i + 1 < count($cur)) {
                    $next[] = self::tapBranchHash($cur[$i], $cur[$i + 1]);
                } else {
                    $next[] = $cur[$i];
                }
            }
            $cur = $next;
        }
        return $cur[0];
    }

    /**
     * Compute the taproot output key (x-only) and its y-parity bit from an
     * internal x-only key and a (possibly empty) merkle root.
     *
     * @return array{0:string, 1:int}  [outputKeyXOnly, parity]
     */
    public static function tweakOutputKey(string $internalXOnly, string $merkleRoot): array {
        if (strlen($internalXOnly) !== 32) {
            throw new InvalidArgumentException('internal x-only key must be 32 bytes');
        }
        if ($merkleRoot !== '' && strlen($merkleRoot) !== 32) {
            throw new InvalidArgumentException('merkle root must be empty or 32 bytes');
        }
        $t = Secp256k1::taggedHash('TapTweak', $internalXOnly . $merkleRoot);
        $tInt = Secp256k1::bytesToNum($t);
        if ($tInt->cmp(Secp256k1::n()) >= 0) {
            throw new RuntimeException('TapTweak overflow (negligible probability)');
        }
        $P = Secp256k1::liftX(Secp256k1::bytesToNum($internalXOnly));
        if ($P === null) {
            throw new RuntimeException('internal key has no curve lift');
        }
        $tG = Secp256k1::generatorMult($tInt);
        $Q = Secp256k1::pointAdd($P, $tG);
        if ($Q === null) {
            throw new RuntimeException('output key is point at infinity');
        }
        return [Secp256k1::numTo32Bytes($Q[0]), Secp256k1::pointParity($Q)];
    }

    /**
     * BIP327 KeyAgg over an ordered list of 33-byte compressed pubkeys.
     * Returns the 32-byte x-only aggregate, which is the Taproot internal
     * key per BIP341 convention (the x-coordinate of the aggregate point).
     *
     * Pure math, no nonces or secret state — safe to invoke anywhere.
     *
     * @param string[] $pubkeys33
     */
    public static function keyAggInternalKey(array $pubkeys33): string {
        if (count($pubkeys33) === 0) {
            throw new InvalidArgumentException('No pubkeys to aggregate');
        }
        foreach ($pubkeys33 as $pk) {
            if (strlen($pk) !== 33) {
                throw new InvalidArgumentException('Each pubkey must be 33 bytes compressed');
            }
        }
        $L = Secp256k1::taggedHash('KeyAgg list', implode('', $pubkeys33));

        // pk_2nd: second distinct pubkey (its coefficient short-circuits to 1)
        $pk2nd = null;
        foreach ($pubkeys33 as $pk) {
            if ($pk !== $pubkeys33[0]) { $pk2nd = $pk; break; }
        }

        $n = Secp256k1::n();
        $sum = null;
        foreach ($pubkeys33 as $pk) {
            $P = Secp256k1::compressedToPoint($pk);
            if ($P === null) {
                throw new RuntimeException('KeyAgg: invalid compressed pubkey');
            }
            if ($pk2nd !== null && $pk === $pk2nd) {
                $coef = BigNum::one();
            } else {
                $h = Secp256k1::taggedHash('KeyAgg coefficient', $L . $pk);
                $coef = Secp256k1::bytesToNum($h)->mod($n);
            }
            $term = Secp256k1::scalarMult($coef, $P);
            $sum = Secp256k1::pointAdd($sum, $term);
        }
        if ($sum === null) {
            throw new RuntimeException('KeyAgg sum is point at infinity');
        }
        // Return the x-coordinate (BIP341 internal-key convention).
        return Secp256k1::numTo32Bytes($sum[0]);
    }

    /**
     * Decode bech32m P2TR address to the 32-byte witness program (output key).
     * Network is one of mainnet/testnet/signet/regtest.
     *
     * Returns null on parse failure or non-P2TR address.
     */
    public static function decodeP2trAddress(string $address, string $network): ?string {
        $hrp = match ($network) {
            'mainnet' => 'bc',
            'testnet', 'signet' => 'tb',
            'regtest' => 'bcrt',
            default => null,
        };
        if ($hrp === null) return null;
        try {
            [$decodedHrp, $program] = self::bech32mDecode($address);
        } catch (Throwable $e) {
            return null;
        }
        if ($decodedHrp !== $hrp) return null;
        if (count($program) < 1 || $program[0] !== 1) return null; // witness v1
        // remainder is the converted program (5-bit groups). Re-pack to 8-bit.
        $bytes = self::convertBits(array_slice($program, 1), 5, 8, false);
        if ($bytes === null || count($bytes) !== 32) return null;
        return implode('', array_map('chr', $bytes));
    }

    /**
     * Encode a 32-byte witness program as bech32m P2TR address.
     */
    public static function encodeP2trAddress(string $witnessProgram32, string $network): string {
        if (strlen($witnessProgram32) !== 32) {
            throw new InvalidArgumentException('witness program must be 32 bytes for P2TR');
        }
        $hrp = match ($network) {
            'mainnet' => 'bc',
            'testnet', 'signet' => 'tb',
            'regtest' => 'bcrt',
            default => throw new InvalidArgumentException("Unsupported network: {$network}"),
        };
        $data = [1]; // witness version 1
        $bytes = array_values(unpack('C*', $witnessProgram32));
        $fiveBit = self::convertBits($bytes, 8, 5, true);
        if ($fiveBit === null) {
            throw new RuntimeException('convertBits failed');
        }
        $data = array_merge($data, $fiveBit);
        return self::bech32mEncode($hrp, $data);
    }

    // -------- BIP341 sighash --------

    /**
     * Compute the BIP341 sighash for a single-input claim transaction using
     * SIGHASH_DEFAULT (== ALL with no flag byte). Tap-leaf hash is included
     * because this is a script-path spend.
     *
     * @param string $rawTx unsigned tx bytes (no witness; for sighash we
     *                      compute prevouts/scriptPubKey/amount separately)
     * @param int $inputIndex
     * @param string[] $prevoutScripts scriptPubKey (full output script) for each input
     * @param int[] $prevoutAmounts amount in sats for each input
     * @param string $tapLeafHash 32-byte tap_leaf_hash being executed
     */
    public static function sighashSchnorrScriptPath(
        string $rawTxNoWitness,
        int $inputIndex,
        array $prevoutScripts,
        array $prevoutAmounts,
        string $tapLeafHash
    ): string {
        // Parse the raw tx minimally to extract per-input prevouts, sequences,
        // and per-output values/scripts.
        $tx = self::parseUnsignedTx($rawTxNoWitness);

        $sha = fn(string $s) => hash('sha256', $s, true);

        // shaPrevouts = sha256(concat(prevout for each input))
        $prevouts = '';
        foreach ($tx['inputs'] as $in) {
            $prevouts .= strrev($in['txid']) . pack('V', $in['vout']);
        }
        $shaPrevouts = $sha($prevouts);

        // shaAmounts = sha256(concat(amount as int64 LE))
        $amts = '';
        foreach ($prevoutAmounts as $amt) {
            $amts .= pack('P', $amt);
        }
        $shaAmounts = $sha($amts);

        // shaScriptPubKeys = sha256(concat(compactSize(script) || script))
        $spks = '';
        foreach ($prevoutScripts as $spk) {
            $spks .= self::compactSize(strlen($spk)) . $spk;
        }
        $shaScriptPubKeys = $sha($spks);

        // shaSequences = sha256(concat(sequence as uint32 LE))
        $seqs = '';
        foreach ($tx['inputs'] as $in) {
            $seqs .= pack('V', $in['sequence']);
        }
        $shaSequences = $sha($seqs);

        // shaOutputs = sha256(concat(value as int64 LE || compactSize(spk) || spk))
        $outs = '';
        foreach ($tx['outputs'] as $out) {
            $outs .= pack('P', $out['value']) . self::compactSize(strlen($out['script'])) . $out['script'];
        }
        $shaOutputs = $sha($outs);

        // Build the BIP341 sighash preimage.
        $spendType = 2; // ext_flag (script-path tapscript) << 1 | annex_present (0)
        $preimage = "\x00"; // hash_type = SIGHASH_DEFAULT
        $preimage .= pack('V', $tx['version']);
        $preimage .= pack('V', $tx['locktime']);
        $preimage .= $shaPrevouts;
        $preimage .= $shaAmounts;
        $preimage .= $shaScriptPubKeys;
        $preimage .= $shaSequences;
        $preimage .= $shaOutputs;
        $preimage .= chr($spendType);
        $preimage .= pack('V', $inputIndex);
        // tapscript ext: tap_leaf_hash || key_version (0x00) || codesep_pos (0xFFFFFFFF)
        $preimage .= $tapLeafHash;
        $preimage .= "\x00";
        $preimage .= pack('V', 0xFFFFFFFF);

        return Secp256k1::taggedHash('TapSighash', "\x00" . $preimage);
    }

    /**
     * Build a control block for a 2-leaf taproot tree (typical Boltz layout).
     *
     * @param int $leafVersion
     * @param int $outputKeyParity 0 or 1
     * @param string $internalXOnly 32-byte internal pubkey
     * @param string $siblingLeafHash 32-byte hash of the other leaf
     */
    public static function controlBlock2Leaf(int $leafVersion, int $outputKeyParity, string $internalXOnly, string $siblingLeafHash): string {
        if (strlen($internalXOnly) !== 32 || strlen($siblingLeafHash) !== 32) {
            throw new InvalidArgumentException('control block: bad input length');
        }
        $firstByte = ($leafVersion & 0xFE) | ($outputKeyParity & 0x01);
        return chr($firstByte) . $internalXOnly . $siblingLeafHash;
    }

    // -------- internals --------

    /**
     * Parse an unsigned (no-witness) transaction. Returns ['version', 'locktime',
     * 'inputs' => [{txid_be_bytes, vout, sequence, script_sig}], 'outputs' => [{value, script}]].
     *
     * @return array
     */
    public static function parseUnsignedTx(string $raw): array {
        $pos = 0;
        $len = strlen($raw);
        $version = unpack('V', self::readSlice($raw, $pos, 4, $len))[1]; $pos += 4;
        // No segwit marker/flag in unsigned tx body
        $numIn = self::readCompactSize($raw, $pos, $len);
        // A count can't exceed the remaining bytes (each input is >= 41 bytes,
        // each output >= 9), so a bogus huge compactSize from a malformed /
        // hostile provider tx is rejected instead of driving a giant loop.
        if ($numIn > $len - $pos) {
            throw new InvalidArgumentException('parseUnsignedTx: input count exceeds buffer');
        }
        $inputs = [];
        for ($i = 0; $i < $numIn; $i++) {
            $txidLE = self::readSlice($raw, $pos, 32, $len); $pos += 32;
            $vout = unpack('V', self::readSlice($raw, $pos, 4, $len))[1]; $pos += 4;
            $sLen = self::readCompactSize($raw, $pos, $len);
            $script = self::readSlice($raw, $pos, $sLen, $len); $pos += $sLen;
            $sequence = unpack('V', self::readSlice($raw, $pos, 4, $len))[1]; $pos += 4;
            $inputs[] = ['txid' => strrev($txidLE), 'vout' => $vout, 'sequence' => $sequence, 'script_sig' => $script];
        }
        $numOut = self::readCompactSize($raw, $pos, $len);
        if ($numOut > $len - $pos) {
            throw new InvalidArgumentException('parseUnsignedTx: output count exceeds buffer');
        }
        $outputs = [];
        for ($i = 0; $i < $numOut; $i++) {
            $valLE = self::readSlice($raw, $pos, 8, $len); $pos += 8;
            $value = unpack('P', $valLE)[1];
            $sLen = self::readCompactSize($raw, $pos, $len);
            $script = self::readSlice($raw, $pos, $sLen, $len); $pos += $sLen;
            $outputs[] = ['value' => $value, 'script' => $script];
        }
        $locktime = unpack('V', self::readSlice($raw, $pos, 4, $len))[1];
        return ['version' => $version, 'locktime' => $locktime, 'inputs' => $inputs, 'outputs' => $outputs];
    }

    /**
     * Read exactly $n bytes at $pos, throwing if the buffer is too short.
     * Centralizes the bounds check so a truncated/malformed tx surfaces a
     * clear error instead of an "undefined array key" off a short unpack().
     */
    private static function readSlice(string $raw, int $pos, int $n, ?int $len = null): string {
        $len = $len ?? strlen($raw);
        if ($n < 0 || $pos < 0 || $pos + $n > $len) {
            throw new InvalidArgumentException("parseUnsignedTx: truncated read ({$n} bytes at {$pos}, len {$len})");
        }
        return substr($raw, $pos, $n);
    }

    private static function readCompactSize(string $raw, int &$pos, ?int $len = null): int {
        $len = $len ?? strlen($raw);
        if ($pos + 1 > $len) {
            throw new InvalidArgumentException('readCompactSize: truncated');
        }
        $b = ord($raw[$pos]); $pos++;
        if ($b < 0xFD) return $b;
        if ($b === 0xFD) { $v = unpack('v', self::readSlice($raw, $pos, 2, $len))[1]; $pos += 2; return $v; }
        if ($b === 0xFE) { $v = unpack('V', self::readSlice($raw, $pos, 4, $len))[1]; $pos += 4; return $v; }
        $v = unpack('P', self::readSlice($raw, $pos, 8, $len))[1]; $pos += 8;
        return $v;
    }

    // -------- bech32m (BIP350) --------

    private const BECH32M_CONST = 0x2BC830A3;
    private const BECH32_CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';

    private static function bech32Polymod(array $values): int {
        $gen = [0x3B6A57B2, 0x26508E6D, 0x1EA119FA, 0x3D4233DD, 0x2A1462B3];
        $chk = 1;
        foreach ($values as $v) {
            $top = $chk >> 25;
            $chk = (($chk & 0x1FFFFFF) << 5) ^ $v;
            for ($i = 0; $i < 5; $i++) {
                if (($top >> $i) & 1) {
                    $chk ^= $gen[$i];
                }
            }
        }
        return $chk;
    }

    private static function bech32HrpExpand(string $hrp): array {
        $out = [];
        foreach (str_split($hrp) as $c) $out[] = ord($c) >> 5;
        $out[] = 0;
        foreach (str_split($hrp) as $c) $out[] = ord($c) & 31;
        return $out;
    }

    private static function bech32mEncode(string $hrp, array $data): string {
        $values = array_merge(self::bech32HrpExpand($hrp), $data, [0,0,0,0,0,0]);
        $polymod = self::bech32Polymod($values) ^ self::BECH32M_CONST;
        $checksum = [];
        for ($i = 0; $i < 6; $i++) {
            $checksum[] = ($polymod >> (5 * (5 - $i))) & 31;
        }
        $out = $hrp . '1';
        foreach (array_merge($data, $checksum) as $d) {
            $out .= self::BECH32_CHARSET[$d];
        }
        return $out;
    }

    private static function bech32mDecode(string $s): array {
        $s = strtolower($s);
        $pos = strrpos($s, '1');
        if ($pos === false || $pos < 1 || $pos + 7 > strlen($s)) {
            throw new InvalidArgumentException('Invalid bech32m: no separator');
        }
        $hrp = substr($s, 0, $pos);
        $data = [];
        for ($i = $pos + 1; $i < strlen($s); $i++) {
            $d = strpos(self::BECH32_CHARSET, $s[$i]);
            if ($d === false) throw new InvalidArgumentException('Invalid bech32m charset');
            $data[] = $d;
        }
        $check = self::bech32Polymod(array_merge(self::bech32HrpExpand($hrp), $data));
        if ($check !== self::BECH32M_CONST) {
            throw new InvalidArgumentException('Invalid bech32m checksum');
        }
        return [$hrp, array_slice($data, 0, -6)];
    }

    /**
     * General convertBits used by bech32 encode/decode.
     *
     * @return int[]|null
     */
    private static function convertBits(array $data, int $fromBits, int $toBits, bool $pad): ?array {
        $acc = 0; $bits = 0;
        $ret = [];
        $maxv = (1 << $toBits) - 1;
        $maxAcc = (1 << ($fromBits + $toBits - 1)) - 1;
        foreach ($data as $value) {
            if ($value < 0 || ($value >> $fromBits) !== 0) return null;
            $acc = (($acc << $fromBits) | $value) & $maxAcc;
            $bits += $fromBits;
            while ($bits >= $toBits) {
                $bits -= $toBits;
                $ret[] = ($acc >> $bits) & $maxv;
            }
        }
        if ($pad) {
            if ($bits > 0) $ret[] = ($acc << ($toBits - $bits)) & $maxv;
        } elseif ($bits >= $fromBits || (($acc << ($toBits - $bits)) & $maxv) !== 0) {
            return null;
        }
        return $ret;
    }
}
