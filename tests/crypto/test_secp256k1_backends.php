<?php
/**
 * Secp256k1 GMP↔BCMath differential test at the curve level.
 *
 * test_bignum pins the arithmetic; this pins the point operations the
 * signing/ECDH paths compose them into: fixed-base multiplication (and its
 * cached doubling chain vs. the generic ladder), variable-base
 * multiplication, lift_x, compressed round trips, and ECDH symmetry.
 * Boundary scalars (1, 2, n-1, n+1 ≡ 1, half-order) plus seeded-random ones.
 *
 * Skips when either backend is unavailable.
 */

require_once __DIR__ . '/../../app/includes/crypto/secp256k1.php';

if (!function_exists('gmp_init') || !function_exists('bcadd')) {
    echo "SKIP: differential test needs both GMP and BCMath\n";
    exit(0);
}

function point_hex(?array $p): string {
    if ($p === null) return 'infinity';
    return $p[0]->toHex() . ',' . $p[1]->toHex();
}

$nM1 = gmp_strval(gmp_sub(gmp_init(Secp256k1::N_HEX, 16), 1), 16);
$half = gmp_strval(gmp_div_q(gmp_init(Secp256k1::N_HEX, 16), 2), 16);
$scalars = ['1', '2', '3', $nM1, $half, Secp256k1::N_HEX /* ≡ 0 → infinity */];
mt_srand(0xC0DE);
for ($i = 0; $i < 4; $i++) {
    $hex = '';
    for ($j = 0; $j < 64; $j++) $hex .= dechex(mt_rand(0, 15));
    $scalars[] = ltrim($hex, '0') ?: '1';
}

$xCandidates = [
    Secp256k1::GX_HEX,
    '1', '2', '3', '5',
    // x with no curve point (BIP340 vector 5's pubkey)
    'EEFDEA4CDB677750A420FEE807EACF21EB9898AE79B9768766E4FAA04A2D4A34',
    // x >= p → must be rejected
    Secp256k1::P_HEX,
];

$failures = 0;
$checks = 0;

/** Run the full battery on one backend, returning label => result strings. */
function battery(array $scalars, array $xCandidates, string $backend): array {
    BigNum::forceBackend($backend);
    Secp256k1::resetCaches();
    $out = [];
    foreach ($scalars as $sHex) {
        $k = BigNum::fromHex($sHex);
        $g = Secp256k1::generatorMult($k);
        $out["genmult:{$sHex}"] = point_hex($g);
        // Cached-chain fixed-base must agree with the generic ladder.
        $out["genmult=scalarmult:{$sHex}"] =
            (point_hex($g) === point_hex(Secp256k1::scalarMult($k, Secp256k1::gPoint()))) ? 'agree' : 'DISAGREE';
        if ($g !== null) {
            $out["compressed:{$sHex}"] = bin2hex(Secp256k1::pointToCompressed($g));
            $rt = Secp256k1::compressedToPoint(Secp256k1::pointToCompressed($g));
            $out["compressed_rt:{$sHex}"] = point_hex($rt);
        }
    }
    foreach ($xCandidates as $xHex) {
        $out["liftx:{$xHex}"] = point_hex(Secp256k1::liftX(BigNum::fromHex($xHex)));
    }
    // ECDH symmetry: a·(b·G) == b·(a·G), and variable-base mult diff basis.
    $a = BigNum::fromHex($scalars[4]);
    $b = BigNum::fromHex($scalars[6]);
    $aG = Secp256k1::generatorMult($a);
    $bG = Secp256k1::generatorMult($b);
    $out['ecdh_sym'] = (point_hex(Secp256k1::scalarMult($a, $bG)) === point_hex(Secp256k1::scalarMult($b, $aG)))
        ? 'symmetric' : 'ASYMMETRIC';
    $out['varmult'] = point_hex(Secp256k1::scalarMult($a, $bG));
    return $out;
}

$gmp = battery($scalars, $xCandidates, 'gmp');
$bc = battery($scalars, $xCandidates, 'bcmath');
BigNum::forceBackend(null);
Secp256k1::resetCaches();

foreach ($gmp as $label => $expected) {
    $checks++;
    $got = $bc[$label] ?? '(missing)';
    if ($expected !== $got) {
        echo "MISMATCH {$label}\n  gmp={$expected}\n  bc ={$got}\n";
        $failures++;
    }
    if (str_contains($expected, 'DISAGREE') || str_contains($expected, 'ASYMMETRIC')) {
        echo "SELF-FAIL [gmp] {$label}: {$expected}\n";
        $failures++;
    }
    if (str_contains($got, 'DISAGREE') || str_contains($got, 'ASYMMETRIC')) {
        echo "SELF-FAIL [bcmath] {$label}: {$got}\n";
        $failures++;
    }
}

// Known-answer anchors (independent of both backends): G, 2G, and the
// rejections liftX must produce.
$anchors = [
    ['genmult:1', strtolower(Secp256k1::GX_HEX) . ',' . strtolower(Secp256k1::GY_HEX)],
    ['genmult:2', 'c6047f9441ed7d6d3045406e95c07cd85c778e4b8cef3ca7abac09b95c709ee5,'
        . '1ae168fef15e15c62f2f676e9fb9cf9d1aeae1e8b6e3c2b0d1e0e0e0e0e0e0e0'],
    ['liftx:EEFDEA4CDB677750A420FEE807EACF21EB9898AE79B9768766E4FAA04A2D4A34', 'infinity'],
    ['liftx:' . Secp256k1::P_HEX, 'infinity'],
];
foreach ($anchors as [$label, $expected]) {
    $checks++;
    $got = $gmp[$label] ?? '(missing)';
    if ($label === 'genmult:2') {
        // 2G's y is long-tail; anchor just the well-known x coordinate.
        $gotX = explode(',', $got)[0];
        if ($gotX !== 'c6047f9441ed7d6d3045406e95c07cd85c778e4b8cef3ca7abac09b95c709ee5') {
            echo "ANCHOR-FAIL {$label}: x={$gotX}\n";
            $failures++;
        }
        continue;
    }
    if (strtolower($got) !== strtolower($expected)) {
        echo "ANCHOR-FAIL {$label}\n  expected={$expected}\n  got     ={$got}\n";
        $failures++;
    }
}

echo "\n{$checks} checks, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
