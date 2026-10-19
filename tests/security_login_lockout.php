<?php
/**
 * The login lockout must hold under concurrency (DS41-26, SPB-SEC-006, OPUS-006).
 *
 * The counter used to be read, the slow password hash checked, then the counter written
 * back: N parallel guesses all saw "fewer than five" and all got checked. Now an attempt
 * is reserved atomically *before* the password check. This test fires 20 processes at
 * one database at once and asserts only five get through.
 *
 * Run: php tests/security_login_lockout.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

// Child mode: reserve one attempt against the shared database and report the result.
if (($argv[1] ?? '') === '--child') {
    define('CASHUPAY_DATA_DIR', $argv[2]);
    require_once dirname(__DIR__) . '/includes/database.php';
    require_once dirname(__DIR__) . '/includes/security.php';
    // Line the children up so they really overlap.
    while (microtime(true) < (float)$argv[3]) {
        usleep(1000);
    }
    echo Security::reserveLoginAttempt('203.0.113.77') ? 'Y' : 'N';
    exit(0);
}

$dataDir = sys_get_temp_dir() . '/cashupay-lockout-' . bin2hex(random_bytes(8));
mkdir($dataDir, 0700, true);
define('CASHUPAY_DATA_DIR', $dataDir);

require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/security.php';
require_once dirname(__DIR__) . '/includes/auth.php';

function check(bool $cond, string $what): void {
    if (!$cond) { fwrite(STDERR, "FAIL: $what\n"); exit(1); }
    echo "  ok: $what\n";
}

Database::initialize();

echo "Sequential attempts\n";
$granted = 0;
for ($i = 0; $i < 8; $i++) {
    $granted += Security::reserveLoginAttempt('198.51.100.1') ? 1 : 0;
}
check($granted === 5, 'five attempts are allowed, the rest refused');
check(Security::isLockedOut('198.51.100.1'), 'the identifier is locked out afterwards');
check(Security::getLockoutRemaining('198.51.100.1') > 0, 'and the remaining time is reported');
check(!Security::isLockedOut('198.51.100.2'), 'another address is unaffected');
Security::clearLoginAttempts('198.51.100.1');
check(Security::reserveLoginAttempt('198.51.100.1'), 'a successful login clears the counter');

echo "The old two-step API still counts\n";
for ($i = 0; $i < 5; $i++) {
    Security::recordFailedLogin('198.51.100.3');
}
check(Security::isLockedOut('198.51.100.3'), 'five recorded failures lock out');

echo "Auth::attemptLogin\n";
Auth::setAdminPassword('correct horse');
check(Auth::attemptLogin('wrong', '198.51.100.4') === 'invalid', 'a wrong password is "invalid"');
for ($i = 0; $i < 4; $i++) {
    Auth::attemptLogin('wrong', '198.51.100.4');
}
check(Auth::attemptLogin('correct horse', '198.51.100.4') === 'locked',
    'after five failures even the right password waits out the lockout');

echo "Twenty simultaneous guesses\n";
$start = microtime(true) + 1.0;
$procs = [];
for ($i = 0; $i < 20; $i++) {
    $cmd = [PHP_BINARY, __FILE__, '--child', $dataDir, sprintf('%.6f', $start)];
    $procs[] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
}
$results = '';
foreach ($procs as $i => $proc) {
    $results .= stream_get_contents($pipes[$i][1]);
    $err = stream_get_contents($pipes[$i][2]);
    if (trim($err) !== '') {
        fwrite(STDERR, "child $i: $err\n");
    }
    proc_close($proc);
}
check(strlen($results) === 20, 'every child answered');
check(substr_count($results, 'Y') === 5, 'exactly five got a password check (got ' . substr_count($results, 'Y') . ')');
check(Security::isLockedOut('203.0.113.77'), 'and the address is locked out');

echo "All login lockout checks passed.\n";
