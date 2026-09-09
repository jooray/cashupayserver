<?php
/**
 * Installation ownership: the first browser claims setup, others are locked out, and the
 * recovery code takes it back.
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

// --- The merchant opens setup for the first time -----------------------------
browser();
check(Setup::ownershipVerified(), 'the first visitor claims the installation with no extra step');

// setcookie() cannot run under CLI, so read the secret the way the browser would have.
$claim = Config::get('setup_claim');
check(is_array($claim) && !empty($claim['hash']), 'the claim is recorded server-side');

// Reconstruct what the cookie would hold by claiming again from a clean slate.
$recoveryCode = Setup::expectedToken();
check($recoveryCode !== null && strlen($recoveryCode) === 10, 'a short typeable recovery code is generated');
check(is_file(Setup::tokenPath()), 'the recovery code is written to the protected data directory');

// --- The same browser keeps working ------------------------------------------
// Its session already carries the verified flag.
check(Setup::ownershipVerified(), 'the claiming browser continues without re-checking');

// --- A different browser is refused ------------------------------------------
browser(['cashupay_setup_claim' => 'not-the-right-secret']);
check(!Setup::ownershipVerified(), 'a different browser cannot configure the installation');
check(Setup::claimedByAnother(), 'the page can tell the visitor setup was started elsewhere');

// --- A wrong recovery code does not help -------------------------------------
browser();
check(!Setup::verifyOwnershipToken('WRONGCODE0'), 'a wrong recovery code is refused');
check(!Setup::verifyOwnershipToken(''), 'an empty recovery code is refused');

// --- The real owner takes over with the code ---------------------------------
browser();
check(Setup::verifyOwnershipToken($recoveryCode), 'the recovery code takes back control');
check(Setup::ownershipVerified(), 'the recovering browser may now configure the installation');

// Taking over re-claims, so the previous holder is the one locked out now.
$newClaim = Config::get('setup_claim');
check($newClaim['hash'] !== $claim['hash'], 'taking over replaces the previous claim');

// --- Finishing setup retires the code ----------------------------------------
Setup::clearOwnershipToken();
check(!is_file(Setup::tokenPath()), 'the recovery code file is removed once setup completes');
check(Config::get('setup_claim') === null, 'the claim is released once setup completes');

// --- An abandoned claim ages out ---------------------------------------------
Config::set('setup_claim', ['hash' => hash('sha256', 'someone-else'), 'at' => time() - 90000]);
browser();
check(Setup::ownershipVerified(), 'a claim abandoned for over a day can be taken by the next visitor');

echo "setup_ownership: OK\n";

foreach (glob($dataDir . '/*') ?: [] as $file) { @unlink($file); }
@rmdir($dataDir);
