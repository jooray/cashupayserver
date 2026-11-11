<?php
/**
 * Test rigs must never contact the live BareBits endpoint, and real installs
 * must. The guard is the fixtures' `.updater_disabled` sentinel in the data
 * dir (tests/fixtures/payserver.py writes it): with the sentinel present and
 * no explicit CASHUPAY_DEV_FEE_BRANCH_ENDPOINT, FeeBranch is disabled — the
 * cron tick reports 'disabled', ensure() is a no-op, the dev fee never takes
 * an on-chain rail. The CASHUPAY_UPDATER_DISABLED env var alone must NOT
 * disable it: the Windows desktop launcher sets that for real users.
 */
declare(strict_types=1);
putenv('CASHUPAY_UPDATER_DISABLED=1');
require __DIR__ . '/harness.php';
// harness.php sets the env to 'disabled' when unset; clear it so the
// production default URL is what the constant sees.
putenv('CASHUPAY_DEV_FEE_BRANCH_ENDPOINT');
fresh_db();
putenv('CASHUPAY_DEV_FEE_BRANCH_ENDPOINT');
require_once dirname(__DIR__, 2) . '/app/includes/dev_fee.php';
require_once dirname(__DIR__, 2) . '/app/includes/fee_branch.php';

assert_eq('https://getbarebits.com/xpub/xpub.php', CASHUPAY_DEV_FEE_BRANCH_ENDPOINT, 'production default URL');

// Desktop-style install: updater env set, no sentinel -> the client is LIVE.
assert_false(FeeBranch::isDisabled(), 'CASHUPAY_UPDATER_DISABLED alone does not disable the branch client');
assert_eq('https://getbarebits.com/xpub/xpub.php', FeeBranch::status()['endpoint']);

// Fixture-style install: sentinel present -> disabled, no network, no stamps.
file_put_contents(CASHUPAY_DATA_DIR . '/.updater_disabled', "test\n");
assert_true(FeeBranch::isDisabled(), 'fixture sentinel + no explicit endpoint -> disabled');
assert_null(FeeBranch::ensure(), 'ensure() is a no-op');
assert_null(Config::get(FeeBranch::CONFIG_LAST_ATTEMPT_AT, null), 'no attempt was recorded');
assert_eq('disabled', FeeBranch::cronTick(), 'cron reports disabled');
$st = FeeBranch::status();
assert_true($st['disabled']);
assert_null($st['endpoint'], 'status hides the endpoint when disabled');

// Fee-redirect candidates carry branch=null -> shared-chain derivation.
require_once dirname(__DIR__, 2) . '/app/includes/fee_redirect.php';
$cands = FeeRedirect::candidates(['onchain_network' => 'mainnet'], ['dev_owed' => 5, 'hosting_owed' => 0]);
assert_null($cands[0]['branch'], 'dev candidate has no branch');
assert_null($cands[1]['branch'], 'hosting candidate never has a branch');

// An explicit 'disabled' value switches it off on any install.
unlink(CASHUPAY_DATA_DIR . '/.updater_disabled');
assert_false(FeeBranch::isDisabled(), 'sentinel removed -> live again');

echo "test_fee_branch_disabled_default: ok\n";
