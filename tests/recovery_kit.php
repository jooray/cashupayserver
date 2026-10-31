<?php
/**
 * The recovery sheet lists every payment service a store has used, and the dashboard
 * asks for a new one when that list changes (R8, 2026-10-28).
 *
 * No network: it only reads the database.
 *
 * Run: php tests/recovery_kit.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

$dataDir = sys_get_temp_dir() . '/cashupay-kit-' . bin2hex(random_bytes(8));
mkdir($dataDir, 0700, true);
define('CASHUPAY_DATA_DIR', $dataDir);
register_shutdown_function(fn() => exec('rm -rf ' . escapeshellarg($dataDir)));

require_once dirname(__DIR__) . '/cashu-wallet-php/CashuWallet.php';
require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/recovery_kit.php';

function check(bool $cond, string $what): void {
    if (!$cond) { fwrite(STDERR, "FAIL: $what\n"); exit(1); }
    echo "  ok: $what\n";
}

$seed = 'legal winner thank year wave sausage worth useful legal winner thank yellow';
Database::initialize();
Database::insert('stores', [
    'id' => 's1', 'name' => 'Yarn <Shop>', 'wallet_account_id' => Database::generateWalletAccountId(),
    'mint_url' => 'https://new.example/', 'mint_unit' => 'sat', 'seed_phrase' => $seed,
    'created_at' => Database::timestamp(),
]);

echo "The sheet lists every mint the store has used\n";
Config::addStoreBackupMint('s1', 'https://backup.example', 'sat');
Database::insert('invoices', ['id' => 'inv_old', 'store_id' => 's1', 'amount' => '10', 'currency' => 'SAT',
    'amount_sats' => 10, 'status' => 'Settled', 'mint_url' => 'https://old.example', 'mint_unit' => 'sat', 'expiration_time' => time(),
    'created_at' => Database::timestamp()]);
$mints = array_map(fn($m) => $m['mint_url'] . ($m['current'] ? ' now' : ' before'), RecoveryKit::mints('s1'));
check(in_array('https://new.example now', $mints, true), 'the primary mint, in use');
check(in_array('https://backup.example now', $mints, true), 'the backup mint, in use');
check(in_array('https://old.example before', $mints, true), 'and a mint an earlier invoice went through');

$store = Config::getStore('s1');
$text = RecoveryKit::text($store);
check(str_contains($text, ' 1. legal') && str_contains($text, '12. yellow'), 'the text sheet numbers all twelve words');
check(str_contains($text, 'https://old.example') && str_contains($text, 'used before'), 'and names the old mint');
$html = RecoveryKit::html($store);
check(str_contains($html, 'Yarn &lt;Shop&gt;') && !str_contains($html, 'Yarn <Shop>'), 'the printable sheet escapes the store name');

echo "The dashboard asks for a sheet, and for a new one when the mints change\n";
check(RecoveryKit::reminder('s1') === 'never', 'before any sheet was taken');
RecoveryKit::markTaken('s1');
check(RecoveryKit::reminder('s1') === null, 'not after one was taken');
Config::addStoreBackupMint('s1', 'https://another.example', 'sat');
check(RecoveryKit::reminder('s1') === 'mints_changed', 'again once a new mint is added');

echo "recovery_kit: OK\n";
