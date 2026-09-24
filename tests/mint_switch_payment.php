<?php
/**
 * After a store switches to a new mint, the next customer payment must still settle.
 *
 * v0.5.4 bound the seed to the new mint in restore mode but never ran restore(), so the
 * account refused to mint: every paid invoice stayed Processing and the shop never
 * marked the order paid. Two paths are covered: the admin mint change itself, and the
 * background repair of stores already left in that state by v0.5.4.
 *
 * Uses two local mock mints (tests/mock_mint_router.php); no network, no real funds.
 *
 * Run: php tests/mint_switch_payment.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

$root = dirname(__DIR__);
$dataDir = sys_get_temp_dir() . '/cashupay-mintswitch-' . bin2hex(random_bytes(8));
mkdir($dataDir, 0700, true);
define('CASHUPAY_DATA_DIR', $dataDir);

require_once $root . '/cashu-wallet-php/CashuWallet.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/config.php';
require_once $root . '/includes/invoice.php';

function check(bool $cond, string $what): void {
    if (!$cond) { fwrite(STDERR, "FAIL: $what\n"); exit(1); }
    echo "  ok: $what\n";
}

$mints = [];
function startMint(string $name): string {
    global $mints, $dataDir, $root;
    $port = random_int(18100, 18999);
    $proc = proc_open(
        ['php', '-S', "127.0.0.1:$port", "$root/tests/mock_mint_router.php"],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes, $root, ['MOCK_MINT_STATE' => "$dataDir/$name.json"] + getenv()
    );
    for ($i = 0; $i < 50 && !@file_get_contents("http://127.0.0.1:$port/v1/info"); $i++) {
        usleep(100000);
    }
    $mints[] = $proc;
    return "http://127.0.0.1:$port";
}
register_shutdown_function(function () use ($dataDir) {
    global $mints;
    foreach ($mints as $p) { proc_terminate($p); }
    exec('rm -rf ' . escapeshellarg($dataDir));
});

/** Pay an invoice at the mock mint and poll it as the checkout page would. */
function payAndPoll(string $mintUrl, array $invoice): array {
    $ctx = stream_context_create(['http' => [
        'method' => 'POST', 'header' => 'Content-Type: application/json',
        'content' => json_encode(['quote' => $invoice['quote_id']]),
    ]]);
    @file_get_contents("$mintUrl/__control/pay", false, $ctx);
    Database::update('invoices', ['last_polled_at' => null], 'id = ?', [$invoice['id']]);
    Invoice::pollSingleQuote($invoice['id'], true);
    return Database::fetchOne('SELECT status, last_poll_error FROM invoices WHERE id = ?', [$invoice['id']]);
}

function newStore(string $id, string $mintUrl, string $seed): void {
    Database::insert('stores', [
        'id' => $id, 'name' => $id, 'wallet_account_id' => Database::generateWalletAccountId(),
        'mint_url' => $mintUrl, 'mint_unit' => 'sat',
        'seed_phrase' => $seed,
        'exchange_fee_percent' => 0, 'created_at' => Database::timestamp(),
    ]);
    Invoice::initializeWalletForStore($id, false);
}

$mintA = startMint('a');
$mintB = startMint('b');
Database::initialize();
Config::set('setup_complete', '1');

echo "Admin mint change readies the new account\n";
newStore('s1', $mintA, 'half depart obvious quality work element tank gorilla view sugar picture humble');
Config::updateStore('s1', ['mint_url' => $mintB], true);
check(Invoice::readyStoreWallet('s1'), 'the new mint account is ready right after the change');
$row = payAndPoll($mintB, Invoice::create('s1', ['amount' => 21, 'currency' => 'sat']));
check($row['status'] === 'Settled', 'a payment after the switch settles (got ' . $row['status'] . ')');

echo "Background repair of a store left unready by v0.5.4\n";
// A different seed: two stores never share one (the admin refuses duplicates).
newStore('s2', $mintA, 'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about');
Config::updateStore('s2', ['mint_url' => $mintB], true);
// Exactly what v0.5.4's update_store did: bind in restore mode, never restore.
$w = Invoice::initializeWalletForStore('s2', true);
check($w->requiresRecovery(), 'reproduces the broken state: account bound but not ready');

// What the demo instance hit: a customer pays while the account is still unready.
$stuck = Invoice::create('s2', ['amount' => 20, 'currency' => 'sat']);
$row = payAndPoll($mintB, $stuck);
check($row['status'] !== 'Settled', 'reproduces the stuck payment (' . $row['status'] . ')');

$result = Invoice::ensurePrimaryWalletsReady();
check(str_contains($result, 'readied 1'), "the runner task readies it without a slow scan ($result)");
check(Invoice::ensurePrimaryWalletsReady() === 'none', 'a second run finds nothing to do');

Database::update('invoices', ['last_polled_at' => null, 'processing_since' => 0], 'id = ?', [$stuck['id']]);
Invoice::pollSingleQuote($stuck['id'], true);
$row = Database::fetchOne('SELECT status FROM invoices WHERE id = ?', [$stuck['id']]);
check($row['status'] === 'Settled', 'the payment that was stuck is credited (got ' . $row['status'] . ')');

$row = payAndPoll($mintB, Invoice::create('s2', ['amount' => 34, 'currency' => 'sat']));
check($row['status'] === 'Settled', 'a payment after the repair settles (got ' . $row['status'] . ')');

echo "All mint-switch payment checks passed.\n";
