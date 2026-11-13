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

echo "Mints the live tables forget stay on the sheet (UX-F5)\n";
Database::insert('payment_requests', ['id' => 'pr1', 'store_id' => 's1', 'amount' => 5, 'unit' => 'sat',
    'mint_url' => 'https://request.example', 'status' => 'pending', 'expires_at' => time() + 600,
    'created_at' => Database::timestamp()]);
$urls = fn() => array_column(RecoveryKit::mints('s1'), 'mint_url');
check(in_array('https://request.example', $urls(), true), 'a mint a payment request went through');
// Pruned invoices and a removed backup mint: only the history remembers them.
Database::query('DELETE FROM invoices');
$anotherId = (int)Database::fetchOne("SELECT id FROM store_mints WHERE mint_url = 'https://another.example'")['id'];
Config::removeStoreBackupMint($anotherId);
check(in_array('https://old.example', $urls(), true), 'the old invoice mint, after invoices were pruned');
check(in_array('https://another.example', $urls(), true), 'a removed backup mint');
// A mint switch records both sides, even on a database that never wrote the history.
Database::insert('stores', ['id' => 's2', 'name' => 'B', 'wallet_account_id' => Database::generateWalletAccountId(),
    'mint_url' => 'https://first.example', 'mint_unit' => 'sat', 'created_at' => Database::timestamp()]);
Config::updateStore('s2', ['mint_url' => 'https://second.example', 'mint_unit' => 'usd']);
$h = array_map(fn($m) => $m['mint_url'] . '|' . $m['unit'], Config::storeMintHistory('s2'));
check($h === ['https://first.example|sat', 'https://second.example|usd'], 'a mint and unit change keeps the old one: ' . json_encode($h));
check(Config::get('mint_history_s2') !== null, 'and the history is stored, not just computed');

echo "The sheet counts as kept only once it was made, and a page once confirmed (UX-F6)\n";
Config::delete('recovery_kit_s1');
$fpShown = RecoveryKit::fingerprint('s1');
$html = RecoveryKit::html(Config::getStore('s1'), 'tok123');
check(str_contains($html, 'name="action" value="recovery_kit_taken"') && str_contains($html, 'value="tok123"')
    && str_contains($html, 'value="' . $fpShown . '"'), 'the page ends with an "I have printed or saved it" form');
check(!str_contains(RecoveryKit::html(Config::getStore('s1')), 'recovery_kit_taken'), 'only when a token is given');
check(RecoveryKit::reminder('s1') === 'never', 'showing the page alone does not count as taken');
Config::addStoreBackupMint('s1', 'https://later.example', 'sat');
RecoveryKit::markTaken('s1', $fpShown);
check(RecoveryKit::reminder('s1') === 'mints_changed', 'confirming an older sheet still asks for one with the newer mint');

echo "recovery_kit: OK\n";
