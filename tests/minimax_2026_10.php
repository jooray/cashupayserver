<?php
/**
 * Regression checks for the third 2026-10 audit (MINIMAX).
 *
 * - A trusted-proxy list cannot name the whole internet, and a stored wide range is
 *   ignored after the upgrade (MINIMAX 4.3).
 * - Password sign-in has an address-independent budget, cleared by a successful login
 *   (MINIMAX 4.7).
 * - Leftover backup snapshots are removed from the data folder (MINIMAX 4.2).
 * - A finished transfer row cannot be rewritten by a later check (MINIMAX 4.18).
 * - A store's recovery phrase cannot be replaced once it exists (MINIMAX 4.24).
 *
 * Run: php tests/minimax_2026_10.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

$root = dirname(__DIR__);
$dataDir = sys_get_temp_dir() . '/cashupay-minimax-' . bin2hex(random_bytes(6));
mkdir($dataDir, 0700, true);
define('CASHUPAY_DATA_DIR', $dataDir);

require_once $root . '/includes/database.php';
require_once $root . '/includes/config.php';
require_once $root . '/includes/security.php';
require_once $root . '/includes/transfer.php';
require_once $root . '/includes/background_runner.php';

function check(bool $cond, string $what): void {
    if (!$cond) { fwrite(STDERR, "FAIL: $what\n"); exit(1); }
    echo "  ok: $what\n";
}

Database::initialize();
Config::set('setup_complete', true);

echo "Trusted proxy width (MINIMAX 4.3)\n";
check(Security::normalizeProxyEntry('0.0.0.0/0') === null, 'the whole IPv4 internet is not a proxy');
check(Security::normalizeProxyEntry('::/0') === null, 'nor the whole IPv6 internet');
check(Security::normalizeProxyEntry('10.0.0.0/8') === '10.0.0.0/8', 'a private /8 is still accepted');
check(Security::normalizeProxyEntry('2001:db8::/32') === '2001:db8::/32', 'an IPv6 /32 is still accepted');
check(Security::normalizeProxyEntry('fc00::/7') === 'fc00::/7' && Security::normalizeProxyEntry('fe80::/10') === 'fe80::/10',
    'private IPv6 proxy ranges are still accepted');
check(str_contains((string)Security::proxyEntryProblem('0.0.0.0/0'), 'too many addresses'),
    'the refusal explains why in plain words');

Config::set('proxy_mode', 'custom');
Config::set('trusted_proxies', ['0.0.0.0/0']);
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
check(!Security::isTrustedProxy('203.0.113.9'),
    'a wide range stored by an older release is ignored when read');

echo "Address-independent sign-in budget (MINIMAX 4.7)\n";
$_SERVER['REMOTE_ADDR'] = '198.51.100.1';
Security::clearLoginAttempts('198.51.100.1');
$allowed = 0;
for ($i = 1; $i <= 20; $i++) {
    if (Security::reserveLoginAttempt('198.51.100.' . $i, true)) {
        $allowed++;
    }
}
check($allowed === 20, 'twenty attempts from twenty addresses are counted');
check(!Security::reserveLoginAttempt('198.51.100.21', true),
    'the next attempt is refused even from an address with a fresh budget');
Security::clearLoginAttempts('198.51.100.21');
check(Security::reserveLoginAttempt('198.51.100.22', true),
    'a successful sign-in clears the global budget');

echo "Leftover backup snapshots (MINIMAX 4.2)\n";
$old = $dataDir . '/backup-20260101-000000-deadbeef.sqlite';
$fresh = $dataDir . '/backup-20260101-000001-feedface.sqlite';
file_put_contents($old, 'SQLite format 3');
file_put_contents($fresh, 'SQLite format 3');
touch($old, time() - BackgroundRunner::BACKUP_FILE_MAX_AGE - 60);
$removed = BackgroundRunner::cleanupBackups();
check($removed === 1 && !is_file($old) && is_file($fresh),
    'a snapshot older than the limit is removed; a fresh one is not');

echo "Finished transfers are history (MINIMAX 4.18)\n";
Database::insert('stores', [
    'id' => 'store_minimax',
    'name' => 'Minimax shop',
    'mint_url' => 'https://mint.example.com',
    'mint_unit' => 'sat',
    'seed_phrase' => 'one two three',
    'created_at' => time(),
]);
$xfer = Transfer::open('store_minimax', Transfer::TYPE_TOKEN_EXPORT, 21, 'sat', null, 'secret-1', 'Token export');
check(Transfer::complete($xfer, 21) === true, 'a pending transfer can be completed');
check(Transfer::fail($xfer, 'taken back') === false, 'a completed transfer cannot be failed');
check(Transfer::note($xfer, 'rewritten') === false, 'nor have its detail rewritten');
check((Transfer::getById($xfer)['status'] ?? '') === Transfer::STATUS_COMPLETED,
    'the row keeps the state it reached first');

echo "Recovery phrase is not replaceable (MINIMAX 4.24)\n";
$refused = false;
try {
    Config::updateStore('store_minimax', ['seed_phrase' => 'four five six'], true);
} catch (Throwable $e) {
    $refused = str_contains($e->getMessage(), 'cannot be replaced');
}
check($refused, 'a store that already has a phrase refuses a different one');

echo "All MINIMAX regression checks passed.\n";
