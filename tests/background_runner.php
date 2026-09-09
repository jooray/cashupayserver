<?php
/**
 * The background runner's lease, cursor and health bookkeeping.
 *
 * Run: php tests/background_runner.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

$dataDir = sys_get_temp_dir() . '/cashupay-runner-' . bin2hex(random_bytes(8));
mkdir($dataDir, 0700, true);
define('CASHUPAY_DATA_DIR', $dataDir);

require_once dirname(__DIR__) . '/cashu-wallet-php/CashuWallet.php';
require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/background_runner.php';

function check(bool $cond, string $what): void {
    if (!$cond) { fwrite(STDERR, "FAIL: $what\n"); exit(1); }
    echo "  ok: $what\n";
}

Database::initialize();

// With no stores configured every task is a cheap no-op, so this exercises the
// bookkeeping rather than the network.
$result = BackgroundRunner::run(20);
check(isset($result['tasks']) && !empty($result['tasks']), 'a run executes tasks');
check(($result['ran'] ?? 0) > 0, 'the run reports how many tasks it got through');

$status = BackgroundRunner::status();
check($status['lastRun'] !== null, 'the heartbeat is recorded');
check($status['secondsAgo'] !== null && $status['secondsAgo'] < 60, 'the heartbeat is recent');

// Config::get() decodes JSON, so reading these back must not decode a second time —
// that silently produced an empty health map on every run.
check(is_array($status['taskHealth']) && !empty($status['taskHealth']), 'per-task health survives a round trip');
check(is_array($status['lastResult']), 'the last result survives a round trip');

$firstTask = array_key_first($status['taskHealth']);
check(isset($status['taskHealth'][$firstTask]['last_ok']), 'a successful task records last_ok');

// Health from earlier runs must accumulate, not be wiped each cycle.
Config::clearCache();
$before = array_keys(BackgroundRunner::status()['taskHealth']);
BackgroundRunner::run(20);
Config::clearCache();
$after = array_keys(BackgroundRunner::status()['taskHealth']);
check(count(array_diff($before, $after)) === 0, 'health from earlier runs is not discarded');

// The lease stops a second run from overlapping the first.
Config::set('cron_lease_until', (string)(time() + 60));
Config::set('cron_lease_token', 'someone-else');
Config::clearCache();
$blocked = BackgroundRunner::run(20);
check(isset($blocked['skipped']), 'a second run is refused while another holds the lease');
check(empty($blocked['tasks']), 'the refused run does no work');

// An expired lease is not a permanent block.
Config::set('cron_lease_until', (string)(time() - 1));
Config::clearCache();
check(!isset(BackgroundRunner::run(20)['skipped']), 'an expired lease is taken over');

echo "background_runner: OK\n";

foreach (glob($dataDir . '/*') ?: [] as $file) { @unlink($file); }
@rmdir($dataDir);
