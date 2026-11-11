<?php
/**
 * MC-3: restore before readiness (server side of the LM-5 gate).
 *
 *   - a store created with a PASTED seed (one the stub mint already signed
 *     outputs for) is bound for restore: requiresRecovery() is true, the
 *     dashboard status is "pending", Invoice::create throws the "being set up"
 *     message and sends NO mint-quote request;
 *   - the cron step (Invoice::advanceStoreRecovery / ensureWalletsReady) drives
 *     restoreStep() to completion in several bounded calls; afterwards the
 *     counters sit past every restored output, the seed's old ecash is in the
 *     balance, and Invoice::create succeeds (the mint signs fresh outputs);
 *   - a seed GENERATED for a store (SEED_NEW) is ready at once;
 *   - an imported seed is bound for restore on a backup mint the first time the
 *     store touches it; a generated seed is adopted ready there;
 *   - a failing restore step records exponential backoff; the next tick skips
 *     the account until the retry time; forcing retries at once;
 *   - mintAndStoreTokens never claims an invoice while the account is unready.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/invoice.php';
require __DIR__ . '/lib_mint_stub.php';

use Cashu\BigInt;
use Cashu\Mnemonic;
use Cashu\ProofState;
use Cashu\Secp256k1;
use Cashu\Wallet;
use Cashu\WalletStorage;

$stub = lib_mint_stub_start();
$backupStub = lib_mint_stub_start();
$mintUrl = $stub['url'];
$seed = Mnemonic::generate();

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

function store_seed(string $id, string $seed): void {
    Database::update('stores', ['seed_phrase' => $seed], 'id = ?', [$id]);
}

function expect_setup_message(callable $fn, string $label): void {
    try {
        $fn();
    } catch (Exception $e) {
        assert_true(stripos($e->getMessage(), 'being set up') !== false,
            "$label: refusal names the setup state: " . $e->getMessage());
        return;
    }
    fail("$label: expected the 'being set up' refusal");
}

// The seed was used before: counters 0..3 and 40 were signed at the mint (one of
// them spent since), exactly what a store re-created from its written-down phrase
// looks like.
$deriver = new Wallet($mintUrl, 'sat');
$deriver->loadMint();
$deriver->initFromMnemonic($seed);
$signed = [];
foreach ([0 => 8, 1 => 4, 2 => 2, 3 => 1, 40 => 16] as $counter => $amount) {
    $signed[$counter] = stub_sign_counter($stub, $deriver, $counter, $amount);
}
lib_mint_stub_mark_spent($stub, [$signed[2]]);

// ---- imported seed: bound for restore, unready, no quote issued ------------------------
make_store('s_imp', $mintUrl);
store_seed('s_imp', $seed);
$w = Invoice::bindStoreSeed('s_imp', $mintUrl, 'sat', Invoice::SEED_RESTORE);
assert_true($w->requiresRecovery(), 'imported seed requires recovery');
$st = Invoice::accountRecoveryStatus('s_imp', $mintUrl, 'sat');
assert_eq('pending', $st['status'], 'account status pending before any scan');
assert_false($st['ready'], 'not ready');
assert_true(Invoice::primaryRequiresRecovery('s_imp'), 'primary requires recovery');
$summary = Invoice::storeRecoveryStatus('s_imp');
assert_true($summary['pending'] && $summary['primaryPending'], 'store summary pending');
assert_eq('Preparing payments with your mint', $summary['message'], 'dashboard message');
$row = Database::fetchOne("SELECT * FROM wallet_recovery WHERE store_id = 's_imp'");
assert_not_null($row, 'bookkeeping row written');
assert_eq('pending', $row['status'], 'bookkeeping pending');

// getWalletForStore honours the recorded readiness (fresh cache).
Invoice::forgetWallets();
assert_true(Invoice::getWalletForStore('s_imp')->requiresRecovery(), 'getWalletForStore keeps the restore binding');

lib_mint_stub_reset_requests($stub);
expect_setup_message(fn() => Invoice::create('s_imp', ['amount' => 10, 'currency' => 'sat']), 'create on unready store');
assert_eq([], lib_mint_stub_requests_for($stub, 'POST /v1/mint/quote/bolt11'), 'no mint quote requested while unready');
assert_eq(0, (int)Database::fetchOne("SELECT COUNT(*) AS c FROM invoices WHERE store_id = 's_imp'")['c'], 'no invoice row left behind');

// mintAndStoreTokens never claims an invoice on an unready account.
$m = new ReflectionMethod(Invoice::class, 'mintAndStoreTokens');
$m->setAccessible(true);
Database::insert('invoices', [
    'id' => 'inv_unready', 'store_id' => 's_imp', 'amount' => 5, 'currency' => 'sat', 'amount_sats' => 5,
    'status' => 'New', 'quote_id' => 'mq_fake', 'mint_url' => $mintUrl,
    'expiration_time' => time() + 600, 'created_at' => time(),
]);
try {
    $m->invoke(null, Database::fetchOne("SELECT * FROM invoices WHERE id = 'inv_unready'"), Invoice::getWalletForStore('s_imp'));
    fail('mintAndStoreTokens must refuse an unready account');
} catch (Exception $e) {
    assert_true(stripos($e->getMessage(), 'recover') !== false, 'refusal names recovery: ' . $e->getMessage());
}
assert_eq('New', Database::fetchOne("SELECT status FROM invoices WHERE id = 'inv_unready'")['status'], 'invoice not claimed Processing');
Database::delete('invoices', "id = 'inv_unready'");

// ---- the cron step drives restoreStep() to completion in bounded calls ----------------
$steps = 0;
do {
    $r = Invoice::advanceStoreRecovery('s_imp', $mintUrl, 'sat', microtime(true) + 30, 50);
    $steps++;
    assert_true(in_array($r['status'], ['pending', 'complete'], true), 'step status ' . $r['status'] . ' ' . ($r['error'] ?? ''));
    if ($r['status'] === 'pending') {
        assert_true(Invoice::primaryRequiresRecovery('s_imp'), 'still unready mid-scan');
        expect_setup_message(fn() => Invoice::create('s_imp', ['amount' => 10, 'currency' => 'sat']), 'create mid-scan');
    }
} while ($r['status'] === 'pending' && $steps < 60);
assert_eq('complete', $r['status'], "restore completed in $steps steps");
assert_true($steps >= 5, "bounded steps: 50 outputs per call needs several calls ($steps)");
assert_false(Invoice::primaryRequiresRecovery('s_imp'), 'ready after the scan');
assert_eq('ready', Invoice::accountRecoveryStatus('s_imp', $mintUrl, 'sat')['status'], 'status ready');
assert_false(Invoice::storeRecoveryStatus('s_imp')['pending'], 'summary no longer pending');
assert_eq('ready', Database::fetchOne("SELECT status FROM wallet_recovery WHERE store_id = 's_imp'")['status'], 'bookkeeping ready');
$storage = StoreSafety::storage($mintUrl, 'sat');
assert_eq(41, $storage->getCounter(LIB_STUB_KEYSET), 'counter advanced past the highest restored output (40)');
assert_eq(8 + 4 + 1 + 16, Invoice::getBalance('s_imp'), 'the seed\'s old ecash is in the balance (spent one excluded)');

// Nothing left to do for the cron.
$rec = Invoice::ensureWalletsReady(5.0);
assert_eq(0, $rec['accounts'], 'no account requires recovery any more');

// create succeeds and the mint signs fresh outputs above the restored range.
lib_mint_stub_reset_requests($stub);
Invoice::forgetWallets();
$inv = Invoice::create('s_imp', ['amount' => 10, 'currency' => 'sat']);
assert_true(!empty($inv['quote_id']), 'invoice carries a mint quote after recovery');
assert_eq(1, count(lib_mint_stub_requests_for($stub, 'POST /v1/mint/quote/bolt11')), 'one quote request made');
lib_mint_stub_script($stub, ['mint_quotes' => [$inv['quote_id'] => ['state' => 'PAID', 'amount' => 10]]], false);
Invoice::pollSingleQuote($inv['id']);
assert_eq('Settled', Invoice::getById($inv['id'])['status'], 'paid quote settles (no "outputs already signed")');
assert_true($storage->getCounter(LIB_STUB_KEYSET) > 41, 'fresh outputs issued above counter 40');

// ---- generated seed: ready at once ----------------------------------------------------
make_store('s_new', $mintUrl);
store_seed('s_new', Mnemonic::generate());
$wNew = Invoice::bindStoreSeed('s_new', $mintUrl, 'sat', Invoice::SEED_NEW);
assert_false($wNew->requiresRecovery(), 'generated seed is ready at once');
assert_false(Invoice::primaryRequiresRecovery('s_new'), 'status ready');
Invoice::forgetWallets();
$inv2 = Invoice::create('s_new', ['amount' => 7, 'currency' => 'sat']);
assert_true(!empty($inv2['quote_id']), 'new store takes an invoice immediately');

// ---- backup mints: imported seed → restore-bound on first contact; generated → ready ---
add_backup_mint('s_imp', $backupStub['url']);
add_backup_mint('s_new', $backupStub['url']);
Invoice::forgetWallets();
assert_true(Invoice::getWalletForStore('s_imp', $backupStub['url'])->requiresRecovery(),
    'imported seed is unready on a backup mint it never scanned');
assert_false(Invoice::getWalletForStore('s_new', $backupStub['url'])->requiresRecovery(),
    'generated seed is adopted ready on the backup mint');
$rec = Invoice::ensureWalletsReady(20.0);
assert_eq(1, $rec['accounts'], 'exactly the imported seed\'s backup account needs a scan');
assert_true($rec['stepped'] >= 1, 'it was stepped');
// With GMP a 500-gap scan of an empty keyset finishes in one 20 s budget.
$st = Invoice::accountRecoveryStatus('s_imp', $backupStub['url'], 'sat');
assert_true(in_array($st['status'], ['ready', 'pending'], true), 'backup scan ran: ' . json_encode($st));

// ---- failure + exponential backoff -----------------------------------------------------
$failStub = lib_mint_stub_start();
lib_mint_stub_script($failStub, ['restore' => 'error']);
make_store('s_fail', $failStub['url']);
store_seed('s_fail', Mnemonic::generate());
Invoice::bindStoreSeed('s_fail', $failStub['url'], 'sat', Invoice::SEED_RESTORE);
$r = Invoice::advanceStoreRecovery('s_fail', $failStub['url'], 'sat', microtime(true) + 10);
assert_eq('error', $r['status'], 'restore outage reported as error');
$row = Database::fetchOne("SELECT * FROM wallet_recovery WHERE store_id = 's_fail'");
assert_eq(1, (int)$row['attempts'], 'one attempt recorded');
assert_true((int)$row['next_attempt_at'] >= time() + 25, 'first backoff ~30 s');
assert_eq('error', Invoice::accountRecoveryStatus('s_fail', $failStub['url'], 'sat')['status'], 'status error');
$r = Invoice::advanceStoreRecovery('s_fail', $failStub['url'], 'sat', microtime(true) + 10);
assert_eq('backoff', $r['skipped'], 'next tick skips the account during backoff');
$rec = Invoice::ensureWalletsReady(5.0);
assert_eq(1, $rec['backoff'], 'cron reports the backoff');
$r = Invoice::advanceStoreRecovery('s_fail', $failStub['url'], 'sat', microtime(true) + 10, null, true);
assert_eq('error', $r['status'], 'forced retry runs the step again');
$row = Database::fetchOne("SELECT * FROM wallet_recovery WHERE store_id = 's_fail'");
assert_eq(2, (int)$row['attempts'], 'second attempt recorded');
assert_true((int)$row['next_attempt_at'] >= time() + 55, 'backoff doubled (~60 s)');
assert_true(Invoice::primaryRequiresRecovery('s_fail'), 'still unready after failures');
// The mint recovers: a forced step completes and readies the account.
lib_mint_stub_script($failStub, []);
$n = 0;
do {
    $r = Invoice::advanceStoreRecovery('s_fail', $failStub['url'], 'sat', microtime(true) + 30, null, true);
    $n++;
} while ($r['status'] === 'pending' && $n < 20);
assert_eq('complete', $r['status'], 'completes once the mint answers: ' . ($r['error'] ?? ''));
assert_false(Invoice::primaryRequiresRecovery('s_fail'), 'ready');
assert_eq(0, (int)Database::fetchOne("SELECT attempts FROM wallet_recovery WHERE store_id = 's_fail'")['attempts'], 'attempts reset');

// ---- backward compatibility: a seed never bound on a namespace with data adopts ready --
make_store('s_legacy', $mintUrl);
store_seed('s_legacy', Mnemonic::generate());
Invoice::forgetWallets();
assert_false(Invoice::getWalletForStore('s_legacy')->requiresRecovery(), 'legacy/upgrade path: adopted ready without a scan');

echo "PASS test_wallet_recovery_gate\n";
exit(0);
