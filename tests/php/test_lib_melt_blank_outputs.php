<?php
/**
 * Library — LM-1 / LM-6: NUT-08 blank change outputs on melt.
 *
 *   - the POST carries blankOutputCount(inputs - amount - inputFee) outputs, all
 *     with amount 0; the mint assigns the change amounts (unused fee reserve +
 *     overpayment) and the wallet unblinds whatever it assigned;
 *   - amount-0 signatures on unused blanks are skipped, never a throw after PAID;
 *   - the counter advances for every blank (used or not);
 *   - when the POST response is lost and GET quote has no `change` (NUT-05 only
 *     defines it on POST), the change is recovered via NUT-09 restore of the
 *     journaled blank outputs — both inside melt() and from recoverPendingMelts();
 *   - selectProofsWithFees covers the inputs' own NUT-02 fee; blankOutputCount math.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/cashu-wallet-php/CashuWallet.php';
require __DIR__ . '/lib_mint_stub.php';

use Cashu\CashuException;
use Cashu\Proof;
use Cashu\ProofState;
use Cashu\Wallet;

// blankOutputCount: one output per bit of the largest possible change.
assert_eq(0, Wallet::blankOutputCount(0), 'no change -> no blanks');
assert_eq(1, Wallet::blankOutputCount(1), '1 -> 1 bit');
assert_eq(2, Wallet::blankOutputCount(3), '3 -> 2 bits');
assert_eq(3, Wallet::blankOutputCount(4), '4 -> 3 bits');
assert_eq(5, Wallet::blankOutputCount(28), '28 -> 5 bits');
assert_eq(63, Wallet::blankOutputCount(PHP_INT_MAX), 'PHP_INT_MAX is 63 bits (cap is 64)');

$stub = lib_mint_stub_start();
$w = lib_stub_wallet($stub);
$storage = $w->getStorage();

// ---- blanks on the wire; zero-amount signatures skipped ---------------------------
$funded = lib_stub_fund($stub, $w, 128);
lib_stub_melt_quote($stub, 'q1', 100, 3);
lib_mint_stub_script($stub, ['melt_fee' => 1, 'zero_blanks' => true], false);
$counterBefore = $storage->getCounter(LIB_STUB_KEYSET);
lib_mint_stub_reset_requests($stub);
$res = $w->melt('q1', $funded);
$post = lib_mint_stub_requests_for($stub, 'POST /v1/melt/bolt11')[0]['body'];
assert_eq(5, count($post['outputs']), '5 blank outputs for maxChange 28');
assert_eq([0, 0, 0, 0, 0], array_column($post['outputs'], 'amount'), 'all blanks amount 0');
assert_true($res['paid'] && !$res['changeRecoveryPending'], 'paid without change-recovery backlog');
// change = 128 - 100 - 1 = 27 = 16+8+2+1 -> 4 signed + 1 zero-amount signature
assert_eq(27, Wallet::sumProofs($res['change']), 'mint-assigned change amounts unblinded');
assert_eq(4, count($res['change']), 'amount-0 signature skipped');
assert_eq($counterBefore + 5, $storage->getCounter(LIB_STUB_KEYSET), 'counter advanced for all 5 blanks');
assert_eq(27, $w->getBalance(), 'change stored UNSPENT');
assert_eq([ProofState::SPENT], lib_states_of($w, $funded), 'inputs SPENT');
foreach ($res['change'] as $p) {
    assert_eq(LIB_STUB_KEYSET, $p->id, 'change on the wallet keyset');
    assert_true(in_array($p->amount, [1, 2, 8, 16], true), 'change denominations are the decomposition');
}

// ---- NUT-09 change recovery inside melt(): lost POST, GET PAID without change -----
$change = $w->getStoredProofs();
lib_stub_melt_quote($stub, 'q2', 20, 2); // no change_on_get
lib_mint_stub_script($stub, [
    'overrides' => ['POST /v1/melt/bolt11' => ['status' => 500, 'body' => ['detail' => 'gateway', 'code' => 0], 'side_effect' => 'pay']],
    'melt_fee' => 1, 'zero_blanks' => false,
], false);
lib_mint_stub_reset_requests($stub);
$res = $w->melt('q2', $change);
assert_true($res['paid'], 'lost response reconciled as paid');
assert_false($res['changeRecoveryPending'], 'change recovered via NUT-09 within melt()');
assert_eq(6, Wallet::sumProofs($res['change']), 'change 27-20-1 = 6 recovered through /v1/restore');
assert_eq(1, count(lib_mint_stub_requests_for($stub, 'POST /v1/restore')), 'one NUT-09 restore call made');
assert_eq(6, $w->getBalance(), 'recovered change spendable');
assert_null($storage->getPendingOperationById('melt:q2'), 'journal retired');

// ---- NUT-09 change recovery from recoverPendingMelts() ----------------------------
// Journal present, mint paid (quote PAID, no change on GET), and the restore
// endpoint is briefly broken -> melt() reports changeRecoveryPending; cron finishes.
$change2 = $w->getStoredProofs();
lib_stub_melt_quote($stub, 'q3', 3, 1);
lib_mint_stub_script($stub, [
    'overrides' => ['POST /v1/melt/bolt11' => ['status' => 500, 'body' => ['detail' => 'gateway', 'code' => 0], 'side_effect' => 'pay']],
    'melt_fee' => 1, 'restore' => 'error',
], false);
$res = $w->melt('q3', $change2);
assert_true($res['paid'], 'paid even though change recovery failed');
assert_true($res['changeRecoveryPending'], 'changeRecoveryPending reported instead of a throw');
assert_true(isset($res['changeRecoveryError']), 'changeRecoveryError carries the reason');
assert_eq([ProofState::PENDING], lib_states_of($w, $change2), 'inputs stay reserved until change is collected');
assert_not_null($storage->getPendingOperationById('melt:q3'), 'journal kept for change recovery');
lib_mint_stub_script($stub, ['overrides' => [], 'restore' => 'normal'], false);
$r = $w->recoverPendingMelts();
assert_eq(1, $r['paid'], 'cron finalized the paid melt');
assert_eq(2, $r['change_recovered'], 'change 6-3-1 = 2 recovered via NUT-09 by cron');
assert_eq([ProofState::SPENT], lib_states_of($w, $change2), 'inputs SPENT after cron');
assert_eq(2, $w->getBalance(), 'recovered change spendable');
assert_null($storage->getPendingOperationById('melt:q3'), 'journal retired by cron');

// ---- restore returning nothing for the blanks: journal retired, change 0 ---------
$left = $w->getStoredProofs();
lib_stub_melt_quote($stub, 'q4', 1, 1);
lib_mint_stub_script($stub, [
    'overrides' => ['POST /v1/melt/bolt11' => ['status' => 500, 'body' => ['detail' => 'gateway', 'code' => 0], 'side_effect' => 'pay']],
    'melt_fee' => 1, 'restore' => 'none',
], false);
$res = $w->melt('q4', $left);
assert_true($res['paid'] && !$res['changeRecoveryPending'], 'no change found is not an error');
assert_eq([], $res['change'], 'change empty (fee ate the reserve)');
assert_null($storage->getPendingOperationById('melt:q4'), 'journal retired when NUT-09 finds nothing');

// ---- selectProofsWithFees ---------------------------------------------------------
lib_mint_stub_script($stub, ['overrides' => [], 'input_fee_ppk' => 1000], false);
$wf = new Wallet($stub['url'], 'sat', Database::getDbPath());
$wf->loadMint();
assert_eq(1000, $wf->getInputFeePpk(), 'fee keyset loaded');
$pool = [new Proof(LIB_STUB_KEYSET, 8, 's8', '02' . str_repeat('00', 32)),
         new Proof(LIB_STUB_KEYSET, 4, 's4', '02' . str_repeat('00', 32)),
         new Proof(LIB_STUB_KEYSET, 1, 's1', '02' . str_repeat('00', 32))];
$sel = $wf->selectProofsWithFees($pool, 8); // 8 + fee(1 input)=1 -> needs 9 -> [8,4]
assert_eq(12, Wallet::sumProofs($sel), 'selection covers amount + its own input fee');
$threw = false;
try { $wf->selectProofsWithFees($pool, 11); } catch (\Cashu\InsufficientBalanceException $e) { $threw = true; }
assert_true($threw, '13 total, 3 inputs cost 3 fee -> 11 not coverable');
// A string input_fee_ppk is parsed, a negative one refused (CashuException, never TypeError).
lib_mint_stub_script($stub, ['input_fee_ppk' => -5], false);
$threw = null;
try { (new Wallet($stub['url'], 'sat'))->loadMint(); } catch (\Throwable $e) { $threw = $e; }
assert_true($threw instanceof CashuException, 'negative input_fee_ppk -> CashuException: ' . get_class($threw));

echo "PASS test_lib_melt_blank_outputs\n";
exit(0);
