<?php
/**
 * Free-trial env-seeding on first migration.
 *
 * The deployment-time env vars CASHUPAY_FREE_TRIAL_UNTIL and
 * CASHUPAY_FREE_TRIAL_REVENUE_SATS are read once on first schema run
 * (includes/database.php) and stamped into the config table. The
 * free_trial_seeded marker ensures subsequent migrations don't re-evaluate
 * the env (so admins can still un-set the trial by deleting config rows;
 * they can't re-arm a different trial through env changes alone).
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';

// Set both env vars BEFORE fresh_db() runs the migration, so the seeding
// branch sees them. Picked a future date and a non-zero cap.
$futureTs = time() + 7 * 86400;
putenv("CASHUPAY_FREE_TRIAL_UNTIL={$futureTs}");
putenv("CASHUPAY_FREE_TRIAL_REVENUE_SATS=500000");

fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/free_trial.php';

$status = FreeTrial::status();
assert_true($status['configured'], 'env-seeded → configured');
assert_true($status['active'], 'env-seeded with both thresholds unmet → active');
assert_eq($futureTs, $status['until_ts'], 'until_ts seeded from env');
assert_eq(500000, $status['revenue_cap_sats'], 'cap seeded from env');
assert_eq(true, (bool) Config::get('free_trial_seeded'), 'seeded marker set');

echo "test_free_trial_seed[both-thresholds]: ok\n";

// Clean up env so it doesn't leak into other tests if this process is reused.
putenv('CASHUPAY_FREE_TRIAL_UNTIL');
putenv('CASHUPAY_FREE_TRIAL_REVENUE_SATS');

echo "test_free_trial_seed: ok\n";
