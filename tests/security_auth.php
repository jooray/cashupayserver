<?php
/**
 * Admin password, sessions, API-key identity and permissions, mint URL checks.
 *
 * - The dashboard's own key is recognised by the key itself, not by its label, and the
 *   label cannot be claimed by anyone else (SPB-SEC-009).
 * - Changing the password, or "log out everywhere", ends every older session
 *   (SPB-SEC-008).
 * - BTCPay's permission hierarchy, including 2.4.5's canmanageinvoicestatus (OPUS-014).
 * - An operator-entered mint URL on a private network is refused (SPB-SSRF-001).
 *
 * Run: php tests/security_auth.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

// Sessions need headers not to have been "sent"; buffer the progress output.
ob_start();

$dataDir = sys_get_temp_dir() . '/cashupay-auth-' . bin2hex(random_bytes(8));
mkdir($dataDir, 0700, true);
define('CASHUPAY_DATA_DIR', $dataDir);

require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/mint_helpers.php';

function check(bool $cond, string $what): void {
    if (!$cond) { fwrite(STDERR, "FAIL: $what\n"); exit(1); }
    echo "  ok: $what\n";
}

function throws(callable $fn): ?string {
    try {
        $fn();
    } catch (Throwable $e) {
        return $e->getMessage();
    }
    return null;
}

Database::initialize();
Database::insert('stores', [
    'id' => 'store_a', 'name' => 'A', 'wallet_account_id' => 'wa_a', 'created_at' => Database::timestamp(),
]);

echo "The dashboard key (SPB-SEC-009)\n";
foreach (['Internal (Dashboard)', 'internal (dashboard)', '  Internal  (Dashboard) ', "INTERNAL\t(DASHBOARD)"] as $label) {
    check(throws(fn() => Auth::createApiKey('store_a', $label)) !== null, 'refused label: ' . json_encode($label));
}
$internalRaw = Auth::getOrCreateInternalApiKey('store_a');
$internalId = Database::fetchOne('SELECT id FROM api_keys WHERE key_hash = ?', [hash('sha256', $internalRaw)])['id'];
check(Auth::isInternalApiKey($internalId), 'the real internal key is recognised');
check(throws(fn() => Auth::deleteApiKey($internalId)) !== null, 'and cannot be deleted');

// A row that only *looks* like the internal key (as a pairing could create before).
Database::insert('api_keys', [
    'id' => 'key_lookalike', 'key_hash' => hash('sha256', 'whatever'), 'store_id' => 'store_a',
    'label' => 'Internal (Dashboard)', 'permissions' => '["*"]', 'created_at' => Database::timestamp(),
]);
check(!Auth::isInternalApiKey('key_lookalike'), 'a look-alike label is not the internal key');
check(Auth::deleteApiKey('key_lookalike') === true, 'and can be deleted');
$normal = Auth::createApiKey('store_a', 'WooCommerce', ['btcpay.store.cancreateinvoice']);
check(Auth::deleteApiKey($normal['id']) === true, 'ordinary keys delete as before');

echo "Permission hierarchy (OPUS-014)\n";
$has = fn(array $perms, string $p) => Auth::hasPermission(['permissions' => $perms], $p);
check($has(['btcpay.store.canmodifyinvoices'], 'btcpay.store.canmanageinvoicestatus'), 'modify invoices implies manage status');
check($has(['btcpay.store.canmodifyinvoices:store_a'], 'btcpay.store.canviewinvoices'), 'and viewing, scoped or not');
check($has(['btcpay.store.canmodifystoresettings'], 'btcpay.store.webhooks.canmodifywebhooks'), 'store settings implies webhooks');
check($has(['btcpay.store.canmodifystoresettings'], 'btcpay.store.canmanageinvoicestatus'), 'and everything below it');
check($has(['btcpay.store.canviewstoresettings'], 'btcpay.store.canviewinvoices'), 'viewing settings implies viewing invoices');
check(!$has(['btcpay.store.canmanageinvoicestatus'], 'btcpay.store.canmodifyinvoices'), 'the implication is one-way');
check(!$has(['btcpay.store.canviewinvoices'], 'btcpay.store.cancreateinvoice'), 'viewing does not imply creating');
check(!$has(['btcpay.store.canviewinvoicesX'], 'btcpay.store.canviewinvoices'), 'a longer name is not a prefix match');
check($has(['*'], 'btcpay.store.canmodifystoresettings'), 'the admin wildcard still holds everything');

echo "Password change and sessions (SPB-SEC-008)\n";
Auth::setAdminPassword('first-password');
check(Auth::login('first-password'), 'logging in works');
check(Auth::isLoggedIn(), 'the session is valid');

check(throws(fn() => Auth::changeAdminPassword('wrong', 'second-password')) === 'The current password is not correct.',
    'the current password must be right');
check(str_contains((string)throws(fn() => Auth::changeAdminPassword('first-password', 'short')), 'at least 8'),
    'the new one must be at least 8 characters');

// A second session, logged in with the old password (another browser, or a stolen cookie).
$other = ['admin_logged_in' => true, 'login_time' => time(), 'password_version' => $_SESSION['password_version']];

Auth::changeAdminPassword('first-password', 'second-password');
check(Auth::isLoggedIn(), 'the session that changed the password stays logged in');
check(Auth::verifyAdminPassword('second-password') && !Auth::verifyAdminPassword('first-password'), 'the new password is in force');

$mine = $_SESSION;
$_SESSION = $other;
check(!Auth::isLoggedIn(), 'an older session is logged out');
$_SESSION = $mine;

$_SESSION = ['admin_logged_in' => true, 'login_time' => time()];
check(!Auth::isLoggedIn(), 'a session without a version is logged out once a version exists');

check(Auth::login('second-password') && Auth::isLoggedIn(), 'logging in again works');
$before = $_SESSION;
Auth::logoutEverywhere();
session_start();
$_SESSION = $before;
check(!Auth::isLoggedIn(), '"log out everywhere" ends sessions without changing the password');
check(Auth::verifyAdminPassword('second-password'), 'and the password is unchanged');

echo "Mint URLs (SPB-SSRF-001)\n";
Security::setResolverForTesting(fn(string $host) => [
    'mint.example.com' => ['93.184.216.34'],
    'router.lan' => ['192.168.1.1'],
][$host] ?? []);
check(MintHelpers::assertSafeMintUrl('https://Mint.Example.com/') === 'https://mint.example.com', 'a public mint is accepted and canonicalised');
foreach (['http://127.0.0.1:3338', 'https://router.lan', 'http://169.254.169.254/', 'http://100.64.0.1',
          'https://nowhere.example', 'file:///etc/passwd', 'not a url'] as $bad) {
    check(throws(fn() => MintHelpers::assertSafeMintUrl($bad)) !== null, "refused: $bad");
}
$result = MintHelpers::testExpiry('http://127.0.0.1:3338', 'sat');
check($result['success'] === false && str_contains($result['error'], 'private or local network'),
    'testExpiry refuses before contacting a private address');
Config::set('allow_private_mints', true);
check(MintHelpers::assertSafeMintUrl('http://127.0.0.1:3338') === 'http://127.0.0.1:3338', 'unless the operator allows private mints');

echo "All auth and mint URL checks passed.\n";
