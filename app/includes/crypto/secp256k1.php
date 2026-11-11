<?php
/**
 * Pure-PHP secp256k1 operations for the submarine-swap claim path and the
 * CLINK/NWC Nostr clients.
 *
 * Provides just enough curve arithmetic to compute BIP340 Schnorr signatures,
 * BIP341 Taproot output keys, and x-only ECDH. Implemented over BigNum
 * (ext-gmp when callable, ext-bcmath otherwise) so it works on shared hosting
 * without ext-secp256k1, FFI, or even GMP. All operations on the curve are in
 * affine coordinates; this is slow compared to libsecp256k1 but is invoked
 * only a handful of times per swap claim or per Nostr round trip, so latency
 * is not a concern (~2ms per scalar mult on GMP, ~0.4s on BCMath — both well
 * inside the network budgets of the callers).
 *
 * Curve parameters: y^2 = x^3 + 7 over GF(p), generator G of order n.
 */

require_once __DIR__ . '/bignum.php';

final class Secp256k1 {
    // Field prime p = 2^256 - 2^32 - 977
    public const P_HEX = 'FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEFFFFFC2F';
    // Group order n
    public const N_HEX = 'FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEBAAEDCE6AF48A03BBFD25E8CD0364141';
    // Generator x
    public const GX_HEX = '79BE667EF9DCBBAC55A06295CE870B07029BFCDB2DCE28D959F2815B16F81798';
    // Generator y
    public const GY_HEX = '483ADA7726A3C4655DA4FBFC0E1108A8FD17B448A68554199C47D08FFB10D4B8';
    // Curve constant b (a = 0)
    public const B_HEX = '0000000000000000000000000000000000000000000000000000000000000007';

    private static ?BigNum $p = null;
    private static ?BigNum $n = null;
    private static ?BigNum $gx = null;
    private static ?BigNum $gy = null;
    private static ?BigNum $b = null;
    private static ?BigNum $three = null;
    private static ?BigNum $two = null;
    // (p+1)/4, the sqrt exponent for this p ≡ 3 (mod 4) field.
    private static ?BigNum $sqrtExp = null;
    /**
     * Lazily-built [G, 2G, 4G, ...] chain shared by every generatorMult call.
     * Worth having because the doubling half of double-and-add dominates and
     * G never changes; on the BCMath backend this halves signing cost.
     * @var array<int,array{0:BigNum,1:BigNum}>
     */
    private static array $gDoubles = [];

    private static function init(): void {
        if (self::$p !== null) return;
        self::$p = BigNum::fromHex(self::P_HEX);
        self::$n = BigNum::fromHex(self::N_HEX);
        self::$gx = BigNum::fromHex(self::GX_HEX);
        self::$gy = BigNum::fromHex(self::GY_HEX);
        self::$b = BigNum::fromHex(self::B_HEX);
        self::$three = BigNum::fromInt(3);
        self::$two = BigNum::fromInt(2);
        self::$sqrtExp = self::$p->add(BigNum::one())->div(BigNum::fromInt(4));
    }

    /**
     * Test hook: drop cached constants/tables so a BigNum::forceBackend()
     * switch takes effect (cached BigNum values are backend-bound).
     */
    public static function resetCaches(): void {
        self::$p = null;
        self::$n = null;
        self::$gx = null;
        self::$gy = null;
        self::$b = null;
        self::$three = null;
        self::$two = null;
        self::$sqrtExp = null;
        self::$gDoubles = [];
    }

    public static function p(): BigNum { self::init(); return self::$p; }
    public static function n(): BigNum { self::init(); return self::$n; }
    public static function gx(): BigNum { self::init(); return self::$gx; }
    public static function gy(): BigNum { self::init(); return self::$gy; }
    public static function b(): BigNum { self::init(); return self::$b; }
    public static function gPoint(): array { self::init(); return [self::$gx, self::$gy]; }

    /**
     * Modular inverse (m prime in every call site here).
     */
    public static function modInv(BigNum $a, BigNum $m): BigNum {
        return $a->modInverse($m);
    }

    /**
     * Point addition in affine coordinates. Returns null for the point at
     * infinity. Either operand may be null (infinity).
     *
     * @return array{0:BigNum,1:BigNum}|null
     */
    public static function pointAdd(?array $p, ?array $q): ?array {
        if ($p === null) return $q;
        if ($q === null) return $p;
        self::init();
        $prime = self::$p;
        [$x1, $y1] = $p;
        [$x2, $y2] = $q;
        if ($x1->cmp($x2) === 0) {
            if ($y1->add($y2)->mod($prime)->isZero()) {
                return null; // P + (-P) = O
            }
            return self::pointDouble($p);
        }
        $num = $y2->sub($y1);
        $den = $x2->sub($x1);
        $slope = $num->mul(self::modInv($den->mod($prime), $prime))->mod($prime);
        $x3 = $slope->mul($slope)->sub($x1)->sub($x2)->mod($prime);
        $y3 = $slope->mul($x1->sub($x3))->sub($y1)->mod($prime);
        return [$x3, $y3];
    }

