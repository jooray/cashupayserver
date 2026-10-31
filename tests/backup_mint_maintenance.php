<?php
/**
 * Background maintenance covers every account a store lists, not only the primary mint
 * (N4, 2026-10-28). A backup mint that took payments while the primary was down holds
 * real money; its proofs must be moved off a retiring keyset before the mint stops
 * honouring it.
 *
 * Two mock mints on loopback.
 *
 * Run: php tests/backup_mint_maintenance.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

$root = dirname(__DIR__);
$dataDir = sys_get_temp_dir() . '/cashupay-backupmaint-' . bin2hex(random_bytes(8));
mkdir($dataDir, 0700, true);
define('CASHUPAY_DATA_DIR', $dataDir);

require_once $root . '/cashu-wallet-php/CashuWallet.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/config.php';
require_once $root . '/includes/invoice.php';
require_once $root . '/includes/background_runner.php';

use Cashu\ProofState;

function check(bool $cond, string $what): void {
    if (!$cond) { fwrite(STDERR, "FAIL: $what\n"); exit(1); }
    echo "  ok: $what\n";
}

$procs = [];
function startMint(string $name): string {
    global $procs, $dataDir, $root;
    $port = random_int(18100, 18999);
    $p = proc_open([PHP_BINARY, '-d', 'opcache.enable=0', '-S', "127.0.0.1:$port", "$root/tests/mock_mint_router.php"],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root,
        ['MOCK_MINT_STATE' => "$dataDir/$name.json"] + getenv());
    for ($i = 0; $i < 50 && !@file_get_contents("http://127.0.0.1:$port/v1/info"); $i++) usleep(100000);
    $procs[] = $p;
    return "http://127.0.0.1:$port";
}
register_shutdown_function(function () use ($dataDir) { global $procs; foreach ($procs as $p) proc_terminate($p); exec('rm -rf ' . escapeshellarg($dataDir)); });
function post(string $url, array $body) {
    return @file_get_contents($url, false, stream_context_create(['http' => ['method' => 'POST',
        'header' => 'Content-Type: application/json', 'content' => json_encode($body)]]));
}
function fresh(): void { (new ReflectionProperty(Invoice::class, 'walletCache'))->setValue(null, []); }
function fund(string $store, string $mint, int $amt): void {
    $w = Invoice::getWalletForStore($store, $mint, 'sat');
    $q = $w->requestMintQuote($amt);
    post("$mint/__control/pay", ['quote' => $q->quote]);
    $w->mint($q->quote, $amt);
}
function keysetsOf(string $mint, string $acct): array {
    $s = new Cashu\WalletStorage(Database::getDbPath(), $mint, 'sat', $acct);
    $out = [];
    foreach ($s->getProofsAsObjects(ProofState::UNSPENT) as $p) $out[$p->id] = ($out[$p->id] ?? 0) + $p->amount;
    return $out;
}
$A = startMint('primary'); $B = startMint('backup');
Database::initialize();
Config::set('setup_complete', '1');
Config::set('allow_private_mints', true);
$acct = Database::generateWalletAccountId();
Database::insert('stores', ['id' => 's1', 'name' => 's1', 'wallet_account_id' => $acct, 'mint_url' => $A,
    'mint_unit' => 'sat', 'seed_phrase' => 'half depart obvious quality work element tank gorilla view sugar picture humble',
    'exchange_fee_percent' => 0, 'created_at' => Database::timestamp()]);
Invoice::initializeWalletForStore('s1', false);
Config::addStoreBackupMint('s1', $B, 'sat');
Invoice::initializeWalletForStore('s1', false, $B, 'sat');
fund('s1', $A, 64); fund('s1', $B, 64);
$oldA = json_decode(post("$A/__control/rotate", []), true);
$oldB = json_decode(post("$B/__control/rotate", []), true);
fresh();
Config::set('last_keyset_rotation_check', '0');
$tasks = BackgroundRunner::tasks();
echo "Keyset rotation reaches backup mints too (N4)\n";
$tasks['rotate_keyset_proofs']();
check(array_keys(keysetsOf($A, $acct)) === [$oldA['active_keyset']],
    'the primary mint\'s ecash moved to its new keyset');
check(array_keys(keysetsOf($B, $acct)) === [$oldB['active_keyset']],
    'the backup mint\'s ecash moved to its new keyset as well');
check(array_sum(keysetsOf($B, $acct)) === 63, 'and kept its value less the swap fee');

echo "backup_mint_maintenance: OK\n";
