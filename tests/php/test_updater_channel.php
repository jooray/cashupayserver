<?php
/**
 * Updater channel selection. Verifies:
 *   - default channel is 'main' when nothing is configured
 *   - the user_config.php constant feeds the default
 *   - the admin override (DB Config) wins over the constant
 *   - normalization: only 'main' and 'testing' are valid; anything else
 *     collapses to 'main' so a typo can't silently disable updates
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/updater.php';

// 1. Default when nothing is set.
assert_eq('main', Updater::getChannel(), 'default channel');

// 2. Admin override via DB Config.
Updater::setChannel('testing');
assert_eq('testing', Updater::getChannel(), 'admin override applied');

// 3. Garbage normalizes to 'main'.
Updater::setChannel('garbage');
assert_eq('main', Updater::getChannel(), 'garbage normalizes');

// 4. Switching back to main works.
Updater::setChannel('main');
assert_eq('main', Updater::getChannel(), 'switch back to main');

// 5. The CASHUPAY_UPDATE_CHANNEL constant would feed the default if no DB
//    override exists. We can't easily define a constant mid-test, but we
//    can validate the fallback order by clearing the override and falling
//    through to the constant path (if defined) or 'main'. We test the
//    'main' fallback here; the constant path is exercised in the E2E test.
Config::delete('update_channel');
assert_eq('main', Updater::getChannel(), 'fallback when DB cleared');

echo "ok\n";
