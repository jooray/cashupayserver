<?php
/**
 * Library — LM-5: resumable, fail-closed seed restore and seed provenance.
 *
 *   - a seed whose counters 0-3 and 40-41 the mint signed is found with the gap
 *     window; the counter ends at 42 (highest signed + 1); proofs the mint reports
 *     UNSPENT are spendable, SPENT ones recorded SPENT;
 *   - restoreStep(maxOutputs) resumes from the persisted cursor across calls with
 *     identical results; a pre-set higher counter is never lowered;
 *   - a checkstate failure parks the proofs UNKNOWN and keeps the account unready
 *     (status error); once the mint answers, reconciliation readies it;
 *   - an unreadable keyset list / keys endpoint never readies the account;
 *   - initializeForRestore() blocks mint()/swap() until the scan completes;
 *   - seed fingerprint mismatch throws; initFromMnemonic() stays backward
 *     compatible (empty storage -> ready, existing data -> adopted ready);
 *   - V2 ("01") keysets are skipped by restore and refused as the active keyset.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/cashu-wallet-php/CashuWallet.php';
require __DIR__ . '/lib_mint_stub.php';

use Cashu\BigInt;
use Cashu\CashuException;
use Cashu\Mnemonic;
use Cashu\ProofState;
use Cashu\Secp256k1;
use Cashu\Wallet;
use Cashu\WalletStorage;

$stub = lib_mint_stub_start();
$db = Database::getDbPath();
$mnemonic = Mnemonic::generate();

/** Pretend the mint signed $counter of the seed (amount $amount) in the past. */
function stub_sign_counter(array $stub, Wallet $w, int $counter, int $amount): \Cashu\Proof {
    $b = $w->createDeterministicBlindedMessage(LIB_STUB_KEYSET, $counter);
    $pt = Secp256k1::decompressPoint(hex2bin($b['B_']));
    $k = BigInt::fromHex(hash('sha256', 'stubmint:' . $amount))->mod(Secp256k1::getOrder());
    $C_ = bin2hex(Secp256k1::compressPoint(Secp256k1::scalarMult($k, $pt)));
    $state = lib_mint_stub_state($stub);
    $state['signed'][strtolower($b['B_'])] = ['id' => LIB_STUB_KEYSET, 'amount' => $amount, 'C_' => $C_];
    lib_mint_stub_set_state($stub, $state);
    $A = bin2hex(Secp256k1::compressPoint(Secp256k1::scalarMult($k, Secp256k1::getGenerator())));
    return new \Cashu\Proof(LIB_STUB_KEYSET, $amount, $b['secret'], \Cashu\Crypto::unblindSignature($C_, $b['r'], $A));
}

// A throwaway wallet (no storage) just to derive the seed's blinded messages.
$deriver = new Wallet($stub['url'], 'sat');
$deriver->loadMint();
$deriver->initFromMnemonic($mnemonic);
$signed = [];
foreach ([0 => 8, 1 => 4, 2 => 2, 3 => 1, 40 => 16, 41 => 32] as $counter => $amount) {
    $signed[$counter] = stub_sign_counter($stub, $deriver, $counter, $amount);
}
lib_mint_stub_mark_spent($stub, [$signed[2]]); // the 2-sat proof was spent before

// ---- import for restore: unready, mint() refused -------------------------------------
$w = new Wallet($stub['url'], 'sat', $db);
$w->loadMint();
$w->initializeForRestore($mnemonic);
assert_true($w->requiresRecovery(), 'restore-bound seed requires recovery');
assert_eq('none', $w->getRestoreStatus()['status'], 'no session yet');
$prov = $w->getStorage()->getSeedProvenance();
assert_eq(WalletStorage::INIT_RESTORE_REQUIRED, $prov['init_reason'], 'provenance recorded');
assert_false($prov['ready'], 'not ready');
$threw = false;
try { $w->swap($signed, [1]); } catch (CashuException $e) { $threw = true; }
assert_true($threw, 'swap refused while recovery is required');
$threw = false;
try { $w->markSeedReady(); } catch (CashuException $e) { $threw = true; }
assert_true($threw, 'a restore-required account cannot be readied without a scan');

// ---- counter 60 pre-set must never be lowered ------------------------------------------
$w->getStorage()->setCounter(LIB_STUB_KEYSET, 60);

