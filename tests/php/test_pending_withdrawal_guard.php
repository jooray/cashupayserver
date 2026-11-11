<?php
/**
 * WM-1: LightningAddress::assertNoPendingWithdrawal() and the funds lock in
 * the automatic cashout.
 *
 *   - a melt journal whose inputs are PENDING (the mint answered PENDING)
 *     blocks a new withdrawal; the same journal with UNSPENT inputs (an
 *     UNPAID failure the library kept) does not;
 *   - checkAutoMelt() reports such a store as skipped => 'pending' and never
 *     contacts the LNURL host; a store whose funds lock is held by another
 *     request is skipped => 'busy'; a free store is attempted (and fails at
 *     the dead LNURL port, proving the gates were passed).
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/lightning_address.php';
require_once dirname(__DIR__, 2) . '/app/includes/store_ln_addresses.php';
require_once dirname(__DIR__, 2) . '/app/includes/swap/auto_melt.php';
require_once dirname(__DIR__, 2) . '/app/includes/locks.php';

use Cashu\Proof;
use Cashu\ProofState;
use Cashu\WalletStorage;

putenv('CASHU_LNURL_URL_TEMPLATE=http://127.0.0.1:59321/.well-known/lnurlp/{user}');

$ks = '00ad268c4d1f5826';
$C = '02' . str_repeat('33', 32);

function seed_auto_store(string $id, string $mint): void {
    Database::insert('stores', [
        'id' => $id, 'name' => "test $id", 'mint_url' => $mint, 'mint_unit' => 'sat',
        'seed_phrase' => 'about about about about about about about about about about about above',
        'created_at' => Database::timestamp(), 'auto_melt_enabled' => 1, 'auto_melt_threshold' => 2000,
        'auto_melt_use_swap' => SwapAutoMelt::INHERIT, 'onchain_address_mode' => 'xpub',
    ]);
    StoreLnAddresses::replaceForStore($id, [
        ['type' => 'lnaddress', 'address' => 'merchant@example.com', 'supports_verify' => null],
    ]);
}

seed_auto_store('s_pending', 'http://127.0.0.1:1/pending');
seed_auto_store('s_busy', 'http://127.0.0.1:1/busy');
seed_auto_store('s_free', 'http://127.0.0.1:1/free');

$sp = new WalletStorage(Database::getDbPath(), 'http://127.0.0.1:1/pending', 'sat');
$sp->storeProofs([new Proof($ks, 500, 's1', $C), new Proof($ks, 1000, 's2', $C)]);
$sp->updateProofsState(['s1'], ProofState::PENDING);
$sp->savePendingOperation('melt:q1', 'melt', ['counter_start' => 0, 'keyset_id' => $ks, 'amounts' => [], 'input_secrets' => ['s1']]);

try {
    LightningAddress::assertNoPendingWithdrawal('s_pending');
    fail('PENDING inputs must block a new withdrawal');
} catch (Exception $e) {
    assert_true(stripos($e->getMessage(), 'still being settled') !== false, 'message: ' . $e->getMessage());
}

// UNPAID failure journal (inputs UNSPENT) does not block.
$sp->updateProofsState(['s1'], ProofState::UNSPENT);
LightningAddress::assertNoPendingWithdrawal('s_pending'); // no throw
$sp->updateProofsState(['s1'], ProofState::PENDING);

$sb = new WalletStorage(Database::getDbPath(), 'http://127.0.0.1:1/busy', 'sat');
$sb->storeProofs([new Proof($ks, 1000, 'b1', $C)]);
$sf = new WalletStorage(Database::getDbPath(), 'http://127.0.0.1:1/free', 'sat');
$sf->storeProofs([new Proof($ks, 1000, 'f1', $C)]);

$held = cashupay_acquire_lock('funds_s_busy');
assert_true(is_resource($held), 'test holds the busy store lock');

$rows = LightningAddress::checkAutoMelt() ?? [];
$byStore = [];
foreach ($rows as $r) {
    $byStore[$r['store_id']] = $r;
}
assert_eq('pending', $byStore['s_pending']['skipped'] ?? null, 'store with an unsettled withdrawal is skipped: ' . json_encode($byStore['s_pending'] ?? null));
assert_eq('busy', $byStore['s_busy']['skipped'] ?? null, 'locked store is skipped as busy');
assert_true(isset($byStore['s_free']) && empty($byStore['s_free']['skipped']) && $byStore['s_free']['success'] === false,
    'free store is attempted and fails at the dead LNURL host: ' . json_encode($byStore['s_free'] ?? null));
assert_true(stripos((string)($byStore['s_free']['error'] ?? ''), 'still being settled') === false, 'free store was not blocked by the guard');
cashupay_release_lock($held);

// The lock is released after the tick: the busy store can be locked again.
$again = cashupay_acquire_lock('funds_s_free');
assert_true(is_resource($again), 'checkAutoMelt released the per-store lock');
cashupay_release_lock($again);

// The swap rail honours the same gates.
$r = (new ReflectionMethod(SwapAutoMelt::class, 'processStore'));
$r->setAccessible(true);
$storeRow = Database::fetchOne("SELECT * FROM stores WHERE id = 's_pending'");
$res = $r->invoke(null, $storeRow);
assert_eq('pending', $res['skipped'] ?? null, 'swap rail skips a store with an unsettled withdrawal');
$held2 = cashupay_acquire_lock('funds_s_free');
$res = $r->invoke(null, Database::fetchOne("SELECT * FROM stores WHERE id = 's_free'"));
assert_eq('busy', $res['skipped'] ?? null, 'swap rail skips a locked store');
cashupay_release_lock($held2);

fwrite(STDERR, "test_pending_withdrawal_guard: all assertions passed\n");
