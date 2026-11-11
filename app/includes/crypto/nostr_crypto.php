<?php
/**
 * Nostr crypto for the CLINK (NIP-69) and NWC (NIP-47) clients: BIP340 event
 * signing/verification, x-only ECDH, and the NIP-04 / NIP-44 key derivation —
 * all over the in-repo BigNum/Secp256k1/Schnorr stack (ext-gmp when callable,
 * ext-bcmath otherwise).
 *
 * Why not swentel/nostr-php's own crypto: its Key/Sign/Nip04/Nip44 classes go
 * through paragonie/ecc and simplito/elliptic-php, which hard-require ext-gmp
 * with no fallback — the single reason noffers/NWC used to be dead on
 * GMP-less shared hosts. Everything else in nostr-php (event serialization,
 * relay websocket, bech32) is extension-free and still used as-is; only the
 * bignum-touching pieces are replaced. The in-repo Schnorr implementation is
 * the same code the submarine-swap claim path has been signing with in
 * production, and is pinned by the BIP340 vectors (tests/crypto/test_schnorr)
 * plus a GMP↔BCMath differential suite (tests/crypto/test_bignum,
 * test_secp256k1_backends), so both backends produce bit-identical results.
 *
 * Threat notes (why the checks below are strict):
 *  - verifyEventArray feeds on attacker-supplied input (the payment page
 *    forwards raw relay events); it re-derives the event id from the content
 *    and enforces the BIP340 range checks (x < p, s < n, point on curve), so
 *    a forged receipt can't ride on a malformed signature or a mismatched id.
 *  - Signing self-verifies before returning (inside Schnorr::sign): a
 *    miscomputed signature is an exception, never a published event.
 *  - ECDH validates the peer key lies on the curve (liftX fails otherwise),
 *    so an off-curve pubkey in a noffer/NWC URI throws instead of computing
 *    a degenerate shared secret.
 *  - Nothing here is constant-time; neither was the GMP path this replaces
 *    (gmp_powm/gmp_invert are variable-time). Remote timing extraction is
 *    impractical for these once-per-invoice flows on shared hosting.
 */

require_once __DIR__ . '/schnorr.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use swentel\nostr\EventInterface;
use swentel\nostr\Sign\Sign;

final class NostrCrypto {
    /** True when the math this class needs can run here (64-bit + GMP or BCMath). */
    public static function available(): bool {
        return PHP_INT_SIZE >= 8
            && (function_exists('gmp_init') || function_exists('bcadd'));
    }

    /**
     * True when we're on the BCMath fallback — everything works, but GMP
     * would be ~150x faster and is the better-audited bignum. Drives the
     * non-blocking "consider enabling php-gmp" advisory in setup/admin.
     */
    public static function usingBcmathFallback(): bool {
        return self::available() && !function_exists('gmp_init');
    }

    /** Fresh signing key as 64-char lowercase hex, uniform in [1, n-1]. */
    public static function generatePrivateKeyHex(): string {
        do {
            $bytes = random_bytes(32);
            $d = Secp256k1::bytesToNum($bytes);
        } while (!Secp256k1::isValidScalar($d)); // rejects 0 and >= n (~2^-128 per draw)
        return bin2hex($bytes);
    }

    /** x-only public key (64-char lowercase hex) for a hex private key. */
    public static function derivePublicKeyHex(string $skHex): string {
        return bin2hex(Schnorr::xOnlyPubkey(self::skBytes($skHex)));
    }

    /**
     * Sign a nostr event in place: sets pubkey, id (unless already pinned by
     * the caller), and sig. Mirrors swentel\nostr\Sign::signEvent, minus the
     * GMP-only crypto underneath. Hex keys only (nsec callers convert first).
     */
    public static function signEvent(EventInterface $event, string $skHex): void {
        $sk = self::skBytes($skHex);
        $event->setPublicKey(bin2hex(Schnorr::xOnlyPubkey($sk)));

        $serialized = Sign::serializeEvent($event);
        if ($serialized === false) {
            throw new RuntimeException('NostrCrypto: event serialization failed');
        }
        if ($event->getId() === '') {
            $event->setId(hash('sha256', $serialized));
        }
        $id = $event->getId();
        if (!preg_match('/^[0-9a-f]{64}$/', $id)) {
            throw new RuntimeException('NostrCrypto: event id is not 32 hex bytes');
        }
        $sig = Schnorr::sign($sk, hex2bin($id));
        $event->setSignature(bin2hex($sig));
    }

