<?php
/**
 * End-to-end smoke test for the swap-claim crypto stack.
 *
 * Builds the same Taproot tree Boltz uses for a reverse swap (claim leaf +
 * refund leaf), computes the internal key via BIP327 KeyAgg, computes the
 * Taproot output key and lockup address, then constructs a fake claim
 * transaction, computes the BIP341 sighash for the script-path leaf, signs
 * with BIP340 Schnorr, and verifies the signature.
 *
 * Does not validate the transaction against a Bitcoin node — that's done by
 * the regtest e2e suite. This test catches integration bugs in our own code.
 */

require_once __DIR__ . '/../../app/includes/crypto/secp256k1.php';
require_once __DIR__ . '/../../app/includes/crypto/schnorr.php';
require_once __DIR__ . '/../../app/includes/crypto/taproot.php';
require_once __DIR__ . '/../../app/includes/crypto/tx_builder.php';

$failures = 0;

function fail(string $msg, &$failures): void { echo "FAIL {$msg}\n"; $failures++; }
function pass(string $msg): void { echo "PASS {$msg}\n"; }

// Generate keys (fixed seeds for determinism).
$claimPriv = hex2bin('1111111111111111111111111111111111111111111111111111111111111111');
$refundPriv = hex2bin('2222222222222222222222222222222222222222222222222222222222222222');
$preimage = hex2bin('3333333333333333333333333333333333333333333333333333333333333333');

$claimPub33 = Secp256k1::pointToCompressed(Secp256k1::generatorMult(Secp256k1::bytesToNum($claimPriv)));
$refundPub33 = Secp256k1::pointToCompressed(Secp256k1::generatorMult(Secp256k1::bytesToNum($refundPriv)));
$claimXOnly = substr($claimPub33, 1); // x-only
$refundXOnly = substr($refundPub33, 1);

// preimage_hash = sha256(preimage)
$preimageHash = hash('sha256', $preimage, true);
// hash160(preimage_hash) = ripemd160(sha256(preimage_hash))
$hash160Of_preimageHash = hash('ripemd160', hash('sha256', $preimageHash, true), true);

// Boltz claim leaf script:
//   OP_SIZE <0x20> OP_EQUALVERIFY OP_HASH160 <hash160(preimage_hash)> OP_EQUALVERIFY <claim_pubkey_xonly> OP_CHECKSIG
$claimScript = "\x82" . "\x01\x20" . "\x88" // OP_SIZE OP_PUSH1 0x20 OP_EQUALVERIFY
             . "\xA9" // OP_HASH160
             . chr(20) . $hash160Of_preimageHash // push 20-byte
             . "\x88" // OP_EQUALVERIFY
             . chr(32) . $claimXOnly // push 32-byte
             . "\xAC"; // OP_CHECKSIG

// Refund leaf (placeholder; Boltz uses CLTV + refund key)
$timeoutHeight = 900000;
$refundScript = chr(32) . $refundXOnly . "\xAD" // OP_CHECKSIGVERIFY
              . TxBuilder::scriptNumberPush($timeoutHeight)
              . "\xB1"; // OP_CHECKLOCKTIMEVERIFY

$leafClaim = Taproot::tapLeafHash(Taproot::TAPSCRIPT_LEAF_VERSION, $claimScript);
$leafRefund = Taproot::tapLeafHash(Taproot::TAPSCRIPT_LEAF_VERSION, $refundScript);
$merkleRoot = Taproot::tapBranchHash($leafClaim, $leafRefund);

// Internal key via BIP327 KeyAgg of (refund, claim) — Boltz's convention is
// [refund_pubkey, claim_pubkey] order (verified against boltz-core).
$internalKey = Taproot::keyAggInternalKey([$refundPub33, $claimPub33]);
[$outputKey, $outputParity] = Taproot::tweakOutputKey($internalKey, $merkleRoot);
$lockupAddress = Taproot::encodeP2trAddress($outputKey, 'regtest');
echo "lockup address: {$lockupAddress}\n";
if (strlen($outputKey) !== 32) fail('output key wrong length', $failures);

// Construct a fake claim transaction.
$prevTxidBE = hex2bin('aa' . str_repeat('00', 31));
$prevVout = 0;
$lockupAmount = 100000; // sats
$claimDestPkh = hash('ripemd160', hash('sha256', $claimPub33, true), true); // arbitrary pkh
$claimAmount = $lockupAmount - 200; // tiny fee
$outScript = TxBuilder::p2wpkhScript($claimDestPkh);
$unsigned = TxBuilder::buildUnsigned($prevTxidBE, $prevVout, 0xFFFFFFFD, $outScript, $claimAmount, 0);

// Compute sighash for script-path spending the claim leaf.
$lockupScript = TxBuilder::p2trScript($outputKey);
$sighash = Taproot::sighashSchnorrScriptPath(
    $unsigned,
    0,
    [$lockupScript],
    [$lockupAmount],
    $leafClaim
);
if (strlen($sighash) !== 32) fail('sighash wrong length', $failures);

// Sign with claim privkey.
$sig = Schnorr::sign($claimPriv, $sighash, str_repeat("\x00", 32));
if (strlen($sig) !== 64) fail('signature wrong length', $failures);

// Verify with claim x-only pubkey.
if (!Schnorr::verify($claimXOnly, $sighash, $sig)) {
    fail('signature did not verify', $failures);
} else {
    pass('end-to-end: build tx, sighash, sign, verify');
}

// Build control block (2-leaf tree, sibling is the refund leaf hash).
$controlBlock = Taproot::controlBlock2Leaf(
    Taproot::TAPSCRIPT_LEAF_VERSION,
    $outputParity,
    $internalKey,
    $leafRefund
);
if (strlen($controlBlock) !== 65) fail('control block wrong length', $failures);

// Assemble final witness and tx.
$witness = [$sig, $preimage, $claimScript, $controlBlock];
$finalTx = TxBuilder::attachWitness($unsigned, $witness);
pass('attachWitness produced ' . strlen($finalTx) . ' bytes for final tx');

// Re-parse the final tx (minus witness) to sanity-check serialization.
$parsed = Taproot::parseUnsignedTx($unsigned);
if (count($parsed['inputs']) !== 1 || count($parsed['outputs']) !== 1) {
    fail('unsigned tx re-parse: wrong input/output count', $failures);
} else if ($parsed['outputs'][0]['value'] !== $claimAmount) {
    fail('unsigned tx re-parse: wrong output value', $failures);
} else {
    pass('unsigned tx round-trip parse');
}

echo "\nFinal claim tx (hex): " . bin2hex($finalTx) . "\n";

echo "\n" . ($failures === 0 ? "all passed" : "{$failures} failed") . "\n";
exit($failures === 0 ? 0 : 1);
