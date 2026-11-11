<?php
/**
 * Library — LP-5 / LP-2: the BCMath backend passes an explicit scale to every
 * bc* call, so a host-level bcscale(n) cannot change results; DLEQ scalars are
 * length-bounded so a huge e/s/r never reaches the quadratic hex parser.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require_once dirname(__DIR__, 2) . '/app/cashu-wallet-php/CashuWallet.php';

use Cashu\BigInt;
use Cashu\Crypto;

// Force the BCMath backend.
$ref = new ReflectionClass(BigInt::class);
$useGmp = $ref->getProperty('useGmp'); $useGmp->setAccessible(true); $useGmp->setValue(null, false);
$init = $ref->getProperty('initialized'); $init->setAccessible(true); $init->setValue(null, true);
assert_false(BigInt::isUsingGmp(), 'BCMath backend forced');

bcscale(5);
assert_eq('255', BigInt::fromHex('ff')->toDec(), 'fromHex unaffected by bcscale');
assert_eq('7', BigInt::fromDec(22)->div(BigInt::fromDec(3))->toDec(), 'integer division unaffected');
assert_eq('1', BigInt::fromDec(22)->mod(BigInt::fromDec(3))->toDec(), 'mod unaffected');
assert_eq('8', BigInt::fromDec(2)->pow(3)->toDec(), 'pow unaffected');
assert_eq('3', BigInt::fromDec(24)->shiftRight(3)->toDec(), 'shiftRight unaffected');
assert_eq('4', BigInt::fromDec(2)->powMod(BigInt::fromDec(10), BigInt::fromDec(1020))->toDec(), 'powMod unaffected');
assert_eq('ff', BigInt::fromDec(255)->toHex(), 'toHex unaffected');
assert_eq('5', BigInt::fromDec(3)->modInverse(BigInt::fromDec(7))->toDec(), 'modInverse unaffected (3*5=15=1 mod 7)');
assert_eq('4', BigInt::fromDec(12)->bitAnd(BigInt::fromDec(5))->toDec(), 'bitAnd unaffected');
assert_eq(-1, BigInt::fromDec(-4)->cmp(BigInt::fromDec(2)), 'cmp unaffected');

// The NUT-12 Carol vector still verifies under bcscale(5) on BCMath.
$A      = '0279be667ef9dcbbac55a06295ce870b07029bfcdb2dce28d959f2815b16f81798';
$secret = 'daf4dd00a2b68a0858a80450f52c8a7d2ccf87d375e43e216e0c571f089f63e9';
$C      = '024369d2d22a80ecf78f3937da9d5f30c1b9f74f0c32684d583cca0fa6a61cdcfc';
$e      = 'b31e58ac6527f34975ffab13e70a48b6d2b0d35abc4b03f0151f09ee1a9763d4';
$s      = '8fbae004c59e754d71df67e392b6ae4e29293113ddc2ec86592a0431d16306d8';
$r      = 'a6d13fcd7a18442e6076f5e1e7c887ad5de40a019824bdfa9fe740d302e8d861';
assert_true(Crypto::verifyDleq($secret, $C, $A, $e, $s, $r), 'DLEQ vector verifies on BCMath with bcscale(5)');
// Unpadded (leading zeroes omitted) scalars are padded before parsing.
assert_true(Crypto::verifyDleq($secret, $C, $A, ltrim($e, '0'), ltrim($s, '0'), ltrim($r, '0')), 'unpadded scalars accepted');

// Oversized scalars are rejected cheaply instead of feeding the quadratic parser.
$t0 = microtime(true);
assert_false(Crypto::verifyDleq($secret, $C, $A, $e, $s, str_repeat('a', 200000)), '200k-char r rejected');
assert_false(Crypto::verifyDleq($secret, $C, $A, str_repeat('b', 65), $s, $r), '65-char e rejected');
assert_false(Crypto::verifyDleq($secret, $C, $A, $e, 'zz', $r), 'non-hex s rejected');
assert_false(Crypto::verifyDleq($secret, $C, $A, '', $s, $r), 'empty e rejected');
assert_true(microtime(true) - $t0 < 0.5, 'oversized scalars rejected in < 0.5s (' . round(microtime(true) - $t0, 3) . 's)');
assert_true(Crypto::isDleqScalar(str_repeat('f', 64)) && !Crypto::isDleqScalar(str_repeat('f', 65)), 'isDleqScalar bounds');

echo "PASS test_lib_bigint_bcscale\n";
exit(0);
