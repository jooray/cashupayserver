<?php
/**
 * FreeTrial — gate semantics for the deployment-wide free-trial feature.
 *
 * Covers:
 *   1. No trial configured → isActive() false, owed math unaffected.
 *   2. Active trial → dev/hosting owed amounts forced to zero.
 *   3. Date threshold expiry → fee_tracking_start_at advances to expiry
 *      moment; pre-expiry revenue stays excluded; post-expiry revenue
 *      accrues owed amounts.
 *   4. Revenue-cap threshold expiry → same post-expiry behavior.
 *   5. OR semantics → whichever threshold fires first ends the trial.
 *   6. expireIfNeeded is idempotent.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/dev_fee.php';
require_once dirname(__DIR__, 2) . '/app/includes/free_trial.php';

$store = 'store_trial';
make_store($store, 'https://m.example.com');

// Ensure the harness's migration-time fee_tracking_start_at doesn't grandfather
// the test invoices out of fee math. Tests below overwrite this as needed.
Config::set('fee_tracking_start_at', 0);

function paid_invoice_at(string $storeId, int $sats, int $createdAt): void {
    Database::insert('invoices', [
        'id' => 'inv_' . bin2hex(random_bytes(4)),
        'store_id' => $storeId,
        'status' => 'Settled',
        'amount' => (string) $sats,
        'currency' => 'sat',
        'amount_sats' => $sats,
        'created_at' => $createdAt,
        'expiration_time' => $createdAt + 3600,
    ]);
}

// ----- 1. No trial configured -----
assert_true(!FreeTrial::isActive(), 'no trial → not active');
paid_invoice_at($store, 100000, time() - 10);
$o = DevFee::computeOwed($store);
assert_eq(false, $o['trial_active'], 'trial_active false when no trial');
assert_eq(1000, $o['dev_owed'], 'no trial → normal dev accrual');

// ----- 2. Active trial — date in the future -----
$future = time() + 30 * 86400;
Config::set('free_trial_until_ts', $future);
Config::set('free_trial_started_at', time() - 60);
assert_true(FreeTrial::isActive(), 'date in future → active');
$o = DevFee::computeOwed($store);
assert_eq(true, $o['trial_active'], 'trial_active true when active');
assert_eq(0, $o['dev_owed'], 'active trial → dev zero');
assert_eq(0, $o['hosting_owed'], 'active trial → hosting zero');
// Revenue still tallied — it's the fee bypass, not a data filter.
assert_eq(100000, $o['revenue'], 'revenue still tallied during trial');

// ----- 3. Date threshold expiry -----
// Move the deadline into the past and call expireIfNeeded() — owed should
// resume for revenue AFTER the stamped fee_tracking_start_at, but the
// pre-expiry 100k sats should be excluded.
$now = time();
Config::set('free_trial_until_ts', $now - 5);
FreeTrial::expireIfNeeded();
assert_true(!FreeTrial::isActive(), 'past-date → no longer active');
$expiredAt = (int) Config::get('free_trial_expired_at');
assert_true($expiredAt > 0, 'expired_at stamped');
$cutoff = (int) Config::get('fee_tracking_start_at');
assert_true($cutoff >= $now, 'fee_tracking_start_at advanced to expiry moment');

$o = DevFee::computeOwed($store);
assert_eq(0, $o['revenue'], 'pre-trial-end revenue excluded by advanced cutoff');
assert_eq(0, $o['dev_owed'], 'no revenue post-trial → dev zero');

// Post-expiry invoice accrues fees normally.
paid_invoice_at($store, 200000, $cutoff + 60);
$o = DevFee::computeOwed($store);
assert_eq(200000, $o['revenue'], 'post-trial revenue counted');
assert_eq(false, $o['trial_active'], 'trial_active false after expiry');
assert_eq(2000, $o['dev_owed'], 'post-trial dev = 1% of post-trial revenue');

echo "test_free_trial[date-expiry]: ok\n";

// ----- 4. Revenue-cap threshold expiry — fresh sub-scenario -----
// Reset the trial state and the store to model "trial is open with a revenue
// cap only, no date" and watch the cap fire.
Config::delete('free_trial_until_ts');
Config::delete('free_trial_expired_at');
Config::delete('free_trial_expired_reason');
Config::set('free_trial_revenue_cap_sats', 250000);
Config::set('free_trial_started_at', time() - 60);
Config::set('fee_tracking_start_at', 0);

// Wipe prior invoices so the cap math is unambiguous.
Database::query("DELETE FROM invoices WHERE store_id = ?", [$store]);
Database::query("DELETE FROM melts WHERE store_id = ?", [$store]);

paid_invoice_at($store, 100000, time() - 30);
assert_true(FreeTrial::isActive(), 'under cap → active');
$o = DevFee::computeOwed($store);
assert_eq(0, $o['dev_owed'], 'under cap → dev zero');

// Push revenue over the 250k cap.
paid_invoice_at($store, 200000, time() - 10);
assert_true(!FreeTrial::isActive(), 'over cap → no longer active');

$o = DevFee::computeOwed($store);
$cutoffAfterCap = (int) Config::get('fee_tracking_start_at');
assert_true($cutoffAfterCap > 0, 'cap expiry also advanced fee_tracking_start_at');
assert_eq('revenue', (string) Config::get('free_trial_expired_reason'),
    'cap expiry reason recorded');
// Both pre-cap invoices were created BEFORE the cap-fire instant, so they
// shouldn't accrue fees — same going-forward-only guarantee.
assert_eq(0, $o['revenue'], 'pre-cap revenue excluded by advanced cutoff');

echo "test_free_trial[revenue-cap-expiry]: ok\n";

// ----- 5. OR semantics — earliest threshold ends the trial -----
Config::delete('free_trial_revenue_cap_sats');
Config::delete('free_trial_expired_at');
Config::delete('free_trial_expired_reason');
Database::query("DELETE FROM invoices WHERE store_id = ?", [$store]);

// Both set: very high cap, but date is in the past → date wins.
Config::set('free_trial_until_ts', time() - 1);
Config::set('free_trial_revenue_cap_sats', 999999999);
Config::set('free_trial_started_at', time() - 60);

FreeTrial::expireIfNeeded();
assert_true(!FreeTrial::isActive(), 'date past + cap unmet → date wins');
assert_eq('date', (string) Config::get('free_trial_expired_reason'),
    'date reason recorded under OR');

echo "test_free_trial[OR-date-wins]: ok\n";

// ----- 6. expireIfNeeded is idempotent -----
$firstStamp = (int) Config::get('free_trial_expired_at');
$firstCutoff = (int) Config::get('fee_tracking_start_at');
sleep(1); // ensure that if expireIfNeeded re-stamped, we'd notice
FreeTrial::expireIfNeeded();
assert_eq($firstStamp, (int) Config::get('free_trial_expired_at'),
    'expired_at not re-stamped on second call');
assert_eq($firstCutoff, (int) Config::get('fee_tracking_start_at'),
    'fee_tracking_start_at not re-advanced on second call');

echo "test_free_trial[idempotent]: ok\n";

echo "test_free_trial: ok\n";
