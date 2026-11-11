<?php
/**
 * Theme (submodule): the wallet must validate a mint's blind-signature
 * response against the outputs we requested BEFORE treating the unblinded
 * proof as money. mint() and swap() (the LN-invoice and token-receive settle
 * paths) now call the private assertValidMintSignature() helper. A compromised
 * or buggy mint that signs the wrong amount/keyset, or returns a NUT-12 DLEQ
 * proof that doesn't verify, must be rejected — otherwise the invoice settles
 * with bad-denomination / unprovable ecash.
 *
 * We exercise the helper directly via reflection (constructing a full
 * settle flow would require a live mint + paid quote). The valid DLEQ case
 * reuses the official NUT-12 "Carol" test vector.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require_once dirname(__DIR__, 2) . '/app/cashu-wallet-php/CashuWallet.php';

use Cashu\Wallet;
use Cashu\BigInt;
use Cashu\CashuProtocolException;

// Official NUT-12 Carol vector (matches test_dleq_verify.php).
$A      = '0279be667ef9dcbbac55a06295ce870b07029bfcdb2dce28d959f2815b16f81798';
$secret = 'daf4dd00a2b68a0858a80450f52c8a7d2ccf87d375e43e216e0c571f089f63e9';
$C      = '024369d2d22a80ecf78f3937da9d5f30c1b9f74f0c32684d583cca0fa6a61cdcfc';
$e      = 'b31e58ac6527f34975ffab13e70a48b6d2b0d35abc4b03f0151f09ee1a9763d4';
$s      = '8fbae004c59e754d71df67e392b6ae4e29293113ddc2ec86592a0431d16306d8';
$r      = 'a6d13fcd7a18442e6076f5e1e7c887ad5de40a019824bdfa9fe740d302e8d861';

$keysetId = '00ad268c4d1f5826';
$amount   = 8;

// Build a wallet and inject the keyset key for the test amount via reflection
// (no network used — we call the private helper directly).
$wallet = new Wallet('http://127.0.0.1:1', 'sat');
$ref = new ReflectionClass($wallet);
$keysProp = $ref->getProperty('keys');
$keysProp->setAccessible(true);
$keysProp->setValue($wallet, [$keysetId => [$amount => $A]]);

$method = $ref->getMethod('assertValidMintSignature');
$method->setAccessible(true);

$output   = ['amount' => $amount, 'id' => $keysetId, 'B_' => '02' . str_repeat('00', 32)];
$blinding = ['secret' => $secret, 'r' => BigInt::fromHex($r)];

$invoke = function (array $sig) use ($method, $wallet, $output, $blinding, $C): void {
    $method->invoke($wallet, $sig, $output, $blinding, $C);
};
$throws = function (array $sig) use ($invoke): bool {
    try { $invoke($sig); return false; }
    catch (CashuProtocolException $e) { return true; }
};

// 1. Valid signature with a verifying DLEQ proof passes.
$invoke(['amount' => $amount, 'id' => $keysetId, 'dleq' => ['e' => $e, 's' => $s]]);
echo "  ok  valid mint signature + DLEQ accepted\n";

// 2. Amount mismatch is rejected (mint signed a different denomination).
assert_true($throws(['amount' => $amount + 1, 'id' => $keysetId, 'dleq' => ['e' => $e, 's' => $s]]),
    'amount mismatch rejected');

// 3. Keyset mismatch is rejected.
assert_true($throws(['amount' => $amount, 'id' => 'deadbeefdeadbeef', 'dleq' => ['e' => $e, 's' => $s]]),
    'keyset mismatch rejected');

// 4. DLEQ present but tampered (flipped e) is rejected.
$badE = substr($e, 0, -2) . 'd5';
assert_true($throws(['amount' => $amount, 'id' => $keysetId, 'dleq' => ['e' => $badE, 's' => $s]]),
    'tampered DLEQ rejected');

// 5. No DLEQ (older mint), matching amount/keyset: accepted (can't verify what
//    isn't there, but amount/keyset integrity still holds).
$invoke(['amount' => $amount, 'id' => $keysetId]);
echo "  ok  matching amount/keyset without DLEQ accepted\n";

echo "PASS test_mint_signature_verify\n";
exit(0);
