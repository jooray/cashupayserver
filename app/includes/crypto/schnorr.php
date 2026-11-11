<?php
/**
 * BIP340 Schnorr signing/verification.
 *
 * Used for the script-path Taproot claim of a submarine-swap lockup output
 * and for signing/verifying Nostr events (CLINK noffers, NWC). The signature
 * scheme is single-signer; multi-sig (musig2/BIP327) is out of scope for v1 —
 * script-path with preimage is sufficient for merchant claims.
 *
 * Runs over BigNum, so it works on both ext-gmp and ext-bcmath hosts.
 *
 * Reference: https://github.com/bitcoin/bips/blob/master/bip-0340.mediawiki
 */

require_once __DIR__ . '/secp256k1.php';

final class Schnorr {
    /**
     * Sign a 32-byte message digest with a 32-byte secret key.
     * Returns 64-byte signature (R.x || s) per BIP340.
     */
    public static function sign(string $seckey32, string $msg32, ?string $auxRand32 = null): string {
        if (strlen($seckey32) !== 32) {
            throw new InvalidArgumentException('seckey must be 32 bytes');
        }
        if (strlen($msg32) !== 32) {
            throw new InvalidArgumentException('msg must be 32 bytes');
        }
        // When the caller pins auxRand we must stay deterministic (BIP340 test
        // vectors, reproducible signatures), so a degenerate nonce is a hard
        // error. When we own the randomness we can resample fresh auxRand on the
        // astronomically-rare (~2^-256) degenerate nonce instead of failing the
        // caller's swap claim.
        $auxProvided = $auxRand32 !== null;
        if ($auxProvided && strlen($auxRand32) !== 32) {
            throw new InvalidArgumentException('auxRand must be 32 bytes when provided');
        }

        $n = Secp256k1::n();
        $dPrime = Secp256k1::bytesToNum($seckey32);
        if (!Secp256k1::isValidScalar($dPrime)) {
            throw new InvalidArgumentException('seckey out of range');
        }
        $P = Secp256k1::generatorMult($dPrime);
        if ($P === null) {
            throw new RuntimeException('seckey produces point at infinity');
        }
        // Normalize d so that P has even y.
        $d = Secp256k1::pointParity($P) === 0 ? $dPrime : $n->sub($dPrime);
        $pubX = Secp256k1::numTo32Bytes($P[0]);

        $maxAttempts = $auxProvided ? 1 : 8;
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $aux = $auxProvided ? $auxRand32 : random_bytes(32);

            // t = d XOR tagged_hash("BIP0340/aux", auxRand)
            $t = self::xor32(Secp256k1::numTo32Bytes($d), Secp256k1::taggedHash('BIP0340/aux', $aux));

            // rand = tagged_hash("BIP0340/nonce", t || bytes(P) || msg)
            $rand = Secp256k1::taggedHash('BIP0340/nonce', $t . $pubX . $msg32);
            $kPrime = Secp256k1::bytesToNum($rand)->mod($n);
            if ($kPrime->isZero()) {
                if ($auxProvided) {
                    throw new RuntimeException('Schnorr nonce was zero — try again with different auxRand');
                }
                continue; // resample with fresh auxRand
            }
            $R = Secp256k1::generatorMult($kPrime);
            if ($R === null) {
                if ($auxProvided) {
                    throw new RuntimeException('Schnorr nonce produced point at infinity');
                }
                continue; // resample with fresh auxRand
            }
            $k = Secp256k1::pointParity($R) === 0 ? $kPrime : $n->sub($kPrime);
            $rX = Secp256k1::numTo32Bytes($R[0]);

            // e = int(tagged_hash("BIP0340/challenge", bytes(R) || bytes(P) || msg)) mod n
            $e = Secp256k1::bytesToNum(
                Secp256k1::taggedHash('BIP0340/challenge', $rX . $pubX . $msg32)
            )->mod($n);

            $s = $k->add($e->mul($d))->mod($n);
            $sig = $rX . Secp256k1::numTo32Bytes($s);

            // Self-verify as a sanity check (BIP340 recommended for new code). A
            // failure here signals a logic/hardware fault, not a bad nonce, so we
            // surface it rather than silently resampling.
            if (!self::verify($pubX, $msg32, $sig)) {
                throw new RuntimeException('Schnorr self-verify failed');
            }
            return $sig;
        }

        // Exhausted resampling without a usable nonce — practically impossible.
        throw new RuntimeException('Schnorr signing failed to find a valid nonce after resampling');
    }

    /**
     * Verify a 64-byte signature against a 32-byte x-only pubkey and 32-byte message.
     */
    public static function verify(string $pubkey32, string $msg32, string $sig64): bool {
        if (strlen($pubkey32) !== 32 || strlen($msg32) !== 32 || strlen($sig64) !== 64) {
            return false;
        }
        $p = Secp256k1::p();
        $n = Secp256k1::n();
        $px = Secp256k1::bytesToNum($pubkey32);
        if ($px->cmp($p) >= 0) return false;
        $P = Secp256k1::liftX($px);
        if ($P === null) return false;

        $r = Secp256k1::bytesToNum(substr($sig64, 0, 32));
        $s = Secp256k1::bytesToNum(substr($sig64, 32, 32));
        if ($r->cmp($p) >= 0) return false;
        if ($s->cmp($n) >= 0) return false;

        $e = Secp256k1::bytesToNum(
            Secp256k1::taggedHash('BIP0340/challenge', substr($sig64, 0, 32) . $pubkey32 . $msg32)
        )->mod($n);

        // R = s·G - e·P
        $sG = Secp256k1::generatorMult($s);
        $negE = $n->sub($e)->mod($n);
        $eP = Secp256k1::scalarMult($negE, $P);
        $R = Secp256k1::pointAdd($sG, $eP);
        if ($R === null) return false;
        if (Secp256k1::pointParity($R) !== 0) return false;
        return $R[0]->cmp($r) === 0;
    }

    /**
     * Derive the 32-byte x-only public key for a 32-byte secret key.
     */
    public static function xOnlyPubkey(string $seckey32): string {
        $d = Secp256k1::bytesToNum($seckey32);
        if (!Secp256k1::isValidScalar($d)) {
            throw new InvalidArgumentException('seckey out of range');
        }
        $P = Secp256k1::generatorMult($d);
        if ($P === null) {
            throw new RuntimeException('seckey produces point at infinity');
        }
        return Secp256k1::numTo32Bytes($P[0]);
    }

    private static function xor32(string $a, string $b): string {
        $out = '';
        for ($i = 0; $i < 32; $i++) {
            $out .= chr(ord($a[$i]) ^ ord($b[$i]));
        }
        return $out;
    }
}
