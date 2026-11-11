<?php
/**
 * Operator config file: user_config.php constants override env vars for
 * deployment-time settings (free-trial here). Mirrors the env-seeding test
 * but loads via constant.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';

// Define the constants BEFORE fresh_db() (which kicks off the migration).
// Picked clear values different from any inadvertent env carryover.
$futureTs = time() + 30 * 86400;
define('CASHUPAY_FREE_TRIAL_UNTIL', (string) $futureTs);
define('CASHUPAY_FREE_TRIAL_REVENUE_SATS', '750000');

// Belt-and-suspenders: ensure env vars are NOT set so a passing test
// genuinely exercises the constant code path.
putenv('CASHUPAY_FREE_TRIAL_UNTIL');
putenv('CASHUPAY_FREE_TRIAL_REVENUE_SATS');

fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/free_trial.php';

$status = FreeTrial::status();
assert_true($status['configured'], 'constants set → configured');
assert_true($status['active'], 'both thresholds unmet → active');
assert_eq($futureTs, $status['until_ts'], 'until_ts seeded from constant');
assert_eq(750000, $status['revenue_cap_sats'], 'cap seeded from constant');

echo "test_free_trial_user_config: ok\n";
