<?php
/**
 * allocateAddress() must read the chain tip OUTSIDE the write transaction (a
 * best-effort provider network call). Holding the SQLite write lock across that
 * round-trip would block every other writer for the whole HTTP timeout and 500
 * them on a slow provider. We prove the tip read is post-commit by making the
 * provider's currentTipHeight() throw: the allocation must still succeed and
 * persist (index incremented, no rolled-back work) with tip_height = null, and
 * no transaction may be left open on the shared PDO.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require_once dirname(__DIR__, 2) . '/app/includes/onchain/payments.php';
require_once dirname(__DIR__, 2) . '/app/includes/onchain/provider.php';

fresh_db();
make_store('s1');

$xpub = 'xpub69uEaVYoN1mZyMon8qwRP41YjYyevp3YxJ68ymBGV7qmXZ9rsbMy9kBZnLNPg3TLjKd2EnMw5BtUFQCGrTVDjQok859LowMV2SEooseLCt1';
Database::query(
    "UPDATE stores SET onchain_address_mode='xpub', onchain_xpub=?, onchain_network='mainnet', onchain_address_type='P2WPKH' WHERE id='s1'",
    [$xpub]
);

// Provider whose tip read ALWAYS throws (simulates a slow/dead provider).
$fake = new class implements BlockchainProvider {
    public function addressTransactions(string $address, ?int $sinceHeight = null): array { return []; }
    public function currentTipHeight(): int { throw new RuntimeException('provider down'); }
};
OnchainProviderFactory::$testProvider = $fake;

$pdo = Database::getInstance();

// First allocation: tip read throws but is best-effort + post-commit, so the
// call succeeds with a null tip and the index work is committed.
$a = OnchainPayments::allocateAddress('s1');
assert_not_null($a, 'allocation returned a result despite tip failure');
assert_true(!empty($a['address']), 'address derived');
assert_eq(0, $a['index'], 'first index is 0');
assert_null($a['tip_height'], 'tip_height null when provider tip read fails');
assert_false($pdo->inTransaction(), 'no transaction left open after allocate');

// Index increment was COMMITTED (proves work happened before the failing tip
// read, i.e. the read is outside the txn — otherwise the throw would have rolled
// it back).
$state = Database::fetchOne("SELECT next_index FROM onchain_xpub_state WHERE xpub_hash = ?", [hash('sha256', $xpub)]);
assert_eq(1, (int)$state['next_index'], 'counter advanced and persisted');

// Second allocation advances again (monotonic), still no open txn.
$b = OnchainPayments::allocateAddress('s1');
assert_eq(1, $b['index'], 'second index is 1');
assert_neq($a['address'], $b['address'], 'distinct addresses');
assert_false($pdo->inTransaction(), 'still no transaction left open');

// And when the provider tip read SUCCEEDS, it is surfaced.
OnchainProviderFactory::$testProvider = new class implements BlockchainProvider {
    public function addressTransactions(string $address, ?int $sinceHeight = null): array { return []; }
    public function currentTipHeight(): int { return 850000; }
};
$c = OnchainPayments::allocateAddress('s1');
assert_eq(850000, $c['tip_height'], 'tip_height surfaced when provider succeeds');
assert_false($pdo->inTransaction(), 'no open txn on the success path either');

OnchainProviderFactory::$testProvider = null;
echo "PASS test_onchain_allocate_tip_outside_lock\n";
exit(0);
