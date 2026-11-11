<?php
/**
 * NUT-12 DLEQ offline-verification tests for Cashu\Crypto::verifyDleq().
 *
 * Validates against the official NUT-12 "Carol" (receiver) test vector from
 * https://github.com/cashubtc/nuts/blob/main/tests/12-tests.md and checks that
 * tampered inputs are rejected.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../app/cashu-wallet-php/CashuWallet.php';

use Cashu\Crypto;

$failures = 0;
function ok(string $msg): void { echo "  ok  $msg\n"; }
function bad(string $msg, int &$failures): void { echo "  FAIL $msg\n"; $failures++; }

// Official NUT-12 Carol/Proof DLEQ test vector.
$A      = '0279be667ef9dcbbac55a06295ce870b07029bfcdb2dce28d959f2815b16f81798';
$secret = 'daf4dd00a2b68a0858a80450f52c8a7d2ccf87d375e43e216e0c571f089f63e9';
$C      = '024369d2d22a80ecf78f3937da9d5f30c1b9f74f0c32684d583cca0fa6a61cdcfc';
$e      = 'b31e58ac6527f34975ffab13e70a48b6d2b0d35abc4b03f0151f09ee1a9763d4';
$s      = '8fbae004c59e754d71df67e392b6ae4e29293113ddc2ec86592a0431d16306d8';
$r      = 'a6d13fcd7a18442e6076f5e1e7c887ad5de40a019824bdfa9fe740d302e8d861';

// 1. Valid vector must verify.
if (Crypto::verifyDleq($secret, $C, $A, $e, $s, $r)) {
    ok('valid NUT-12 Carol vector verifies');
} else {
    bad('valid NUT-12 Carol vector should verify but did not', $failures);
}

// 2. Tampered secret must fail.
$badSecret = str_repeat('00', 32);
if (!Crypto::verifyDleq($badSecret, $C, $A, $e, $s, $r)) {
    ok('tampered secret rejected');
} else {
    bad('tampered secret should be rejected', $failures);
}

// 3. Wrong mint key A must fail.
$wrongA = '0379be667ef9dcbbac55a06295ce870b07029bfcdb2dce28d959f2815b16f81798';
if (!Crypto::verifyDleq($secret, $C, $wrongA, $e, $s, $r)) {
    ok('wrong mint key A rejected');
} else {
    bad('wrong mint key A should be rejected', $failures);
}

// 4. Flipped last byte of e must fail.
$badE = substr($e, 0, -2) . 'd5';
if (!Crypto::verifyDleq($secret, $C, $A, $badE, $s, $r)) {
    ok('tampered challenge e rejected');
} else {
    bad('tampered challenge e should be rejected', $failures);
}

// 5. Tampered blinding factor r must fail.
$badR = substr($r, 0, -2) . '62';
if (!Crypto::verifyDleq($secret, $C, $A, $e, $s, $badR)) {
    ok('tampered blinding factor r rejected');
} else {
    bad('tampered blinding factor r should be rejected', $failures);
}

// 6. Garbage / non-point C must fail gracefully (no exception escapes).
if (!Crypto::verifyDleq($secret, 'ff' . substr($C, 2), $A, $e, $s, $r)) {
    ok('invalid signature point C rejected without throwing');
} else {
    bad('invalid signature point C should be rejected', $failures);
}

if ($failures === 0) {
    echo "PASS test_dleq_verify (6 checks)\n";
    exit(0);
}
echo "FAILED test_dleq_verify: $failures failure(s)\n";
exit(1);