// ---- resumable steps: 50 outputs per call, gap 500 ------------------------------------
$steps = 0;
do {
    $r = $w->restoreStep(50, microtime(true) + 30, 500);
    $steps++;
    assert_true(in_array($r['status'], ['pending', 'complete'], true), 'step status: ' . $r['status'] . ' ' . ($r['error'] ?? ''));
    if ($r['status'] === 'pending') {
        assert_false($r['ready'], 'not ready while pending');
        assert_true($w->requiresRecovery(), 'still requires recovery while pending');
    }
} while ($r['status'] === 'pending' && $steps < 40);
assert_eq('complete', $r['status'], 'restore completed in ' . $steps . ' steps');
assert_true($steps >= 10, 'bounded steps: 542 counters at 50 per call needs >= 10 calls (' . $steps . ')');
assert_true($r['ready'], 'ready after complete');
assert_false($w->requiresRecovery(), 'recovery no longer required');
$p = $r['progress'][LIB_STUB_KEYSET];
assert_eq(41, $p['highestSigned'], 'highest signed counter 41');
assert_true($p['done'] && $p['emptyRun'] >= 500, 'gap of 500 empty counters past 41 reached');
assert_eq(60, $w->getStorage()->getCounter(LIB_STUB_KEYSET), 'pre-set counter 60 was not lowered to 42');
assert_eq(60, $w->getCounter(LIB_STUB_KEYSET), 'in-memory counter follows storage');
assert_eq(8 + 4 + 1 + 16 + 32, $w->getBalance(), 'unspent restored proofs (2-sat one is SPENT)');
assert_eq(ProofState::SPENT, $w->getStorage()->getProofsStatesBySecrets([$signed[2]->secret])[$signed[2]->secret], 'spent proof recorded SPENT');
$status = $w->getRestoreStatus();
assert_eq('complete', $status['status'], 'status persisted');
assert_eq(WalletStorage::READY_RECOVERY_COMPLETE, $status['provenance']['ready_reason'], 'readied by recovery');
// The restored wallet can now issue, and does so above every signed counter.
$quote = $w->requestMintQuote(2);
lib_mint_stub_script($stub, ['mint_quotes' => [$quote->quote => ['state' => 'PAID', 'amount' => 2]]], false);
$minted = $w->mint($quote->quote, 2);
assert_eq(2, \Cashu\Wallet::sumProofs($minted), 'mint works after recovery');
assert_eq(61, $w->getStorage()->getCounter(LIB_STUB_KEYSET), 'issued at counter 60');

// Same seed, fresh storage, with the counter not pre-set: ends at 42.
$db2 = sys_get_temp_dir() . '/lib_restore2_' . bin2hex(random_bytes(4)) . '.sqlite';
$w2 = new Wallet($stub['url'], 'sat', $db2);
$w2->loadMint();
$w2->initializeForRestore($mnemonic);
$r = $w2->restoreStep(1000, null, 500);
assert_eq('complete', $r['status'], 'one big step completes');
// The mint above issued at counter 60 of this seed, so the scan finds 0-3, 40-41 and 60.
assert_eq(61, $w2->getStorage()->getCounter(LIB_STUB_KEYSET), 'counter = highest signed (60) + 1');
assert_eq(8 + 4 + 1 + 16 + 32 + 2, $w2->getBalance(), 'same proofs found, including the 2 sat minted at counter 60');

// ---- checkstate failure parks UNKNOWN and keeps the account unready ------------------
$db3 = sys_get_temp_dir() . '/lib_restore3_' . bin2hex(random_bytes(4)) . '.sqlite';
lib_mint_stub_script($stub, ['checkstate' => 'error']);
$w3 = new Wallet($stub['url'], 'sat', $db3);
$w3->loadMint();
$w3->initializeForRestore($mnemonic);
$r = $w3->restoreStep(1000, null, 500);
assert_eq('error', $r['status'], 'checkstate outage -> error status');
assert_false($r['ready'], 'not ready after a checkstate outage');
assert_true($w3->requiresRecovery(), 'still requires recovery');
assert_true($r['recovered']['unknown'] > 0, 'proofs parked UNKNOWN');
assert_eq(0, $w3->getBalance(), 'UNKNOWN proofs are not balance');
assert_eq(61, $w3->getStorage()->getCounter(LIB_STUB_KEYSET), 'counters still raised (never lowered later)');
$threw = false;
try { $w3->swap($signed, [1]); } catch (CashuException $e) { $threw = true; }
assert_true($threw, 'still refusing to issue');
// Mint answers again: the next step reconciles the UNKNOWN proofs and readies.
lib_mint_stub_script($stub, ['checkstate' => 'normal']);
$r = $w3->restoreStep(1000, null, 500);
assert_eq('complete', $r['status'], 'completes once states can be verified: ' . ($r['error'] ?? ''));
assert_true($r['ready'], 'ready after reconciliation');
assert_eq(8 + 4 + 1 + 16 + 32 + 2, $w3->getBalance(), 'UNKNOWN promoted to UNSPENT');
assert_eq(0, $w3->getStorage()->countProofsInState(ProofState::UNKNOWN), 'no UNKNOWN left');

