<?php
/**
 * Library — LM-4: NUT-07 helpers are fail-closed. A short, empty, wrong-Y or
 * state-less reply never reads as UNSPENT:
 *   - checkProofState() throws on short/empty/wrong-Y replies, matches by Y (a
 *     reordered reply still maps every proof correctly) and reports UNKNOWN when
 *     the mint sent no state;
 *   - syncProofStates() changes nothing on a bad reply and only moves UNSPENT->SPENT;
 *   - recoverPendingMelts() on an expired UNPAID quote keeps the journal unless the
 *     mint confirms every input UNSPENT in a complete reply;
 *   - recoverPendingSwaps() recovers / releases / records double-spends only on
 *     complete answers, and never releases what the mint reports SPENT;
 *   - a swap definitively rejected by the mint settles its journal at once (imported
 *     token proofs removed, own inputs released or marked SPENT).
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/cashu-wallet-php/CashuWallet.php';
require __DIR__ . '/lib_mint_stub.php';

use Cashu\CashuException;
use Cashu\CashuProtocolException;
use Cashu\ProofState;
use Cashu\Wallet;

$stub = lib_mint_stub_start();
$w = lib_stub_wallet($stub);
$storage = $w->getStorage();
$proofs = lib_stub_fund($stub, $w, 7); // [1, 2, 4]
assert_eq(3, count($proofs), 'three proofs');

// ---- checkProofState -----------------------------------------------------------------
lib_mint_stub_mark_spent($stub, [$proofs[1]]); // the 2-sat proof is spent elsewhere
$states = $w->checkProofState($proofs);
assert_eq(['UNSPENT', 'SPENT', 'UNSPENT'], array_column($states, 'state'), 'normal reply in input order');
assert_eq(strtolower($proofs[1]->Y), $states[1]['Y'], 'entries carry Y');
lib_mint_stub_script($stub, ['checkstate' => 'reordered']);
$states = $w->checkProofState($proofs);
assert_eq(['UNSPENT', 'SPENT', 'UNSPENT'], array_column($states, 'state'), 'reordered reply is matched by Y, not position');
foreach (['short', 'empty', 'wrongY'] as $mode) {
    lib_mint_stub_script($stub, ['checkstate' => $mode]);
    $threw = false;
    try { $w->checkProofState($proofs); } catch (CashuException $e) { $threw = true; }
    assert_true($threw, "checkProofState throws on a $mode reply");
}
lib_mint_stub_script($stub, ['checkstate' => 'nostate']);
$states = $w->checkProofState($proofs);
assert_eq(['UNKNOWN', 'UNKNOWN', 'UNKNOWN'], array_column($states, 'state'), 'missing state reads as UNKNOWN, never UNSPENT');

// ---- syncProofStates ------------------------------------------------------------------
foreach (['short', 'empty', 'wrongY', 'error'] as $mode) {
    lib_mint_stub_script($stub, ['checkstate' => $mode]);
    $r = $w->syncProofStates();
    assert_eq(1, $r['errors'], "sync reports an error on a $mode reply");
    assert_eq(0, $r['updated'], "sync changes nothing on a $mode reply");
    assert_eq([ProofState::UNSPENT], lib_states_of($w, $proofs), "local states untouched after $mode reply");
}
lib_mint_stub_script($stub, ['checkstate' => 'normal']);
$r = $w->syncProofStates();
assert_eq(1, $r['updated'], 'complete reply marks the spent proof SPENT');
assert_eq(ProofState::SPENT, lib_proof_states($w, [$proofs[1]])[$proofs[1]->secret], '2-sat proof SPENT locally');
assert_eq(5, $w->getBalance(), 'balance 1+4');

// ---- recoverPendingMelts: expired UNPAID needs a complete UNSPENT confirmation -------
$inputs = [$proofs[0], $proofs[2]];
lib_stub_melt_quote($stub, 'qx', 4, 1, ['expiry' => time() - 5]);
lib_mint_stub_script($stub, ['overrides' => ['POST /v1/melt/bolt11' => ['status' => 500, 'body' => ['detail' => 'gw', 'code' => 0]]]], false);
try { $w->melt('qx', $inputs); } catch (CashuException $e) { }
assert_not_null($storage->getPendingOperationById('melt:qx'), 'journal present');
assert_eq([ProofState::PENDING], lib_states_of($w, $inputs), 'inputs reserved');
foreach (['short', 'empty', 'wrongY', 'nostate', 'error'] as $mode) {
    lib_mint_stub_script($stub, ['checkstate' => $mode, 'overrides' => []], false);
    $r = $w->recoverPendingMelts();
    assert_eq(1, $r['still_pending'], "expired UNPAID with a $mode checkstate reply is NOT released");
    assert_not_null($storage->getPendingOperationById('melt:qx'), "journal kept after $mode reply");
    assert_eq([ProofState::PENDING], lib_states_of($w, $inputs), "inputs still PENDING after $mode reply");
}
// A melt-quote reply without `state` is UNKNOWN, not UNPAID: nothing is released.
lib_mint_stub_script($stub, ['checkstate' => 'normal'], false);
lib_stub_melt_quote($stub, 'qx', 4, 1, ['expiry' => time() - 5, 'omit_state' => true]);
$r = $w->recoverPendingMelts();
assert_eq(1, $r['still_pending'], 'stateless quote reply keeps the journal');
lib_stub_melt_quote($stub, 'qx', 4, 1, ['expiry' => time() - 5]);
// One input SPENT at the mint: not released either.
lib_mint_stub_mark_spent($stub, [$proofs[2]]);
$r = $w->recoverPendingMelts();
assert_eq(1, $r['still_pending'], 'a SPENT input blocks the release');
$state = lib_mint_stub_state($stub);
unset($state['spent'][\Cashu\Crypto::computeY($proofs[2]->secret)]);
lib_mint_stub_set_state($stub, $state);
$r = $w->recoverPendingMelts();
assert_eq(1, $r['restored'], 'complete UNSPENT confirmation releases the inputs');
assert_eq([ProofState::UNSPENT], lib_states_of($w, $inputs), 'inputs back to UNSPENT');

// ---- recoverPendingSwaps ----------------------------------------------------------------
// (1) lost response: swap executed at the mint, 500 returned -> journal; recovery stores outputs
lib_mint_stub_script($stub, ['checkstate' => 'normal', 'overrides' => ['POST /v1/swap' => ['status' => 500, 'body' => ['detail' => 'gw', 'code' => 0]]]]);
$threw = null;
try { $w->swap($inputs, [1, 4]); } catch (CashuException $e) { $threw = $e; }
assert_true($threw instanceof CashuProtocolException, 'swap 500 rethrown');
$swapJournals = $storage->getPendingOperations('swap');
assert_eq(1, count($swapJournals), 'swap journal kept');
assert_eq([ProofState::PENDING], lib_states_of($w, $inputs), 'swap inputs reserved PENDING');
// The mint actually executed it: mark inputs spent and sign the journaled outputs.
$plan = $swapJournals[0]['data'];
$state = lib_mint_stub_state($stub);
foreach ($inputs as $p) { $state['spent'][\Cashu\Crypto::computeY($p->secret)] = true; }
foreach ($plan['amounts'] as $i => $amt) {
    $b = $w->createDeterministicBlindedMessage($plan['keyset_id'], $plan['counter_start'] + $i);
    $pt = \Cashu\Secp256k1::decompressPoint(hex2bin($b['B_']));
    $k = \Cashu\BigInt::fromHex(hash('sha256', 'stubmint:' . $amt))->mod(\Cashu\Secp256k1::getOrder());
    $state['signed'][strtolower($b['B_'])] = ['id' => $plan['keyset_id'], 'amount' => $amt,
        'C_' => bin2hex(\Cashu\Secp256k1::compressPoint(\Cashu\Secp256k1::scalarMult($k, $pt)))];
}
lib_mint_stub_set_state($stub, $state);
foreach (['short', 'empty', 'wrongY'] as $mode) {
    lib_mint_stub_script($stub, ['checkstate' => $mode, 'overrides' => []]);
    $r = $w->recoverPendingSwaps();
    assert_eq(0, $r['recovered'] + $r['restored'], "nothing decided on a $mode reply");
    assert_eq(1, count($storage->getPendingOperations('swap')), "swap journal kept after $mode reply");
}
lib_mint_stub_script($stub, ['checkstate' => 'normal', 'overrides' => []]);
$r = $w->recoverPendingSwaps();
assert_eq(1, $r['recovered'], 'complete SPENT answer + NUT-09 outputs -> recovered');
assert_eq([ProofState::SPENT], lib_states_of($w, $inputs), 'inputs SPENT');
assert_eq(5, $w->getBalance(), 'swapped outputs stored (1+4)');
assert_eq(0, count($storage->getPendingOperations('swap')), 'journal gone');

// (2) swap never reached the mint: inputs UNSPENT, nothing signed -> resubmitted and completed
$fresh = $w->getStoredProofs();
lib_mint_stub_script($stub, ['overrides' => ['POST /v1/swap' => ['status' => 503, 'body' => ['detail' => 'down', 'code' => 0]]]]);
try { $w->swap($fresh, [1, 4]); } catch (CashuException $e) { }
assert_eq(1, count($storage->getPendingOperations('swap')), 'journal after 503');
lib_mint_stub_script($stub, ['overrides' => []]);
$r = $w->recoverPendingSwaps();
assert_eq(1, $r['recovered'], 'unspent inputs + unsigned plan -> resubmitted and recovered');
assert_eq(5, $w->getBalance(), 'balance preserved');
assert_eq([ProofState::SPENT], lib_states_of($w, $fresh), 'old inputs SPENT');

// (3) definitive rejection on resubmit: inputs released (restored == released)
$fresh2 = $w->getStoredProofs();
lib_mint_stub_script($stub, ['overrides' => ['POST /v1/swap' => ['status' => 503, 'body' => ['detail' => 'down', 'code' => 0]]]]);
try { $w->swap($fresh2, [1, 4]); } catch (CashuException $e) { }
lib_mint_stub_script($stub, ['overrides' => ['POST /v1/swap' => ['status' => 400, 'body' => ['detail' => 'keyset inactive', 'code' => 12002]]]]);
$r = $w->recoverPendingSwaps();
assert_eq(1, $r['released'], 'definitive rejection releases');
assert_eq($r['released'], $r['restored'], 'restored mirrors released for existing callers');
assert_eq([ProofState::UNSPENT], lib_states_of($w, $fresh2), 'inputs UNSPENT again');
assert_eq(0, count($storage->getPendingOperations('swap')), 'journal gone');

// (4) direct definitive rejection in swap(): imported token proofs are removed, nothing leaks
lib_mint_stub_script($stub, ['overrides' => []]);
$donor = lib_stub_wallet($stub, sys_get_temp_dir() . '/lib_donor_' . bin2hex(random_bytes(3)) . '.sqlite');
$donorProofs = lib_stub_fund($stub, $donor, 3);
$token = $donor->serializeToken($donorProofs);
lib_mint_stub_mark_spent($stub, $donorProofs); // the sender double-spent it
// NUT-07 pre-check refuses before reserving anything.
$threw = null;
try { $w->receive($token); } catch (CashuException $e) { $threw = $e; }
assert_true($threw instanceof CashuProtocolException && $threw->getCode() === CashuProtocolException::PROOFS_ALREADY_SPENT, 'spent token refused by the NUT-07 pre-check');
assert_eq(0, count($storage->getPendingOperations('swap')), 'no journal for a pre-refused token');
assert_eq([], lib_proof_states($w, $donorProofs), 'no imported rows');
// A mint without NUT-07 (pre-check skipped): the swap is rejected definitively and settled.
lib_mint_stub_script($stub, ['overrides' => ['GET /v1/info' => ['status' => 200, 'body' => ['name' => 'x', 'nuts' => ['7' => ['supported' => false]]]]]]);
$w7 = lib_stub_wallet($stub, null, $w->getMnemonic());
$threw = null;
try { $w7->receive($token); } catch (CashuException $e) { $threw = $e; }
assert_true($threw instanceof CashuProtocolException && $threw->isDefinitive(), 'mint rejected the double-spent token');
assert_eq(0, count($storage->getPendingOperations('swap')), 'journal settled immediately after a definitive rejection');
assert_eq([], lib_proof_states($w7, $donorProofs), 'imported rows removed again');
assert_eq(5, $w7->getBalance(), 'own balance untouched');
// Duplicate proofs in a token are refused before any network call.
$dup = $donor->serializeToken([$donorProofs[0], $donorProofs[0]]);
lib_mint_stub_reset_requests($stub);
$threw = false;
try { $w7->receive($dup); } catch (CashuException $e) { $threw = true; }
assert_true($threw, 'duplicate proofs refused');
assert_eq(0, count(lib_mint_stub_requests($stub)), 'duplicate check happens before any request');

echo "PASS test_lib_checkstate_fail_closed\n";
exit(0);
