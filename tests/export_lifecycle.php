<?php
/**
 * Exported tokens must stay out of the spendable pool.
 *
 * The defect this covers: export marked its proofs PENDING, and the next export called
 * checkPendingProofs(), which asked the mint, got "UNSPENT" (the recipient simply had
 * not redeemed yet) and returned them to the balance — so the same bearer proofs could
 * be handed to two people.
 *
 * Run: php tests/export_lifecycle.php
 */

declare(strict_types=1);

// Tests create disposable databases and spawn helper processes; they must never be
// reachable over HTTP, even on a deployment that serves this directory.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

$dataDir = sys_get_temp_dir() . '/cashupay-export-' . bin2hex(random_bytes(8));
mkdir($dataDir, 0700, true);
define('CASHUPAY_DATA_DIR', $dataDir);

require_once dirname(__DIR__) . '/cashu-wallet-php/CashuWallet.php';
require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/includes/transfer.php';
require_once dirname(__DIR__) . '/includes/lightning_address.php';

use Cashu\Proof;
use Cashu\ProofState;
use Cashu\WalletStorage;

function check(bool $cond, string $what): void {
    if (!$cond) { fwrite(STDERR, "FAIL: $what\n"); exit(1); }
    echo "  ok: $what\n";
}

$pdo = Database::getInstance();
Database::initialize();

$storeId = 'store_export';
$accountId = 'wa_export';
$mintUrl = 'https://mint.example';
Database::query(
    "INSERT INTO stores (id, name, mint_url, mint_unit, seed_phrase, wallet_account_id, created_at)
     VALUES (?, 'Export test', ?, 'sat', 'seed words here', ?, ?)",
    [$storeId, $mintUrl, $accountId, time()]
);

$storage = new WalletStorage(Database::getDbPath(), $mintUrl, 'sat', $accountId);
$proofs = [];
foreach ([1 => 4, 2 => 8, 3 => 16, 4 => 32] as $i => $amount) {
    $proofs[] = new Proof('009a1f293253e41e', $amount, "secret-$i", '02' . str_repeat((string)$i, 64));
}
$storage->storeProofs($proofs);
check($storage->getBalance() === 60, 'store starts with a 60 sat balance');

// --- An exported proof leaves the spendable pool -----------------------------
Invoice::markProofsExported($storeId, ['secret-2']);
check($storage->getBalance() === 52, 'exported proof no longer counts towards the balance');

$spendable = array_map(fn($p) => $p->secret, Invoice::getUnspentProofs($storeId));
check(!in_array('secret-2', $spendable, true), 'exported proof is not selectable for a second export');

// --- Reconciliation must not resurrect it ------------------------------------
// checkPendingProofs() contacts the mint, which is unreachable here; the point is that
// no code path returns an EXPORTED proof to UNSPENT, so assert the state directly after
// the reconciliation attempt.
Invoice::checkPendingProofs($storeId);
$states = $storage->getProofsStatesBySecrets(['secret-2']);
check(
    ($states['secret-2'] ?? null) === ProofState::EXPORTED,
    'reconciliation leaves an exported proof EXPORTED, never UNSPENT'
);

// --- The export is a durable, recoverable operation --------------------------
$transferId = Transfer::open(
    $storeId,
    Transfer::TYPE_TOKEN_EXPORT,
    8,
    'sat',
    null,
    'secret-2',
    'Cashu token',
    'cashuBexampletoken'
);
check($transferId !== null, 'export is recorded before the token is disclosed');

$row = Transfer::getById($transferId);
check($row['status'] === Transfer::STATUS_PENDING, 'a disclosed export starts pending, not completed');
check($row['token'] === 'cashuBexampletoken', 'the token is stored so it can be re-displayed');
check($row['reference'] === 'secret-2', 'the export names the exact proofs it owns');

$pending = array_column(Transfer::getPending(), 'id');
check(in_array($transferId, $pending, true), 'pending exports are listed for reconciliation');

// Redemption by the recipient settles it.
$storage->updateProofsState(['secret-2'], ProofState::SPENT);
Transfer::complete($transferId, 8);
check(Transfer::getById($transferId)['status'] === Transfer::STATUS_COMPLETED, 'redeemed export completes');

// --- Offline donations must be exact, never "at least" -----------------------
// A 1 sat donation out of proofs [4, 16, 32] has no exact subset; sending the 4 sat
// proof would be a 400% overpayment the operator never authorized.
$remaining = Invoice::getUnspentProofs($storeId);
check(Donation::selectExact($remaining, 1) === null, 'no exact change means no donation, not an overpayment');

$exact = Donation::selectExact($remaining, 20);
check($exact !== null && \Cashu\Wallet::sumProofs($exact) === 20, 'an exact subset is selected when one exists');

$exact = Donation::selectExact($remaining, 4);
check($exact !== null && count($exact) === 1 && $exact[0]->amount === 4, 'a single matching proof is used for an exact donation');

echo "export_lifecycle: OK\n";

// Cleanup
foreach (glob($dataDir . '/*') ?: [] as $file) {
    @unlink($file);
}
@rmdir($dataDir);