// ---- keyset list / keys failures never ready the account ----------------------------
$db4 = sys_get_temp_dir() . '/lib_restore4_' . bin2hex(random_bytes(4)) . '.sqlite';
$w4 = new Wallet($stub['url'], 'sat', $db4);
$w4->loadMint();
$w4->initializeForRestore($mnemonic);
lib_mint_stub_script($stub, ['overrides' => ['GET /v1/keysets' => ['status' => 200, 'body' => ['keysets' => 'garbage']]]]);
$r = $w4->restoreStep(1000, null, 500);
assert_eq('error', $r['status'], 'unreadable keyset list -> error');
assert_false($r['ready'], 'not ready');
lib_mint_stub_script($stub, ['overrides' => ['GET /v1/keysets' => ['status' => 200, 'body' => ['keysets' => []]]]]);
$r = $w4->restoreStep(1000, null, 500);
assert_eq('error', $r['status'], 'empty keyset list -> error (nothing to scan proves nothing)');
assert_true($w4->requiresRecovery(), 'still unready');
lib_mint_stub_script($stub, ['keysets' => [
    ['id' => LIB_STUB_KEYSET, 'unit' => 'sat', 'active' => true, 'input_fee_ppk' => 0],
    ['id' => '00deadbeef000001', 'unit' => 'sat', 'active' => false, 'input_fee_ppk' => 0],
]]);
$r = $w4->restoreStep(1000, null, 500);
assert_eq('error', $r['status'], 'a keyset whose keys cannot be fetched blocks readiness');
assert_true(isset($r['errors']['00deadbeef000001']), 'the failing keyset is named');
assert_false($r['ready'], 'not ready while a keyset is unscanned');
// V2 keysets are skipped (nothing of ours can be there) and the scan completes.
lib_mint_stub_script($stub, ['keysets' => [
    ['id' => LIB_STUB_KEYSET, 'unit' => 'sat', 'active' => true, 'input_fee_ppk' => 0],
    ['id' => '01' . str_repeat('ab', 32), 'unit' => 'sat', 'active' => false, 'input_fee_ppk' => 0],
]]);
$r = $w4->restoreStep(1000, null, 500);
assert_eq('complete', $r['status'], 'completes with the V2 keyset skipped: ' . ($r['error'] ?? ''));
assert_eq(['01' . str_repeat('ab', 32)], $r['skippedKeysets'], 'V2 keyset reported as skipped');

// ---- active V2 keyset: loadMint fails closed ------------------------------------------
lib_mint_stub_script($stub, ['keysets' => [['id' => '01' . str_repeat('ab', 32), 'unit' => 'sat', 'active' => true, 'input_fee_ppk' => 0]]]);
$threw = null;
try { (new Wallet($stub['url'], 'sat'))->loadMint(); } catch (CashuException $e) { $threw = $e; }
assert_true($threw !== null && str_contains($threw->getMessage(), 'cannot derive'), 'V2-only active keyset refused: ' . ($threw ? $threw->getMessage() : 'no throw'));
lib_mint_stub_script($stub, ['keysets' => [
    ['id' => '01' . str_repeat('ab', 32), 'unit' => 'sat', 'active' => true, 'input_fee_ppk' => 0],
    ['id' => LIB_STUB_KEYSET, 'unit' => 'sat', 'active' => true, 'input_fee_ppk' => 0],
]]);
$wv = new Wallet($stub['url'], 'sat');
$wv->loadMint();
assert_eq(LIB_STUB_KEYSET, $wv->getActiveKeysetId(), 'a derivable active keyset is preferred over the V2 one');
lib_mint_stub_script($stub, []);

