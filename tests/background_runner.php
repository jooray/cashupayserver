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

// --- Failure classification (SPB-OPS-003) ------------------------------------
check(BackgroundRunner::failureOf('success') === null, 'success is healthy');
check(BackgroundRunner::failureOf('checked 4 proofs, marked 0 spent, 0 errors') === null, '"0 errors" is healthy');
check(BackgroundRunner::failureOf('checked 4 proofs, marked 0 spent, 2 errors') !== null, '"2 errors" is a failure');
check(BackgroundRunner::failureOf('unreachable') !== null, '"unreachable" is a failure');
check(BackgroundRunner::failureOf(['accounts' => 1, 'errors' => []]) === null, 'an empty errors list is healthy');
check(BackgroundRunner::failureOf(['accounts' => 1, 'errors' => ['s|m|sat' => 'down']]) !== null, 'a non-empty errors list is a failure');
check(BackgroundRunner::failureOf(['checked' => 3, 'errors' => 1]) !== null, 'an errors count is a failure');
check(BackgroundRunner::failureOf([['store' => 'A', 'error' => 'no route']]) !== null, 'a per-store entry with an error is a failure');
check(BackgroundRunner::failureOf([['store' => 'A', 'amount' => 10]]) === null, 'a per-store entry without one is not');

// --- Backoff (SPB-OPS-007) ----------------------------------------------------
$failed = new ReflectionMethod(BackgroundRunner::class, 'failedHealth');
$h1 = $failed->invoke(null, ['last_ok' => 1], 'boom', true, false);
check($h1['failures'] === 1 && $h1['retry_after'] > time(), 'a task that throws waits before its next try');
$h5 = $h1;
for ($i = 0; $i < 20; $i++) { $h5 = $failed->invoke(null, $h5, 'boom', true, false); }
check($h5['retry_after'] - time() <= BackgroundRunner::BACKOFF_CAP, 'the wait is capped');
check($h5['last_ok'] === 1, 'the last success is remembered while failing');
$partial = $failed->invoke(null, [], 'one mint down', false, true);
check(!isset($partial['retry_after']), 'a partial failure is reported but not backed off');

Config::set('cron_task_health', ['clean_cache' => [
    'last_error' => time(), 'message' => 'disk full', 'failures' => 3,
    'failing_since' => time() - 600, 'retry_after' => time() + 600,
]]);
Config::set('cron_task_cursor', '0');
Config::set('cron_lease_until', '0');
$run = BackgroundRunner::run(300);
check(str_starts_with((string)($run['tasks']['clean_cache'] ?? ''), 'skipped'), 'a task backing off is skipped');
$failing = BackgroundRunner::status()['failing'];
check(count($failing) === 1 && $failing[0]['task'] === 'clean_cache', 'status() lists the failing task');
check($failing[0]['name'] !== 'clean_cache' && $failing[0]['impact'] !== '', 'with a plain-language name and impact');
check($failing[0]['retryAt'] !== null, 'and when it will be tried again');

// --- Budget starvation (SPB-OPS-001) -----------------------------------------
// The rotating budget starts after the payment path, and at least one rotating task
// runs every time, so a slow payment path cannot stall the rotation forever.
$always = (new ReflectionClassConstant(BackgroundRunner::class, 'ALWAYS_RUN'))->getValue();
$rotating = array_values(array_diff(array_keys(BackgroundRunner::tasks()), $always));
Config::set('cron_task_cursor', '3');
$run = BackgroundRunner::run(1);
check(array_key_exists($rotating[3], $run['tasks']), 'the rotation resumes at its cursor and runs at least one task');
Config::clearCache();
check((int)Config::get('cron_task_cursor', '0') !== 3 || count($rotating) === count($run['tasks']) - count($always),
    'the cursor moves past what ran');

// --- Lease renewal (SPB-OPS-002) ---------------------------------------------
check(BackgroundRunner::LEASE_SECONDS > BackgroundRunner::MAX_BUDGET, 'the lease outlasts the largest budget');
$acquire = new ReflectionMethod(BackgroundRunner::class, 'acquireLease');
$renew = new ReflectionMethod(BackgroundRunner::class, 'renewLease');
$release = new ReflectionMethod(BackgroundRunner::class, 'releaseLease');
Config::set('cron_lease_until', '0');
$token = $acquire->invoke(null);
check($token !== null && $renew->invoke(null, $token) === true, 'the owner renews its lease');
Config::set('cron_lease_token', 'taken-over');
check($renew->invoke(null, $token) === false, 'a run whose lease was taken over learns it and stops');
$release->invoke(null, $token);
check((int)Config::get('cron_lease_until', '0') > time(), 'and does not release the new owner\'s lease');
Config::set('cron_lease_until', '0');

echo "background_runner: OK\n";

foreach (glob($dataDir . '/*') ?: [] as $file) { @unlink($file); }
@rmdir($dataDir);
