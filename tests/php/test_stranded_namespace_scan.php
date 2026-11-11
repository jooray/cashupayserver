<?php
/**
 * MC-1 (server phase): funds left behind by a mint change.
 *
 *   - after the store's primary moves from mintA to mintB (consented),
 *     scanStrandedNamespaces() reports mintA with the amount it holds;
 *   - exportNamespaceAsToken() reserves the proofs as EXPORTED, records the
 *     export in the transfers ledger, and a second call returns the SAME
 *     token and export id (nothing is handed out twice);
 *   - markNamespaceRecovered() is bound to that export id: it flips exactly
 *     those proofs to SPENT and completes the ledger row; the namespace then
 *     disappears from the scan;
 *   - a live namespace (mintA kept as a backup) is neither reported nor
 *     exportable through this path;
 *   - getAccountBalances() shows funds sitting at a backup mint.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/invoice.php';

use Cashu\Proof;
use Cashu\ProofState;
use Cashu\WalletStorage;

$mintA = 'https://mint-a.example';
$mintB = 'https://mint-b.example';
$ks = '00ad268c4d1f5826';
$C = '02' . str_repeat('66', 32);

make_store('s1', $mintA);
$sa = new WalletStorage(Database::getDbPath(), $mintA, 'sat');
$sa->storeProofs([new Proof($ks, 8, 'a-1', $C), new Proof($ks, 16, 'a-2', $C), new Proof($ks, 1, 'a-spent', $C)]);
$sa->updateProofsState(['a-spent'], ProofState::SPENT);

assert_eq([], Invoice::scanStrandedNamespaces(), 'nothing stranded while mintA is the primary');

Config::updateStore('s1', ['mint_url' => $mintB], true);
$stranded = Invoice::scanStrandedNamespaces();
assert_eq(1, count($stranded), 'one stranded namespace: ' . json_encode($stranded));
assert_eq($mintA, $stranded[0]['mint_url'], 'identified as mintA');
assert_eq('sat', $stranded[0]['unit'], 'unit identified');
assert_eq(24, $stranded[0]['amount'], 'amount = UNSPENT + EXPORTED (spent excluded)');
assert_eq(2, $stranded[0]['count'], 'two proofs');
assert_null($stranded[0]['export_id'], 'no recovery token yet');

// Export: reserved as EXPORTED, recorded, re-export returns the same token.
$first = Invoice::exportNamespaceAsToken($mintA, 'sat', 's1');
assert_eq(24, $first['amount'], 'token carries the whole namespace');
assert_true(str_starts_with($first['token'], 'cashuB'), 'v4 token serialised offline');
$states = $sa->getProofsStatesBySecrets(['a-1', 'a-2', 'a-spent']);
assert_eq('EXPORTED', $states['a-1'], 'proof reserved as EXPORTED');
assert_eq('EXPORTED', $states['a-2'], 'proof reserved as EXPORTED');
assert_eq('SPENT', $states['a-spent'], 'spent proof untouched');
$row = Transfer::getById($first['export_id']);
assert_eq('stranded_export', $row['type'], 'ledger row type');
assert_eq($mintA, $row['mint_url'], 'ledger row remembers the namespace');
assert_eq(['a-1', 'a-2'], Transfer::secretsOf($row), 'ledger row binds the secrets');

$second = Invoice::exportNamespaceAsToken($mintA, 'sat', 's1');
assert_eq($first['export_id'], $second['export_id'], 're-export returns the same export id');
assert_eq($first['token'], $second['token'], 're-export shows the same token');
$scan2 = Invoice::scanStrandedNamespaces();
assert_eq($first['export_id'], $scan2[0]['export_id'], 'scan reports the pending recovery token');
assert_eq(24, $scan2[0]['exported'], 'and that the whole amount is in it');

// A newly arrived proof supersedes the pending export with a new token.
$sa->storeProofs([new Proof($ks, 2, 'a-late', $C)]);
$third = Invoice::exportNamespaceAsToken($mintA, 'sat', 's1');
assert_neq($first['export_id'], $third['export_id'], 'a changed set gets a new export id');
assert_eq(26, $third['amount'], 'new token carries the late proof too');
assert_eq('failed', Transfer::getById($first['export_id'])['status'], 'the superseded export is closed');

// Mark recovered: bound to the export id.
try {
    Invoice::markNamespaceRecovered($first['export_id']);
    fail('a superseded export cannot be marked recovered');
} catch (Exception $e) {
    assert_true(stripos($e->getMessage(), 'already failed') !== false, 'message: ' . $e->getMessage());
}
$n = Invoice::markNamespaceRecovered($third['export_id']);
assert_eq(3, $n, 'exactly the three exported proofs marked');
$states = $sa->getProofsStatesBySecrets(['a-1', 'a-2', 'a-late']);
assert_eq(['a-1' => 'SPENT', 'a-2' => 'SPENT', 'a-late' => 'SPENT'], $states, 'all SPENT');
assert_eq('completed', Transfer::getById($third['export_id'])['status'], 'ledger row completed');
assert_eq([], Invoice::scanStrandedNamespaces(), 'namespace no longer reported');

// Live namespace guard: mintA kept as a backup is not stranded and not exportable here.
$sa->storeProofs([new Proof($ks, 4, 'a-again', $C)]);
add_backup_mint('s1', $mintA);
assert_eq([], Invoice::scanStrandedNamespaces(), 'a backup mint is a live account, not stranded');
try {
    Invoice::exportNamespaceAsToken($mintA, 'sat', 's1');
    fail('live namespace must not be exportable as stranded');
} catch (Exception $e) {
    assert_true(stripos($e->getMessage(), 'still uses') !== false, 'message: ' . $e->getMessage());
}
assert_eq('UNSPENT', $sa->getProofsStatesBySecrets(['a-again'])['a-again'], 'refusal changed nothing');

// Per-account breakdown shows the backup's funds; the headline balance stays primary-only.
$accounts = Invoice::getAccountBalances('s1');
assert_eq(2, count($accounts), 'primary + backup');
assert_eq(0, $accounts[0]['balance'], 'primary (mintB) is empty');
assert_eq(4, $accounts[1]['balance'], 'backup (mintA) holds 4');
assert_eq(0, Invoice::getBalance('s1'), 'spendable balance is the primary only');

fwrite(STDERR, "test_stranded_namespace_scan: all assertions passed\n");
