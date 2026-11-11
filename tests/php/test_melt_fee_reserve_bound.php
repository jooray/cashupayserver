<?php
/**
 * LightningAddress::assertSaneFeeReserve must reject a mint-supplied melt
 * quote whose fee reserve is implausibly large (a hostile/buggy mint trying to
 * drain extra balance into "fees"), while never tripping on a legitimate
 * routing reserve. The bound is intentionally generous: a reserve may be up to
 * the payment amount itself (or a small-payment floor), which is far beyond any
 * real Lightning routing fee.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/lightning_address.php';

$m = new ReflectionMethod('LightningAddress', 'assertSaneFeeReserve');
$m->setAccessible(true);

$sane = function (int $amount, int $reserve) use ($m): bool {
    try { $m->invoke(null, $amount, $reserve); return true; }
    catch (\Exception $e) { return false; }
};

// Legitimate reserves (small fraction of amount) are accepted.
assert_true($sane(100000, 1000),  '1% reserve on 100k accepted');
assert_true($sane(100000, 5000),  '5% reserve on 100k accepted');
assert_true($sane(1000000, 50000),'5% reserve on 1M accepted');

// Tiny payments: a base-fee reserve under the floor is accepted.
assert_true($sane(10, 500),  'sub-floor reserve on tiny payment accepted');
assert_true($sane(10, 1000), 'reserve at floor accepted');

// Egregious reserves (larger than the payment + floor) are rejected.
assert_false($sane(100000, 100001), 'reserve > amount rejected');
assert_false($sane(100000, 500000), 'reserve 5x amount rejected');
assert_false($sane(10, 1001),       'reserve above floor on tiny payment rejected');

echo "PASS test_melt_fee_reserve_bound\n";
exit(0);
