<?php
/**
 * Library (cashu-wallet-php) — LM-3: every melt writes its journal (inputs
 * reserved PENDING + blank-output counter range) BEFORE the POST, also when no
 * change is possible, and any exception from the POST is reconciled through the
 * melt quote:
 *
 *   POST 500 (ambiguous), mint did NOT pay, GET UNPAID + inputs UNSPENT
 *       -> error rethrown, inputs stay PENDING, journal kept (fail closed: a 5xx
 *          may mean a gateway timed out while the mint kept going)
 *   POST 400 (definitive), GET UNPAID + NUT-07 confirms all inputs UNSPENT
 *       -> error rethrown, inputs released to UNSPENT, journal gone
 *   POST 500 but the mint DID pay (response lost), GET PAID (+change)
 *       -> melt() returns paid=true, inputs SPENT, change stored, journal gone
 *   POST 500 while the mint is still paying, GET PENDING
 *       -> error rethrown, inputs PENDING, journal kept
 *   zero-change melt (inputs == amount, no fee reserve) -> journal still written
 *   quote already PAID and no journal -> refused (never attributes an old payment
 *       to fresh inputs)
 *   a cron recoverPendingMelts() afterwards finalizes the PENDING-then-PAID case.
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

// Fund: 128 sat as [128]; melt quotes of amount 100 / fee_reserve 3.
$funded = lib_stub_fund($stub, $w, 128);
assert_eq(128, Wallet::sumProofs($funded), 'funded 128');

// ---- (a) ambiguous 500, mint did not pay --------------------------------------
lib_stub_melt_quote($stub, 'q_amb', 100, 3);
lib_mint_stub_script($stub, ['overrides' => ['POST /v1/melt/bolt11' => ['status' => 500, 'body' => ['detail' => 'gateway', 'code' => 0]]]], false);
lib_mint_stub_reset_requests($stub);
$threw = null;
try { $w->melt('q_amb', $funded); } catch (CashuException $e) { $threw = $e; }
assert_true($threw instanceof CashuProtocolException, '5xx surfaces as CashuProtocolException');
assert_false($threw->isDefinitive(), '5xx is not a definitive rejection');
assert_eq([ProofState::PENDING], lib_states_of($w, $funded), '5xx: inputs stay reserved PENDING');
$journal = $storage->getPendingOperationById('melt:q_amb');
assert_not_null($journal, '5xx: journal kept');
assert_eq(array_map(fn($p) => $p->secret, $funded), $journal['data']['input_secrets'], 'journal carries input secrets');
assert_eq(5, count($journal['data']['amounts']), 'journal carries the 5 blank-output counters (maxChange 28)');
assert_eq([0, 0, 0, 0, 0], $journal['data']['amounts'], 'blank outputs have amount 0');
// Journal-before-POST: the POST failed, so the journal can only have been written before it.
$posts = lib_mint_stub_requests_for($stub, 'POST /v1/melt/bolt11');
assert_eq(1, count($posts), 'exactly one melt POST');
assert_eq(5, count($posts[0]['body']['outputs']), 'POST carried the 5 blank outputs');
foreach ($posts[0]['body']['outputs'] as $o) { assert_eq(0, $o['amount'], 'blank output amount 0 on the wire'); }
$gets = lib_mint_stub_requests_for($stub, 'GET /v1/melt/quote/bolt11/q_amb');
assert_true(count($gets) >= 2, 'quote state was re-read after the failure (reconcile)');
// The PENDING inputs are not selectable: balance excludes them.
assert_eq(0, $w->getBalance(), 'reserved inputs are not in the spendable balance');
// A retry with the same inputs re-finds the journal (no second counter range).
$counterBefore = $storage->getCounter(LIB_STUB_KEYSET);
lib_mint_stub_script($stub, ['overrides' => ['POST /v1/melt/bolt11' => ['status' => 500, 'body' => ['detail' => 'gateway', 'code' => 0]]]], false);
try { $w->melt('q_amb', $funded); } catch (CashuException $e) { }
assert_eq($counterBefore, $storage->getCounter(LIB_STUB_KEYSET), 'retry reuses the journaled counter range');
// Cron: the quote is still UNPAID and not expired -> still pending, nothing released.
lib_mint_stub_script($stub, ['overrides' => []], false);
$r = $w->recoverPendingMelts();
assert_eq(1, $r['still_pending'], 'unexpired UNPAID journal is kept by recovery');
assert_eq([ProofState::PENDING], lib_states_of($w, $funded), 'still PENDING after recovery pass');
// Expire the quote: NUT-07 confirms UNSPENT -> released.
lib_stub_melt_quote($stub, 'q_amb', 100, 3, ['expiry' => time() - 10]);
$r = $w->recoverPendingMelts();
assert_eq(1, $r['restored'], 'expired UNPAID + mint-confirmed UNSPENT -> released');
assert_eq([ProofState::UNSPENT], lib_states_of($w, $funded), 'inputs back to UNSPENT');
assert_null($storage->getPendingOperationById('melt:q_amb'), 'journal gone after release');

// ---- (b) definitive 400, GET UNPAID, inputs UNSPENT -> released at once ---------
lib_stub_melt_quote($stub, 'q_def', 100, 3);
lib_mint_stub_script($stub, ['overrides' => ['POST /v1/melt/bolt11' => ['status' => 400, 'body' => ['detail' => 'unit mismatch', 'code' => 11005]]]], false);
$threw = null;
try { $w->melt('q_def', $funded); } catch (CashuException $e) { $threw = $e; }
assert_true($threw instanceof CashuProtocolException && $threw->isDefinitive(), '400 is a definitive rejection');
assert_eq([ProofState::UNSPENT], lib_states_of($w, $funded), 'definitive rejection releases inputs');
assert_null($storage->getPendingOperationById('melt:q_def'), 'definitive rejection retires the journal');
assert_eq(128, $w->getBalance(), 'balance restored');

// ---- (c) 500 but the mint paid (lost response), GET PAID with change -------------
lib_stub_melt_quote($stub, 'q_lost', 100, 3, ['change_on_get' => true]);
lib_mint_stub_script($stub, [
    'overrides' => ['POST /v1/melt/bolt11' => ['status' => 500, 'body' => ['detail' => 'gateway', 'code' => 0], 'side_effect' => 'pay']],
    'melt_fee' => 1,
], false);
$res = $w->melt('q_lost', $funded);
assert_true($res['paid'], 'lost PAID response reconciled to paid=true');
assert_false($res['changeRecoveryPending'], 'change recovered synchronously');
assert_eq('PAID', $res['state'], 'state key is PAID');
assert_eq(27, Wallet::sumProofs($res['change']), 'change 128-100-1 = 27 returned');
assert_eq([ProofState::SPENT], lib_states_of($w, $funded), 'inputs SPENT after reconciled PAID');
assert_null($storage->getPendingOperationById('melt:q_lost'), 'journal gone after paid');
assert_eq(27, $w->getBalance(), 'change is the new spendable balance');

// ---- (d) 500 while the mint is still paying, GET PENDING -> kept -----------------
$changeProofs = $w->getStoredProofs();
lib_stub_melt_quote($stub, 'q_pend', 20, 2);
lib_mint_stub_script($stub, ['overrides' => ['POST /v1/melt/bolt11' => ['status' => 500, 'body' => ['detail' => 'gateway', 'code' => 0], 'side_effect' => 'pending']]], false);
$threw = null;
try { $w->melt('q_pend', $changeProofs); } catch (CashuException $e) { $threw = $e; }
assert_not_null($threw, 'PENDING reconcile rethrows');
assert_eq([ProofState::PENDING], lib_states_of($w, $changeProofs), 'PENDING: inputs stay PENDING');
assert_not_null($storage->getPendingOperationById('melt:q_pend'), 'PENDING: journal kept');
$r = $w->recoverPendingMelts();
assert_eq(1, $r['still_pending'], 'recovery keeps a PENDING melt');
// The mint completes the payment later: cron finalizes it (change via quote).
$state = lib_mint_stub_state($stub);
$state['melts']['q_pend'] = ['state' => 'PAID', 'payment_preimage' => str_repeat('ef', 32), 'change' => []];
foreach ($changeProofs as $p) { $state['spent'][\Cashu\Crypto::computeY($p->secret)] = true; }
lib_mint_stub_set_state($stub, $state);
lib_mint_stub_script($stub, ['overrides' => []], false);
$r = $w->recoverPendingMelts();
assert_eq(1, $r['paid'], 'recovery finalizes the melt once the quote is PAID');
assert_eq([ProofState::SPENT], lib_states_of($w, $changeProofs), 'inputs SPENT after recovery');
assert_null($storage->getPendingOperationById('melt:q_pend'), 'journal gone after recovery');

// ---- (e) zero-change melt still journals ------------------------------------------
$exact = lib_stub_fund($stub, $w, 64);
lib_stub_melt_quote($stub, 'q_zero', 64, 0);
lib_mint_stub_script($stub, ['overrides' => ['POST /v1/melt/bolt11' => ['status' => 500, 'body' => ['detail' => 'gateway', 'code' => 0]]]], false);
lib_mint_stub_reset_requests($stub);
try { $w->melt('q_zero', $exact); } catch (CashuException $e) { }
$journal = $storage->getPendingOperationById('melt:q_zero');
assert_not_null($journal, 'zero-change melt wrote a journal before the POST');
assert_eq([], $journal['data']['amounts'], 'no blank outputs when no change is possible');
assert_eq([ProofState::PENDING], lib_states_of($w, $exact), 'zero-change inputs reserved PENDING');
$posts = lib_mint_stub_requests_for($stub, 'POST /v1/melt/bolt11');
assert_eq([], $posts[0]['body']['outputs'], 'zero-change POST carried no outputs');
assert_null($journal['expires_at'], 'melt journals never expire (cron cleanup must not delete them)');
assert_eq(0, $storage->cleanExpiredPendingOperations(), 'cleanExpiredPendingOperations leaves money journals alone');
// Mint later refuses definitively on retry with the quote still UNPAID -> release.
lib_mint_stub_script($stub, ['overrides' => ['POST /v1/melt/bolt11' => ['status' => 400, 'body' => ['detail' => 'no', 'code' => 11005]]]], false);
try { $w->melt('q_zero', $exact); } catch (CashuException $e) { }
assert_eq([ProofState::UNSPENT], lib_states_of($w, $exact), 'definitive retry rejection releases zero-change inputs');

// ---- (f) quote already PAID with no journal -> refused ----------------------------
lib_mint_stub_script($stub, ['overrides' => []], false);
lib_stub_melt_quote($stub, 'q_paid', 10, 1, ['state' => 'PAID']);
$threw = null;
try { $w->melt('q_paid', $exact); } catch (CashuException $e) { $threw = $e; }
assert_not_null($threw, 'a quote the mint already paid cannot be melted with new inputs');
assert_true(str_contains($threw->getMessage(), 'already'), 'refusal names the state: ' . $threw->getMessage());
assert_eq([ProofState::UNSPENT], lib_states_of($w, $exact), 'refused melt reserves nothing');

// ---- (g) the happy path still returns the documented keys -----------------------
lib_stub_melt_quote($stub, 'q_ok', 60, 2);
$res = $w->melt('q_ok', $exact);
assert_true($res['paid'] && !$res['pending'] && !$res['changeRecoveryPending'], 'happy path keys');
assert_eq(3, Wallet::sumProofs($res['change']), 'change 64-60-1 = 3');
assert_eq(3, $w->getBalance(), 'change spendable');

echo "PASS test_lib_melt_reconcile\n";
exit(0);
