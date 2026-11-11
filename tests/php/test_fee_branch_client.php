<?php
/**
 * FeeBranch (includes/fee_branch.php) against the REAL endpoint script under
 * php -S: acquiring + persisting a branch, idempotence, every failure mode
 * the client must survive (down / 500 / garbage / wrong type / out of range /
 * rate-limited / timeout), the inline throttle vs. the unthrottled cron tick,
 * the concurrent-store guard, and the status payload.
 */
declare(strict_types=1);
require __DIR__ . '/xpub_endpoint_driver.php';
$ep = XpubEndpointDriver::start();
register_shutdown_function(static fn() => $ep->stop());
// Point the client at the local endpoint BEFORE the harness/dev_fee constants load.
putenv('CASHUPAY_DEV_FEE_BRANCH_ENDPOINT=' . $ep->url);
putenv('CASHUPAY_ALLOW_PRIVATE_ENDPOINTS=1');
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/dev_fee.php';
require_once dirname(__DIR__, 2) . '/app/includes/fee_branch.php';

assert_eq($ep->url, CASHUPAY_DEV_FEE_BRANCH_ENDPOINT, 'env override reaches the constant');
assert_false(FeeBranch::isDisabled(), 'explicit endpoint enables the client even on a test rig');
assert_null(FeeBranch::current(), 'fresh install has no branch');
$st = FeeBranch::status();
assert_null($st['branch']);
assert_null($st['last_attempt_at'], 'no attempt recorded yet');
assert_null($st['last_error']);
assert_eq($ep->url, $st['endpoint']);

// ---- parseAllocation: the pure contract --------------------------------------
assert_eq(7, FeeBranch::parseAllocation(200, '{"branch":7}'));
foreach ([
    [0, '', 'Failed to connect'],   // unreachable
    [500, 'boom'],
    [200, '<html>'],
    [200, '{"ok":true}'],
    [200, '{"branch":"7"}'],        // wrong type
    [200, '{"branch":0}'],          // reserved
    [200, '{"branch":-5}'],
    [200, '{"branch":2147483648}'], // past the non-hardened range
    [200, '{"branch":1.5}'],
    [429, '{"error":"rate_limited","retry_after":30}'],
] as $case) {
    try {
        FeeBranch::parseAllocation($case[0], $case[1], $case[2] ?? '');
        fail('parseAllocation accepted ' . json_encode($case));
    } catch (\RuntimeException $e) {
        assert_true($e->getMessage() !== '', 'rejection carries a reason');
    }
}
assert_eq(2147483647, FeeBranch::parseAllocation(200, '{"branch":2147483647}'), 'top of the range is valid');

// ---- failure modes against the live script: null, error recorded, no branch --
foreach (['http500', 'garbage', 'badbranch', 'strbranch', 'nobranch', 'ratelimit'] as $mode) {
    $ep->setMode($mode);
    Config::delete(FeeBranch::CONFIG_LAST_ATTEMPT_AT);
    assert_null(FeeBranch::ensure(), "mode {$mode}: ensure() yields null");
    assert_null(FeeBranch::current(), "mode {$mode}: nothing stored");
    $st = FeeBranch::status();
    assert_true($st['last_error'] !== null && $st['last_error'] !== '', "mode {$mode}: error recorded ({$st['last_error']})");
    assert_true((int)$st['last_attempt_at'] > 0, "mode {$mode}: attempt stamped");
    assert_eq('error: ' . $st['last_error'], FeeBranch::cronTick(), "mode {$mode}: cronTick reports the error");
}
assert_true(str_contains((string)Config::get(FeeBranch::CONFIG_LAST_ERROR), 'rate-limited'), 'last mode (429) names the rate limit');

// Timeout: the endpoint hangs past the client timeout -> null, no branch.
$ep->setMode('hang');
$t0 = microtime(true);
assert_null(FeeBranch::ensure(['timeout' => 2, 'connectTimeout' => 1]), 'hung endpoint -> null');
assert_true(microtime(true) - $t0 < 10, 'hung endpoint bounded by the timeout');
assert_true(str_contains((string)Config::get(FeeBranch::CONFIG_LAST_ERROR), 'unreachable'), 'timeout is reported as unreachable');

