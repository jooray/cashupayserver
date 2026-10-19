<?php
/**
 * Installation ownership: the first browser to start setup claims it, others are locked
 * out, and the recovery code takes it back (evicting the previous holder).
 *
 * Run: php tests/setup_ownership.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

$dataDir = sys_get_temp_dir() . '/cashupay-ownership-' . bin2hex(random_bytes(8));
mkdir($dataDir, 0700, true);
define('CASHUPAY_DATA_DIR', $dataDir);

require_once dirname(__DIR__) . '/cashu-wallet-php/CashuWallet.php';
require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/security.php';
require_once dirname(__DIR__) . '/includes/setup_ownership.php';

function check(bool $cond, string $what): void {
    if (!$cond) { fwrite(STDERR, "FAIL: $what\n"); exit(1); }
    echo "  ok: $what\n";
}

Database::initialize();

/** Simulate a browser: its own session array and cookie jar. */
function browser(array $cookies = []): void {
    $_SESSION = [];
    $_COOKIE = $cookies;
}

// --- A plain page view claims nothing ------------------------------------------
browser();
check(!Setup::ownershipVerified(), 'opening the page does not grant ownership by itself');
check(!Setup::isClaimed(), 'a GET (link preview, crawler) does not claim the installation');
check(Setup::tokenPath() === null, 'and writes no recovery code');

// --- The merchant starts setup (the step-1 POST) -------------------------------
browser();
check(Setup::claim(), 'the first visitor to start setup claims it with no extra step');
$merchantSession = $_SESSION;
$claim = Config::get('setup_claim');
check(is_array($claim) && !empty($claim['hash']), 'the claim is recorded server-side');
check(($merchantSession['setup_ownership_verified'] ?? null) === $claim['hash'],
    'the session is bound to this claim, not to a bare flag');

$recoveryCode = Setup::expectedToken();
check($recoveryCode !== null && strlen($recoveryCode) === 10, 'a short typeable recovery code is generated');
$tokenFile = Setup::tokenPath();
check($tokenFile !== null && is_file($tokenFile), 'the recovery code is written to the data directory');
check((bool)preg_match('/^setup-token-[0-9a-f]{16}\.txt$/', basename($tokenFile)),
    'under a random file name that cannot be fetched by guessing (' . basename($tokenFile) . ')');
check(Setup::expectedToken() === $recoveryCode && Setup::tokenPath() === $tokenFile,
    'the operator-facing file stays the same across requests');
check(!str_contains(Setup::tokenLocationHint(), $dataDir) && str_contains(Setup::tokenLocationHint(), 'setup-token-'),
    'the lock-out page describes where the file is without printing the server path');

// --- The same browser keeps working ------------------------------------------
check(Setup::ownershipVerified(), 'the claiming browser continues without re-checking');

// --- A different browser is refused ------------------------------------------
browser(['cashupay_setup_claim' => 'not-the-right-secret']);
check(!Setup::ownershipVerified(), 'a different browser cannot configure the installation');
check(!Setup::claim(), 'and cannot claim it while it is held');
check(Setup::claimedByAnother(), 'the page can tell the visitor setup was started elsewhere');
browser(['cashupay_setup_claim' => ['array']]);
check(!Setup::ownershipVerified(), 'an array-shaped cookie is refused, not a fatal');

// --- A wrong recovery code does not help -------------------------------------
browser();
check(!Setup::verifyOwnershipToken('WRONGCODE0'), 'a wrong recovery code is refused');
check(Setup::$lastRecoveryError === 'invalid', 'and reported as wrong');
check(!Setup::verifyOwnershipToken(''), 'an empty recovery code is refused');

// --- The real owner takes over with the code ---------------------------------
browser();
check(Setup::verifyOwnershipToken(strtolower($recoveryCode)), 'the recovery code takes back control (any case)');
check(Setup::ownershipVerified(), 'the recovering browser may now configure the installation');
$newClaim = Config::get('setup_claim');
check($newClaim['hash'] !== $claim['hash'], 'taking over replaces the previous claim');

// The previous holder's still-open tab: its session carries the old claim hash.
$_SESSION = $merchantSession;
$_COOKIE = [];
check(!Setup::ownershipVerified(), 'the browser that held the claim before is locked out at once');

// --- Guessing is throttled ---------------------------------------------------
browser();
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$limited = false;
for ($i = 0; $i < Setup::RECOVERY_ATTEMPTS + 2; $i++) {
    Setup::verifyOwnershipToken('BADBADBAD' . $i);
    $limited = $limited || Setup::$lastRecoveryError === 'rate_limited';
}
check($limited, 'repeated wrong codes from one address are rate-limited');
check(!Setup::verifyOwnershipToken($recoveryCode) && Setup::$lastRecoveryError === 'rate_limited',
    'even the right code waits out the limit');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

// --- Finishing setup retires the code ----------------------------------------
Setup::clearOwnershipToken();
check(!is_file($tokenFile), 'the recovery code file is removed once setup completes');
check(Setup::tokenPath() === null, 'no recovery code file is left behind');
check(Config::get('setup_claim') === null, 'the claim is released once setup completes');

// --- A legacy fixed-name token is moved off the guessable name ----------------
file_put_contents($dataDir . '/setup-token.txt', "LEGACY0001\n");
check(Setup::expectedToken() === 'LEGACY0001', 'a code from an older version keeps working');
check(!is_file($dataDir . '/setup-token.txt') && Setup::tokenPath() !== null, 'but now lives under a random name');
Setup::clearOwnershipToken();

// --- An abandoned claim ages out only when nothing was done (OPUS-017) --------
Config::set('setup_claim', ['hash' => hash('sha256', 'someone-else'), 'at' => time() - 4 * 3600]);
browser();
check(!Setup::isClaimed() && Setup::claim(), 'an idle claim with no progress can be taken by the next visitor who starts setup');

Config::set('setup_claim', ['hash' => hash('sha256', 'someone-else'), 'at' => time() - 90 * 86400]);
Config::set('admin_password_hash', password_hash('owner-password', PASSWORD_DEFAULT));
browser();
check(Setup::isClaimed() && !Setup::claim(),
    'once an admin password was chosen, a stalled setup is never handed to a stranger');

echo "setup_ownership: OK\n";

foreach (glob($dataDir . '/*') ?: [] as $file) { @unlink($file); }
@rmdir($dataDir);
