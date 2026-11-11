<?php
/**
 * DO-1 / DO-7 / WM-6: exported proofs leave the spendable pool for good.
 *
 *   - markProofsExported moves UNSPENT → EXPORTED; getBalance / getUnspentProofs
 *     exclude them; a second export can never select them;
 *   - markProofsExported refuses (and changes nothing) when any proof is not
 *     UNSPENT — the cron's cashout may have spent it in the meantime;
 *   - checkPendingProofs only ever applies the SPENT transition: a mint that
 *     answers UNSPENT leaves PENDING/EXPORTED rows alone, a short reply changes
 *     nothing, and an unreachable mint is reported, not fatal;
 *   - the one-off migration turns a PENDING proof no journal owns into
 *     EXPORTED and leaves a journal-reserved PENDING proof PENDING;
 *   - the transfers ledger: open → pending with token + secrets, matched as a
 *     set (not a subset), completed exactly once, terminal rows immutable;
 *   - fee-aware input selection picks the smallest covering set, never the
 *     whole balance.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require __DIR__ . '/mock_mint_stub.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/invoice.php';
require_once dirname(__DIR__, 2) . '/app/includes/store_safety.php';
require_once dirname(__DIR__, 2) . '/app/includes/transfer.php';

use Cashu\Proof;
use Cashu\ProofState;
use Cashu\Wallet;
use Cashu\WalletStorage;

$mint = start_mock_mint(['default_state' => 'UNSPENT']);
make_store('s1', $mint['url']);
Database::update('stores', ['seed_phrase' => 'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about'], 'id = ?', ['s1']); // the library validates BIP39 checksums
$storage = new WalletStorage(Database::getDbPath(), $mint['url'], 'sat');
$ks = '00ad268c4d1f5826';
$C = '02' . str_repeat('11', 32);
$storage->storeProofs([
    new Proof($ks, 4, 'secret-1', $C),
    new Proof($ks, 8, 'secret-2', $C),
    new Proof($ks, 16, 'secret-3', $C),
    new Proof($ks, 32, 'secret-4', $C),
]);
assert_eq(60, Invoice::getBalance('s1'), 'baseline balance');

function state_of(WalletStorage $s, string $secret): string {
    return (string)($s->getProofsStatesBySecrets([$secret])[$secret] ?? 'MISSING');
}

// --- reserve as EXPORTED ----------------------------------------------------
Invoice::markProofsExported('s1', ['secret-2'], $mint['url'], 'sat');
assert_eq('EXPORTED', state_of($storage, 'secret-2'), 'exported proof is EXPORTED, not PENDING');
assert_eq(52, Invoice::getBalance('s1'), 'balance excludes the exported proof');
$unspentSecrets = array_map(fn($p) => $p->secret, Invoice::getUnspentProofs('s1'));
assert_false(in_array('secret-2', $unspentSecrets, true), 'getUnspentProofs excludes it');
assert_eq(8, Invoice::getExportedBalance('s1'), 'exported balance reported separately');

// Refuse to export a set containing a non-UNSPENT proof; nothing changes.
try {
    Invoice::markProofsExported('s1', ['secret-1', 'secret-2'], $mint['url'], 'sat');
    fail('export over an already-exported proof must be refused');
} catch (Exception $e) {
    assert_true(stripos($e->getMessage(), 'another payment') !== false, 'refusal message: ' . $e->getMessage());
}
assert_eq('UNSPENT', state_of($storage, 'secret-1'), 'untouched proof stays UNSPENT after refusal');

// --- checkPendingProofs never writes UNSPENT -------------------------------
$storage->updateProofsState(['secret-3'], ProofState::PENDING); // an orphan PENDING (legacy export)
$res = Invoice::checkPendingProofs('s1');
assert_eq(1, $res['checked'], 'one PENDING proof checked');
assert_eq(0, $res['spent'], 'mint says UNSPENT → nothing spent');
assert_eq('PENDING', state_of($storage, 'secret-3'), 'UNSPENT at the mint does NOT return the proof to the balance');
assert_eq('EXPORTED', state_of($storage, 'secret-2'), 'EXPORTED is never touched by the pending check');
assert_eq(52 - 16, Invoice::getBalance('s1'), 'balance unchanged by the check');

mock_mint_update_state($mint['dir'], ['checkstate_mode' => 'short']);
$res = Invoice::checkPendingProofs('s1');
assert_true(!empty($res['error']), 'a short reply is an error');
assert_eq('PENDING', state_of($storage, 'secret-3'), 'short reply changes nothing');

$Y3 = \Cashu\Crypto::computeY('secret-3');
mock_mint_update_state($mint['dir'], ['checkstate_mode' => 'lowercase', 'states' => [$Y3 => 'SPENT']]);
$res = Invoice::checkPendingProofs('s1');
assert_eq(1, $res['spent'], 'lowercase "spent" is recognised');
assert_eq('SPENT', state_of($storage, 'secret-3'), 'SPENT transition applied');
mock_mint_update_state($mint['dir'], ['checkstate_mode' => 'ok', 'states' => []]);

// Unreachable mint: reported, not thrown.
make_store('s_dead', 'http://127.0.0.1:1');
Database::update('stores', ['seed_phrase' => 'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about'], 'id = ?', ['s_dead']);
$deadStorage = new WalletStorage(Database::getDbPath(), 'http://127.0.0.1:1', 'sat');
$deadStorage->storeProofs([new Proof($ks, 2, 'dead-1', $C)]);
$deadStorage->updateProofsState(['dead-1'], ProofState::PENDING);
$res = Invoice::checkPendingProofs('s_dead');
assert_true(!empty($res['error']), 'unreachable mint reported as error');
assert_eq('PENDING', state_of($deadStorage, 'dead-1'), 'nothing changes when the mint is unreachable');

// --- migration: orphan PENDING → EXPORTED, journal-owned PENDING stays -------
$storage->storeProofs([
    new Proof($ks, 1, 'orphan-pending', $C),
    new Proof($ks, 2, 'journal-pending', $C),
]);
$storage->updateProofsState(['orphan-pending', 'journal-pending'], ProofState::PENDING);
$storage->savePendingOperation('melt:q-mig', 'melt', [
    'counter_start' => 0, 'keyset_id' => $ks, 'amounts' => [], 'input_secrets' => ['journal-pending'],
]);
$ref = new ReflectionMethod('Database', 'migrateExportedProofs');
$ref->setAccessible(true);
$ref->invoke(null, Database::getInstance());
assert_eq('EXPORTED', state_of($storage, 'orphan-pending'), 'orphan PENDING becomes EXPORTED');
assert_eq('PENDING', state_of($storage, 'journal-pending'), 'journal-owned PENDING stays PENDING');
$ref->invoke(null, Database::getInstance());
assert_eq('PENDING', state_of($storage, 'journal-pending'), 'migration is idempotent');
$storage->deletePendingOperation('melt:q-mig');

// --- transfers ledger ---------------------------------------------------------
$id = Transfer::open('s1', Transfer::TYPE_TOKEN_EXPORT, 8, 'sat', ['secret-2'], 'cashuBtoken', $mint['url'], 'export');
$row = Transfer::getById($id);
assert_eq('pending', $row['status'], 'opened as pending');
assert_eq('cashuBtoken', $row['token'], 'token stored');
assert_eq(['secret-2'], Transfer::secretsOf($row), 'secrets stored');
assert_eq(1, count(Transfer::getPending(10, 's1')), 'listed as pending');
assert_not_null(Transfer::findPendingBySecrets('s1', ['secret-2']), 'found by exact secret set');
assert_null(Transfer::findPendingBySecrets('s1', ['secret-2', 'secret-1']), 'a superset is a different token');
$id2 = Transfer::open('s1', Transfer::TYPE_TOKEN_EXPORT, 36, 'sat', ['secret-1', 'secret-4'], 'cashuBtwo', $mint['url']);
assert_null(Transfer::findPendingBySecrets('s1', ['secret-1']), 'a strict subset is a different token');
assert_not_null(Transfer::findPendingBySecrets('s1', ['secret-4', 'secret-1']), 'order does not matter');
$api = Transfer::formatForApi(Transfer::getById($id2));
assert_true(!isset($api['token']) && !isset($api['reference']), 'API shape carries neither the token nor the secrets');
assert_eq(true, $api['hasToken'], 'API shape flags that a token is stored');

assert_true(Transfer::complete($id, 'cashed in'), 'complete a pending row');
assert_false(Transfer::complete($id), 'completing twice is a no-op');
assert_false(Transfer::markReclaimed($id), 'a completed row cannot be reclaimed');
assert_eq('completed', Transfer::getById($id)['status'], 'terminal row immutable');
assert_null(Transfer::findPendingBySecrets('s1', ['secret-2']), 'completed rows are not pending');

// --- fee-aware selection ------------------------------------------------------
$wallet = Invoice::getWalletInstance('s1'); // loads the mock keyset (fee 0)
$proofs = [];
foreach ([1, 2, 4, 8, 16, 32] as $i => $amt) {
    $proofs[] = new Proof($ks, $amt, "sel-$i", $C);
}
$sel = StoreSafety::selectProofsWithFees($wallet, $proofs, 5);
assert_true(Wallet::sumProofs($sel) >= 5, 'selection covers the amount');
assert_eq(1, count($sel), 'fewest inputs (greedy largest-first), never the whole set: ' . Wallet::sumProofs($sel));
assert_true(Wallet::sumProofs($sel) < 63, 'not the whole balance');
// Fee-charging keyset (library selectProofsWithFees through the StoreSafety
// wrapper): the selection covers amount + its own fee.
mock_mint_update_state($mint['dir'], ['fee_ppk' => 1000]); // 1 sat per input
make_store('s_fee', $mint['url'] . '/');
Database::update('stores', ['seed_phrase' => 'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about'], 'id = ?', ['s_fee']);
$walletFee = Invoice::getWalletForStore('s_fee', $mint['url']);
$fn = new ReflectionMethod(StoreSafety::class, 'selectProofsWithFees');
$selFee = StoreSafety::selectProofsWithFees($walletFee, $proofs, 5);
assert_true(Wallet::sumProofs($selFee) - $walletFee->calculateFee($selFee) >= 5, 'selection covers amount plus its own fee');
assert_true(Wallet::sumProofs($selFee) < 63, 'and is not the whole balance');

fwrite(STDERR, "test_export_lifecycle: all assertions passed\n");