    /**
     * Verify a raw nostr event array (id, pubkey, created_at, kind, tags,
     * content, sig): structural validity, id really is the sha256 of the
     * canonical serialization, and the Schnorr signature checks out. The
     * strict input validation matters — this runs on relay- and
     * browser-supplied data (NWC replies, CLINK receipts).
     */
    public static function verifyEventArray(array $ev): bool {
        if (!isset($ev['id'], $ev['pubkey'], $ev['created_at'], $ev['kind'], $ev['tags'], $ev['content'], $ev['sig'])
            || !is_string($ev['id']) || !is_string($ev['pubkey']) || !is_int($ev['created_at'])
            || !is_int($ev['kind']) || !is_array($ev['tags']) || !is_string($ev['content'])
            || !is_string($ev['sig'])
        ) {
            return false;
        }
        if (!preg_match('/^[0-9a-f]{64}$/', $ev['id'])
            || !preg_match('/^[0-9a-f]{64}$/', $ev['pubkey'])
            || !preg_match('/^[0-9a-f]{128}$/', $ev['sig'])
        ) {
            return false;
        }
        foreach ($ev['tags'] as $tag) {
            if (!is_array($tag)) {
                return false;
            }
            foreach ($tag as $value) {
                if (!is_string($value)) {
                    return false;
                }
            }
        }
        // The id must be derived from the content actually presented — the
        // same canonical serialization nostr-php and every relay use.
        $computedId = hash('sha256', json_encode(
            [0, $ev['pubkey'], $ev['created_at'], $ev['kind'], $ev['tags'], $ev['content']],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));
        if (!hash_equals($computedId, $ev['id'])) {
            return false;
        }
        return Schnorr::verify(hex2bin($ev['pubkey']), hex2bin($ev['id']), hex2bin($ev['sig']));
    }

    /**
     * NIP-44 v2 conversation key (32 raw bytes): HKDF-extract with salt
     * 'nip44-v2' over the ECDH shared x. Matches the official NIP-44 vectors
     * (tests/crypto/test_nostr_crypto). Use with swentel Nip44::encrypt /
     * ::decrypt, which are extension-free.
     */
    public static function nip44ConversationKey(string $skHex, string $pubHex): string {
        $sharedX = self::ecdhSharedX($skHex, $pubHex);
        return hash_hmac('sha256', $sharedX, 'nip44-v2', true);
    }

    /** NIP-04 encrypt (AES-256-CBC, key = raw ECDH shared x per nostr-tools). */
    public static function nip04Encrypt(string $plaintext, string $skHex, string $pubHex): string {
        $iv = random_bytes(16);
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-cbc', self::ecdhSharedX($skHex, $pubHex), OPENSSL_RAW_DATA, $iv);
        if ($ciphertext === false) {
            throw new RuntimeException('NIP-04 encryption failed: ' . openssl_error_string());
        }
        return base64_encode($ciphertext) . '?iv=' . base64_encode($iv);
    }

    /** NIP-04 decrypt. Throws on malformed payloads or a key mismatch. */
    public static function nip04Decrypt(string $payload, string $skHex, string $pubHex): string {
        $parts = explode('?iv=', $payload);
        if (count($parts) !== 2) {
            throw new RuntimeException('NIP-04: invalid ciphertext format');
        }
        $data = base64_decode($parts[0], true);
        $iv = base64_decode($parts[1], true);
        if ($data === false || $iv === false || strlen($iv) !== 16) {
            throw new RuntimeException('NIP-04: invalid base64/IV');
        }
        $plain = openssl_decrypt($data, 'aes-256-cbc', self::ecdhSharedX($skHex, $pubHex), OPENSSL_RAW_DATA, $iv);
        if ($plain === false) {
            throw new RuntimeException('NIP-04 decryption failed');
        }
        return $plain;
    }

    // ---- internals ----

    /**
     * x coordinate (32 raw bytes) of sk·P, where P is the even-y curve point
     * for an x-only pubkey (nostr's "02||x" convention). Throws on an invalid
     * scalar, an x that has no curve point, or a degenerate shared point.
     */
    private static function ecdhSharedX(string $skHex, string $pubHex): string {
        $d = Secp256k1::bytesToNum(self::skBytes($skHex));
        if (!preg_match('/^[0-9a-fA-F]{64}$/', $pubHex)) {
            throw new InvalidArgumentException('NostrCrypto: public key must be 64 hex chars');
        }
        $P = Secp256k1::liftX(BigNum::fromHex($pubHex));
        if ($P === null) {
            throw new InvalidArgumentException('NostrCrypto: public key is not on the curve');
        }
        $S = Secp256k1::scalarMult($d, $P);
        if ($S === null) {
            throw new RuntimeException('NostrCrypto: degenerate ECDH shared point');
        }
        return $S[0]->to32Bytes();
    }

    /** Validate + decode a hex private key; throws unless in [1, n-1]. */
    private static function skBytes(string $skHex): string {
        if (!preg_match('/^[0-9a-fA-F]{64}$/', $skHex)) {
            throw new InvalidArgumentException('NostrCrypto: private key must be 64 hex chars');
        }
        $bytes = hex2bin($skHex);
        if (!Secp256k1::isValidScalar(Secp256k1::bytesToNum($bytes))) {
            throw new InvalidArgumentException('NostrCrypto: private key out of range');
        }
        return $bytes;
    }
}
