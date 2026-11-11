<?php
/**
 * On-chain side of long-lived invoices (scripted BlockchainProvider):
 *
 *   1. Settlement threshold honours onchain_amount_floor_sat: a payment of
 *      an earlier, smaller quoted amount settles; without a floor the same
 *      payment is a partial (Processing).
 *   2. Cron poll backoff: an unpaid long-lived invoice is polled every
 *      minute for a day, then every 10 min, then hourly; a Processing one
 *      and ordinary invoices keep the base interval.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/onchain/payments.php';
require_once dirname(__DIR__, 2) . '/app/includes/invoice.php';

final class ScriptedProvider implements BlockchainProvider {
    /** @var array<string, OnchainTxObservation[]> */
    public array $byAddress = [];
    public array $polled = [];
    public function addressTransactions(string $address, ?int $sinceHeight = null): array {
        $this->polled[] = $address;
        return $this->byAddress[$address] ?? [];
    }
    public function currentTipHeight(): int { return 800100; }
}
$prov = new ScriptedProvider();
OnchainProviderFactory::$testProvider = $prov;

$now = time();
make_store('s1');
Database::update('stores', ['onchain_min_confs' => 1, 'onchain_confirm_timeout_sec' => 86400], 'id = ?', ['s1']);

function mk(string $id, array $cols): void {
    global $now;
    Database::insert('invoices', array_merge([
        'id' => $id, 'store_id' => 's1', 'status' => 'New', 'amount' => '10.00', 'currency' => 'USD',
        'amount_sats' => 20000, 'onchain_address' => 'bcrt1q' . $id, 'onchain_amount_sat' => 20000,
        'created_at' => $now, 'expiration_time' => $now + 90 * 86400, 'long_lived' => 1,
    ], $cols));
}
function st(string $id): array { return Database::fetchOne("SELECT status, onchain_last_polled_at FROM invoices WHERE id = ?", [$id]); }

// ---- 1. floor ----
mk('floor', ['onchain_amount_floor_sat' => 5000]);
mk('nofloor', []);
$prov->byAddress['bcrt1qfloor'] = [new OnchainTxObservation('aa', 0, 5000, 2, 800099)];
$prov->byAddress['bcrt1qnofloor'] = [new OnchainTxObservation('bb', 0, 5000, 2, 800099)];
OnchainPayments::pollInvoice('floor');
OnchainPayments::pollInvoice('nofloor');
assert_eq('Settled', st('floor')['status'], 'payment of the earlier (floor) amount settles');
assert_eq('Processing', st('nofloor')['status'], 'same payment without a floor is a partial');
mk('floor_full', ['onchain_amount_floor_sat' => 5000]);
$prov->byAddress['bcrt1qfloor_full'] = [new OnchainTxObservation('cc', 0, 20000, 2, 800099)];
OnchainPayments::pollInvoice('floor_full');
assert_eq('Settled', st('floor_full')['status'], 'payment of the current amount settles too');

// ---- 2. backoff ----
Database::query("DELETE FROM invoices"); // part 1 rows would be picked up as never-polled
Database::query("DELETE FROM onchain_payments");
$day = 86400;
mk('ord_recent',   ['long_lived' => 0, 'expiration_time' => $now + 3600, 'onchain_last_polled_at' => $now - 70]);
mk('ll_fresh',     ['created_at' => $now - 3600, 'onchain_last_polled_at' => $now - 70]);
mk('ll_2d_recent', ['created_at' => $now - 2 * $day, 'onchain_last_polled_at' => $now - 70]);
mk('ll_2d_due',    ['created_at' => $now - 2 * $day, 'onchain_last_polled_at' => $now - OnchainPayments::LONG_LIVED_POLL_SEC_WEEK - 5]);
mk('ll_10d_recent',['created_at' => $now - 10 * $day, 'onchain_last_polled_at' => $now - OnchainPayments::LONG_LIVED_POLL_SEC_WEEK - 5]);
mk('ll_10d_due',   ['created_at' => $now - 10 * $day, 'onchain_last_polled_at' => $now - OnchainPayments::LONG_LIVED_POLL_SEC_OLD - 5]);
mk('ll_10d_proc',  ['created_at' => $now - 10 * $day, 'status' => 'Processing', 'onchain_first_seen_at' => $now - 100, 'onchain_last_polled_at' => $now - 70]);
mk('ll_never',     ['created_at' => $now - 30 * $day, 'onchain_last_polled_at' => null]);
$prov->polled = [];
$results = OnchainPayments::pollPending(60, 50);
$polled = array_keys($results);
sort($polled);
assert_eq(
    ['ll_10d_due', 'll_10d_proc', 'll_2d_due', 'll_fresh', 'll_never', 'ord_recent'],
    $polled,
    'backoff picks: ordinary + fresh long-lived at 60 s, 1–7 d at 10 min, older hourly, Processing always, never-polled always'
);

echo "PASS test_long_lived_onchain\n";