    /**
     * Point doubling: returns 2P.
     *
     * @return array{0:BigNum,1:BigNum}|null
     */
    public static function pointDouble(?array $p): ?array {
        if ($p === null) return null;
        self::init();
        $prime = self::$p;
        [$x, $y] = $p;
        if ($y->mod($prime)->isZero()) {
            return null;
        }
        $num = self::$three->mul($x->mul($x));
        $den = self::$two->mul($y);
        $slope = $num->mul(self::modInv($den->mod($prime), $prime))->mod($prime);
        $x3 = $slope->mul($slope)->sub(self::$two->mul($x))->mod($prime);
        $y3 = $slope->mul($x->sub($x3))->sub($y)->mod($prime);
        return [$x3, $y3];
    }

    /**
     * Scalar multiplication via double-and-add. Constant-time is not a goal
     * here (no private-key operations leak to remote observers); this is the
     * simplest correct algorithm.
     *
     * @return array{0:BigNum,1:BigNum}|null
     */
    public static function scalarMult(BigNum $k, ?array $p): ?array {
        self::init();
        $k = $k->mod(self::$n);
        if ($k->isZero() || $p === null) {
            return null;
        }
        $result = null;
        $addend = $p;
        $kbits = $k->toBits();
        for ($i = strlen($kbits) - 1; $i >= 0; $i--) {
            if ($kbits[$i] === '1') {
                $result = self::pointAdd($result, $addend);
            }
            $addend = self::pointDouble($addend);
        }
        return $result;
    }

    /**
     * Compute k·G, reusing the process-wide doubling chain of G.
     *
     * @return array{0:BigNum,1:BigNum}|null
     */
    public static function generatorMult(BigNum $k): ?array {
        self::init();
        $k = $k->mod(self::$n);
        if ($k->isZero()) {
            return null;
        }
        if (self::$gDoubles === []) {
            self::$gDoubles[] = self::gPoint();
        }
        $result = null;
        $kbits = $k->toBits();
        $len = strlen($kbits);
        // Extend the cached chain up to the highest bit we need. G's doubling
        // chain never hits infinity below bit 256 (n has 256 bits), so the
        // pointDouble results are always concrete points.
        for ($i = count(self::$gDoubles); $i < $len; $i++) {
            $next = self::pointDouble(self::$gDoubles[$i - 1]);
            if ($next === null) {
                throw new RuntimeException('generator doubling chain hit infinity');
            }
            self::$gDoubles[$i] = $next;
        }
        for ($i = $len - 1; $i >= 0; $i--) {
            if ($kbits[$i] === '1') {
                $result = self::pointAdd($result, self::$gDoubles[$len - 1 - $i]);
            }
        }
        return $result;
    }

    /**
     * BIP340 lift_x: find the unique point with given x and even y.
     * Returns null if x does not correspond to a point on the curve.
     *
     * @return array{0:BigNum,1:BigNum}|null
     */
    public static function liftX(BigNum $x): ?array {
        self::init();
        $prime = self::$p;
        if ($x->isNegative() || $x->cmp($prime) >= 0) {
            return null;
        }
        $ySq = $x->powMod(self::$three, $prime)->add(self::$b)->mod($prime);
        // y = ySq^((p+1)/4) mod p
        $y = $ySq->powMod(self::$sqrtExp, $prime);
        if ($y->mul($y)->mod($prime)->cmp($ySq) !== 0) {
            return null;
        }
        // Pick the even y per BIP340.
        if ($y->isOdd()) {
            $y = $prime->sub($y);
        }
        return [$x, $y];
    }

    /**
     * Encode a 256-bit scalar/coord as exactly 32 big-endian bytes.
     */
    public static function numTo32Bytes(BigNum $n): string {
        return $n->to32Bytes();
    }

    public static function bytesToNum(string $bytes): BigNum {
        return BigNum::fromBytes($bytes);
    }

    /**
     * Serialize a point as 33-byte compressed sec1 (02|03 || x).
     */
    public static function pointToCompressed(array $p): string {
        [$x, $y] = $p;
        $parity = $y->isOdd() ? "\x03" : "\x02";
        return $parity . $x->to32Bytes();
    }

    /**
     * Parse 33-byte compressed sec1 to a point. Returns null on invalid input.
     *
     * @return array{0:BigNum,1:BigNum}|null
     */
    public static function compressedToPoint(string $bytes): ?array {
        if (strlen($bytes) !== 33) return null;
        $prefix = ord($bytes[0]);
        if ($prefix !== 0x02 && $prefix !== 0x03) return null;
        $x = self::bytesToNum(substr($bytes, 1));
        $point = self::liftX($x);
        if ($point === null) return null;
        // liftX returns even-y; flip if prefix asks for odd
        if ($prefix === 0x03) {
            self::init();
            $point[1] = self::$p->sub($point[1]);
        }
        return $point;
    }

    /**
     * y-parity (0 = even, 1 = odd) of a point.
     */
    public static function pointParity(array $p): int {
        return $p[1]->isOdd() ? 1 : 0;
    }

    /**
     * Verify a scalar is in the range [1, n-1].
     */
    public static function isValidScalar(BigNum $s): bool {
        self::init();
        return $s->cmpInt(0) > 0 && $s->cmp(self::$n) < 0;
    }

    /**
     * BIP340 tagged hash: SHA256(SHA256(tag) || SHA256(tag) || msg).
     */
    public static function taggedHash(string $tag, string $msg): string {
        $th = hash('sha256', $tag, true);
        return hash('sha256', $th . $th . $msg, true);
    }
}
