<?php
/**
 * MC-5 / MC-4: Invoice::checkPendingProofs()
 *   - skips inputs an unfinished melt/swap journal owns (the mint may report
 *     them UNSPENT after a timeout while the request is queued);
 *   - applies only the SPENT transition to the rest;
 *   - asks the mint that holds the proofs: PENDING rows under a BACKUP mint's
 *     namespace are checked against that backup, not the primary.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require __DIR__ . '/mock_mint_stub.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/invoice.php';

use Cashu\Proof;
use Cashu\ProofState;
use Cashu\WalletStorage;

$ks = '00ad268c4d1f5826';
$C = '02' . str_repeat('22', 32);
$Y = fn(string $s) => \Cashu\Crypto::computeY($s);

$primary = start_mock_mint(['default_state' => 'UNSPENT', 'states' => [$Y('free') => 'SPENT', $Y('reserved') => 'SPENT']]);
$backup = start_mock_mint(['default_state' => 'UNSPENT', 'states' => [$Y('on-backup') => 'SPENT']]);
make_store('s1', $primary['url']);
add_backup_mint('s1', $backup['url']);

$ps = new WalletStorage(Database::getDbPath(), $primary['url'], 'sat');
$ps->storeProofs([new Proof($ks, 4, 'free', $C), new Proof($ks, 8, 'reserved', $C), new Proof($ks, 2, 'stays', $C)]);
$ps->updateProofsState(['free', 'reserved', 'stays'], ProofState::PENDING);
$ps->savePendingOperation('melt:q1', 'melt', [
    'counter_start' => 0, 'keyset_id' => $ks, 'amounts' => [], 'input_secrets' => ['reserved'],
]);

$bs = new WalletStorage(Database::getDbPath(), $backup['url'], 'sat');
$bs->storeProofs([new Proof($ks, 16, 'on-backup', $C)]);
$bs->updateProofsState(['on-backup'], ProofState::PENDING);

$res = Invoice::checkPendingProofs('s1');
assert_true(empty($res['error']), 'no error: ' . json_encode($res));
assert_eq(3, $res['checked'], 'two non-reserved primary rows + one backup row checked');
assert_eq(2, $res['spent'], 'free (primary) and on-backup (backup) marked spent');
assert_eq(1, $res['pending'], 'stays remains pending');

$st = $ps->getProofsStatesBySecrets(['free', 'reserved', 'stays']);
assert_eq('SPENT', $st['free'], 'non-reserved SPENT at mint → SPENT');
assert_eq('PENDING', $st['reserved'], 'journal-reserved input is skipped even though the mint says SPENT');
assert_eq('PENDING', $st['stays'], 'UNSPENT at mint never becomes UNSPENT locally');
assert_eq('SPENT', $bs->getProofsStatesBySecrets(['on-backup'])['on-backup'], 'backup namespace reconciled');

// The reserved secret's Y was never sent to the primary; the backup proof's Y
// went to the backup mint, not the primary.
$primaryBodies = file_get_contents($primary['dir'] . '/requests.log');
$backupBodies = file_get_contents($backup['dir'] . '/requests.log');
assert_true(strpos($primaryBodies, $Y('reserved')) === false, 'reserved Y not asked of the mint');
assert_true(strpos($primaryBodies, $Y('free')) !== false, 'primary asked about its own proof');
assert_true(strpos($primaryBodies, $Y('on-backup')) === false, 'primary NOT asked about the backup proof');
assert_true(strpos($backupBodies, $Y('on-backup')) !== false, 'backup mint asked about the backup proof');

// The journal keeps its reservation until the library's recovery resolves it.
$ps->deletePendingOperation('melt:q1');
$res2 = Invoice::checkPendingProofs('s1');
assert_eq('SPENT', $ps->getProofsStatesBySecrets(['reserved'])['reserved'], 'once un-reserved, the SPENT answer is applied');

fwrite(STDERR, "test_check_pending_proofs_reserved: all assertions passed\n");
