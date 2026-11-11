<?php
/**
 * Arbitrary-precision unsigned/signed integer over ext-gmp OR ext-bcmath.
 *
 * Why this exists: the crypto stack (secp256k1/Schnorr for swap claims and
 * the CLINK/NWC Nostr clients) was written directly against gmp_*, but shared
 * WordPress hosts frequently ship PHP without GMP while BCMath is present
 * (Cashu already runs on BCMath there via cashu-wallet-php's own fallback).
 * This class is the single numeric backend for includes/crypto: GMP when the
 * functions are callable, BCMath otherwise. function_exists, not
 * extension_loaded, because hardened hosts disable functions while the
 * extension still reports loaded.
 *
 * Values are immutable. The BCMath representation is a plain decimal string
 * (bcmath's native form); negatives are allowed internally (sub/ext-Euclid),
 * and mod() always returns the canonical representative in [0, m).
 *
 * Not constant-time in either backend — the same property the GMP-only code
 * always had (gmp_powm/gmp_invert are variable-time too). See the threat
 * notes in includes/crypto/nostr_crypto.php.
 */

final class BigNum {
    private static ?bool $useGmp = null;

    /** @var \GMP|string */
    private $value;

    /** @param \GMP|string $value */
    private function __construct($value) {
        $this->value = $value;
    }

    /** Decide the backend once. Throws when neither backend is usable. */
    private static function init(): void {
        if (self::$useGmp !== null) {
            return;
        }
        if (function_exists('gmp_init')) {
            self::$useGmp = true;
        } elseif (function_exists('bcadd')) {
            self::$useGmp = false;
        } else {
            throw new RuntimeException(
                'BigNum needs PHP\'s GMP or BCMath extension; neither is available (or both are disabled).'
            );
        }
    }

    public static function isUsingGmp(): bool {
        self::init();
        return self::$useGmp;
    }

    /**
     * Test hook: force a backend ('gmp' | 'bcmath') or null to re-detect.
     * Forcing a backend whose functions are unavailable throws.
     */
    public static function forceBackend(?string $backend): void {
        if ($backend === null) {
            self::$useGmp = null;
            return;
        }
        if ($backend === 'gmp') {
            if (!function_exists('gmp_init')) {
                throw new RuntimeException('Cannot force GMP backend: gmp_init unavailable');
            }
            self::$useGmp = true;
        } elseif ($backend === 'bcmath') {
            if (!function_exists('bcadd')) {
                throw new RuntimeException('Cannot force BCMath backend: bcadd unavailable');
            }
            self::$useGmp = false;
        } else {
            throw new InvalidArgumentException("Unknown BigNum backend: {$backend}");
        }
    }

    // ---- constructors ----

    public static function fromHex(string $hex): self {
        self::init();
        if ($hex === '' || !preg_match('/^[0-9a-fA-F]+$/', $hex)) {
            throw new InvalidArgumentException('BigNum::fromHex: not a hex string');
        }
        if (self::$useGmp) {
            return new self(gmp_init($hex, 16));
        }
        $dec = '0';
        foreach (str_split(strtolower($hex)) as $c) {
            $dec = bcadd(bcmul($dec, '16', 0), (string)hexdec($c), 0);
        }
        return new self($dec);
    }

    /** Big-endian unsigned bytes. */
    public static function fromBytes(string $bytes): self {
        if ($bytes === '') {
            throw new InvalidArgumentException('BigNum::fromBytes: empty input');
        }
        return self::fromHex(bin2hex($bytes));
    }

    public static function fromInt(int $n): self {
        self::init();
        if (self::$useGmp) {
            return new self(gmp_init($n));
        }
        return new self((string)$n);
    }

    public static function zero(): self { return self::fromInt(0); }
    public static function one(): self { return self::fromInt(1); }

    // ---- arithmetic ----

    public function add(self $other): self {
        if (self::$useGmp) {
            return new self(gmp_add($this->value, $other->value));
        }
        return new self(bcadd($this->value, $other->value, 0));
    }

    public function sub(self $other): self {
        if (self::$useGmp) {
            return new self(gmp_sub($this->value, $other->value));
        }
        return new self(bcsub($this->value, $other->value, 0));
    }

    public function mul(self $other): self {
        if (self::$useGmp) {
            return new self(gmp_mul($this->value, $other->value));
        }
        return new self(bcmul($this->value, $other->value, 0));
    }

    /** Floor division; only defined here for non-negative operands. */
    public function div(self $other): self {
        if ($this->isNegative() || $other->isNegative() || $other->isZero()) {
            throw new InvalidArgumentException('BigNum::div: needs non-negative operands, non-zero divisor');
        }
        if (self::$useGmp) {
            return new self(gmp_div_q($this->value, $other->value));
        }
        return new self(bcdiv($this->value, $other->value, 0));
    }

