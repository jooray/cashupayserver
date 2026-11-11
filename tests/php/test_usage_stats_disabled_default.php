<?php
/**
 * Test rigs must never post usage reports to the live BareBits endpoint, and
 * real installs that opted in must. Same guard as the fee-branch client: the
 * fixtures' `.updater_disabled` sentinel in the data dir disables the client
 * when no explicit CASHUPAY_USAGE_STATS_ENDPOINT is set; the
 * CASHUPAY_UPDATER_DISABLED env var alone must NOT (the Windows desktop
 * launcher sets that for real users). An explicit 'disabled' value switches
 * it off anywhere; the production default is the getbarebits.com URL.
 */
declare(strict_types=1);
putenv('CASHUPAY_UPDATER_DISABLED=1');
require __DIR__ . '/harness.php';
// harness.php sets the env to 'disabled' when unset; clear it so the
// production default URL is what the constant sees.
putenv('CASHUPAY_USAGE_STATS_ENDPOINT');
fresh_db();
putenv('CASHUPAY_USAGE_STATS_ENDPOINT');
require_once dirname(__DIR__, 2) . '/app/includes/usage_stats.php';

assert_eq('https://getbarebits.com/analytics/report.php', CASHUPAY_USAGE_STATS_ENDPOINT, 'production default URL');

// Desktop-style install: updater env set, no sentinel -> the client is LIVE.
assert_false(UsageStats::isDisabled(), 'CASHUPAY_UPDATER_DISABLED alone does not disable usage statistics');
assert_eq('https://getbarebits.com/analytics/report.php', UsageStats::status(false)['endpoint']);
// ...but with nothing chosen, nothing happens.
assert_eq('off', UsageStats::cronTick(), 'unanswered -> off, no network');
assert_false(UsageStats::sendNow(), 'sendNow is a no-op while off');
assert_null(Config::get(UsageStats::CONFIG_LAST_ATTEMPT_AT, null), 'no attempt recorded');

// Fixture-style install: sentinel present -> disabled, no network, no stamps,
// even when the operator opted in on this install.
file_put_contents(CASHUPAY_DATA_DIR . '/.updater_disabled', "test\n");
UsageStats::setChoice(true, 3);
assert_true(UsageStats::isDisabled(), 'fixture sentinel + no explicit endpoint -> disabled');
assert_eq('disabled', UsageStats::cronTick(), 'cron reports disabled');
assert_false(UsageStats::sendNow(), 'sendNow is a no-op');
assert_null(Config::get(UsageStats::CONFIG_LAST_ATTEMPT_AT, null), 'no attempt was recorded');
$st = UsageStats::status(false);
assert_true($st['disabled']);
assert_null($st['endpoint'], 'status hides the endpoint when disabled');
assert_true($st['enabled'], 'the opt-in itself is still recorded');
assert_not_null($st['install_id'], 'opting in minted an id even though nothing is sent');

unlink(CASHUPAY_DATA_DIR . '/.updater_disabled');
assert_false(UsageStats::isDisabled(), 'sentinel removed -> live again');

echo "test_usage_stats_disabled_default: ok\n";