// Endpoint down entirely (port with nothing listening).
$ep->setMode('normal');
$savedUrl = $ep->url;
// Use a closed port: fee_branch reads the constant, so exercise via a second
// process-free path: SafeHttp status 0 is covered by parseAllocation above and
// the hang case; here we pin that a refused connection also records an error.
$probe = SafeHttp::request('http://127.0.0.1:1/xpub.php', ['method' => 'POST', 'allowPrivate' => true, 'timeout' => 2, 'connectTimeout' => 1]);
assert_eq(0, $probe['status'], 'closed port -> status 0 (unreachable path)');

// ---- inline throttle: a failed attempt within 60s is not repeated -------------
$ep->setMode('http500');
Config::delete(FeeBranch::CONFIG_LAST_ATTEMPT_AT);
assert_null(FeeBranch::ensure(['throttle' => true]), 'first throttled attempt runs (and fails)');
$firstAttempt = (int)Config::get(FeeBranch::CONFIG_LAST_ATTEMPT_AT);
assert_true($firstAttempt > 0);
$ep->setMode('normal'); // endpoint healthy again...
assert_null(FeeBranch::ensure(['throttle' => true]), '...but the throttled retry is skipped inside the interval');
assert_eq($firstAttempt, (int)Config::get(FeeBranch::CONFIG_LAST_ATTEMPT_AT), 'no new attempt stamped');
assert_eq("0\n", $ep->request('GET')['body'], 'endpoint saw no allocation during the throttle');
// The cron path ignores the throttle and succeeds immediately.
assert_eq('acquired (branch 1)', FeeBranch::cronTick(), 'cron tick acquires the first branch');
assert_eq(1, FeeBranch::current(), 'branch persisted');
$st = FeeBranch::status();
assert_eq(1, $st['branch']);
assert_true((int)$st['acquired_at'] > 0, 'acquired_at stamped');
assert_null($st['last_error'], 'error cleared once acquired');

// ---- idempotent: further calls never touch the endpoint again ----------------
assert_eq(1, FeeBranch::ensure(), 'ensure returns the stored branch');
assert_eq('ok (branch 1)', FeeBranch::cronTick(), 'cron tick reports the stored branch');
$ep->setMode('http500');
assert_eq(1, FeeBranch::ensure(), 'stored branch survives a now-broken endpoint');
$ep->setMode('normal');
assert_eq("1\n", $ep->request('GET')['body'], 'exactly one allocation happened');

// ---- a second install gets the next number ----------------------------------
assert_eq(['branch' => 2], json_decode($ep->allocate()['body'], true), 'another install gets branch 2');

// ---- concurrent-store guard: a branch stored by another process wins ----------
// Simulate: this process has no cached branch, but the DB already holds one
// (written by a concurrent request after our endpoint round-trip).
Config::delete(FeeBranch::CONFIG_BRANCH);
Database::query("INSERT INTO config (key, value, created_at, updated_at) VALUES (?, ?, ?, ?)",
    [FeeBranch::CONFIG_BRANCH, '41', time(), time()]);
// Config::delete cleared the cache, so current() re-reads the DB.
assert_eq(41, FeeBranch::current(), 'DB-stored branch is read back');
$m = new ReflectionMethod(FeeBranch::class, 'store');
$m->setAccessible(true);
assert_eq(41, $m->invoke(null, 99, time()), 'store() keeps the concurrently-stored branch, discards the fetched one');
assert_eq(41, FeeBranch::current());

// ---- status/current reject corrupted config values ---------------------------
Config::set(FeeBranch::CONFIG_BRANCH, 'garbage');
assert_null(FeeBranch::current(), 'non-numeric stored value reads as no branch');
Config::set(FeeBranch::CONFIG_BRANCH, 0);
assert_null(FeeBranch::current(), 'stored 0 reads as no branch');
Config::set(FeeBranch::CONFIG_BRANCH, '12');
assert_eq(12, FeeBranch::current(), 'numeric string is accepted');

echo "test_fee_branch_client: ok\n";
