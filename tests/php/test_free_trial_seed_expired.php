<?php
/**
 * Free-trial env-seeding edge case: setting CASHUPAY_FREE_TRIAL_UNTIL to a
 * date in the past should be treated as "no trial" rather than as an instant
 * expiry. Same for a zero/negative revenue cap. This matches the operator's
 * expectation that a misconfigured deployment doesn't accidentally end up
 * mid-trial-expired with a weirdly-advanced fee_tracking_start_at.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';

// Past date + zero cap → no trial should be seeded at all.
putenv("CASHUPAY_FREE_TRIAL_UNTIL=" . (time() - 86400));
putenv("CASHUPAY_FREE_TRIAL_REVENUE_SATS=0");

fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/free_trial.php';

$status = FreeTrial::status();
assert_eq(false, $status['configured'], 'past-date + zero cap → not configured');
assert_eq(false, $status['active'], 'no trial → not active');
assert_null($status['until_ts'], 'until_ts not seeded for past date');
assert_null($status['revenue_cap_sats'], 'cap not seeded for zero value');
assert_eq(true, (bool) Config::get('free_trial_seeded'),
    'seeded marker still set so we do not re-evaluate env');

putenv('CASHUPAY_FREE_TRIAL_UNTIL');
putenv('CASHUPAY_FREE_TRIAL_REVENUE_SATS');

echo "test_free_trial_seed_expired: ok\n";
