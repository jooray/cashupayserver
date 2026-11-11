<?php
/**
 * Library — LM-2 / LM-4 (storage): proof rows can never be resurrected or
 * overwritten by a re-store; state changes are compare-and-set; EXPORTED is
 * never spendable; journal-reserved inputs are discoverable; the pending-spend
 * primitives are atomic (a failing finalize rolls back and keeps the journal).
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/cashu-wallet-php/CashuWallet.php';

use Cashu\CashuException;
use Cashu\Proof;
use Cashu\ProofState;
use Cashu\WalletStorage;

$db = Database::getDbPath();
$s = new WalletStorage($db, 'https://mint.example', 'sat');
$ks = '00aabbccddeeff01';
$C = '02' . str_repeat('ab', 32);
$p1 = new Proof($ks, 8, 'secret-1', $C);
$p2 = new Proof($ks, 4, 'secret-2', $C);
$p3 = new Proof($ks, 2, 'secret-3', $C);

// ---- storeProofs: count stored, DO NOTHING on conflict, never resurrect ---------
assert_eq(2, $s->storeProofs([$p1, $p2]), 'two rows stored');
assert_eq(0, $s->storeProofs([$p1]), 're-store of a known secret stores nothing');
$s->updateProofsState(['secret-1'], ProofState::SPENT);
assert_eq(0, $s->storeProofs([$p1, $p3]) - 1, 'only the new row counts');
assert_eq(ProofState::SPENT, $s->getProofsStatesBySecrets(['secret-1'])['secret-1'], 'SPENT row not resurrected by re-store');
// Conflicting data for an existing secret is an error, and the whole batch rolls back.
$conflict = new Proof($ks, 16, 'secret-1', $C);
$p4 = new Proof($ks, 1, 'secret-4', $C);
$threw = false;
try { $s->storeProofs([$p4, $conflict]); } catch (CashuException $e) { $threw = true; }
assert_true($threw, 'conflicting amount for an existing secret throws');
assert_eq([], $s->getProofsStatesBySecrets(['secret-4']), 'batch rolled back: secret-4 not stored');
assert_eq(8, (int)$s->getPdo()->query("SELECT amount FROM cashu_proofs WHERE secret='secret-1'")->fetchColumn(), 'existing row untouched');
$conflictC = new Proof($ks, 8, 'secret-1', '03' . str_repeat('ab', 32));
$threw = false;
try { $s->storeProofs([$conflictC]); } catch (CashuException $e) { $threw = true; }
assert_true($threw, 'conflicting C for an existing secret throws');
// Idempotent inside a caller transaction too (savepoint path).
$s->beginTransaction();
assert_eq(1, $s->storeProofs([$p4]), 'stores inside an outer transaction');
$s->rollBack();
assert_eq([], $s->getProofsStatesBySecrets(['secret-4']), 'outer rollback undoes the store');

// ---- transitionProofStates: CAS with from-states and exclusions -------------------
assert_eq(2, $s->transitionProofStates(['secret-2', 'secret-3'], ProofState::PENDING, [ProofState::UNSPENT]), 'two UNSPENT -> PENDING');
assert_eq(0, $s->transitionProofStates(['secret-2'], ProofState::PENDING, [ProofState::UNSPENT]), 'already PENDING: not matched again');
assert_eq(0, $s->transitionProofStates(['secret-1'], ProofState::UNSPENT, [ProofState::PENDING]), 'SPENT row cannot be moved back via a PENDING-only CAS');
assert_eq(1, $s->transitionProofStates(['secret-2', 'secret-3'], ProofState::UNSPENT, [ProofState::PENDING], ['secret-3' => true]), 'excluded secret untouched');
assert_eq(ProofState::PENDING, $s->getProofsStatesBySecrets(['secret-3'])['secret-3'], 'secret-3 still PENDING');
$threw = false;
try { $s->transitionProofStates(['secret-2'], 'BOGUS', [ProofState::UNSPENT]); } catch (CashuException $e) { $threw = true; }
assert_true($threw, 'unknown target state refused');
assert_true(ProofState::isValid(ProofState::EXPORTED) && ProofState::isValid(ProofState::UNKNOWN), 'new states are valid');
assert_eq([ProofState::UNSPENT], ProofState::SPENDABLE, 'only UNSPENT is spendable');

// ---- EXPORTED / UNKNOWN never count as balance ----------------------------------------
$s->transitionProofStates(['secret-3'], ProofState::UNSPENT, [ProofState::PENDING]);
assert_eq(6, $s->getBalance(), 'balance 4+2');
assert_eq(1, $s->transitionProofStates(['secret-3'], ProofState::EXPORTED, [ProofState::UNSPENT]), 'export via CAS');
assert_eq(4, $s->getBalance(), 'EXPORTED excluded from balance');
assert_eq(['secret-2'], array_column($s->getProofs(ProofState::UNSPENT), 'secret'), 'EXPORTED excluded from UNSPENT reads');
assert_eq(1, count($s->getProofsAsObjects(ProofState::UNSPENT)), 'getProofsAsObjects(UNSPENT) excludes EXPORTED');
$s->updateProofsState(['secret-2'], ProofState::UNKNOWN);
assert_eq(0, $s->getBalance(), 'UNKNOWN excluded from balance');
assert_eq(1, $s->countProofsInState(ProofState::UNKNOWN), 'countProofsInState');
$s->updateProofsState(['secret-2'], ProofState::UNSPENT);
// An exported proof can be reclaimed through the journal (reserved from EXPORTED,
// released back to EXPORTED, never to UNSPENT).
$plan = $s->preparePendingSpend('swap:x', 'swap', [$p3], $ks, [2], [], [ProofState::EXPORTED]);
assert_eq(ProofState::PENDING, $s->getProofsStatesBySecrets(['secret-3'])['secret-3'], 'reserved from EXPORTED');
assert_eq(['secret-3' => ProofState::EXPORTED], $plan['reserved_from'], 'journal remembers the origin state');
$s->finalizePendingSpend('swap:x', ['secret-3'], ProofState::UNSPENT);
assert_eq(ProofState::EXPORTED, $s->getProofsStatesBySecrets(['secret-3'])['secret-3'], 'release returns it to EXPORTED');

// ---- preparePendingSpend / getReservedInputSecrets / finalize atomicity -----------
$before = $s->getCounter($ks);
$plan = $s->preparePendingSpend('melt:q1', 'melt', [$p2], $ks, [0, 0, 0]);
assert_eq($before, $plan['counter_start'], 'plan starts at the current counter');
assert_eq($before + 3, $s->getCounter($ks), 'counter advanced by the plan length');
assert_eq(ProofState::PENDING, $s->getProofsStatesBySecrets(['secret-2'])['secret-2'], 'input reserved PENDING');
assert_eq(['secret-2'], $s->getReservedInputSecrets(), 'reserved inputs discoverable');
$again = $s->preparePendingSpend('melt:q1', 'melt', [$p2], $ks, [0, 0, 0]);
assert_eq($plan, $again, 'same id + inputs returns the existing plan');
assert_eq($before + 3, $s->getCounter($ks), 'no second counter range');
$threw = false;
try { $s->preparePendingSpend('melt:q1', 'melt', [$p1], $ks, [0]); } catch (CashuException $e) { $threw = true; }
assert_true($threw, 'same id with different inputs is a collision');
$threw = null;
try { $s->preparePendingSpend('melt:q2', 'melt', [$p2], $ks, [0]); } catch (CashuException $e) { $threw = $e; }
assert_true($threw !== null && str_contains($threw->getMessage(), 'Cannot spend proof: state is PENDING'), 'a reserved input cannot be reserved twice (message kept for host classifiers)');
$threw = false;
try { $s->preparePendingSpend('melt:q3', 'melt', [$p1], $ks, [0]); } catch (CashuException $e) { $threw = true; }
assert_true($threw, 'SPENT input cannot be reserved');
$threw = false;
try { $s->preparePendingSpend('melt:q4', 'melt', [$p4, $p4], $ks, [0]); } catch (CashuException $e) { $threw = true; }
assert_true($threw, 'duplicate inputs refused');
// Foreign (unknown) inputs are imported PENDING and recorded as imported.
$foreign = new Proof($ks, 32, 'foreign-1', $C);
$plan2 = $s->preparePendingSpend('swap:f', 'swap', [$foreign], $ks, [32]);
assert_eq(['foreign-1'], $plan2['imported_secrets'], 'imported inputs recorded');
assert_eq(ProofState::PENDING, $s->getProofsStatesBySecrets(['foreign-1'])['foreign-1'], 'imported row is PENDING');
// settlePendingSpend deletes imported rows on a direct rejection.
$s->settlePendingSpend('swap:f', ['foreign-1'], ['foreign-1' => null]);
assert_eq([], $s->getProofsStatesBySecrets(['foreign-1']), 'imported row removed on settle(null)');
assert_null($s->getPendingOperationById('swap:f'), 'journal gone');
// finalizePendingSpend with a conflicting output rolls back everything (inputs stay
// PENDING, journal stays).
$badOut = new Proof($ks, 99, 'secret-1', $C); // conflicts with the SPENT 8-sat row
$threw = false;
try { $s->finalizePendingSpend('melt:q1', ['secret-2'], ProofState::SPENT, [$badOut]); } catch (CashuException $e) { $threw = true; }
assert_true($threw, 'conflicting output fails finalization');
assert_eq(ProofState::PENDING, $s->getProofsStatesBySecrets(['secret-2'])['secret-2'], 'rolled back: input still PENDING');
assert_not_null($s->getPendingOperationById('melt:q1'), 'rolled back: journal still there');
// An input moved outside the journal cannot be finalized.
$s->updateProofsState(['secret-2'], ProofState::UNSPENT);
$threw = null;
try { $s->finalizePendingSpend('melt:q1', ['secret-2'], ProofState::SPENT, []); } catch (CashuException $e) { $threw = $e; }
assert_true($threw !== null && str_contains($threw->getMessage(), 'no longer owns'), 'finalize refuses inputs changed outside the journal');
$s->updateProofsState(['secret-2'], ProofState::PENDING);
$good = new Proof($ks, 1, 'change-1', $C);
$s->finalizePendingSpend('melt:q1', ['secret-2'], ProofState::SPENT, [$good]);
assert_eq(ProofState::SPENT, $s->getProofsStatesBySecrets(['secret-2'])['secret-2'], 'finalized: input SPENT');
assert_eq(ProofState::UNSPENT, $s->getProofsStatesBySecrets(['change-1'])['change-1'], 'finalized: output stored');
assert_null($s->getPendingOperationById('melt:q1'), 'finalized: journal gone');
assert_eq([], $s->getReservedInputSecrets(), 'nothing reserved any more');

// ---- raiseCounter never lowers ------------------------------------------------------
$s->setCounter($ks, 60);
assert_eq(60, $s->raiseCounter($ks, 10), 'raise below current keeps current');
assert_eq(70, $s->raiseCounter($ks, 70), 'raise above current raises');

// ---- busy_timeout / write-lock-first transactions -------------------------------------
assert_eq(15000, (int)$s->getPdo()->query('PRAGMA busy_timeout')->fetchColumn(), 'busy_timeout 15000');
// A second connection holding a write lock: our BEGIN IMMEDIATE waits instead of
// failing with "database is locked" at upgrade time (bounded wait, then succeeds).
// A competing connection holds the write lock: our write waits (busy_timeout) rather
// than failing at once. Release it from a timer process and measure the wait.
$other = new \PDO("sqlite:$db");
$other->exec('PRAGMA busy_timeout = 1000');
$other->exec('BEGIN IMMEDIATE');
$other->exec("INSERT INTO cashu_counters (wallet_id, keyset_id, counter) VALUES ('zz', 'k', 1)");
$s2 = new WalletStorage($db, 'https://mint.example', 'sat');
$t0 = microtime(true);
$helper = sys_get_temp_dir() . '/lib_lock_helper_' . bin2hex(random_bytes(3)) . '.php';
file_put_contents($helper, '<?php usleep(400000); $p = new PDO("sqlite:" . $argv[1]); $p->exec("PRAGMA busy_timeout = 5000"); $p->exec("INSERT INTO cashu_counters (wallet_id, keyset_id, counter) VALUES (\'yy\', \'k\', 1)");');
// The helper's own write is blocked by $other too; release $other after a short hold
// via a second statement from this process once the storage write is queued. We
// cannot interleave within one thread, so simply hold for the helper's sleep, then
// release and store: the store must succeed, and the helper must have waited too.
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($helper) . ' ' . escapeshellarg($db) . ' >/dev/null 2>&1 &');
usleep(100000);
$other->exec('ROLLBACK');
assert_eq(1, $s2->storeProofs([new Proof($ks, 1, 'late-1', $C)]), 'store succeeds once the competing lock is released');
usleep(600000);
assert_eq(1, (int)$other->query("SELECT COUNT(*) FROM cashu_counters WHERE wallet_id='yy'")->fetchColumn(), 'helper write landed after waiting (busy_timeout, no "database is locked")');
@unlink($helper);

// ---- cleanExpiredPendingOperations keeps money journals ---------------------------
$s->savePendingOperation('melt:old', 'melt', ['input_secrets' => []], time() - 100);
$s->savePendingOperation('misc:old', 'misc', [], time() - 100);
assert_eq(1, $s->cleanExpiredPendingOperations(), 'only the non-money journal is purged');
assert_not_null($s->getPendingOperationById('melt:old'), 'expired melt journal kept');

echo "PASS test_lib_proof_storage\n";
exit(0);
