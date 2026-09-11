<?php
/**
 * Regression: the on-chain cron batch poll must not be starved on invoices
 * that also carry a Lightning rail.
 *
 * Every rail poller used to share invoices.last_polled_at as its throttle.
 * The Lightning pollers (min interval 15-30s) run before the on-chain poller
 * (min interval 60s) in cron.php and re-stamp the column on every pass, so
 * on a dual-rail invoice (bolt11 + onchain_address) the on-chain poller's
 * "60s since last stamp" check never came due — cron NEVER chain-polled the
 * invoice, and only the payment page's 2s poll could detect an on-chain
 * payment. A customer who paid on-chain and closed the tab ended with an
 * Expired invoice on received funds.
 *
 * The on-chain poller now throttles on its own column,
 * invoices.onchain_last_polled_at:
 *
 *   1. A dual-rail invoice whose last_polled_at was JUST stamped (as the
 *      Lightning poller does every cron pass) is still selected by
 *      OnchainPayments::pollPending and settles from the scripted provider's
 *      observation; last_polled_at is left alone.
 *   2. The 60s throttle still works — on the poller's own column: a fresh
 *      onchain_last_polled_at stamp skips the invoice, an aged one polls it.
 *   3. A pure-onchain invoice keeps working unchanged.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require_once dirname(__DIR__, 2) . '/includes/onchain/payments.php';
require_once dirname(__DIR__, 2) . '/includes/onchain/provider.php';

fresh_db();
make_store('s1');
Database::query("UPDATE stores SET onchain_min_confs = 1, onchain_address_mode = 'xpub' WHERE id = 's1'");

$fake = new class implements BlockchainProvider {
    public array $obs = [];
    public int $tip = 800000;
    public function addressTransactions(string $address, ?int $sinceHeight = null): array { return $this->obs; }
    public function currentTipHeight(): int { return $this->tip; }
};
OnchainProviderFactory::$testProvider = $fake;

$now = time();

// ---------- 1. dual-rail invoice, LN poller just stamped last_polled_at ----
// The shape a Strike-LN + on-chain (or mint + xpub) invoice is in on every
// cron pass: its Lightning poller ran seconds ago and stamped the shared
// column. Under the old shared-throttle behaviour pollPending skipped it.
Database::insert('invoices', [
    'id' => 'inv_dual', 'store_id' => 's1', 'status' => 'New',
    'amount' => '5000', 'currency' => 'sat', 'amount_sats' => 5000,
    'payment_rail' => 'strike', 'bolt11' => 'lnbcmockdualrail',
    'onchain_address' => 'addr_dual', 'onchain_amount_sat' => 5000,
    'last_polled_at' => $now, // <- the Lightning poller's fresh stamp
    'created_at' => $now, 'expiration_time' => $now + 3600,
]);
$fake->obs = [new OnchainTxObservation('d1d1', 0, 5000, 1, 799999)];
$results = OnchainPayments::pollPending(60, 20);
assert_true(isset($results['inv_dual']), 'dual-rail invoice is polled despite a fresh LN stamp');
$row = Database::fetchOne("SELECT * FROM invoices WHERE id = 'inv_dual'");
assert_eq('Settled', $row['status'], 'cron poll settles the dual-rail on-chain payment');
assert_eq('onchain', $row['settled_rail'], 'settled via the on-chain rail');
assert_not_null($row['onchain_last_polled_at'], 'poller stamped its own column');
assert_eq($now, (int)$row['last_polled_at'], 'the Lightning throttle stamp is left alone');

// ---------- 2. the throttle still works, on the poller's own column --------
$fake->obs = [];
Database::insert('invoices', [
    'id' => 'inv_throttled', 'store_id' => 's1', 'status' => 'New',
    'amount' => '5000', 'currency' => 'sat', 'amount_sats' => 5000,
    'payment_rail' => 'onchain',
    'onchain_address' => 'addr_thr', 'onchain_amount_sat' => 5000,
    'onchain_last_polled_at' => time(), // polled seconds ago
    'created_at' => time(), 'expiration_time' => time() + 3600,
]);
$results = OnchainPayments::pollPending(60, 20);
assert_false(isset($results['inv_throttled']), 'a freshly chain-polled invoice is skipped');

Database::query("UPDATE invoices SET onchain_last_polled_at = ? WHERE id = 'inv_throttled'", [time() - 61]);
$results = OnchainPayments::pollPending(60, 20);
assert_true(isset($results['inv_throttled']), 'an aged stamp comes due again');

// ---------- 3. pure on-chain invoice unchanged ----------
Database::insert('invoices', [
    'id' => 'inv_pure', 'store_id' => 's1', 'status' => 'New',
    'amount' => '700', 'currency' => 'sat', 'amount_sats' => 700,
    'payment_rail' => 'onchain',
    'onchain_address' => 'addr_pure', 'onchain_amount_sat' => 700,
    'created_at' => time(), 'expiration_time' => time() + 3600,
]);
$fake->obs = [new OnchainTxObservation('p1p1', 0, 700, 1, 799999)];
$results = OnchainPayments::pollPending(60, 20);
assert_true(isset($results['inv_pure']), 'never-polled invoice is picked up (NULL sorts first)');
$row = Database::fetchOne("SELECT status FROM invoices WHERE id = 'inv_pure'");
assert_eq('Settled', $row['status'], 'pure on-chain invoice still settles via cron');

OnchainProviderFactory::$testProvider = null;
echo "test_onchain_dual_rail_cron_poll: ok\n";
