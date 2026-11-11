<?php
/**
 * Minimal Bitcoin transaction builder for the swap claim path.
 *
 * Only supports what we need: a single-input single-output v2 transaction
 * that spends a Taproot lockup via script-path. Not a general-purpose builder.
 *
 * Serialization format reference: https://en.bitcoin.it/wiki/Protocol_documentation#tx
 */

require_once __DIR__ . '/taproot.php';

final class TxBuilder {
    public const SEGWIT_MARKER = 0x00;
    public const SEGWIT_FLAG = 0x01;
    public const TX_VERSION = 2;

    /**
     * Output script for a P2WPKH (BIP141) address — used for legacy claim
     * destinations. For P2WSH this caller-side; not needed here.
     *
     * @param string $pubkeyHash 20-byte pubkey hash (HASH160)
     */
    public static function p2wpkhScript(string $pubkeyHash): string {
        if (strlen($pubkeyHash) !== 20) {
            throw new InvalidArgumentException('p2wpkh: pubkeyHash must be 20 bytes');
        }
        return "\x00\x14" . $pubkeyHash; // OP_0 OP_PUSH20 <hash>
    }

    /**
     * Output script for a P2SH-P2WPKH address.
     */
    public static function p2shScript(string $scriptHash): string {
        if (strlen($scriptHash) !== 20) {
            throw new InvalidArgumentException('p2sh: scriptHash must be 20 bytes');
        }
        return "\xA9\x14" . $scriptHash . "\x87"; // OP_HASH160 OP_PUSH20 <hash> OP_EQUAL
    }

    /**
     * Output script for a P2TR (BIP341) address.
     *
     * @param string $outputKey32 32-byte x-only output key
     */
    public static function p2trScript(string $outputKey32): string {
        if (strlen($outputKey32) !== 32) {
            throw new InvalidArgumentException('p2tr: outputKey must be 32 bytes');
        }
        return "\x51\x20" . $outputKey32; // OP_1 OP_PUSH32 <key>
    }

    /**
     * Build the unsigned (no-witness) serialization of a 1-in 1-out v2 tx
     * spending the given utxo to the given output script.
     *
     * Used to feed into Taproot::sighashSchnorrScriptPath().
     *
     * @param string $prevTxidBE 32-byte txid in big-endian (display order)
     * @param int $prevVout
     * @param int $sequence
     * @param string $outScript
     * @param int $outValueSats
     * @param int $locktime
     */
    public static function buildUnsigned(
        string $prevTxidBE,
        int $prevVout,
        int $sequence,
        string $outScript,
        int $outValueSats,
        int $locktime
    ): string {
        if (strlen($prevTxidBE) !== 32) {
            throw new InvalidArgumentException('prevTxid must be 32 bytes');
        }
        if ($outValueSats < 0) {
            throw new InvalidArgumentException('Negative output value');
        }
        $out = pack('V', self::TX_VERSION);
        $out .= Taproot::compactSize(1); // input count
        $out .= strrev($prevTxidBE) . pack('V', $prevVout);
        $out .= Taproot::compactSize(0); // empty scriptSig (segwit)
        $out .= pack('V', $sequence);
        $out .= Taproot::compactSize(1); // output count
        $out .= pack('P', $outValueSats);
        $out .= Taproot::compactSize(strlen($outScript)) . $outScript;
        $out .= pack('V', $locktime);
        return $out;
    }

    /**
     * Build the final witness-bearing serialization for broadcast.
     *
     * @param string $unsignedTx the same bytes returned by buildUnsigned()
     * @param string[] $witnessStackItems each element is raw bytes; serialized
     *                                    in order as the witness for input 0.
     */
    public static function attachWitness(string $unsignedTx, array $witnessStackItems): string {
        // Reparse to slot in the marker+flag immediately after version, and
        // the witness section before the locktime.
        $version = substr($unsignedTx, 0, 4);
        $body    = substr($unsignedTx, 4, strlen($unsignedTx) - 8);
        $lock    = substr($unsignedTx, -4);

        $witness = Taproot::compactSize(count($witnessStackItems));
        foreach ($witnessStackItems as $item) {
            $witness .= Taproot::compactSize(strlen($item)) . $item;
        }

        return $version
             . chr(self::SEGWIT_MARKER)
             . chr(self::SEGWIT_FLAG)
             . $body
             . $witness
             . $lock;
    }

    /**
     * Encode a Bitcoin script-number push (BIP62-friendly).
     * Used by the refund leaf which includes <timeout> OP_CLTV.
     */
    public static function scriptNumberPush(int $n): string {
        if ($n === 0) return "\x00"; // OP_0
        if ($n >= 1 && $n <= 16) return chr(0x50 + $n); // OP_1..OP_16
        $neg = $n < 0;
        $abs = abs($n);
        $bytes = '';
        while ($abs > 0) {
            $bytes .= chr($abs & 0xFF);
            $abs >>= 8;
        }
        // If the top byte has the sign bit set, add a padding byte.
        if (ord($bytes[strlen($bytes) - 1]) & 0x80) {
            $bytes .= $neg ? "\x80" : "\x00";
        } elseif ($neg) {
            $bytes[strlen($bytes) - 1] = chr(ord($bytes[strlen($bytes) - 1]) | 0x80);
        }
        return chr(strlen($bytes)) . $bytes;
    }
}
