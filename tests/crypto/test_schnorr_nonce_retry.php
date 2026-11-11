<?php
/**
 * Schnorr signer behaviour around nonce randomness.
 *
 * Complements test_schnorr.php (BIP340 vectors): those pin aux_rand and assert
 * deterministic output. Here we cover the auto-randomness path added so a
 * degenerate nonce resamples instead of throwing:
 *
 *   1. Auto-aux signing (no aux_rand) produces a valid, verifiable signature.
 *   2. Two auto-aux signatures over the same key+message DIFFER — confirming
 *      fresh randomness per call (no reuse of R / the s-scalar).
 *   3. Pinned aux_rand stays deterministic (same aux -> byte-identical sig),
 *      so the resampling change did not weaken the reproducibility contract.
 *   4. Invalid aux length is still rejected.
 */

require_once __DIR__ . '/../../app/includes/crypto/schnorr.php';

$failures = 0;
$total = 0;

function check(string $label, bool $cond): void {
    global $failures, $total;
    $total++;
    if ($cond) {
        echo "PASS {$label}\n";
    } else {
        echo "FAIL {$label}\n";
        $failures++;
    }
}

$sk = hex2bin('B7E151628AED2A6ABF7158809CF4F3C762E7160F38B4DA56A784D9045190CFEF');
$pub = Schnorr::xOnlyPubkey($sk);
$msg = hash('sha256', 'submarine-swap-claim', true);

// 1. Auto-aux signing yields a verifiable signature.
$sigA = Schnorr::sign($sk, $msg); // no aux -> internal random_bytes
check('auto-aux signature verifies', Schnorr::verify($pub, $msg, $sigA));
check('auto-aux signature is 64 bytes', strlen($sigA) === 64);

// 2. Fresh randomness per call: a second auto-aux signature differs but also
//    verifies. (BIP340 randomized signing => different R each time.)
$sigB = Schnorr::sign($sk, $msg);
check('second auto-aux signature verifies', Schnorr::verify($pub, $msg, $sigB));
check('two auto-aux signatures differ (no nonce reuse)', $sigA !== $sigB);
check('the R component (first 32 bytes) differs', substr($sigA, 0, 32) !== substr($sigB, 0, 32));

// 3. Pinned aux stays deterministic.
$aux = hex2bin('0000000000000000000000000000000000000000000000000000000000000001');
$sigP1 = Schnorr::sign($sk, $msg, $aux);
$sigP2 = Schnorr::sign($sk, $msg, $aux);
check('pinned-aux signing is deterministic', $sigP1 === $sigP2);
check('pinned-aux signature verifies', Schnorr::verify($pub, $msg, $sigP1));

// 4. Invalid aux length is still rejected.
$threw = false;
try {
    Schnorr::sign($sk, $msg, 'too-short');
} catch (InvalidArgumentException $e) {
    $threw = true;
}
check('short aux_rand rejected', $threw);

echo "\n{$total} tested, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