    /** Canonical residue in [0, m). $m must be positive. */
    public function mod(self $m): self {
        if (self::$useGmp) {
            // gmp_mod already returns a non-negative result.
            return new self(gmp_mod($this->value, $m->value));
        }
        $r = bcmod($this->value, $m->value, 0);
        if (bccomp($r, '0', 0) < 0) {
            $r = bcadd($r, $m->value, 0);
        }
        return new self($r);
    }

    /** ($this ^ $exp) mod $m for non-negative $exp, positive $m. */
    public function powMod(self $exp, self $m): self {
        if ($exp->isNegative()) {
            throw new InvalidArgumentException('BigNum::powMod: negative exponent');
        }
        if (self::$useGmp) {
            return new self(gmp_powm($this->value, $exp->value, $m->value));
        }
        // bcpowmod requires a non-negative base; canonicalize first.
        $base = $this->mod($m);
        return new self(bcpowmod($base->value, $exp->value, $m->value, 0));
    }

    /**
     * Modular inverse in [0, m). Throws when no inverse exists — every caller
     * in this codebase inverts values that are provably coprime to a prime
     * modulus, so a failure signals corrupted input, not a soft condition.
     */
    public function modInverse(self $m): self {
        if (self::$useGmp) {
            $r = gmp_invert($this->value, $m->value);
            if ($r === false) {
                throw new RuntimeException('BigNum::modInverse: no inverse exists');
            }
            return new self($r);
        }
        // Extended Euclid on the canonical residue.
        $a = $this->mod($m)->value;
        $mVal = $m->value;
        if (bccomp($a, '0', 0) === 0) {
            throw new RuntimeException('BigNum::modInverse: no inverse exists');
        }
        $oldR = $a;      $r = $mVal;
        $oldS = '1';     $s = '0';
        while (bccomp($r, '0', 0) !== 0) {
            $q = bcdiv($oldR, $r, 0);
            [$oldR, $r] = [$r, bcsub($oldR, bcmul($q, $r, 0), 0)];
            [$oldS, $s] = [$s, bcsub($oldS, bcmul($q, $s, 0), 0)];
        }
        if (bccomp($oldR, '1', 0) !== 0) {
            throw new RuntimeException('BigNum::modInverse: no inverse exists');
        }
        if (bccomp($oldS, '0', 0) < 0) {
            $oldS = bcadd($oldS, $mVal, 0);
        }
        return new self($oldS);
    }

    // ---- comparisons / predicates ----

    /** -1, 0, or 1. */
    public function cmp(self $other): int {
        if (self::$useGmp) {
            return gmp_cmp($this->value, $other->value) <=> 0;
        }
        return bccomp($this->value, $other->value, 0);
    }

    public function cmpInt(int $n): int {
        return $this->cmp(self::fromInt($n));
    }

    public function isZero(): bool { return $this->cmpInt(0) === 0; }

    public function isNegative(): bool { return $this->cmpInt(0) < 0; }

    public function isOdd(): bool {
        if (self::$useGmp) {
            return gmp_testbit($this->value, 0);
        }
        // bcmod of a negative dividend keeps its sign; oddness is sign-agnostic.
        $r = bcmod($this->value, '2', 0);
        return $r === '1' || $r === '-1';
    }

    // ---- conversions ----

    /** Lowercase hex, no leading zeros ('0' for zero). Non-negative only. */
    public function toHex(): string {
        if ($this->isNegative()) {
            throw new RuntimeException('BigNum::toHex: negative value');
        }
        if (self::$useGmp) {
            return gmp_strval($this->value, 16);
        }
        if ($this->isZero()) {
            return '0';
        }
        $hex = '';
        $v = $this->value;
        while (bccomp($v, '0', 0) > 0) {
            $digit = (int)bcmod($v, '16', 0);
            $hex = dechex($digit) . $hex;
            $v = bcdiv($v, '16', 0);
        }
        return $hex;
    }

    /** Exactly 32 big-endian bytes; throws when the value doesn't fit. */
    public function to32Bytes(): string {
        $hex = $this->toHex();
        if (strlen($hex) > 64) {
            throw new RuntimeException('BigNum::to32Bytes: value exceeds 32 bytes');
        }
        return hex2bin(str_pad($hex, 64, '0', STR_PAD_LEFT));
    }

    /** MSB-first binary expansion, e.g. '10110'. Non-negative only; '0' for zero. */
    public function toBits(): string {
        if ($this->isNegative()) {
            throw new RuntimeException('BigNum::toBits: negative value');
        }
        if (self::$useGmp) {
            return gmp_strval($this->value, 2);
        }
        $hex = $this->toHex();
        if ($hex === '0') {
            return '0';
        }
        $bits = '';
        foreach (str_split($hex) as $c) {
            $bits .= str_pad(base_convert($c, 16, 2), 4, '0', STR_PAD_LEFT);
        }
        return ltrim($bits, '0') ?: '0';
    }

    /** Decimal string (mainly for diagnostics/tests). */
    public function toDec(): string {
        if (self::$useGmp) {
            return gmp_strval($this->value, 10);
        }
        return $this->value;
    }
}
