<?php
/**
 * pollInvoice() must reconcile persisted onchain_payments against the provider's
 * CURRENT view: a (txid,vout) that previously confirmed but is no longer
 * reported has been reorged out / evicted and must stop counting toward the
 * invoice total — otherwise a stale row keeps the invoice "paid" on phantom
 * funds forever. The rollback is capped at REORG_SAFETY_DEPTH (5): a payment
 * already buried >= 5 blocks is treated as final and is NOT dropped if the
 * provider transiently omits it.
 *
 * Driven through pollInvoice() with a scripted provider (OnchainProviderFactory
 * test seam) so no network is needed.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require_once dirname(__DIR__, 2) . '/app/includes/onchain/payments.php';
require_once dirname(__DIR__, 2) . '/app/includes/onchain/provider.php';

fresh_db();
make_store('s1');

// xpub mode (default), min_confs = 1. Amount is large so confirmations alone
// never settle the invoice — we want to observe reconciliation in isolation.
Database::query("UPDATE stores SET onchain_min_confs = 1, onchain_address_mode = 'xpub' WHERE id = 's1'");
Database::insert('invoices', [
    'id' => 'inv1', 'store_id' => 's1', 'status' => 'New',
    'amount' => '100000', 'currency' => 'sat', 'amount_sats' => 100000,
    'payment_rail' => 'onchain',
    'onchain_address' => 'addr1', 'onchain_amount_sat' => 100000,
    'created_at' => time(), 'expiration_time' => time() + 3600,
]);

// Scripted provider: returns whatever observations we set on it.
$fake = new class implements BlockchainProvider {
    public array $obs = [];
    public int $tip = 800000;
    public function addressTransactions(string $address, ?int $sinceHeight = null): array { return $this->obs; }
    public function currentTipHeight(): int { return $this->tip; }
};
OnchainProviderFactory::$testProvider = $fake;

// --- Poll 1: two UTXOs seen. A is deep (6 confs), B is shallow (2 confs). ----
$fake->obs = [
    new OnchainTxObservation('aaaa', 0, 6000, 6, 799994),  // deep
    new OnchainTxObservation('bbbb', 0, 4000, 2, 799998),  // shallow
];
$r = OnchainPayments::pollInvoice('inv1');
assert_eq(2, $r['observation_count'], 'poll1: two rows persisted');
assert_eq(10000, $r['total_confirmed'], 'poll1: both count (>= min_confs)');

// --- Poll 2: B vanishes (reorged out / double-spent), A still present. -------
// B is shallow (<5 confs) so it must be dropped; A must remain.
$fake->obs = [
    new OnchainTxObservation('aaaa', 0, 6000, 8, 799994),  // now 8 confs
];
$r = OnchainPayments::pollInvoice('inv1');
assert_eq(1, $r['observation_count'], 'poll2: shallow vanished row dropped');
assert_eq(6000, $r['total_confirmed'], 'poll2: B no longer counts');
$bGone = Database::fetchOne("SELECT * FROM onchain_payments WHERE txid='bbbb'");
assert_null($bGone, 'poll2: B row deleted');
$aRow = Database::fetchOne("SELECT * FROM onchain_payments WHERE txid='aaaa'");
assert_not_null($aRow, 'poll2: A row kept');
assert_eq(8, (int)$aRow['confirmations'], 'poll2: A confirmations refreshed');

// --- Poll 3: provider omits A too (transient hiccup). A is deep (>=5) so it ---
// must be KEPT — we do not chase reorgs deeper than REORG_SAFETY_DEPTH.
$fake->obs = [];
$r = OnchainPayments::pollInvoice('inv1');
assert_eq(1, $r['observation_count'], 'poll3: deep row retained despite omission');
assert_eq(6000, $r['total_confirmed'], 'poll3: A still counts');
$aRow = Database::fetchOne("SELECT * FROM onchain_payments WHERE txid='aaaa'");
assert_not_null($aRow, 'poll3: deep A row survives a provider hiccup');

// --- Control: a SHALLOW row that vanishes when it is the only one is dropped --
Database::insert('invoices', [
    'id' => 'inv2', 'store_id' => 's1', 'status' => 'New',
    'amount' => '100000', 'currency' => 'sat', 'amount_sats' => 100000,
    'payment_rail' => 'onchain',
    'onchain_address' => 'addr2', 'onchain_amount_sat' => 100000,
    'created_at' => time(), 'expiration_time' => time() + 3600,
]);
$fake->obs = [new OnchainTxObservation('cccc', 0, 3000, 1, 799999)]; // shallow
$r = OnchainPayments::pollInvoice('inv2');
assert_eq(1, $r['observation_count'], 'inv2 poll1: one shallow row');
$fake->obs = [];
$r = OnchainPayments::pollInvoice('inv2');
assert_eq(0, $r['observation_count'], 'inv2 poll2: shallow-only row dropped on disappearance');

OnchainProviderFactory::$testProvider = null;
echo "PASS test_onchain_reorg_reconcile\n";
exit(0);