// ---- seed provenance: several seeds per namespace, each with its own readiness ----------
// A namespace (mint:unit) is shared by every store on a multi-store server, each
// with its own seed: a second seed is NOT a mismatch, it gets its own row.
$other = Mnemonic::generate();
$wOther = new Wallet($stub['url'], 'sat', $db);
$wOther->loadMint();
$wOther->initializeForRestore($other);
assert_true($wOther->requiresRecovery(), 'second seed bound restore_required on the shared namespace');
assert_eq(2, count($wOther->getStorage()->getBoundSeedFingerprints()), 'two seeds bound to one namespace');
$wFirst = new Wallet($stub['url'], 'sat', $db);
$wFirst->loadMint();
$wFirst->initFromMnemonic($mnemonic);
assert_false($wFirst->requiresRecovery(), 'first seed stays ready');
$threw = false;
try { $wOther->swap($signed, [1]); } catch (CashuException $e) { $threw = true; }
assert_true($threw, 'unready second seed cannot issue');
$quote = $wFirst->requestMintQuote(1);
lib_mint_stub_script($stub, ['mint_quotes' => [$quote->quote => ['state' => 'PAID', 'amount' => 1]]], false);
assert_eq(1, Wallet::sumProofs($wFirst->mint($quote->quote, 1)), 'ready first seed still issues on the shared namespace');
$r = $wOther->restoreStep(1000, null, 500);
assert_eq('complete', $r['status'], 'second seed restores independently: ' . ($r['error'] ?? ''));
assert_false($wOther->requiresRecovery(), 'second seed ready after its own scan');
assert_false($wFirst->requiresRecovery(), 'first seed unaffected');
(new Wallet($stub['url'], 'sat', $db))->initFromMnemonic($mnemonic); // same seed: fine
// Readiness is per seed: re-opening the first seed does not inherit the other's session.
$st = (new Wallet($stub['url'], 'sat', $db));
$st->initFromMnemonic($mnemonic);
assert_eq('complete', $st->getRestoreStatus()['status'], 'first seed keeps its own (completed) session');
// Empty storage + initFromMnemonic -> bound as a fresh seed, ready.
$db5 = sys_get_temp_dir() . '/lib_compat_' . bin2hex(random_bytes(4)) . '.sqlite';
$w5 = new Wallet($stub['url'], 'sat', $db5);
$w5->loadMint();
$w5->initFromMnemonic($other);
assert_false($w5->requiresRecovery(), 'legacy initFromMnemonic on empty storage stays usable');
assert_eq(WalletStorage::INIT_FRESH_SEED, $w5->getStorage()->getSeedProvenance()['init_reason'], 'bound as fresh seed');
// Existing data without a fingerprint + initFromMnemonic -> adopted, ready.
$db6 = sys_get_temp_dir() . '/lib_adopt_' . bin2hex(random_bytes(4)) . '.sqlite';
$legacy = new WalletStorage($db6, $stub['url'], 'sat');
$legacy->setCounter(LIB_STUB_KEYSET, 7);
$w6 = new Wallet($stub['url'], 'sat', $db6);
$w6->loadMint();
$w6->initFromMnemonic($other);
assert_false($w6->requiresRecovery(), 'legacy storage with data is adopted ready');
assert_eq(WalletStorage::INIT_ADOPTED_STORAGE, $w6->getStorage()->getSeedProvenance()['init_reason'], 'adopted');
assert_eq(7, $w6->getCounter(LIB_STUB_KEYSET), 'adopted counters kept');
// initializeNewFromMnemonic refuses legacy storage that holds data but has no bound seed
// (that data may be the seed's own history); once another seed is bound, a brand-new
// seed may join the shared namespace.
$db7 = sys_get_temp_dir() . '/lib_new_' . bin2hex(random_bytes(4)) . '.sqlite';
(new WalletStorage($db7, $stub['url'], 'sat'))->setCounter(LIB_STUB_KEYSET, 1);
$threw = false;
try { (new Wallet($stub['url'], 'sat', $db7))->initializeNewFromMnemonic($other); } catch (CashuException $e) { $threw = true; }
assert_true($threw, 'initializeNewFromMnemonic refuses unbound non-empty storage');
(new Wallet($stub['url'], 'sat', $db7))->adoptSeedForExistingStorage($mnemonic);
$wNew = new Wallet($stub['url'], 'sat', $db7);
$wNew->initializeNewFromMnemonic($other);
assert_false($wNew->requiresRecovery(), 'a fresh seed joins a namespace another seed already owns');
// adoptSeedForExistingStorage on EMPTY storage binds restore_required (empty proves nothing).
$db8 = sys_get_temp_dir() . '/lib_adoptempty_' . bin2hex(random_bytes(4)) . '.sqlite';
$w8 = new Wallet($stub['url'], 'sat', $db8);
$w8->adoptSeedForExistingStorage($other);
assert_true($w8->requiresRecovery(), 'adopting empty storage binds restore_required');

foreach ([$db2, $db3, $db4, $db5, $db6, $db7, $db8] as $f) { @unlink($f); @unlink("$f-wal"); @unlink("$f-shm"); }
echo "PASS test_lib_restore_step\n";
exit(0);
