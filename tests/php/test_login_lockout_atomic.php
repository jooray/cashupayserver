<?php
/**
 * Login lockout reservation is atomic: N parallel guesses cost N attempts.
 *
 * The old sequence (isLockedOut -> bcrypt -> recordFailedLogin) was a
 * read-modify-write with no lock, so a burst of concurrent requests all read
 * count < 5 and all got a password check. Security::reserveLoginAttempt()
 * does the check and the increment under one exclusive lock, BEFORE the
 * password check, so exactly MAX_LOGIN_ATTEMPTS (5) of any burst are granted.
 *
 * Sequential part: 8 reserves -> exactly 5 granted, isLockedOut true,
 * neighbouring address unaffected, clearLoginAttempts resets.
 * Concurrent part: 20 child PHP processes released on a shared start
 * timestamp against the same data dir -> exactly 5 'Y'.
 *
 * Child mode: `php test_login_lockout_atomic.php --child <dataDir> <startAt>`.
 */
declare(strict_types=1);

if (($argv[1] ?? '') === '--child') {
    define('CASHUPAY_DATA_DIR', (string)$argv[2]);
    require_once dirname(__DIR__, 2) . '/app/includes/security.php';
    $startAt = (float)$argv[3];
    while (microtime(true) < $startAt) {
        usleep(200);
    }
    echo Security::reserveLoginAttempt('203.0.113.77') ? 'Y' : 'N';
    exit(0);
}

require __DIR__ . '/harness.php';
$dataDir = fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/security.php';

// ---- sequential --------------------------------------------------------------
$ip = '198.51.100.23';
$granted = 0;
for ($i = 0; $i < 8; $i++) {
    if (Security::reserveLoginAttempt($ip)) {
        $granted++;
    }
}
assert_eq(5, $granted, 'exactly MAX_LOGIN_ATTEMPTS of 8 sequential reserves are granted');
assert_true(Security::isLockedOut($ip), 'address is locked out after the budget is spent');
assert_true(Security::getLockoutRemaining($ip) > 0, 'lockout remaining is reported');
assert_false(Security::isLockedOut('198.51.100.24'), 'neighbouring address is unaffected');
assert_true(Security::reserveLoginAttempt('198.51.100.24'), 'neighbouring address still gets attempts');

Security::clearLoginAttempts($ip);
assert_false(Security::isLockedOut($ip), 'clearLoginAttempts lifts the lockout');
assert_true(Security::reserveLoginAttempt($ip), 'and the budget is fresh again');

// The counter lives under the data directory (CASHUPAY_DATA_DIR), not the
// code tree: a read-only or mis-owned code tree used to disable lockouts.
assert_true(is_dir($dataDir . '/cache'), 'rate-limit cache lives under CASHUPAY_DATA_DIR');
$repoCache = dirname(__DIR__, 2) . '/app/data/cache';
$leaked = is_dir($repoCache) ? glob($repoCache . '/*.cache') : [];
foreach ($leaked as $f) {
    assert_true(filemtime($f) < $_SERVER['REQUEST_TIME'] - 5, 'no new cache file written under the repo data/cache');
}

// ---- fail closed -------------------------------------------------------------
// When the cache directory cannot be written, an attempt is refused rather
// than silently granted. (Skipped as root: chmod does not bind root.)
if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    $cacheDir = $dataDir . '/cache';
    chmod($cacheDir, 0500);
    try {
        // Lock file for an unseen key cannot be created -> refuse.
        assert_false(Security::reserveLoginAttempt('192.0.2.200'), 'unwritable cache dir refuses the attempt (fail closed)');
        assert_false(Security::checkRateLimit('t', '192.0.2.200', 3), 'checkRateLimit fails closed too');
    } finally {
        chmod($cacheDir, 0750);
    }
}

// ---- concurrent --------------------------------------------------------------
$children = 20;
$startAt = microtime(true) + 1.0;
$procs = [];
$pipes = [];
for ($i = 0; $i < $children; $i++) {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__)
        . ' --child ' . escapeshellarg($dataDir) . ' ' . escapeshellarg((string)$startAt);
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp);
    assert_true(is_resource($p), 'child spawned');
    $procs[] = $p;
    $pipes[] = $pp;
}
$out = '';
$errs = '';
foreach ($procs as $i => $p) {
    $out .= stream_get_contents($pipes[$i][1]);
    $errs .= stream_get_contents($pipes[$i][2]);
    fclose($pipes[$i][1]);
    fclose($pipes[$i][2]);
    proc_close($p);
}
assert_eq('', trim($errs), 'children ran without errors: ' . $errs);
assert_eq($children, strlen($out), 'every child answered');
$yes = substr_count($out, 'Y');
assert_eq(5, $yes, "exactly 5 of $children concurrent reserves granted (got '$out')");
assert_true(Security::isLockedOut('203.0.113.77'), 'burst address is locked out afterwards');

echo "ok\n";
