<?php
/**
 * BigNum GMP↔BCMath differential test.
 *
 * The BCMath fallback only ever runs on hosts where GMP is absent — exactly
 * where nobody is watching. This suite pins it to the GMP backend across
 * every operation the crypto stack uses, over seeded-random 256-bit operands
 * plus the adversarial boundary values that historically break bignum ports
 * (values straddling p and n, leading-zero bytes, 0/1/2^256-1). A silent
 * arithmetic divergence here is how a signing key leaks (biased nonces), so
 * any mismatch is a hard failure.
 *
 * Skips (cleanly, with a notice) when either backend is unavailable — the
 * differential comparison needs both.
 */

require_once __DIR__ . '/../../app/includes/crypto/bignum.php';

if (!function_exists('gmp_init') || !function_exists('bcadd')) {
    echo "SKIP: differential test needs both GMP and BCMath\n";
    exit(0);
}

const P_HEX = 'FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEFFFFFC2F';
const N_HEX = 'FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEBAAEDCE6AF48A03BBFD25E8CD0364141';

/** Deterministic operand pool: boundary values + seeded random 256-bit hex. */
function operand_pool(): array {
    $pool = [
        '1', '2', '3', 'F',
        str_repeat('0', 63) . '1',                      // leading zeros
        '00FF' . str_repeat('AB', 30),                  // leading zero byte
        P_HEX,
        N_HEX,
        dechex_bc_sub(P_HEX, 1),                        // p-1
        dechex_bc_sub(N_HEX, 1),                        // n-1
        dechex_bc_add(P_HEX, 1),                        // p+1
        str_repeat('F', 64),                            // 2^256-1
        '7' . str_repeat('F', 63),
        '8' . str_repeat('0', 63),
    ];
    mt_srand(0x5ec9); // fixed seed: reproducible failures
    for ($i = 0; $i < 60; $i++) {
        $hex = '';
        for ($j = 0; $j < 64; $j++) {
            $hex .= dechex(mt_rand(0, 15));
        }
        $pool[] = ltrim($hex, '0') ?: '0';
    }
    // '0' is invalid for fromHex('')-adjacent paths? No: '0' is fine.
    $pool[] = '0';
    return $pool;
}

function dechex_bc_sub(string $hex, int $k): string {
    return gmp_strval(gmp_sub(gmp_init($hex, 16), $k), 16);
}
function dechex_bc_add(string $hex, int $k): string {
    return gmp_strval(gmp_add(gmp_init($hex, 16), $k), 16);
}

/** Evaluate one op on the current backend; returns a comparable string. */
function evaluate(string $op, string $aHex, string $bHex): string {
    $a = BigNum::fromHex($aHex);
    $b = BigNum::fromHex($bHex);
    $p = BigNum::fromHex(P_HEX);
    try {
        switch ($op) {
            case 'add':      return $a->add($b)->toDec();
            case 'sub':      return $a->sub($b)->toDec();          // may be negative
            case 'mul':      return $a->mul($b)->toDec();
            case 'mod':      return $b->isZero() ? 'div0' : $a->mod($b)->toDec();
            case 'submod':   return $a->sub($b)->mod($p)->toDec(); // negative → canonical
            case 'powmod':   return $b->isZero() ? $a->powMod(BigNum::zero(), $p)->toDec()
                                                 : $a->powMod($b->mod(BigNum::fromInt(1000)), $p)->toDec();
            case 'powmod_big': return $a->powMod($b, $p)->toDec(); // full 256-bit exponent
            case 'modinv':   return $a->mod($p)->isZero() ? 'noinv' : $a->modInverse($p)->toDec();
            case 'cmp':      return (string)$a->cmp($b);
            case 'isodd':    return $a->isOdd() ? '1' : '0';
            case 'tohex':    return $a->toHex();
            case 'tobits':   return $a->toBits();
            case 'to32':     try { return bin2hex($a->to32Bytes()); } catch (Throwable $e) { return 'overflow'; }
            case 'div':      return $b->isZero() ? 'div0' : $a->div($b)->toDec();
        }
    } catch (RuntimeException $e) {
        return 'throw:' . $e->getMessage();
    }
    throw new LogicException("unknown op {$op}");
}

$ops = ['add', 'sub', 'mul', 'mod', 'submod', 'powmod', 'powmod_big', 'modinv', 'cmp', 'isodd', 'tohex', 'tobits', 'to32', 'div'];
$pool = operand_pool();
$count = count($pool);

$failures = 0;
$checks = 0;
foreach ($ops as $op) {
    // Pair every operand with a rotating partner; heavy ops sample sparser.
    $step = in_array($op, ['powmod_big', 'modinv'], true) ? 7 : 1;
    for ($i = 0; $i < $count; $i += $step) {
        $aHex = $pool[$i];
        $bHex = $pool[($i * 31 + 17) % $count];
        if ($aHex === '0' && in_array($op, ['modinv'], true)) continue;

        BigNum::forceBackend('gmp');
        $gmpResult = evaluate($op, $aHex, $bHex);
        BigNum::forceBackend('bcmath');
        $bcResult = evaluate($op, $aHex, $bHex);
        $checks++;

        if ($gmpResult !== $bcResult) {
            echo "MISMATCH op={$op}\n  a={$aHex}\n  b={$bHex}\n  gmp={$gmpResult}\n  bc ={$bcResult}\n";
            $failures++;
        }
    }
}
BigNum::forceBackend(null);

// Round-trip sanity on both backends independently.
foreach (['gmp', 'bcmath'] as $backend) {
    BigNum::forceBackend($backend);
    foreach ([P_HEX, N_HEX, '1', 'ff00ff00'] as $hex) {
        $checks++;
        $rt = BigNum::fromHex($hex)->toHex();
        if (strcasecmp(ltrim($hex, '0') ?: '0', $rt) !== 0) {
            echo "MISMATCH roundtrip [{$backend}] {$hex} -> {$rt}\n";
            $failures++;
        }
    }
    // fromBytes/to32Bytes round trip with a leading zero byte.
    $checks++;
    $bytes = "\x00\x01" . str_repeat("\xAB", 30);
    if (BigNum::fromBytes($bytes)->to32Bytes() !== $bytes) {
        echo "MISMATCH bytes roundtrip [{$backend}]\n";
        $failures++;
    }
}
BigNum::forceBackend(null);

echo "\n{$checks} differential checks, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
